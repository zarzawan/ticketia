<?php
// Evita heredar configuracion privada cuando Windows omite variables vacias.
if (!str_starts_with((string)getenv('DB_NAME'), 'ticketia_pruebas_')) {
    throw new RuntimeException('El entorno de pruebas exige una base desechable.');
}
foreach (['DB_PASS'=>getenv('TEST_DB_PASS') ?: '', 'SMTP_HOST'=>'', 'OPENAI_API_KEY'=>'', 'XAI_API_KEY'=>''] as $clave=>$valor) {
    $_ENV[$clave] = $valor;
    putenv("$clave=$valor");
}
