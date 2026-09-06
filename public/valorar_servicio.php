<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('cliente');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$id = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$puntuacion = filter_input(INPUT_POST, 'puntuacion', FILTER_VALIDATE_INT);
$comentario = trim((string)($_POST['comentario'] ?? ''));
if (!$id || !incidencia_visible_para_cliente($pdo, $id, auth_usuario())) { http_response_code(404); exit; }
$stmt = $pdo->prepare("SELECT id FROM incidencias WHERE id=? AND creado_por=? AND estado IN ('resuelta','cerrada')");
$stmt->execute([$id, auth_usuario()['id']]);
if (!$stmt->fetchColumn()) { http_response_code(403); exit; }
if (!$puntuacion || $puntuacion < 1 || $puntuacion > 5 || mb_strlen($comentario) > 1000) {
    header("Location: portal_ver.php?id=$id&valoracion=error#valoracion"); exit;
}
try {
    $pdo->prepare('INSERT INTO satisfaccion_servicio (incidencia_id,usuario_id,puntuacion,comentario) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE puntuacion=VALUES(puntuacion), comentario=VALUES(comentario), actualizado_en=NOW()')->execute([$id, auth_usuario()['id'], $puntuacion, $comentario]);
    auditar($pdo, 'valorar_servicio', "incidencia #$id: $puntuacion/5");
} catch (PDOException $e) {
    header("Location: portal_ver.php?id=$id&valoracion=error#valoracion"); exit;
}
header("Location: portal_ver.php?id=$id&valoracion=ok#valoracion");
