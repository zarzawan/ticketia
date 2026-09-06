<?php
// Feedback humano sobre el brief y adopcion del borrador del copiloto.
require_once __DIR__ . '/../src/arranque.php';

header('Content-Type: application/json; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || auth_es('cliente')) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$incidenciaId = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$contenidoHash = strtolower(trim((string)($_POST['contenido_hash'] ?? '')));
$accion = (string)($_POST['accion'] ?? '');
$usuario = auth_usuario();
if (!$incidenciaId || !$usuario || !gobierno_ia_hash_valido($contenidoHash)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos no validos']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT contenido_hash FROM copiloto_ia WHERE id_incidencia = :id AND contenido_hash = :hash"
);
$stmt->execute([':id' => $incidenciaId, ':hash' => $contenidoHash]);
if ($stmt->fetchColumn() === false) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'El brief ya no es la version actual']);
    exit;
}

$valoracion = null;
$usado = false;
if ($accion === 'valorar') {
    $valoracion = filter_input(INPUT_POST, 'valoracion', FILTER_VALIDATE_INT);
    if (!in_array($valoracion, [-1, 1], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valoracion no valida']);
        exit;
    }
} elseif ($accion === 'usar_borrador') {
    $usado = true;
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Accion no valida']);
    exit;
}

$ok = gobierno_ia_feedback_guardar(
    $pdo,
    (int)$usuario['id'],
    (int)$incidenciaId,
    $contenidoHash,
    $valoracion,
    $usado
);
if (!$ok) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el feedback']);
    exit;
}

if ($valoracion !== null) {
    auditar($pdo, 'valorar_copiloto', "incidencia #$incidenciaId: $valoracion");
}
echo json_encode(['ok' => true]);
exit;
