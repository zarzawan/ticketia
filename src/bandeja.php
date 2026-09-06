<?php
// Consulta comun: filtrar y ordenar todo el conjunto antes de paginar.

/** Usa la misma instantanea de politicas y precedencia que el calculo de dominio. */
function bandeja_objetivo_sql(PDO $pdo, string $objetivo): string {
    if (!in_array($objetivo, ['primera_respuesta', 'resolucion'], true)) {
        throw new InvalidArgumentException('Objetivo SLA no valido');
    }
    $politicas = $GLOBALS['ticketia_sla_politicas'] ?? [];
    $sql = 'CASE BINARY nivel_servicio';
    foreach (array_keys(dominio_niveles_servicio()) as $nivel) {
        $porUrgencia = "CASE BINARY COALESCE(urgencia, 'leve')";
        foreach (dominio_urgencias() as $urgencia) {
            $defecto = dominio_sla_objetivos($urgencia, '', $nivel)[$objetivo];
            $porTipo = '';
            foreach ($politicas[$nivel] ?? [] as $tipo => $reglas) {
                if ($tipo === '*' || $tipo === '' || !isset($reglas[$urgencia])) continue;
                $porTipo .= ' WHEN ' . $pdo->quote((string)$tipo) . ' THEN ' . (int)$reglas[$urgencia][$objetivo];
            }
            $valor = $porTipo === '' ? (string)$defecto : "CASE BINARY TRIM(tipo) $porTipo ELSE $defecto END";
            $porUrgencia .= ' WHEN ' . $pdo->quote($urgencia) . " THEN $valor";
            if ($urgencia === 'leve') $valorLeve = $valor;
        }
        $porUrgencia .= " ELSE $valorLeve END";
        $sql .= ' WHEN ' . $pdo->quote($nivel) . " THEN ($porUrgencia)";
        if ($nivel === 'estandar') $valorEstandar = $porUrgencia;
    }
    return "$sql ELSE ($valorEstandar) END";
}

