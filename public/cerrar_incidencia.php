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

// Actualizar estado y fecha de cierre
$sql = "UPDATE incidencias SET estado = 'cerrada', fecha_cierre = NOW() WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id_incidencia]);

// Redirigir de nuevo
header("Location: ver_incidencia.php?id=" . $id_incidencia);
exit;
