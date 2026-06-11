<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$titulo = trim((string)($_POST['titulo'] ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));

if ($titulo === '' || $descripcion === '') {
    header('Location: index.php?error=1');
    exit;
}

// --- Fase 1: insertar la incidencia inmediatamente con valores seguros ---
// El usuario no espera al LLM. Si la clasificacion falla o tarda, el ticket
// ya existe con estos valores y puede corregirse despues (reclasificar/reprocesar).
$sql = "INSERT INTO incidencias (titulo, descripcion, estado, fecha_creacion, urgencia, recomendacion, tipo, resumen, idioma)
        VALUES (:titulo, :descripcion, 'abierta', NOW(), 'leve', :recomendacion, NULL, :resumen, 'es')";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':titulo' => $titulo,
    ':descripcion' => $descripcion,
    ':recomendacion' => normalizar_recomendacion_markdown(CLASIFICACION_RECOMENDACION_DEFECTO),
    ':resumen' => mb_substr($descripcion, 0, 75)
]);

$id_incidencia = (int)$pdo->lastInsertId();

// --- Fase 2: responder ya al usuario y clasificar en segundo plano ---
ignore_user_abort(true);
set_time_limit(180);
header('Location: index.php?ok=1');
header('Content-Length: 0');
header('Connection: close');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$clasificacion = clasificar_incidencia($titulo, $descripcion);
if ($clasificacion !== null) {
    clasificacion_aplicar($pdo, $id_incidencia, $clasificacion);
} else {
    error_log("Clasificacion IA fallida para incidencia #$id_incidencia; quedan los valores por defecto.");
}
exit;
