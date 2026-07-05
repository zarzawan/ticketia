<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
if (!$id_incidencia) {
    header('Location: index.php?error=1');
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM incidencias WHERE id = :id");
$stmt->execute([':id' => $id_incidencia]);
if (!$stmt->fetchColumn()) {
    header('Location: index.php');
    exit;
}

$resultado = adjuntos_guardar($pdo, $id_incidencia, $_FILES['adjunto'] ?? []);

if ($resultado['ok']) {
    auditar($pdo, 'subir_adjunto', "incidencia #$id_incidencia: " . ($_FILES['adjunto']['name'] ?? ''));
    header("Location: ver_incidencia.php?id=$id_incidencia&adjunto=1#adjuntos");
} else {
    header("Location: ver_incidencia.php?id=$id_incidencia&adjunto_error=" . urlencode((string)$resultado['error']) . "#adjuntos");
}
exit;
