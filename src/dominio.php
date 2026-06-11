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

// Lista de tecnicos asignables. Editar aqui para anadir o quitar personas
// (cuando exista gestion de usuarios, esta lista pasara a la base de datos).
function dominio_tecnicos(): array {
    return ['Jose Luis', 'Tecnico 2', 'Tecnico 3'];
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
            $sql .= " AND ({$prefix}asignado_a IS NULL OR {$prefix}asignado_a = '')";
        } else {
            $sql .= " AND {$prefix}asignado_a = :asignado";
            $params[':asignado'] = $f['asignado'];
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
