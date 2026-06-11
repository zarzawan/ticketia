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

$stmt = $pdo->prepare("SELECT titulo, descripcion FROM incidencias WHERE id = :id");
$stmt->execute([':id' => $id_incidencia]);
$incidencia = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$incidencia) {
    header('Location: index.php');
    exit;
}

$clasificacion = clasificar_incidencia((string)$incidencia['titulo'], (string)$incidencia['descripcion']);
if ($clasificacion === null) {
    header("Location: ver_incidencia.php?id=$id_incidencia&reclasificada=0");
    exit;
}

clasificacion_aplicar($pdo, $id_incidencia, $clasificacion);
auditar($pdo, 'reclasificar_ia', "incidencia #$id_incidencia");
header("Location: ver_incidencia.php?id=$id_incidencia&reclasificada=1");
exit;
