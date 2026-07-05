<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$motivo = trim((string)($_POST['motivo'] ?? ''));

if (!$id_incidencia || $motivo === '') {
    header("Location: ver_incidencia.php?id=" . (int)$id_incidencia . "&error=1");
    exit;
}

// Insertar en tabla de reaperturas
$sql = "INSERT INTO reaperturas (id_incidencia, motivo) VALUES (:id_incidencia, :motivo)";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id_incidencia' => $id_incidencia,
    ':motivo' => $motivo
]);

incidencia_cambiar_estado($pdo, $id_incidencia, 'en_curso');
auditar($pdo, 'reabrir_incidencia', "incidencia #$id_incidencia");

header("Location: ver_incidencia.php?id=" . $id_incidencia);
exit;
