<?php
require_once __DIR__ . '/../src/arranque.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
$consulta = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 180));
echo json_encode(['articulos' => mb_strlen($consulta) >= 4 ? conocimiento_buscar($pdo, $consulta, auth_es('cliente'), 3) : []], JSON_UNESCAPED_UNICODE);
