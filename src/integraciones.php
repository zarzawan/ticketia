<?php
// Credenciales cifradas en ajustes. APP_KEY permanece solo en el servidor.
function integraciones_campos(): array {
    return [
        'gmail'=>['GMAIL_BUZON'=>'Buzon principal','GMAIL_LABEL_ID'=>'ID de etiqueta','GMAIL_CLIENT_ID'=>'Client ID de Google','GMAIL_CLIENT_SECRET'=>'Client secret de Google','GMAIL_REFRESH_TOKEN'=>'Refresh token de Google'],
        'openai'=>['OPENAI_API_KEY'=>'Clave API de OpenAI','OPENAI_MODEL'=>'Modelo para respuestas','OPENAI_MODEL_STREAM'=>'Modelo para analisis en streaming'],
        'xai'=>['XAI_API_KEY'=>'Clave API de xAI','XAI_MODEL'=>'Modelo para respuestas','XAI_MODEL_STREAM'=>'Modelo para analisis en streaming'],
        'local'=>['LLM_LOCAL_API_KEY'=>'Clave API local (opcional)','LLM_LOCAL_MODEL'=>'Modelo local'],
    ];
}

function integraciones_es_secreto(string $clave): bool {
    return str_contains($clave,'KEY') || str_contains($clave,'SECRET') || str_contains($clave,'TOKEN') || $clave==='GMAIL_CLIENT_ID';
}

function integraciones_descifrar(string $grupo, string $cifrado): array {
    $texto=cuenta_secreto_descifrar($cifrado);
    $datos=$texto===null ? null : json_decode($texto,true);
    if (!is_array($datos) || ($datos['grupo'] ?? '')!==$grupo || !is_array($datos['valores'] ?? null)) throw new RuntimeException('No se puede descifrar la configuracion. Revisa APP_KEY o restablece este apartado.');
    $campos=integraciones_campos()[$grupo] ?? [];
    if (array_keys($datos['valores'])!==array_keys($campos)) throw new RuntimeException('Configuracion guardada no valida.');
    foreach ($datos['valores'] as $valor) if (!is_string($valor)) throw new RuntimeException('Configuracion guardada no valida.');
    return $datos['valores'];
}

function integraciones_cargar(PDO $pdo): void {
    $GLOBALS['integraciones_valores']=[];$GLOBALS['integraciones_errores']=[];
    try {
        $filas=$pdo->query("SELECT clave,valor FROM ajustes WHERE clave IN ('conexion_gmail','conexion_openai','conexion_xai','conexion_local')")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return; } // Compatibilidad con instalaciones sin esquema inicial.
    foreach ($filas as $fila) {
        $grupo=substr($fila['clave'],9);
        try {$valores=integraciones_descifrar($grupo,$fila['valor']);}
        catch (RuntimeException $e) {
            $GLOBALS['integraciones_errores'][$grupo]=true;
            // Nunca volver silenciosamente a credenciales antiguas ante un fallo de cifrado.
            $valores=array_fill_keys(array_keys(integraciones_campos()[$grupo]),'');
        }
        $GLOBALS['integraciones_valores']=array_merge($GLOBALS['integraciones_valores'],$valores);
    }
}

function integraciones_validar(string $grupo, array $entrada, callable $leer): array {
    $campos=integraciones_campos()[$grupo] ?? null;
    if (!$campos) throw new RuntimeException('Apartado no valido.');
    $valores=[];
    foreach ($campos as $clave=>$etiqueta) {
        if (isset($entrada[$clave]) && !is_string($entrada[$clave])) throw new RuntimeException('Formato de campo no valido.');
        $valor=trim($entrada[$clave] ?? '');
        if (integraciones_es_secreto($clave) && $valor==='') $valor=(string)$leer($clave);
        if (strlen($valor)>4096 || preg_match('/[\x00-\x1f\x7f]/',$valor)) throw new RuntimeException('Revisa el formato de ' . $etiqueta . '.');
        if ($valor==='' && !in_array($clave,['LLM_LOCAL_API_KEY','LLM_LOCAL_MODEL'],true)) throw new RuntimeException('Completa ' . $etiqueta . '.');
        if (str_contains($clave,'MODEL') && $valor!=='' && !preg_match('/^[a-zA-Z0-9_.:\/-]{1,150}$/D',$valor)) throw new RuntimeException('Nombre de modelo no valido.');
        $valores[$clave]=$valor;
    }
    if ($grupo==='gmail') {
        $valores['GMAIL_BUZON']=strtolower($valores['GMAIL_BUZON']);
        if (!filter_var($valores['GMAIL_BUZON'],FILTER_VALIDATE_EMAIL) || strlen($valores['GMAIL_BUZON'])>254) throw new RuntimeException('Direccion del buzon no valida.');
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$valores['GMAIL_LABEL_ID'])) throw new RuntimeException('Usa el ID de etiqueta, no su nombre.');
    }
    return $valores;
}

function integraciones_version(PDO $pdo, string $grupo): string {
    $stmt=$pdo->prepare('SELECT valor FROM ajustes WHERE clave=?');$stmt->execute(['conexion_' . $grupo]);
    return hash('sha256',(string)$stmt->fetchColumn());
}