/** No materializa tickets en PHP. El reloj es el mismo para filtros y presentacion. */
function bandeja_fuente_sql(PDO $pdo, ?DateTimeImmutable $ahora = null): string {
    $ahora = $pdo->quote(($ahora ?? new DateTimeImmutable())->format('Y-m-d H:i:s'));
    $respuesta = bandeja_objetivo_sql($pdo, 'primera_respuesta');
    $resolucion = bandeja_objetivo_sql($pdo, 'resolucion');
    $base = "SELECT i.*, u.nombre AS asignado_nombre, c.nombre AS cliente_nombre,
        COALESCE(c.nivel_servicio, 'estandar') AS nivel_servicio,
        (SELECT autor FROM mensajes m WHERE m.id_incidencia = i.id AND m.interno = 0 ORDER BY m.fecha DESC, m.id DESC LIMIT 1) AS ultimo_autor,
        (SELECT MIN(fecha) FROM mensajes m WHERE m.id_incidencia = i.id AND m.interno = 0 AND m.autor = 'tecnico') AS primera_respuesta,
        COALESCE((SELECT MAX(fecha) FROM mensajes m WHERE m.id_incidencia = i.id), i.fecha_creacion) AS ultima_actividad,
        (SELECT COUNT(*) FROM mensajes m WHERE m.id_incidencia = i.id) AS mensajes_total
        FROM incidencias i LEFT JOIN clientes c ON c.id = i.cliente_id LEFT JOIN usuarios u ON u.id = i.asignado_id";
    $plazos = "SELECT base.*, DATE_ADD(fecha_creacion, INTERVAL ($respuesta) HOUR) AS limite_respuesta,
        DATE_ADD(fecha_creacion, INTERVAL ($resolucion) HOUR) AS limite_resolucion,
        CASE WHEN estado = 'resuelta' THEN COALESCE(fecha_resolucion, $ahora)
             WHEN estado = 'cerrada' THEN COALESCE(fecha_resolucion, fecha_cierre, $ahora)
             ELSE $ahora END AS referencia_sla FROM ($base) base";
    // ROUND replica el umbral historico: 74,5 % se presenta como 75 % (riesgo).
    $fases = "SELECT plazos.*,
        CASE WHEN COALESCE(primera_respuesta, referencia_sla) > limite_respuesta THEN 'vencido'
             WHEN primera_respuesta IS NOT NULL THEN 'cumplido'
             WHEN ROUND(GREATEST(0, TIMESTAMPDIFF(SECOND, fecha_creacion, referencia_sla)) * 100 / GREATEST(1, TIMESTAMPDIFF(SECOND, fecha_creacion, limite_respuesta))) >= 75 THEN 'riesgo'
             ELSE 'ok' END AS sla_respuesta,
        CASE WHEN referencia_sla > limite_resolucion THEN 'vencido'
             WHEN estado IN ('resuelta','cerrada') THEN 'cumplido'
             WHEN ROUND(GREATEST(0, TIMESTAMPDIFF(SECOND, fecha_creacion, referencia_sla)) * 100 / GREATEST(1, TIMESTAMPDIFF(SECOND, fecha_creacion, limite_resolucion))) >= 75 THEN 'riesgo'
             ELSE 'ok' END AS sla_resolucion FROM ($plazos) plazos";
    $sla = "SELECT fases.*,
        CASE WHEN sla_respuesta = 'vencido' OR sla_resolucion = 'vencido' THEN 'vencido'
             WHEN sla_respuesta = 'riesgo' OR sla_resolucion = 'riesgo' THEN 'riesgo'
             WHEN estado IN ('resuelta','cerrada') THEN 'cumplido' ELSE 'ok' END AS sla_estado,
        CASE WHEN primera_respuesta IS NULL AND sla_respuesta IN ('riesgo','vencido')
             THEN TIMESTAMPDIFF(SECOND, referencia_sla, limite_respuesta)
             ELSE TIMESTAMPDIFF(SECOND, referencia_sla, limite_resolucion) END AS sla_restante
        FROM ($fases) fases";
    return "(SELECT sla.*, LEAST(100,
        CASE urgencia WHEN 'critico' THEN 55 WHEN 'urgente' THEN 30 ELSE 10 END
        + CASE sla_estado WHEN 'vencido' THEN 30 WHEN 'riesgo' THEN 15 ELSE 0 END
        + IF(asignado_id IS NULL, 10, 0)
        + IF(COALESCE(ultimo_autor, 'cliente') <> 'tecnico' AND estado IN ('abierta','en_curso'), 10, 0)
        ) AS prioridad_operativa FROM ($sla) sla) AS incidencias";
}

function bandeja_append_cola(string &$sql, string $cola): void {
    if ($cola === 'accion') {
        $sql .= " AND estado IN ('abierta','en_curso') AND COALESCE(ultimo_autor, 'cliente') = 'cliente'";
    } elseif ($cola === 'espera') {
        $sql .= " AND estado IN ('abierta','en_curso') AND ultimo_autor = 'tecnico'";
    } elseif ($cola === 'sla') {
        $sql .= " AND estado <> 'cerrada' AND sla_estado IN ('riesgo','vencido')";
    }
}

/** Lista cerrada de expresiones. Nunca interpola identificadores del usuario. */
function bandeja_orden_sql(string $columna, string $direccion, string $orden = 'id_desc'): string {
    $expresion = match ($columna) {
        'prioridad' => 'prioridad_operativa',
        'incidencia' => 'id',
        'cliente' => "COALESCE(cliente_nombre, '')",
        'estado' => "FIELD(estado, 'abierta','en_curso','resuelta','cerrada')",
        'turno' => "CASE WHEN estado = 'cerrada' THEN 2 WHEN estado = 'resuelta' OR ultimo_autor = 'tecnico' THEN 1 ELSE 0 END",
        'sla' => 'sla_restante',
        'responsable' => "COALESCE(asignado_nombre, '')",
        'actividad' => 'ultima_actividad',
        default => '',
    };
    if ($expresion === '') return dominio_order_by($orden) . ', id DESC';
    $sentido = $direccion === 'asc' ? 'ASC' : 'DESC';
    return "$expresion $sentido, id $sentido";
}
