<?php
// El cliente confirma o rechaza la solucion propuesta por el equipo.
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !auth_es('cliente')) {
    header('Location: portal.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$decision = (string)($_POST['decision'] ?? '');
$motivo = trim((string)($_POST['motivo'] ?? ''));

if (!$id || !in_array($decision, ['aceptar', 'rechazar'], true)
    || !incidencia_visible_para_cliente($pdo, (int)$id, auth_usuario())) {
    header('Location: portal.php');
    exit;
}

$stmt = $pdo->prepare("SELECT estado FROM incidencias WHERE id = :id");
$stmt->execute([':id' => $id]);
if ($stmt->fetchColumn() !== 'resuelta') {
    header("Location: portal_ver.php?id=$id");
    exit;
}

if ($decision === 'aceptar') {
    if (!incidencia_cambiar_estado($pdo, (int)$id, 'cerrada', ['resuelta'])) {
        header("Location: portal_ver.php?id=$id&conflicto=1"); exit;
    }
    auditar($pdo, 'aceptar_resolucion', "incidencia #$id");
    header("Location: portal_ver.php?id=$id&confirmacion=aceptada");
    exit;
}

if ($motivo === '') {
    header("Location: portal_ver.php?id=$id&confirmacion=motivo");
    exit;
}

if (!incidencia_cambiar_estado($pdo, (int)$id, 'en_curso', ['resuelta'], $motivo)) {
    header("Location: portal_ver.php?id=$id&conflicto=1"); exit;
}
auditar($pdo, 'rechazar_resolucion', "incidencia #$id");
header("Location: portal_ver.php?id=$id&confirmacion=rechazada");
exit;
