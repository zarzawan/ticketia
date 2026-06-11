<?php
// Genera con IA un borrador de respuesta para el siguiente mensaje del hilo.
// Devuelve JSON {ok, sugerencia} y no persiste nada: el tecnico edita y envia.
require_once __DIR__ . '/../src/arranque.php';

header('Content-Type: application/json');

$id_incidencia = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_incidencia) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parametros invalidos']);
    exit;
}

$stmt = $pdo->prepare("SELECT titulo, descripcion, estado, urgencia, tipo, recomendacion FROM incidencias WHERE id = :id");
$stmt->execute([':id' => $id_incidencia]);
$incidencia = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$incidencia) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Incidencia no encontrada']);
    exit;
}

$stmt_m = $pdo->prepare("SELECT autor, mensaje, fecha FROM mensajes WHERE id_incidencia = :id ORDER BY fecha DESC LIMIT 10");
$stmt_m->execute([':id' => $id_incidencia]);
$mensajes = array_reverse($stmt_m->fetchAll(PDO::FETCH_ASSOC));

$contexto = "Incidencia #$id_incidencia\n";
$contexto .= "Título: {$incidencia['titulo']}\n";
$contexto .= "Descripción: {$incidencia['descripcion']}\n";
$contexto .= "Estado: {$incidencia['estado']} | Urgencia: {$incidencia['urgencia']} | Tipo: " . ($incidencia['tipo'] ?? 'sin clasificar') . "\n";
if (!empty($incidencia['recomendacion'])) {
    $contexto .= "Recomendación interna inicial: {$incidencia['recomendacion']}\n";
}
$contexto .= "\nÚltimos mensajes del hilo:\n";
if (empty($mensajes)) {
    $contexto .= "(sin mensajes todavía; sería la primera respuesta al cliente)\n";
} else {
    foreach ($mensajes as $m) {
        $contexto .= "[{$m['fecha']}] {$m['autor']}: {$m['mensaje']}\n";
    }
}

$pregunta = "Eres un técnico de soporte. Redacta en español el siguiente mensaje del técnico para este hilo: "
    . "breve (máximo 150 palabras), profesional y empático, que responda a lo último planteado por el cliente "
    . "e indique el siguiente paso concreto (qué se va a hacer o qué se necesita del cliente). "
    . "No inventes datos técnicos que no estén en el contexto. Devuelve únicamente el texto del mensaje, sin saludos genéricos repetidos, sin Markdown y sin comillas.";

$sugerencia = LLMClient::getResponse($contexto, $pregunta);
$sugerencia = trim((string)$sugerencia);

if ($sugerencia === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'La IA no devolvió sugerencia. Inténtalo de nuevo.']);
    exit;
}

echo json_encode(['ok' => true, 'sugerencia' => $sugerencia], JSON_UNESCAPED_UNICODE);
exit;
