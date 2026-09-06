<?php
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
require __DIR__ . '/../src/arranque.php';
require __DIR__ . '/../src/gmail.php';
try {
    if (array_diff(array_slice($argv,1),['--comprobar'])) throw new RuntimeException('Uso: php bin/sincronizar_gmail.php [--comprobar]');
    if (!gmail_disponible($pdo)) throw new RuntimeException('Aplica la migracion 19 antes de sincronizar.');
    $diagnostico=gmail_diagnostico(static fn($clave)=>entorno_valor($clave,''),extension_loaded('curl'));
    if (!$diagnostico['completo']) {
        $errores=[];
        if ($diagnostico['faltan']) $errores[]='Faltan: ' . implode(', ',$diagnostico['faltan']);
        if ($diagnostico['invalidos']) $errores[]='Formato no valido: ' . implode(', ',$diagnostico['invalidos']);
        if (!$diagnostico['curl']) $errores[]='Habilita la extension PHP cURL';
        throw new RuntimeException(implode('. ',$errores) . '. Consulta docs/GMAIL.md.');
    }
    if (in_array('--comprobar',$argv,true)) {
        echo "Configuracion local completa. No se ha contactado con Google: autorizacion y acceso al buzon sin verificar.\n";
        exit(0);
    }
    $cliente=(string)entorno_valor('GMAIL_CLIENT_ID','');$secreto=(string)entorno_valor('GMAIL_CLIENT_SECRET','');$refresh=(string)entorno_valor('GMAIL_REFRESH_TOKEN','');
    $buzon=strtolower(trim((string)entorno_valor('GMAIL_BUZON','')));$etiqueta=trim((string)entorno_valor('GMAIL_LABEL_ID',''));
    if ($cliente==='' || $secreto==='' || $refresh==='' || $buzon==='' || $etiqueta==='') throw new RuntimeException('Falta configurar OAuth de Gmail, el buzon o la etiqueta. Consulta docs/GMAIL.md.');
    $oauth=gmail_http('https://oauth2.googleapis.com/token',['client_id'=>$cliente,'client_secret'=>$secreto,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']);
    $token=(string)($oauth['access_token'] ?? '');if($token==='')throw new RuntimeException('Google no devolvio un token de acceso.');
    $get=static fn(string $ruta):array=>gmail_http('https://gmail.googleapis.com/gmail/v1/users/me/' . $ruta,null,$token);
    $perfil=$get('profile'); if(strtolower($perfil['emailAddress'] ?? '')!==$buzon)throw new RuntimeException('La cuenta autorizada no coincide con GMAIL_BUZON.');
    $n=gmail_sincronizar($pdo,$buzon,$etiqueta,$get);
    echo "OK: $n entradas nuevas pendientes de revision. No se han modificado correos en Gmail.\n";
} catch(Throwable $e) { fwrite(STDERR, $e instanceof PDOException ? "Error de almacenamiento; no se ha avanzado el lote.\n" : $e->getMessage()."\n");exit(1); }
