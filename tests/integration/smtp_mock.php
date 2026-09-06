<?php
// Receptor de una entrega sintetica, sin reenviar a internet ni guardar su contenido.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$servidor = stream_socket_server('tcp://127.0.0.1:0', $numero, $error);
if (!$servidor) exit(1);
echo stream_socket_get_name($servidor,false) . "\n"; flush();
$cliente = stream_socket_accept($servidor,15);
if (!$cliente) { fclose($servidor); exit(1); }
stream_set_timeout($cliente,10);
fwrite($cliente,"220 pruebas.local ESMTP\r\n");
$enDatos = false; $entregado = false;
while (($linea = fgets($cliente)) !== false) {
    if ($enDatos) {
        if (rtrim($linea,"\r\n") === '.') { $enDatos=false; $entregado=true; fwrite($cliente,"250 aceptado\r\n"); }
        continue;
    }
    $orden = strtoupper(strtok($linea," \r\n"));
    if ($orden==='DATA') { $enDatos=true; fwrite($cliente,"354 datos\r\n"); }
    elseif ($orden==='QUIT') { fwrite($cliente,"221 adios\r\n"); break; }
    else fwrite($cliente,"250 OK\r\n");
}
fclose($cliente); fclose($servidor); exit($entregado ? 0 : 1);
