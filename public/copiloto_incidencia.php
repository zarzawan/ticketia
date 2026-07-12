<?php
// Genera un brief estructurado y cacheado para ayudar al tecnico a decidir.
require_once __DIR__ . '/../src/arranque.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$id = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Incidencia no valida']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, titulo, descripcion, estado, urgencia, tipo, resumen, recomendacion FROM incidencias WHERE id = :id');
$stmt->execute([':id' => $id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Incidencia no encontrada']);
    exit;
}

$stmt = $pdo->prepare("SELECT autor, mensaje, fecha FROM mensajes WHERE id_incidencia = :id AND interno = 0 ORDER BY fecha ASC, id ASC");
$stmt->execute([':id' => $id]);
$mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hash = sha1(json_encode([$ticket, $mensajes], JSON_UNESCAPED_UNICODE));

$forzar = isset($_POST['forzar']) && $_POST['forzar'] === '1';
if (!$forzar) {
    $stmt = $pdo->prepare('SELECT resumen, riesgo, sentimiento, siguiente_accion, respuesta_sugerida, confianza, actualizado_en FROM copiloto_ia WHERE id_incidencia = :id AND contenido_hash = :hash');
    $stmt->execute([':id' => $id, ':hash' => $hash]);
    $cache = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cache) {
        echo json_encode(['ok' => true, 'cache' => true, 'insight' => $cache], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$contexto = "Incidencia #$id\nTitulo: {$ticket['titulo']}\nDescripcion: {$ticket['descripcion']}\nEstado: {$ticket['estado']}\nUrgencia: {$ticket['urgencia']}\nDepartamento: " . ($ticket['tipo'] ?: 'sin clasificar') . "\nConversacion publica:\n";
foreach (array_slice($mensajes, -15) as $mensaje) {
    $contexto .= "[{$mensaje['fecha']}] {$mensaje['autor']}: {$mensaje['mensaje']}\n";
}

$pregunta = <<<'PROMPT'
Actua como coordinador senior de soporte. Devuelve exclusivamente JSON valido, sin markdown, con estas claves:
{"resumen":"maximo 60 palabras","riesgo":"bajo|medio|alto|critico","sentimiento":"positivo|neutral|frustrado|urgente","siguiente_accion":"una accion concreta y verificable","respuesta_sugerida":"borrador profesional maximo 120 palabras","confianza":0}
La confianza debe ser un entero de 0 a 100. No inventes hechos. Si faltan datos, indicalo en la siguiente accion y en el borrador.
PROMPT;

$respuesta = trim((string)LLMClient::getResponse($contexto, $pregunta));
$respuesta = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $respuesta);
$datos = json_decode((string)$respuesta, true);
if (!is_array($datos)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'La IA no devolvio un brief valido']);
    exit;
}

$riesgos = ['bajo', 'medio', 'alto', 'critico'];
$sentimientos = ['positivo', 'neutral', 'frustrado', 'urgente'];
$insight = [
    'resumen' => trim(mb_substr((string)($datos['resumen'] ?? ''), 0, 1200)),
    'riesgo' => in_array($datos['riesgo'] ?? '', $riesgos, true) ? $datos['riesgo'] : 'medio',
    'sentimiento' => in_array($datos['sentimiento'] ?? '', $sentimientos, true) ? $datos['sentimiento'] : 'neutral',
    'siguiente_accion' => trim(mb_substr((string)($datos['siguiente_accion'] ?? ''), 0, 1500)),
    'respuesta_sugerida' => trim(mb_substr((string)($datos['respuesta_sugerida'] ?? ''), 0, 3000)),
    'confianza' => min(100, max(0, (int)($datos['confianza'] ?? 50))),
];
if ($insight['resumen'] === '' || $insight['siguiente_accion'] === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'El brief de IA esta incompleto']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO copiloto_ia (id_incidencia, contenido_hash, resumen, riesgo, sentimiento, siguiente_accion, respuesta_sugerida, confianza)
     VALUES (:id, :hash, :resumen, :riesgo, :sentimiento, :accion, :respuesta, :confianza)
     ON DUPLICATE KEY UPDATE contenido_hash = VALUES(contenido_hash), resumen = VALUES(resumen), riesgo = VALUES(riesgo), sentimiento = VALUES(sentimiento), siguiente_accion = VALUES(siguiente_accion), respuesta_sugerida = VALUES(respuesta_sugerida), confianza = VALUES(confianza), actualizado_en = NOW()'
);
$stmt->execute([
    ':id' => $id, ':hash' => $hash, ':resumen' => $insight['resumen'], ':riesgo' => $insight['riesgo'],
    ':sentimiento' => $insight['sentimiento'], ':accion' => $insight['siguiente_accion'],
    ':respuesta' => $insight['respuesta_sugerida'], ':confianza' => $insight['confianza'],
]);
auditar($pdo, 'copiloto_ia', "incidencia #$id");
$insight['actualizado_en'] = date('Y-m-d H:i:s');
echo json_encode(['ok' => true, 'cache' => false, 'insight' => $insight], JSON_UNESCAPED_UNICODE);
exit;
