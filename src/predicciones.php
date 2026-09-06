<?php
// Indicadores explicables de servicio. No son probabilidades ni un modelo entrenado.

function predicciones_evaluar(array $m): array {
    $n = (int)($m['recientes'] ?? 0);
    $valoradas = (int)($m['valoradas'] ?? 0);
    $puntos = 0; $motivos = [];
    if ((int)($m['vencidas'] ?? 0) > 0) {
        $puntos += 30; $motivos[] = $m['vencidas'] . ' incidencias activas con SLA vencido.';
    }
    if ($valoradas >= 3 && (float)$m['satisfaccion'] < 3) {
        $puntos += 35; $motivos[] = 'Satisfaccion media inferior a 3/5 en al menos 3 incidencias.';
    }
    if ($n >= 5 && (int)($m['reabiertas'] ?? 0) / $n >= .2) {
        $puntos += 20; $motivos[] = 'Al menos el 20 % de las incidencias del periodo se han reabierto.';
    }
    if ((int)($m['quejas'] ?? 0) >= 2) {
        $puntos += 15; $motivos[] = 'Dos o mas incidencias clasificadas como quejas.';
    }
    $suficiente = $n >= 5;
    $riesgo = !$suficiente ? 'Datos insuficientes' : ($puntos >= 50 ? 'Alto' : ($puntos >= 20 ? 'Medio' : 'Bajo'));
    $oportunidad = 'Sin evidencia suficiente';
    if ($puntos >= 20) $oportunidad = 'Priorizar la recuperacion del servicio';
    elseif ($suficiente && $valoradas >= 3 && (float)$m['satisfaccion'] >= 4 && (int)($m['comerciales'] ?? 0) >= 2) {
        $oportunidad = 'Revisar necesidades de ampliacion';
    }
    return ['riesgo'=>$riesgo, 'puntos'=>$suficiente ? $puntos : null, 'motivos'=>$motivos,
        'oportunidad'=>$oportunidad, 'cobertura'=>$n > 0 ? round(100 * $valoradas / $n) : 0,
        'accion'=> $puntos >= 20 ? 'Contactar para revisar incidencias pendientes y acordar un plan de recuperacion.'
            : ($oportunidad === 'Revisar necesidades de ampliacion' ? 'Validar con el cliente sus necesidades antes de ofrecer una ampliacion.'
            : 'Recoger valoraciones y revisar necesidades en el seguimiento habitual.')];
}

/** Una fila por empresa; las valoraciones se promedian primero por incidencia. */
function predicciones_metricas(PDO $pdo, array $ids, int $dias, DateTimeImmutable $ahora): array {
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $id): bool => $id > 0));
    if (!$ids) return [];
    $dias = in_array($dias, [30,90,180], true) ? $dias : 90;
    $lista = implode(',', $ids);
    $desde = $pdo->quote($ahora->modify("-$dias days")->format('Y-m-d H:i:s'));
    $anterior = $pdo->quote($ahora->modify('-' . ($dias * 2) . ' days')->format('Y-m-d H:i:s'));
    $hasta = $pdo->quote($ahora->format('Y-m-d H:i:s'));
    $fuente = bandeja_fuente_sql($pdo, $ahora);
    $sql = "SELECT cliente_id, COUNT(*) AS historicas,
        SUM(fecha_creacion >= $desde) AS recientes,
        SUM(fecha_creacion >= $anterior AND fecha_creacion < $desde) AS anteriores,
        SUM(estado IN ('abierta','en_curso')) AS activas,
        SUM(estado = 'abierta') AS abiertas,
        SUM(estado = 'en_curso') AS en_curso,
        SUM(estado = 'resuelta') AS resueltas,
        SUM(estado = 'cerrada') AS cerradas,
        SUM(fecha_creacion >= $desde AND tipo = 'Quejas y reclamaciones') AS quejas,
        SUM(fecha_creacion >= $desde AND tipo IN ('Comercial','Cambios y mejoras')) AS comerciales,
        SUM(fecha_creacion >= $desde AND EXISTS (SELECT 1 FROM cambios_estado ce
            WHERE ce.id_incidencia = incidencias.id AND ce.estado_anterior IN ('resuelta','cerrada')
            AND ce.estado_nuevo IN ('abierta','en_curso') AND ce.fecha >= $desde AND ce.fecha <= $hasta)) AS reabiertas,
        SUM(fecha_creacion >= $desde AND valoraciones.media IS NOT NULL) AS valoradas,
        AVG(CASE WHEN fecha_creacion >= $desde THEN valoraciones.media END) AS satisfaccion,
        AVG(CASE WHEN fecha_creacion >= $desde AND primera_respuesta >= fecha_creacion
            THEN TIMESTAMPDIFF(MINUTE,fecha_creacion,primera_respuesta) / 60 END) AS respuesta_horas,
        COUNT(CASE WHEN fecha_creacion >= $desde AND primera_respuesta >= fecha_creacion THEN 1 END) AS respondidas
        FROM $fuente LEFT JOIN (
            SELECT s.incidencia_id, AVG(s.puntuacion) AS media FROM satisfaccion_servicio s
            JOIN incidencias vi ON vi.id = s.incidencia_id WHERE vi.cliente_id IN ($lista)
            GROUP BY s.incidencia_id
        ) valoraciones ON valoraciones.incidencia_id = incidencias.id
        WHERE cliente_id IN ($lista) AND fecha_creacion <= $hasta GROUP BY cliente_id";
    $resultado = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $fila) $resultado[(int)$fila['cliente_id']] = $fila + ['vencidas'=>0];
    $vencidas = $pdo->query("SELECT cliente_id,COUNT(*) AS total FROM $fuente
        WHERE cliente_id IN ($lista) AND fecha_creacion <= $hasta AND estado IN ('abierta','en_curso')
        AND sla_estado = 'vencido' GROUP BY cliente_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vencidas as $fila) $resultado[(int)$fila['cliente_id']]['vencidas'] = (int)$fila['total'];
    return $resultado;
}

function predicciones_serie(PDO $pdo, int $cliente, int $dias, DateTimeImmutable $ahora): array {
    $dias = in_array($dias, [30,90,180], true) ? $dias : 90;
    $desde = $ahora->modify("-$dias days");
    $serie = [];
    for ($mes = $desde->modify('first day of this month'); $mes->format('Y-m') <= $ahora->format('Y-m'); $mes = $mes->modify('+1 month')) {
        $serie[$mes->format('Y-m')] = 0;
    }
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(fecha_creacion,'%Y-%m') AS mes, COUNT(*) AS total
        FROM incidencias WHERE cliente_id = :cliente AND fecha_creacion >= :desde AND fecha_creacion <= :hasta
        GROUP BY DATE_FORMAT(fecha_creacion,'%Y-%m') ORDER BY mes");
    $stmt->execute([':cliente'=>$cliente, ':desde'=>$desde->format('Y-m-d H:i:s'), ':hasta'=>$ahora->format('Y-m-d H:i:s')]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) $serie[$fila['mes']] = (int)$fila['total'];
    return $serie;
}