function integraciones_guardar(PDO $pdo, string $grupo, ?array $valores, string $version): void {
    if (!isset(integraciones_campos()[$grupo])) throw new RuntimeException('Apartado no valido.');
    $cifrado=$valores===null ? null : cuenta_secreto_cifrar(json_encode(['grupo'=>$grupo,'valores'=>$valores],JSON_THROW_ON_ERROR));
    if ($valores!==null && $cifrado===null) throw new RuntimeException('Configura APP_KEY (al menos 32 caracteres aleatorios) y OpenSSL antes de guardar. No se ha guardado ningun secreto.');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT valor FROM ajustes WHERE clave=? FOR UPDATE');$stmt->execute(['conexion_' . $grupo]);
        if (!hash_equals(hash('sha256',(string)$stmt->fetchColumn()),$version)) throw new RuntimeException('Otro administrador ha cambiado este apartado. Recarga antes de guardar.');
        if ($valores===null) {$stmt=$pdo->prepare('DELETE FROM ajustes WHERE clave=?');$stmt->execute(['conexion_' . $grupo]);}
        else {$stmt=$pdo->prepare('INSERT INTO ajustes (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)');$stmt->execute(['conexion_' . $grupo,$cifrado]);}
        auditar($pdo,$valores===null ? 'restablecer_conexion' : 'guardar_conexion',$grupo);
        $pdo->commit();
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** HTTP exclusivamente contra proveedores conocidos; sin redirecciones ni errores con secretos. */
function integraciones_http(string $url, ?array $formulario=null, string $token=''): array {
    $host=parse_url($url,PHP_URL_HOST);
    if (parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($host,['oauth2.googleapis.com','gmail.googleapis.com','api.openai.com','api.x.ai'],true)) throw new RuntimeException('Destino de conexion no permitido.');
    if (!function_exists('curl_init')) throw new RuntimeException('Habilita cURL en PHP.');
    $ch=curl_init($url);$texto='';
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>$token!=='' ? ['Authorization: Bearer ' . $token] : [],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $parte) use (&$texto): int {if(strlen($texto)+strlen($parte)>1048576)return 0;$texto.=$parte;return strlen($parte);}]);
    if($formulario!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($formulario)]);
    $ok=curl_exec($ch);$codigo=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($ok===false || $codigo!==200) {
        $motivo=match($codigo){400,401=>'Credenciales rechazadas o autorizacion caducada.',403=>'Acceso denegado: revisa los permisos.',404=>'Modelo o etiqueta no disponible.',429=>'Limite de solicitudes alcanzado; prueba mas tarde.',default=>'No se pudo contactar con el proveedor; revisa la red y el servicio.'};
        throw new RuntimeException($motivo . ' HTTP ' . $codigo . '.');
    }
    $datos=json_decode($texto,true);if(!is_array($datos))throw new RuntimeException('El proveedor devolvio una respuesta no valida.');
    return $datos;
}

function integraciones_probar(string $grupo, array $valores, callable $http): string {
    if($grupo==='gmail') {
        $oauth=$http('https://oauth2.googleapis.com/token',['client_id'=>$valores['GMAIL_CLIENT_ID'],'client_secret'=>$valores['GMAIL_CLIENT_SECRET'],'refresh_token'=>$valores['GMAIL_REFRESH_TOKEN'],'grant_type'=>'refresh_token'],'');
        $token=$oauth['access_token'] ?? '';if(!is_string($token) || $token==='' || preg_match('/[\r\n]/',$token))throw new RuntimeException('Google no devolvio un token valido.');
        $perfil=$http('https://gmail.googleapis.com/gmail/v1/users/me/profile',null,$token);
        if(strtolower($perfil['emailAddress'] ?? '')!==$valores['GMAIL_BUZON'])throw new RuntimeException('La cuenta autorizada no coincide con el buzon indicado.');
        $etiqueta=$http('https://gmail.googleapis.com/gmail/v1/users/me/labels/' . rawurlencode($valores['GMAIL_LABEL_ID']),null,$token);
        if(($etiqueta['id'] ?? '')!==$valores['GMAIL_LABEL_ID'])throw new RuntimeException('No se ha podido verificar la etiqueta.');
        return 'Conexion Gmail correcta: cuenta y etiqueta verificadas. No se han leido ni importado mensajes.';
    }
    if(!in_array($grupo,['openai','xai'],true))throw new RuntimeException('Prueba la IA local desde Configuracion, con el endpoint del servidor.');
    if(entorno_valor('LLM_SOLO_LOCAL','')==='1')throw new RuntimeException('El servidor tiene activado LLM_SOLO_LOCAL: no se permiten conexiones a IA externa.');
    $prefijo=$grupo==='openai' ? 'OPENAI' : 'XAI';$host=$grupo==='openai' ? 'api.openai.com' : 'api.x.ai';
    foreach(array_unique([$valores[$prefijo . '_MODEL'],$valores[$prefijo . '_MODEL_STREAM']]) as $modelo) {
        $datos=$http('https://' . $host . '/v1/models/' . rawurlencode($modelo),null,$valores[$prefijo . '_API_KEY']);
        if(!is_string($datos['id'] ?? null) || $datos['id']==='')throw new RuntimeException('El proveedor no ha confirmado el acceso al modelo.');
    }
    return 'Clave y acceso a los modelos verificados. No se ha generado texto: esta prueba no garantiza saldo ni funcionamiento del streaming.';
}
