<?php
require_once __DIR__ . '/../src/arranque.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$id = (int)($_POST['id'] ?? 0);
$util = (string)($_POST['util'] ?? '');
if (!in_array($util,['0','1'],true) || !conocimiento_valoraciones_disponibles($pdo)) { http_response_code(400); exit; }
$stmt = $pdo->prepare('SELECT estado,visibilidad FROM conocimiento WHERE id=?'); $stmt->execute([$id]); $articulo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$articulo || !conocimiento_visible($articulo,auth_es('cliente'))) { http_response_code(404); exit; }
$pdo->prepare('INSERT INTO conocimiento_valoraciones (articulo_id,usuario_id,util) VALUES (?,?,?) ON DUPLICATE KEY UPDATE util=VALUES(util)')
    ->execute([$id,(int)auth_usuario()['id'],(int)$util]);
header("Location: ayuda.php?id=$id&valorado=1");
