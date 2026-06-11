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

$estados_validos = ['abierta', 'en_curso', 'cerrada'];

if (!$id_incidencia || !in_array($nuevo_estado, $estados_validos, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parametros invalidos']);
    exit;
}

$sql = "UPDATE incidencias SET estado = :estado, fecha_cierre = " .
    ($nuevo_estado === 'cerrada' ? 'NOW()' : 'NULL') .
    " WHERE id = :id";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':estado' => $nuevo_estado,
    ':id' => $id_incidencia
]);

echo json_encode(['ok' => true]);
exit;
?>
