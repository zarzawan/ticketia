<?php
function probar_integraciones(PDO $pdo,string $cliente): void {
    $admin=iniciar('admin@pruebas.test');$agente=iniciar('agente5@pruebas.test');
    $ruta='admin_integraciones.php?grupo=gmail';
    foreach([$cliente,$agente] as $jar){[$codigo]=peticion($ruta,$jar);comprobar($codigo===403,'Solo administracion accede a credenciales');}
    [, $html]=peticion($ruta,$admin);$token=csrf($html);
    $version=static function(string $html):string{preg_match('/name="version" value="([^"]+)"/',$html,$m);return $m[1] ?? '';};
    $datos=['accion'=>'guardar','version'=>$version($html),'csrf'=>$token,'password_actual'=>'Pruebas-TicketIA-2026',
        'GMAIL_BUZON'=>'soporte@pruebas.test','GMAIL_LABEL_ID'=>'Label_test','GMAIL_CLIENT_ID'=>'id-sintetico-integracion','GMAIL_CLIENT_SECRET'=>'secreto-sintetico-integracion','GMAIL_REFRESH_TOKEN'=>'refresh-sintetico-integracion'];
    $sinCsrf=$datos;unset($sinCsrf['csrf']);[$codigo]=peticion($ruta,$admin,$sinCsrf);comprobar($codigo===400,'Guardar conexiones exige CSRF');
    [$codigo]=peticion($ruta,$admin,$datos);comprobar($codigo===302,'Guarda conexiones con confirmacion de contrasena');
    $cifrado=$pdo->query("SELECT valor FROM ajustes WHERE clave='conexion_gmail'")->fetchColumn();
    comprobar(is_string($cifrado) && !str_contains($cifrado,'sintetico') && !str_contains($cifrado,'pruebas.test'),'Datos de conexion cifrados en base de datos');
    [, $html]=peticion($ruta,$admin);
    foreach(['GMAIL_CLIENT_ID','GMAIL_CLIENT_SECRET','GMAIL_REFRESH_TOKEN'] as $clave)comprobar(!str_contains($html,$datos[$clave]),'Secreto no vuelve al HTML: ' . $clave);
    comprobar(str_contains($html,'Configurado; vacio conserva el valor'),'UI indica secreto guardado sin revelarlo');
    [, $bandeja]=peticion('admin_correo_entrante.php',$admin);comprobar(str_contains($bandeja,'Configuracion local completa'),'Gmail usa configuracion del panel en la siguiente peticion');
    [, $conflicto]=peticion($ruta,$admin,$datos);comprobar(str_contains($conflicto,'Otro administrador'),'Formulario antiguo no sobrescribe credenciales');
    $datos['version']=$version($html);$datos['GMAIL_CLIENT_ID']='';$datos['GMAIL_CLIENT_SECRET']='';$datos['GMAIL_REFRESH_TOKEN']='';
    [$codigo]=peticion($ruta,$admin,$datos);comprobar($codigo===302,'Campos secretos vacios conservan las credenciales');
    [, $html]=peticion($ruta,$admin);$datos['version']=$version($html);
    $datos['accion']='restablecer';
    [, $respuesta]=peticion($ruta,$admin,$datos);comprobar(str_contains($respuesta,'Confirma que quieres eliminar'),'Restablecer requiere confirmacion explicita');
    $datos['confirmar_restablecer']='1';[$codigo]=peticion($ruta,$admin,$datos);comprobar($codigo===302,'Restablece exclusivamente el apartado confirmado');
    comprobar(!$pdo->query("SELECT valor FROM ajustes WHERE clave='conexion_gmail'")->fetchColumn(),'Restablecer elimina el cifrado del panel');
    [, $html]=peticion('admin_integraciones.php?grupo=openai',$admin);
    $ia=['accion'=>'guardar','version'=>$version($html),'csrf'=>$token,'password_actual'=>'Pruebas-TicketIA-2026','OPENAI_API_KEY'=>'clave-sintetica-ia','OPENAI_MODEL'=>'modelo-prueba','OPENAI_MODEL_STREAM'=>'modelo-stream'];
    [$codigo]=peticion('admin_integraciones.php?grupo=openai',$admin,$ia);comprobar($codigo===302,'Panel admite claves y modelos de IA');
    [, $html]=peticion('admin_integraciones.php?grupo=openai',$admin);$ia['version']=$version($html);$ia['accion']='probar';
    [, $html]=peticion('admin_integraciones.php?grupo=openai',$admin,$ia);comprobar(str_contains($html,'LLM_SOLO_LOCAL'),'Prueba HTTP respeta prohibicion de IA externa');
    comprobar(!str_contains($html,'clave-sintetica-ia'),'Error tampoco revela secreto enviado');
    $pdo->exec("UPDATE ajustes SET valor='cifrado-invalido' WHERE clave='conexion_openai'");
    [, $html]=peticion('admin_integraciones.php?grupo=openai',$admin);comprobar(str_contains($html,'No se pueden descifrar'),'Panel explica corrupcion o perdida de clave');
    $ia['version']=$version($html);$ia['accion']='restablecer';$ia['confirmar_restablecer']='1';
    peticion('admin_integraciones.php?grupo=openai',$admin,$ia);
    $mal=iniciar('admin@pruebas.test');[, $html]=peticion($ruta,$mal);
    $datos['csrf']=csrf($html);$datos['version']=$version($html);$datos['password_actual']='incorrecta';
    [, $html]=peticion($ruta,$mal,$datos);comprobar(str_contains($html,'Confirma tu contrasena'),'Contrasena incorrecta no modifica conexiones');
    [, $html]=peticion($ruta,$mal,$datos);comprobar(str_contains($html,'Espera unos segundos'),'Intentos de confirmacion tienen pausa');
    $stmt=$pdo->query("SELECT detalle FROM auditoria WHERE accion IN ('guardar_conexion','restablecer_conexion','probar_conexion')");
    comprobar(!str_contains(json_encode($stmt->fetchAll()),'sintetica'),'Auditoria no incluye tokens');
}
