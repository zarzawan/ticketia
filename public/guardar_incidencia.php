<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Los clientes crean y vuelven a su portal; el equipo, al panel.
$pagina_retorno = auth_es('cliente') ? 'portal.php' : 'index.php';

$titulo = trim((string)($_POST['titulo'] ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));

if ($titulo === '' || $descripcion === '' || mb_strlen($titulo) > 255 || mb_strlen($descripcion) > 30000) {
    header("Location: $pagina_retorno?error=1");
    exit;
}

// --- Fase 1: insertar la incidencia inmediatamente con valores seguros ---
// El usuario no espera al LLM. Si la clasificacion falla o tarda, el ticket
// ya existe con estos valores y puede corregirse despues (reclasificar/reprocesar).
$usuario_actual = auth_usuario();
$sql = "INSERT INTO incidencias (cliente_id, creado_por, titulo, descripcion, estado, fecha_creacion, urgencia, recomendacion, tipo, resumen, idioma)
        VALUES (:cliente_id, :creado_por, :titulo, :descripcion, 'abierta', NOW(), 'leve', :recomendacion, NULL, :resumen, 'es')";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':cliente_id' => $usuario_actual['cliente_id'] ?? null,
    ':creado_por' => $usuario_actual['id'] ?? null,
    ':titulo' => $titulo,
    ':descripcion' => $descripcion,
    ':recomendacion' => normalizar_recomendacion_markdown(CLASIFICACION_RECOMENDACION_DEFECTO),
    ':resumen' => mb_substr($descripcion, 0, 75)
]);

$id_incidencia = (int)$pdo->lastInsertId();
gobierno_ia_contexto_establecer($id_incidencia);
auditar($pdo, 'crear_incidencia', "incidencia #$id_incidencia: $titulo");

// --- Fase 2: responder ya al usuario y clasificar en segundo plano ---
ignore_user_abort(true);
set_time_limit(180);
header("Location: $pagina_retorno?ok=1");
header('Content-Length: 0');
header('Connection: close');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Aviso al equipo cuando el ticket lo abre un cliente (ya en segundo plano).
if (auth_es('cliente')) {
    correo_notificar_nuevo_ticket($pdo, $id_incidencia);
}

$clasificacion = clasificar_incidencia($titulo, $descripcion);
if ($clasificacion !== null) {
    clasificacion_aplicar($pdo, $id_incidencia, $clasificacion);
} else {
    // Proveedor caido o respuesta invalida: a la cola para que el worker
    // lo reintente con backoff (bin/worker.php).
    trabajos_encolar($pdo, 'clasificar', ['id_incidencia' => $id_incidencia]);
    error_log("Clasificacion IA fallida para incidencia #$id_incidencia; encolada para reintento.");
}
exit;
