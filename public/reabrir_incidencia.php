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

// Cambiar estado a 'en_curso' y quitar fecha de cierre
$sql2 = "UPDATE incidencias SET estado = 'en_curso', fecha_cierre = NULL WHERE id = :id";
$stmt2 = $pdo->prepare($sql2);
$stmt2->execute([':id' => $id_incidencia]);

header("Location: ver_incidencia.php?id=" . $id_incidencia);
exit;
