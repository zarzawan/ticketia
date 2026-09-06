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

if (!incidencia_cambiar_estado($pdo, $id_incidencia, 'en_curso', ['resuelta', 'cerrada'], $motivo)) {
    header("Location: ver_incidencia.php?id=$id_incidencia&conflicto=1"); exit;
}
auditar($pdo, 'reabrir_incidencia', "incidencia #$id_incidencia");

header("Location: ver_incidencia.php?id=" . $id_incidencia);
exit;
