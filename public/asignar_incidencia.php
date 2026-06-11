<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$asignado = trim((string)($_POST['asignado'] ?? ''));

// '' = quitar asignacion. Cualquier otro valor debe estar en la lista de tecnicos.
if (!$id_incidencia || ($asignado !== '' && !in_array($asignado, dominio_tecnicos(), true))) {
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Parametros invalidos']);
        exit;
    }

    header('Location: index.php?error=1');
    exit;
}

$sql = "UPDATE incidencias SET asignado_a = :asignado WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':asignado' => $asignado === '' ? null : $asignado,
    ':id' => $id_incidencia
]);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

header("Location: ver_incidencia.php?id=$id_incidencia&ok=1");
exit;
