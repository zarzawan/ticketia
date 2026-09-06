<?php
// Prueba HTTP con esquema desechable. Nunca instala ni siembra la BD de desarrollo.
// Uso: php tests/integration/experiencia.php [--visual]
// TEST_DB_USER / TEST_DB_PASS: cuenta local con permiso CREATE DATABASE.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$raiz = dirname(__DIR__, 2);
$nombre = 'ticketia_pruebas_' . bin2hex(random_bytes(5));
$entorno = array_merge(getenv(), [
    'DB_HOST'=>'127.0.0.1', 'DB_PORT'=>'3306', 'DB_NAME'=>$nombre,
    'DB_USER'=>getenv('TEST_DB_USER') ?: 'root', 'DB_PASS'=>getenv('TEST_DB_PASS') ?: '',
    'APP_URL'=>'http://127.0.0.1:8091', 'APP_ENV'=>'testing',
    'SMTP_HOST'=>'', 'OPENAI_API_KEY'=>'', 'XAI_API_KEY'=>'', 'LLM_SOLO_LOCAL'=>'1',
    'LLM_PROVIDER'=>'local', 'LLM_LOCAL_ENDPOINT'=>'http://127.0.0.1:8092',
    'LLM_LOCAL_MODEL'=>'pruebas', 'LLM_TIMEOUT'=>'5', 'LLM_CONNECT_TIMEOUT'=>'2',
]);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $entorno['DB_USER'], $entorno['DB_PASS'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$servidores = []; $archivos = []; $comprobaciones = 0; $creada = false;
function comprobar(bool $valor, string $mensaje): void {
    global $comprobaciones;
    if (!$valor) throw new RuntimeException($mensaje);
    $comprobaciones++;
}
function peticion(string $ruta, string $jar, ?array $datos = null): array {
    $ch = curl_init('http://127.0.0.1:8091/' . $ruta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEFILE=>$jar, CURLOPT_COOKIEJAR=>$jar, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>false]);
    if ($datos !== null) curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($datos)]);
    $html = curl_exec($ch); $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    $detalle = '';
    if (preg_match('/(?:Fatal error|Warning)(.{0,700})/s', (string)$html, $coincidencia)) $detalle = strip_tags($coincidencia[0]);
    comprobar($html !== false && $detalle === '', "Error HTTP en $ruta: $error $detalle");
    return [$codigo, (string)$html];
}
function csrf(string $html): string {
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}
function iniciar(string $email): string {
    global $archivos;
    $jar = tempnam(sys_get_temp_dir(), 'ticketia_cookie_'); $archivos[] = $jar;
    [, $html] = peticion('login.php', $jar);
    [$codigo] = peticion('login.php', $jar, ['email'=>$email,'password'=>'Pruebas-TicketIA-2026','csrf'=>csrf($html)]); // gitleaks:allow -- Credencial sintetica de la base desechable, nunca de desarrollo o produccion.
    comprobar($codigo === 302, 'Login de prueba rechazado');
    return $jar;
}
try {
    $pdo->exec("CREATE DATABASE $nombre CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $creada = true;
    $salida = tempnam(sys_get_temp_dir(), 'ticketia_migracion_'); $archivos[] = $salida;
    $preparacion = 'auto_prepend_file=' . __DIR__ . '/entorno.php';
    $proceso = proc_open([PHP_BINARY, '-d', $preparacion, 'vendor/bin/phinx', 'migrate', '-c', 'phinx.php', '-e', 'principal'], [0=>['pipe','r'], 1=>['file',$salida,'w'], 2=>['file',$salida,'a']], $pipes, $raiz, $entorno);
    fclose($pipes[0]);
    comprobar(proc_close($proceso) === 0, 'Fallo de migracion: ' . file_get_contents($salida));
    $pdo->exec("USE $nombre");
    $pdo->exec("INSERT INTO clientes (id,nombre) VALUES (1,'Organizacion de prueba'),(2,'Otra organizacion')");
    $hash = password_hash('Pruebas-TicketIA-2026', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO usuarios (id,nombre,email,hash_password,rol,cliente_id) VALUES (?,?,?,?,?,?)');
    foreach ([[1,'Admin de prueba','admin@pruebas.test','admin',null],[2,'Cliente de prueba','cliente@pruebas.test','cliente',1],[3,'Otra persona','otro@pruebas.test','cliente',2],[4,'Agente de prueba','agente@pruebas.test','operador',null]] as [$id,$nom,$email,$rol,$cliente]) $stmt->execute([$id,$nom,$email,$hash,$rol,$cliente]);
    $stmt = $pdo->prepare('INSERT INTO incidencias (id,cliente_id,creado_por,titulo,descripcion,estado,resolucion_notas,fecha_resolucion) VALUES (?,?,?,?,?,?,?,?)');
    foreach ([[1,1,2,'No puedo acceder al correo','Descripcion visible','en_curso',null,null],[2,1,2,'Configurar el correo en el movil','Descripcion resuelta','resuelta','Revisar la configuracion de la cuenta.',date('Y-m-d H:i:s')],[3,1,2,'Consulta finalizada','Descripcion cerrada','cerrada','Configuracion correcta.',date('Y-m-d H:i:s')],[4,2,3,'NO_VISIBLE_OTRA_EMPRESA','Privado','abierta',null,null]] as $fila) $stmt->execute($fila);
    $pdo->exec("INSERT INTO mensajes (id_incidencia,autor,mensaje,interno) VALUES (1,'tecnico','Respuesta publica del equipo',0),(1,'tecnico','NO_VISIBLE_NOTA_INTERNA',1)");
    $stmt=$pdo->prepare('INSERT INTO conocimiento (titulo,resumen,contenido,categoria,estado,visibilidad) VALUES (?,?,?,?,?,?)');
    foreach ([['Configurar correo','Ayuda con tu correo','Pasos seguros para configurar correo','Correo','publicado','clientes'],['NO_VISIBLE_INTERNO correo','Interno','NO_VISIBLE_INTERNO','Correo','publicado','interno'],['NO_VISIBLE_BORRADOR correo','Borrador','NO_VISIBLE_BORRADOR','Correo','borrador','clientes']] as $fila) $stmt->execute($fila);
    // Volumen suficiente para verificar limites y paginacion real.
    for ($i=5; $i<=31; $i++) {
        $pdo->prepare("INSERT INTO usuarios (nombre,email,hash_password,rol) VALUES (?, ?, ?, 'operador')")->execute(["Agente $i", "agente$i@pruebas.test",$hash]);
        $pdo->prepare("INSERT INTO clientes (nombre) VALUES (?)")->execute(["Organizacion $i"]);
    }
    for ($puerto=8091; $puerto<=8092; $puerto++) {
        $socket=@fsockopen('127.0.0.1',$puerto,$numero,$texto,.2);
        if ($socket) { fclose($socket); throw new RuntimeException("El puerto $puerto esta ocupado. No se ha tocado el servicio existente."); }
        $log=tempnam(sys_get_temp_dir(),'ticketia_http_'); $archivos[]=$log;
        $comando=$puerto===8091 ? [PHP_BINARY,'-d',$preparacion,'-S','127.0.0.1:8091','-t','public'] : [PHP_BINARY,'-S','127.0.0.1:8092','tests/integration/ia_mock.php'];
        $servidores[]=proc_open($comando,[0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$log,'a']],$pipes,$raiz,$entorno);
        fclose($pipes[0]);
    }
    for ($i=0; $i<40; $i++) { $socket=@fsockopen('127.0.0.1',8091,$numero,$texto,.2); if($socket){fclose($socket);break;} usleep(100000); }
    $admin=iniciar('admin@pruebas.test'); $cliente=iniciar('cliente@pruebas.test'); $agente=iniciar('agente@pruebas.test'); $otro=iniciar('otro@pruebas.test');
    foreach (['admin_inicio.php','admin_usuarios.php','admin_clientes.php','admin_flujos.php','admin_ajustes.php','admin_auditoria.php','ver_logs_llm.php','admin_conocimiento.php','index.php','archivo.php','ver_incidencia.php?id=1'] as $ruta) {
        [$codigo]=peticion($ruta,$admin); comprobar($codigo===200,"Pagina admin: $ruta");
    }
    foreach (['admin_usuarios.php','admin_clientes.php'] as $ruta) {
        [, $html]=peticion($ruta,$admin); comprobar(substr_count($html,'<tr ')+substr_count($html,'<tr>')===26,"Paginacion de $ruta");
        [, $html]=peticion($ruta.'?pagina=9999',$admin); comprobar(str_contains($html,'Pagina 2 de 2'),"Acotar pagina en $ruta");
    }
    [$codigo]=peticion('admin_conocimiento.php',$cliente); comprobar($codigo===403,'Guard de administracion para cliente');
    [$codigo]=peticion('admin_conocimiento.php',$agente); comprobar($codigo===403,'Guard de administracion para agente');
    [, $html]=peticion('portal.php',$cliente); comprobar(!str_contains($html,'NO_VISIBLE_OTRA_EMPRESA'),'Aislamiento de empresas');
    comprobar(str_contains($html,'Tienes una respuesta') && !str_contains($html,'Consulta finalizada'),'Siguiente paso y separacion de historial');
    [, $html]=peticion('portal.php?vista=historial',$cliente); comprobar(str_contains($html,'Consulta finalizada') && !str_contains($html,'No puedo acceder'),'Historial separado');
    [, $html]=peticion('portal_ver.php?id=1',$cliente); comprobar(str_contains($html,'Respuesta publica') && !str_contains($html,'NO_VISIBLE_NOTA_INTERNA'),'No exponer notas internas');
    [$codigo]=peticion('portal_ver.php?id=4',$cliente); comprobar($codigo===302,'Detalle ajeno bloqueado');
    [, $html]=peticion('buscar_ayuda.php?q=correo',$cliente); comprobar(str_contains($html,'Configurar correo') && !str_contains($html,'NO_VISIBLE_'),'Busqueda publica sin contenido privado');
    foreach ([2,3] as $id) { [$codigo]=peticion("ayuda.php?id=$id",$cliente); comprobar($codigo===404,'Articulo privado o borrador no accesible'); }
    [, $html]=peticion('admin_conocimiento.php?nuevo=1',$admin); $token=csrf($html);
    $datos=['accion'=>'guardar','id'=>0,'titulo'=>'Guia nueva de correo','resumen'=>'Resumen','contenido'=>'Solucion de prueba','categoria'=>'Correo','estado'=>'publicado','visibilidad'=>'clientes','csrf'=>$token];
    [, $html]=peticion('admin_conocimiento.php',$admin,$datos); comprobar(str_contains($html,'Confirma que has revisado'),'Publicacion exige revision humana');
    $datos['revisado']='1'; [$codigo]=peticion('admin_conocimiento.php',$admin,$datos); comprobar($codigo===302,'Publicar articulo');
    $id=(int)$pdo->query("SELECT id FROM conocimiento WHERE titulo='Guia nueva de correo'")->fetchColumn();
    [, $html]=peticion("ayuda.php?id=$id",$cliente); comprobar(str_contains($html,'Solucion de prueba'),'Articulo publicado visible');
    $datos['id']=$id; $datos['version']=1; $datos['estado']='archivado';
    [$codigo]=peticion('admin_conocimiento.php',$admin,$datos); comprobar($codigo===302,'Archivar articulo');
    [$codigo]=peticion("ayuda.php?id=$id",$cliente); comprobar($codigo===404,'Archivo retirado del portal');
    [, $html]=peticion('admin_conocimiento.php',$admin,$datos); comprobar(str_contains($html,'Otra persona ha editado'),'Control de edicion concurrente');
    [, $html]=peticion('admin_conocimiento.php',$admin,['accion'=>'borrador_ia','incidencia_id'=>2,'csrf'=>$token]); comprobar(str_contains($html,'Borrador preparado') && str_contains($html,'Recuperar el acceso'),'IA prepara un borrador');
    comprobar((int)$pdo->query('SELECT COUNT(*) FROM llm_logs WHERE usuario_id=1 AND incidencia_id=2')->fetchColumn()===1,'Trazabilidad IA por usuario e incidencia');
    foreach (['analisis.php?stream=1', 'analisis_seguridad.php?stream=1'] as $ruta) {
        [$codigo,$html]=peticion($ruta,$admin);
        comprobar($codigo===200 && str_contains($html,'Analisis') && str_contains($html,'Finalizado correctamente'),"Streaming completo: $ruta (HTTP $codigo): " . substr($html,-700));
    }
    [, $html]=peticion('copiloto_incidencia.php',$admin,['id_incidencia'=>1,'csrf'=>$token]);
    $copiloto=json_decode($html,true);
    comprobar(!empty($copiloto['ok']) && !empty($copiloto['insight']['contenido_hash']),'Generar copiloto');
    $hashCopiloto=$copiloto['insight']['contenido_hash'];
    [$codigo]=peticion('feedback_ia.php',$admin,['id_incidencia'=>1,'contenido_hash'=>$hashCopiloto,'accion'=>'valorar','valoracion'=>1,'csrf'=>$token]);
    comprobar($codigo===200,'Valorar copiloto');
    [$codigo]=peticion('guardar_mensaje.php',$admin,['id_incidencia'=>1,'mensaje'=>'Respuesta revisada','origen_ia'=>'copiloto','contenido_hash_ia'=>$hashCopiloto,'csrf'=>$token]);
    comprobar($codigo===302 && (int)$pdo->query('SELECT borrador_enviado FROM feedback_ia WHERE incidencia_id=1')->fetchColumn()===1,'Borrador enviado registrado');
    [$codigo]=peticion('admin_usuarios.php',$admin,['accion'=>'password','id'=>4,'password'=>'Nueva-Prueba-2026','csrf'=>$token]);
    comprobar($codigo===200 && password_verify('Nueva-Prueba-2026',$pdo->query('SELECT hash_password FROM usuarios WHERE id=4')->fetchColumn()),'Cambio de contrasena operativo');
    [, $html]=peticion('portal_ver.php?id=2',$cliente); $tokenCliente=csrf($html);
    [$codigo]=peticion('valorar_servicio.php',$cliente,['id_incidencia'=>2,'puntuacion'=>5,'comentario'=>'Buena ayuda','csrf'=>$tokenCliente]); comprobar($codigo===302,'Guardar satisfaccion');
    [$codigo]=peticion('valorar_servicio.php',$cliente,['id_incidencia'=>2,'puntuacion'=>4,'csrf'=>$tokenCliente]); comprobar($codigo===302,'Actualizar satisfaccion');
    comprobar((int)$pdo->query('SELECT COUNT(*) FROM satisfaccion_servicio')->fetchColumn()===1,'Valoracion sin duplicados');
    comprobar((int)$pdo->query('SELECT puntuacion FROM satisfaccion_servicio')->fetchColumn()===4,'Ultima valoracion guardada');
    $mensajesAntes=(int)$pdo->query('SELECT COUNT(*) FROM mensajes')->fetchColumn();
    [$codigo]=peticion('guardar_mensaje.php',$cliente,['id_incidencia'=>3,'mensaje'=>'No debe insertarse','csrf'=>$tokenCliente]);
    comprobar($codigo===302 && (int)$pdo->query('SELECT COUNT(*) FROM mensajes')->fetchColumn()===$mensajesAntes,'No responder a una solicitud cerrada');
    [$codigo]=peticion('valorar_servicio.php',$cliente,['id_incidencia'=>2,'puntuacion'=>5,'csrf'=>'falso']); comprobar($codigo===400,'CSRF rechazado');
    [, $html]=peticion('portal.php',$otro);
    [$codigo]=peticion('valorar_servicio.php',$otro,['id_incidencia'=>2,'puntuacion'=>1,'csrf'=>csrf($html)]); comprobar($codigo===404,'Valoracion ajena bloqueada');
    require __DIR__ . '/operacion.php';
    require __DIR__ . '/soporte.php';
    probar_soporte($pdo, $admin, $cliente);
    probar_operacion($pdo, $admin, $entorno, $preparacion, $raiz);
    require __DIR__ . '/profesional.php';
    probar_profesional($pdo, $admin, $cliente, $agente, $raiz);
    require __DIR__ . '/predicciones.php';
    probar_predicciones($pdo, $admin, $cliente, $agente);
    require __DIR__ . '/respuestas.php';
    probar_respuestas($pdo, $admin, $cliente);
    require __DIR__ . '/ia_operativa.php';
    probar_ia_operativa($pdo, $cliente);
    echo "OK: $comprobaciones comprobaciones HTTP y de integridad. Base aislada: $nombre\n";
    if (in_array('--visual',$argv,true)) {
        echo "Revision visual disponible en http://127.0.0.1:8091. Usuarios de prueba admin@pruebas.test / cliente@pruebas.test. Pulsa Enter para limpiar.\n";
        fgets(STDIN);
    }
} catch (Throwable $e) {
    fwrite(STDERR,'FALLO: '.$e->getMessage()."\n");
    $fallo = true;
} finally {
    foreach ($servidores as $servidor) { if(is_resource($servidor)){proc_terminate($servidor);proc_close($servidor);} }
    if ($creada && preg_match('/^ticketia_pruebas_[a-f0-9]{10}$/',$nombre)) $pdo->exec("DROP DATABASE $nombre");
    foreach ($archivos as $archivo) if(is_file($archivo)) unlink($archivo);
}
exit(isset($fallo) ? 1 : 0);
