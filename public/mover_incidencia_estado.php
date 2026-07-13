<?php
require_once __DIR__ . '/../src/arranque.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$nuevo_estado = isset($_POST['estado']) ? trim((string)$_POST['estado']) : '';

$estados_validos = dominio_estados_activos();

if (!$id_incidencia || !in_array($nuevo_estado, $estados_validos, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parametros invalidos']);
    exit;
}

if (!incidencia_cambiar_estado($pdo, $id_incidencia, $nuevo_estado)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Incidencia no encontrada']);
    exit;
}
auditar($pdo, 'cambiar_estado', "incidencia #$id_incidencia -> $nuevo_estado");

echo json_encode(['ok' => true]);
exit;
?>
