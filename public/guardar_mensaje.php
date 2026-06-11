<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
$autor = trim((string)($_POST['autor'] ?? ''));
$mensaje = trim((string)($_POST['mensaje'] ?? ''));
$idioma_original = trim((string)($_POST['idioma_original'] ?? ''));

if (!$id_incidencia || $autor === '' || $mensaje === '' || $idioma_original === '') {
    header("Location: ver_incidencia.php?id=" . (int)$id_incidencia . "&error=1");
    exit;
}

// Traducir el mensaje al idioma original si es necesario
$mensaje_final = $mensaje;
if ($idioma_original !== 'es') {
    $contexto_traduccion = "Texto: \"$mensaje\"";
    $pregunta_traduccion = "Traduce el texto del idioma 'es' al idioma '$idioma_original'. Devuelve solo el texto traducido.";
    $traduccion_response = LLMClientTraductor::getResponse($contexto_traduccion, $pregunta_traduccion);

    $mensaje_final = trim($traduccion_response ?? '');
    if ($mensaje_final === '') {
        $mensaje_final = $mensaje; // Fallback al mensaje original si la traduccion falla
        error_log("Traduccion no valida, usando mensaje original. Respuesta recibida: " . ($traduccion_response ?? 'null'));
    }
}

// Insertar el mensaje en la base de datos
$sql = "INSERT INTO mensajes (id_incidencia, autor, mensaje, fecha) VALUES (:id_incidencia, :autor, :mensaje, NOW())";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id_incidencia' => $id_incidencia,
    ':autor' => $autor,
    ':mensaje' => $mensaje_final
]);
auditar($pdo, 'nuevo_mensaje', "incidencia #$id_incidencia ($autor)");

header("Location: ver_incidencia.php?id=$id_incidencia&ok=1");
exit;
