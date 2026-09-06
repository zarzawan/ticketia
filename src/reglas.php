<?php

function reglas_disponibles(PDO $pdo): bool {
    try { $pdo->query('SELECT id FROM reglas_asignacion LIMIT 0'); return true; }
    catch (PDOException $e) { return false; }
}

function reglas_coincide(array $regla, array $ticket): bool {
    return (empty($regla['cliente_id']) || (int)$regla['cliente_id'] === (int)($ticket['cliente_id'] ?? 0))
        && ($regla['tipo'] === '' || $regla['tipo'] === ($ticket['tipo'] ?? ''));
}

function reglas_candidato(PDO $pdo, int $equipo): ?int {
    $stmt = $pdo->prepare("SELECT u.id FROM equipos_miembros m JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.equipo_id = :equipo AND u.activo = 1 AND u.rol IN ('admin','operador')
        ORDER BY (SELECT COUNT(*) FROM incidencias WHERE asignado_id = u.id AND estado IN ('abierta','en_curso')), u.id LIMIT 1");
    $stmt->execute([':equipo' => $equipo]);
    return ($id = $stmt->fetchColumn()) !== false ? (int)$id : null;
}

/** Solo asigna nuevos tickets sin responsable. No altera decisiones manuales. */
function reglas_aplicar(PDO $pdo, int $id): void {
    if (!reglas_disponibles($pdo)) return;
    $propia = !$pdo->inTransaction();
    try {
        if ($propia) $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, estado, tipo, cliente_id, asignado_id FROM incidencias WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ticket && !$ticket['asignado_id'] && in_array($ticket['estado'], dominio_estados_activos(), true)) {
            $reglas = $pdo->query('SELECT * FROM reglas_asignacion WHERE activo = 1 ORDER BY prioridad, id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reglas as $regla) {
                if (!reglas_coincide($regla, $ticket)) continue;
                $pdo->prepare('SELECT id FROM equipos_soporte WHERE id = ? FOR UPDATE')->execute([$regla['equipo_id']]);
                $tecnico = reglas_candidato($pdo, (int)$regla['equipo_id']);
                if ($tecnico === null) continue;
                $pdo->prepare('UPDATE incidencias SET asignado_id = :tecnico WHERE id = :id AND asignado_id IS NULL')
                    ->execute([':tecnico' => $tecnico, ':id' => $id]);
                auditar($pdo, 'asignacion_automatica', 'incidencia #' . $id . ', regla #' . $regla['id'] . ', tecnico #' . $tecnico);
                break;
            }
        }
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        if (!$propia) throw $e;
        error_log('TicketIA: no se pudo aplicar la regla de asignacion.');
    }
}
