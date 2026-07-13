<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$mensaje = trim((string)($_POST['mensaje'] ?? ''));
$interno = isset($_POST['interno']) && $_POST['interno'] === '1' && !auth_es('cliente');

$es_cliente = auth_es('cliente');
$pagina_detalle = $es_cliente ? 'portal_ver.php' : 'ver_incidencia.php';

if (!$id_incidencia || $mensaje === '') {
    header("Location: $pagina_detalle?id=" . (int)$id_incidencia . "&error=1");
    exit;
}

// Un cliente solo puede escribir en tickets de su ambito y mientras estan activos.
if ($es_cliente) {
    if (!incidencia_visible_para_cliente($pdo, (int)$id_incidencia, auth_usuario())) {
        header('Location: portal.php');
        exit;
    }
    $stmt = $pdo->prepare("SELECT estado FROM incidencias WHERE id = :id");
    $stmt->execute([':id' => $id_incidencia]);
    if (!in_array((string)$stmt->fetchColumn(), dominio_estados_activos(), true)) {
        header("Location: portal_ver.php?id=" . (int)$id_incidencia);
        exit;
    }
}

// El idioma original se lee de la incidencia, no del formulario.
$stmt = $pdo->prepare("SELECT idioma, estado FROM incidencias WHERE id = :id");
$stmt->execute([':id' => $id_incidencia]);
$incidencia = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$incidencia) {
    header('Location: index.php');
    exit;
}
if (!in_array((string)$incidencia['estado'], dominio_estados_activos(), true)) {
    header("Location: $pagina_detalle?id=" . (int)$id_incidencia);
    exit;
}

$usuario = auth_usuario();
$autor = auth_es('cliente') ? 'cliente' : 'tecnico';
$idioma_original = (string)($incidencia['idioma'] ?? 'es');

// Las notas internas nunca se traducen: son para el equipo, no para el cliente.
$mensaje_final = $mensaje;
if (!$interno && $autor === 'tecnico' && $idioma_original !== 'es') {
    $contexto_traduccion = "Texto: \"$mensaje\"";
    $pregunta_traduccion = "Traduce el texto del idioma 'es' al idioma '$idioma_original'. Devuelve solo el texto traducido.";
    $traduccion_response = LLMClientTraductor::getResponse($contexto_traduccion, $pregunta_traduccion);

    $mensaje_final = trim($traduccion_response ?? '');
    if ($mensaje_final === '') {
        $mensaje_final = $mensaje; // Fallback al mensaje original si la traduccion falla
        error_log("Traduccion no valida, usando mensaje original. Respuesta recibida: " . ($traduccion_response ?? 'null'));
    }
}

$sql = "INSERT INTO mensajes (id_incidencia, usuario_id, autor, mensaje, interno, fecha)
        VALUES (:id_incidencia, :usuario_id, :autor, :mensaje, :interno, NOW())";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id_incidencia' => $id_incidencia,
    ':usuario_id' => $usuario['id'] ?? null,
    ':autor' => $autor,
    ':mensaje' => $mensaje_final,
    ':interno' => $interno ? 1 : 0
]);
auditar($pdo, $interno ? 'nota_interna' : 'nuevo_mensaje', "incidencia #$id_incidencia");

// La primera respuesta publica del equipo mueve automaticamente el ticket a
// trabajo activo. Las notas internas no alteran el flujo.
if (!$interno && $autor === 'tecnico' && ($incidencia['estado'] ?? '') === 'abierta') {
    $pdo->prepare("UPDATE incidencias SET estado = 'en_curso', fecha_cierre = NULL WHERE id = :id")
        ->execute([':id' => $id_incidencia]);
    $pdo->prepare("INSERT INTO cambios_estado (id_incidencia, usuario_id, estado_anterior, estado_nuevo) VALUES (:id, :usuario, 'abierta', 'en_curso')")
        ->execute([':id' => $id_incidencia, ':usuario' => $usuario['id'] ?? null]);
    auditar($pdo, 'inicio_automatico', "incidencia #$id_incidencia por respuesta del equipo");
}

// Notificar por email (nunca las notas internas).
if (!$interno) {
    correo_notificar_mensaje($pdo, (int)$id_incidencia, $autor === 'cliente');
}

header("Location: $pagina_detalle?id=$id_incidencia&ok=1");
exit;
