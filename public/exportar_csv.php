<?php
require_once __DIR__ . '/../src/arranque.php';

$tipos = dominio_tipos();
$urgencias = dominio_urgencias();
$estados = dominio_estados();
$ordenes = dominio_ordenes();

$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_tipo = isset($_GET['filtro_tipo']) && in_array($_GET['filtro_tipo'], $tipos, true) ? $_GET['filtro_tipo'] : '';
$filtro_urgencia = isset($_GET['filtro_urgencia']) && in_array($_GET['filtro_urgencia'], $urgencias, true) ? $_GET['filtro_urgencia'] : '';
$filtro_estado = isset($_GET['filtro_estado']) && in_array($_GET['filtro_estado'], $estados, true) ? $_GET['filtro_estado'] : '';
$filtro_desde = isset($_GET['filtro_desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['filtro_desde']) ? $_GET['filtro_desde'] : '';
$filtro_hasta = isset($_GET['filtro_hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['filtro_hasta']) ? $_GET['filtro_hasta'] : '';
$orden = isset($_GET['orden']) && in_array($_GET['orden'], $ordenes, true) ? $_GET['orden'] : 'id_desc';
$asignados_validos = array_merge(['sin_asignar'], dominio_tecnicos());
$filtro_asignado = isset($_GET['filtro_asignado']) && in_array($_GET['filtro_asignado'], $asignados_validos, true) ? $_GET['filtro_asignado'] : '';

$sql = "SELECT id, titulo, descripcion, resumen, tipo, urgencia, estado, idioma, asignado_a, fecha_creacion, fecha_cierre FROM incidencias WHERE 1=1";
$params = [];
dominio_append_filtros($sql, $params, [
    'busqueda' => $busqueda,
    'tipo' => $filtro_tipo,
    'urgencia' => $filtro_urgencia,
    'estado' => $filtro_estado,
    'desde' => $filtro_desde,
    'hasta' => $filtro_hasta,
    'asignado' => $filtro_asignado
]);
$sql .= ' ORDER BY ' . dominio_order_by($orden);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'incidencias_filtradas_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

fputcsv($output, [
    'ID',
    'Titulo',
    'Descripcion',
    'Resumen',
    'Tipo',
    'Urgencia',
    'Estado',
    'Idioma',
    'Asignado',
    'Fecha creacion',
    'Fecha cierre'
], ';');

foreach ($rows as $row) {
    fputcsv($output, [
        $row['id'] ?? '',
        $row['titulo'] ?? '',
        $row['descripcion'] ?? '',
        $row['resumen'] ?? '',
        $row['tipo'] ?? '',
        $row['urgencia'] ?? '',
        $row['estado'] ?? '',
        $row['idioma'] ?? '',
        $row['asignado_a'] ?? '',
        $row['fecha_creacion'] ?? '',
        $row['fecha_cierre'] ?? ''
    ], ';');
}

fclose($output);
exit;
