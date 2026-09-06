<?php
// Proveedor ficticio para pruebas locales, sin red externa.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
if (!empty($entrada['stream'])) {
    header('Content-Type: text/event-stream');
    foreach (['Analisis ', 'de prueba.'] as $parte) echo 'data: ' . json_encode(['choices'=>[['delta'=>['content'=>$parte]]]]) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}
header('Content-Type: application/json');
$contenido = json_encode(['titulo'=>'Recuperar el acceso al correo', 'resumen'=>'Pasos revisados para recuperar el acceso.', 'contenido'=>"1. Abre los ajustes.\n2. Comprueba tu cuenta.\n3. Contacta con soporte si continua el error.", 'riesgo'=>'bajo', 'sentimiento'=>'neutral', 'siguiente_accion'=>'Revisar configuracion.', 'respuesta_sugerida'=>'Vamos a revisar tu configuracion.', 'confianza'=>80]);
echo json_encode(['choices'=>[['message'=>['content'=>$contenido]]], 'usage'=>['prompt_tokens'=>20,'completion_tokens'=>40]]);
