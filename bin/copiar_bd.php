<?php
// Copia local mediante mysqldump. Nunca imprime credenciales ni contenido SQL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opciones = getopt('', ['directorio:', 'mysqldump:']);
$directorio = (string)($opciones['directorio'] ?? '');
$binario = (string)($opciones['mysqldump'] ?? 'mysqldump');
if ($directorio === '') { fwrite(STDERR,"Uso: php bin/copiar_bd.php --directorio=RUTA_PRIVADA [--mysqldump=BINARIO]\n"); exit(1); }
$normalizar = static fn(string $ruta): string => strtolower(str_replace('\\','/',rtrim($ruta,'/\\'))) . '/';
if (!is_dir($directorio) && !mkdir($directorio,0700,true)) { fwrite(STDERR,"No se pudo crear el directorio privado.\n"); exit(1); }
$directorio = realpath($directorio);
$web = realpath(dirname(__DIR__,2));
if ($directorio === false || ($web !== false && str_starts_with($normalizar($directorio),$normalizar($web)))) {
    fwrite(STDERR,"La copia debe estar fuera del directorio web y del proyecto.\n"); exit(1);
}
$config = require dirname(__DIR__) . '/phinx.php';
$db = $config['environments']['principal'];
$archivo = $directorio . DIRECTORY_SEPARATOR . 'ticketia_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
$temporal = $archivo . '.parcial';
$entorno = getenv(); $entorno['MYSQL_PWD'] = (string)$db['pass'];
$comando = [$binario,'--host=' . $db['host'],'--port=' . $db['port'],'--user=' . $db['user'],
    '--single-transaction','--quick','--routines','--events','--triggers','--hex-blob','--default-character-set=utf8mb4',
    '--result-file=' . $temporal,(string)$db['name']];
$proceso = proc_open($comando,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$entorno);
if (!is_resource($proceso)) { fwrite(STDERR,"No se pudo iniciar mysqldump.\n"); exit(1); }
fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
$codigo = proc_close($proceso);
if ($codigo !== 0 || !is_file($temporal) || filesize($temporal)<100) {
    fwrite(STDERR,"La copia fallo; no aplicar migraciones. Revisa conexion, permisos y binario. El archivo parcial no es una copia valida.\n"); exit(1);
}
if (!rename($temporal,$archivo)) { fwrite(STDERR,"No se pudo finalizar el archivo de copia.\n"); exit(1); }
if (PHP_OS_FAMILY !== 'Windows') chmod($archivo,0600);
echo 'Copia de BD: ' . $archivo . "\nSHA256: " . hash_file('sha256',$archivo) . "\n";
echo "No incluye adjuntos ni configuracion. Guardar en ubicacion privada; no subir a Git. La restauracion debe ensayarse por separado.\n";
