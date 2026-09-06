<?php
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
require __DIR__ . '/../src/arranque.php';
require __DIR__ . '/../src/gmail.php';
try {
    if (!gmail_disponible($pdo)) throw new RuntimeException('Aplica la migracion 19 antes de sincronizar.');
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
