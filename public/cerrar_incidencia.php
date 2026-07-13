<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$codigo = trim((string)($_POST['resolucion_codigo'] ?? ''));
$notas = trim((string)($_POST['resolucion_notas'] ?? ''));
if (!$id_incidencia || $codigo === '' || $notas === '') {
    header('Location: ver_incidencia.php?id=' . (int)$id_incidencia . '&resolucion=error');
    exit;
}

if (!incidencia_resolver($pdo, $id_incidencia, $codigo, $notas)) {
    header('Location: ver_incidencia.php?id=' . (int)$id_incidencia . '&resolucion=error');
    exit;
}
auditar($pdo, 'resolver_incidencia', "incidencia #$id_incidencia ($codigo)");

header("Location: ver_incidencia.php?id=" . $id_incidencia . '&resolucion=ok');
exit;
