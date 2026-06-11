<?php
// Cambia el proveedor LLM activo desde la UI. Se persiste en la tabla ajustes
// y config.php lo aplica en cada peticion (sin editar codigo).
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$proveedor = trim((string)($_POST['proveedor'] ?? ''));

if (!isset($llm_config[$proveedor]) || !is_array($llm_config[$proveedor])) {
    header('Location: index.php?error=1');
    exit;
}

$stmt = $pdo->prepare(
    "INSERT INTO ajustes (clave, valor) VALUES ('llm_provider', :valor)
     ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
);
$stmt->execute([':valor' => $proveedor]);
auditar($pdo, 'cambiar_proveedor_ia', $proveedor);

header('Location: index.php?proveedor_ok=1');
exit;
