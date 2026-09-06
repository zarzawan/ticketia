<?php
// Escritura coordinada de conversaciones. La IA se ejecuta antes de tomar bloqueos.

function soporte_esquema_disponible(PDO $pdo): bool {
    try {
        $pdo->query('SELECT solicitud_id FROM mensajes LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function soporte_huella(PDO $pdo, int $id): string {
    $stmt = $pdo->prepare("SELECT estado, asignado_id, tipo, urgencia, resolucion_notas,
        (SELECT COALESCE(MAX(id),0) FROM mensajes WHERE id_incidencia = i.id) AS ultimo
        FROM incidencias i WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return hash('sha256', json_encode($stmt->fetch(PDO::FETCH_ASSOC)));
}

/** Devuelve ok, duplicado o conflicto. Un reenvio nunca modifica otra incidencia. */
function soporte_espera_disponible(PDO $pdo): bool {
    $columna = $pdo->query("SHOW COLUMNS FROM incidencias LIKE 'estado'")->fetch(PDO::FETCH_ASSOC);
    return str_contains((string)($columna['Type'] ?? ''), "'esperando_cliente'");
}

function soporte_responder(PDO $pdo, int $id, array $usuario, string $mensaje, bool $interno, string $solicitud, string $huella = '', bool $esperar = false): string {
    $moderno = soporte_esquema_disponible($pdo);
    if ($esperar && ($interno || !in_array($usuario['rol'], ['admin','operador'], true) || !soporte_espera_disponible($pdo))) return 'conflicto';
    if ($mensaje === '' || mb_strlen($mensaje) > 30000 || !preg_match('/^[a-f0-9]{32}$/D', $solicitud)) return 'conflicto';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT estado FROM incidencias WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $estado = $stmt->fetchColumn();
        if ($moderno) {
            $stmt = $pdo->prepare('SELECT id_incidencia FROM mensajes WHERE usuario_id = :usuario AND solicitud_id = :solicitud');
            $stmt->execute([':usuario' => $usuario['id'], ':solicitud' => $solicitud]);
            $previo = $stmt->fetchColumn();
            if ($previo !== false) {
                $pdo->rollBack();
                return (int)$previo === $id ? 'duplicado' : 'conflicto';
            }
        }
        if (!in_array($estado, dominio_estados_activos(), true)
            || ($huella !== '' && !hash_equals(soporte_huella($pdo, $id), $huella))) {
            $pdo->rollBack();
            return 'conflicto';
        }
        $autor = $usuario['rol'] === 'cliente' ? 'cliente' : 'tecnico';
        if ($autor === 'cliente' && ($interno || !incidencia_visible_para_cliente($pdo, $id, $usuario))) {
            $pdo->rollBack();
            return 'conflicto';
        }
        $sql = 'INSERT INTO mensajes (id_incidencia, usuario_id, autor, mensaje, interno' . ($moderno ? ', solicitud_id' : '')
            . ') VALUES (:id, :usuario, :autor, :mensaje, :interno' . ($moderno ? ', :solicitud' : '') . ')';
        $params = [':id' => $id, ':usuario' => $usuario['id'], ':autor' => $autor, ':mensaje' => $mensaje, ':interno' => (int)$interno];
        if ($moderno) $params[':solicitud'] = $solicitud;
        $pdo->prepare($sql)->execute($params);
        $nuevo = $esperar ? 'esperando_cliente' : (!$interno && $estado === 'esperando_cliente' ? 'en_curso' : (!$interno && $autor === 'tecnico' && $estado === 'abierta' ? 'en_curso' : $estado));
        if ($nuevo !== $estado) {
            $pdo->prepare('UPDATE incidencias SET estado = :estado WHERE id = :id')->execute([':estado'=>$nuevo, ':id' => $id]);
            $pdo->prepare("INSERT INTO cambios_estado (id_incidencia, usuario_id, estado_anterior, estado_nuevo)
                VALUES (:id, :usuario, :anterior, :nuevo)")->execute([':id' => $id, ':usuario' => $usuario['id'], ':anterior'=>$estado, ':nuevo'=>$nuevo]);
        }
        $pdo->commit();
        return 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('TicketIA: no se pudo guardar la respuesta.');
        return 'conflicto';
    }
}

function soporte_borrador(PDO $pdo, int $usuario, int $id): array {
    try {
        $stmt = $pdo->prepare('SELECT mensaje, accion, version FROM soporte_borradores WHERE usuario_id = :usuario AND incidencia_id = :id');
        $stmt->execute([':usuario' => $usuario, ':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['mensaje' => '', 'accion' => 'responder', 'version' => 0];
    } catch (PDOException $e) {
        return ['mensaje' => '', 'accion' => 'responder', 'version' => 0];
    }
}

/** Paginacion por cursor estable. La pagina no afecta a los indicadores operativos. */
function soporte_mensajes(PDO $pdo, int $id, bool $cliente, int $antes = 0): array {
    $sql = 'SELECT m.id, m.autor, m.mensaje, m.fecha, m.interno, u.nombre AS usuario_nombre
        FROM mensajes m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.id_incidencia = :id';
    $params = [':id' => $id];
    if ($cliente) $sql .= ' AND m.interno = 0';
    if ($antes > 0) { $sql .= ' AND m.id < :antes'; $params[':antes'] = $antes; }
    $stmt = $pdo->prepare($sql . ' ORDER BY m.id DESC LIMIT 31');
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hayMas = count($filas) > 30;
    if ($hayMas) array_pop($filas);
    return ['mensajes' => array_reverse($filas), 'antes' => $hayMas ? (int)end($filas)['id'] : 0];
}
