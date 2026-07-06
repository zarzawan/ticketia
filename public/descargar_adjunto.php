<?php
require_once __DIR__ . '/../src/arranque.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT a.*, i.cliente_id AS incidencia_cliente
     FROM adjuntos a
     JOIN incidencias i ON i.id = a.id_incidencia
     WHERE a.id = :id"
);
$stmt->execute([':id' => $id]);
$adjunto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$adjunto) {
    http_response_code(404);
    die('Adjunto no encontrado.');
}

// Un usuario con rol cliente solo puede descargar adjuntos de su ambito
// (los tickets de su empresa o, sin empresa, los creados por el).
if (auth_es('cliente') && !incidencia_visible_para_cliente($pdo, (int)$adjunto['id_incidencia'], auth_usuario())) {
    http_response_code(403);
    die('No tienes permisos para descargar este adjunto.');
}

$ruta = adjuntos_directorio() . '/' . $adjunto['nombre_disco'];
if (!is_file($ruta)) {
    http_response_code(404);
    die('El fichero ya no existe en el almacen.');
}

// Descarga forzada: nunca se sirve contenido interpretable por el navegador.
$nombre_seguro = str_replace(['"', "\r", "\n"], '', (string)$adjunto['nombre_original']);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: attachment; filename="' . $nombre_seguro . '"');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
exit;
