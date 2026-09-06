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
$asignables_ids = array_map(fn($u) => (string)$u['id'], usuarios_asignables($pdo));
$filtro_asignado = '';
if (isset($_GET['filtro_asignado'])) {
    $candidato_asignado = (string)$_GET['filtro_asignado'];
    if ($candidato_asignado === 'sin_asignar' || in_array($candidato_asignado, $asignables_ids, true)) {
        $filtro_asignado = $candidato_asignado;
    }
}

$fuente = bandeja_fuente_sql($pdo);
$sql = "SELECT id, titulo, descripcion, resumen, tipo, urgencia, estado, idioma, asignado_nombre, fecha_creacion, fecha_cierre FROM $fuente WHERE 1=1";
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
if ($filtro_estado === '') {
    $sql .= " AND estado IN ('abierta','en_curso')";
}
bandeja_append_cola($sql, (string)($_GET['cola'] ?? ''));
$sql .= ' ORDER BY ' . bandeja_orden_sql((string)($_GET['orden_columna'] ?? ''), (string)($_GET['direccion'] ?? 'desc'), $orden);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

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

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'] ?? '',
        $row['titulo'] ?? '',
        $row['descripcion'] ?? '',
        $row['resumen'] ?? '',
        $row['tipo'] ?? '',
        $row['urgencia'] ?? '',
        $row['estado'] ?? '',
        $row['idioma'] ?? '',
        $row['asignado_nombre'] ?? '',
        $row['fecha_creacion'] ?? '',
        $row['fecha_cierre'] ?? ''
    ], ';');
}

fclose($output);
exit;
