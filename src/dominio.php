<?php
// lib/dominio.php
// Constantes de dominio y helpers de consulta compartidos por todas las paginas.
// Antes estas listas estaban duplicadas en 6+ ficheros; este es el unico punto de verdad.

function dominio_tipo_iconos(): array {
    return [
        'Servidores' => '🖥️',
        'Almacenamiento' => '💾',
        'Red y acceso' => '🌐',
        'Cloud' => '☁️',
        'Backups' => '📦',
        'Seguridad' => '🔒',
        'Sistemas' => '⚙️',
        'Web y dominios' => '🌍',
        'Correo' => '✉️',
        'Bases de datos' => '🗄️',
        'Software y apps' => '📱',
        'Usuarios y permisos' => '👥',
        'Monitorización' => '📊',
        'APIs y scripts' => '💻',
        'Cambios y mejoras' => '🔄',
        'Microsoft 365' => '📧',
        'ERP / CRM' => '📋',
        'Facturación' => '💳',
        'Quejas y reclamaciones' => '😡',
        'Comercial' => '💼'
    ];
}

function dominio_tipo_codigos(): array {
    return [
        'Servidores' => '[SV]',
        'Almacenamiento' => '[ALM]',
        'Red y acceso' => '[NET]',
        'Cloud' => '[CLD]',
        'Backups' => '[BKP]',
        'Seguridad' => '[SEC]',
        'Sistemas' => '[SYS]',
        'Web y dominios' => '[WEB]',
        'Correo' => '[MAIL]',
        'Bases de datos' => '[DB]',
        'Software y apps' => '[APP]',
        'Usuarios y permisos' => '[USR]',
        'Monitorización' => '[MON]',
        'APIs y scripts' => '[API]',
        'Cambios y mejoras' => '[CHG]',
        'Microsoft 365' => '[M365]',
        'ERP / CRM' => '[ERP]',
        'Facturación' => '[FIN]',
        'Quejas y reclamaciones' => '[QR]',
        'Comercial' => '[COM]'
    ];
}

function dominio_tipos(): array {
    return array_keys(dominio_tipo_iconos());
}

/** Lista de tipos en formato "'A', 'B', 'C'" para incrustar en prompts. */
function dominio_tipos_para_prompt(): string {
    return "'" . implode("', '", dominio_tipos()) . "'";
}

function dominio_urgencias(): array {
    return ['critico', 'urgente', 'leve'];
}

function dominio_estados(): array {
    return ['abierta', 'en_curso', 'cerrada'];
}

function dominio_ordenes(): array {
    return ['id_desc', 'recientes', 'antiguas', 'urgencia'];
}

/**
 * Anade a $sql/$params las condiciones de filtro comunes del panel.
 * $f admite: busqueda, tipo, urgencia, estado, desde, hasta.
 * $prefix permite usarlo con alias de tabla (ej: 'i.').
 */
function dominio_append_filtros(string &$sql, array &$params, array $f, string $prefix = ''): void {
    if (!empty($f['tipo'])) {
        $sql .= " AND {$prefix}tipo = :tipo";
        $params[':tipo'] = $f['tipo'];
    }

    if (!empty($f['urgencia'])) {
        $sql .= " AND {$prefix}urgencia = :urgencia";
        $params[':urgencia'] = $f['urgencia'];
    }

    if (!empty($f['estado'])) {
        $sql .= " AND {$prefix}estado = :estado_filtro";
        $params[':estado_filtro'] = $f['estado'];
    }

    if (!empty($f['desde'])) {
        $sql .= " AND DATE({$prefix}fecha_creacion) >= :desde";
        $params[':desde'] = $f['desde'];
    }

    if (!empty($f['hasta'])) {
        $sql .= " AND DATE({$prefix}fecha_creacion) <= :hasta";
        $params[':hasta'] = $f['hasta'];
    }

    if (!empty($f['asignado'])) {
        if ($f['asignado'] === 'sin_asignar') {
            $sql .= " AND {$prefix}asignado_id IS NULL";
        } else {
            $sql .= " AND {$prefix}asignado_id = :asignado";
            $params[':asignado'] = (int)$f['asignado'];
        }
    }

    $busqueda = trim((string)($f['busqueda'] ?? ''));
    if ($busqueda !== '') {
        if (is_numeric($busqueda)) {
            $sql .= " AND {$prefix}id = :id_busqueda";
            $params[':id_busqueda'] = (int)$busqueda;
        } else {
            $sql .= " AND ({$prefix}titulo LIKE :busqueda OR {$prefix}resumen LIKE :busqueda OR {$prefix}descripcion LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }
    }
}

function dominio_order_by(string $orden): string {
    switch ($orden) {
        case 'recientes':
            return 'fecha_creacion DESC';
        case 'antiguas':
            return 'fecha_creacion ASC';
        case 'urgencia':
            return "FIELD(urgencia, 'critico', 'urgente', 'leve'), fecha_creacion DESC";
        case 'id_desc':
        default:
            return 'id DESC';
    }
}

/**
 * Cambia el estado de una incidencia dejando rastro en cambios_estado y
 * manteniendo fecha_cierre. Devuelve false si la incidencia no existe.
 */
function incidencia_cambiar_estado(PDO $pdo, int $id_incidencia, string $nuevo_estado): bool {
    if (!in_array($nuevo_estado, dominio_estados(), true)) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT estado FROM incidencias WHERE id = :id");
    $stmt->execute([':id' => $id_incidencia]);
    $actual = $stmt->fetchColumn();
    if ($actual === false) {
        return false;
    }
    if ($actual === $nuevo_estado) {
        return true;
    }

    $sql = "UPDATE incidencias SET estado = :estado, fecha_cierre = " .
        ($nuevo_estado === 'cerrada' ? 'NOW()' : 'NULL') .
        " WHERE id = :id";
    $pdo->prepare($sql)->execute([':estado' => $nuevo_estado, ':id' => $id_incidencia]);

    $usuario = function_exists('auth_usuario') ? auth_usuario() : null;
    $pdo->prepare(
        "INSERT INTO cambios_estado (id_incidencia, usuario_id, estado_anterior, estado_nuevo)
         VALUES (:id, :usuario, :anterior, :nuevo)"
    )->execute([
        ':id' => $id_incidencia,
        ':usuario' => $usuario['id'] ?? null,
        ':anterior' => $actual,
        ':nuevo' => $nuevo_estado,
    ]);

    if (function_exists('correo_notificar_estado')) {
        correo_notificar_estado($pdo, $id_incidencia, (string)$actual, $nuevo_estado);
    }

    return true;
}

/**
 * true si un usuario con rol cliente puede ver la incidencia: pertenece a su
 * empresa o, si no tiene empresa asignada, la creo el mismo.
 */
function incidencia_visible_para_cliente(PDO $pdo, int $id_incidencia, array $usuario): bool {
    $stmt = $pdo->prepare("SELECT cliente_id, creado_por FROM incidencias WHERE id = :id");
    $stmt->execute([':id' => $id_incidencia]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        return false;
    }
    $empresa = $usuario['cliente_id'] ?? null;
    if ($empresa !== null) {
        return (int)$fila['cliente_id'] === (int)$empresa;
    }
    return $fila['creado_por'] !== null && (int)$fila['creado_por'] === (int)($usuario['id'] ?? 0);
}
