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

$stmt = $pdo->prepare("SELECT estado FROM incidencias WHERE id = :id");
$stmt->execute([':id' => $id_incidencia]);
$estado = $stmt->fetchColumn();
if ($estado === false) {
    header('Location: index.php');
    exit;
}

// Un cliente solo puede adjuntar en tickets de su ambito.
$es_cliente = auth_es('cliente');
if ($es_cliente && !incidencia_visible_para_cliente($pdo, (int)$id_incidencia, auth_usuario())) {
    header('Location: portal.php');
    exit;
}
$pagina_detalle = $es_cliente ? 'portal_ver.php' : 'ver_incidencia.php';
if (!in_array((string)$estado, dominio_estados_activos(), true)) {
    header("Location: $pagina_detalle?id=$id_incidencia");
    exit;
}

$resultado = adjuntos_guardar($pdo, $id_incidencia, $_FILES['adjunto'] ?? []);

if ($resultado['ok']) {
    auditar($pdo, 'subir_adjunto', "incidencia #$id_incidencia: " . ($_FILES['adjunto']['name'] ?? ''));
    header("Location: $pagina_detalle?id=$id_incidencia&adjunto=1#adjuntos");
} else {
    header("Location: $pagina_detalle?id=$id_incidencia&adjunto_error=" . urlencode((string)$resultado['error']) . "#adjuntos");
}
exit;
