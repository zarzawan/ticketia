<?php
function probar_gmail(PDO $pdo, string $cliente): void {
    require_once __DIR__ . '/../../src/gmail.php';
    $admin=iniciar('admin@pruebas.test');$agente=iniciar('agente5@pruebas.test');
    $get=static function(string $ruta):array {
        if(str_starts_with($ruta,'messages?'))return ['messages'=>[['id'=>'correo_prueba_1']]];
        return ['threadId'=>'hilo_prueba_1','payload'=>['mimeType'=>'text/plain','headers'=>[
            ['name'=>'From','value'=>'cliente@pruebas.test'],['name'=>'Subject','value'=>'GMAIL_PRUEBA_AISLADA']],
            'body'=>['data'=>base64_encode('Necesito ayuda con el correo.')]]];
    };
    comprobar(gmail_sincronizar($pdo,'soporte@pruebas.test','Label_test',$get)===1,'Gmail guarda un lote simulado para revision');
    comprobar(gmail_sincronizar($pdo,'soporte@pruebas.test','Label_test',$get)===0,'Gmail no duplica el mismo mensaje');
    $id=(int)$pdo->query("SELECT id FROM gmail_entradas WHERE mensaje_id='correo_prueba_1'")->fetchColumn();
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM incidencias WHERE titulo='GMAIL_PRUEBA_AISLADA'")->fetchColumn()===0,'No crea incidencias antes de aprobar');
    [, $html]=peticion("admin_correo_entrante.php?ver=$id",$admin);$token=csrf($html);
    [$codigo,$html]=peticion("admin_correo_entrante.php?ver=$id",$admin,['id'=>$id,'accion'=>'importar','csrf'=>$token]);
    comprobar(str_contains($html,'Confirma que has verificado'),'Importacion exige confirmar identidad');
    foreach([$agente,$cliente]as$jar){[$codigo]=peticion('admin_correo_entrante.php',$jar);comprobar($codigo===403,'Entrada de correo solo para administracion');}
    [$codigo]=peticion('admin_correo_entrante.php',$admin,['id'=>$id,'accion'=>'importar','confirmar'=>1,'csrf'=>$token]);
    $ticket=(int)$pdo->query("SELECT incidencia_id FROM gmail_entradas WHERE id=$id")->fetchColumn();
    comprobar($codigo===200 && $ticket>0,'Correo aprobado crea incidencia');
    comprobar((int)$pdo->query("SELECT cliente_id FROM incidencias WHERE id=$ticket")->fetchColumn()===1,'Incidencia asociada a la empresa del remitente verificado');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM trabajos_ia WHERE tipo='clasificar' AND JSON_EXTRACT(payload,'$.id_incidencia')=$ticket")->fetchColumn()===1,'Correo aprobado encola IA');
    peticion('admin_correo_entrante.php',$admin,['id'=>$id,'accion'=>'importar','confirmar'=>1,'csrf'=>$token]);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM incidencias WHERE titulo='GMAIL_PRUEBA_AISLADA'")->fetchColumn()===1,'Doble aprobacion no duplica incidencia');
    $pdo->exec("INSERT INTO gmail_entradas (buzon,mensaje_id,hilo_id,remitente,asunto,cuerpo) VALUES ('soporte@pruebas.test','correo_prueba_2','hilo_prueba_1','cliente@pruebas.test','Respuesta','Continuacion')");
    $otro=(int)$pdo->lastInsertId();
    [, $html]=peticion('admin_correo_entrante.php',$admin,['id'=>$otro,'accion'=>'importar','confirmar'=>1,'csrf'=>$token]);
    comprobar(str_contains($html,'Este hilo ya tiene una incidencia'),'Un hilo existente no se fragmenta automaticamente');
}
