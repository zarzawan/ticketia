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
    comprobar(str_contains($html,'El destino del hilo ha cambiado'),'Un formulario antiguo no cambia de destino silenciosamente');
    [, $html]=peticion("admin_correo_entrante.php?ver=$otro",$admin);
    comprobar(str_contains($html,'Incorporar respuesta') && str_contains($html,'incidencia #' . $ticket),'Revision muestra el destino antes de incorporar');
    $pdo->exec("UPDATE incidencias SET estado='esperando_cliente' WHERE id=$ticket");
    $post=['id'=>$otro,'destino'=>$ticket,'accion'=>'importar','confirmar'=>1,'csrf'=>$token];
    [, $html]=peticion('admin_correo_entrante.php',$admin,$post);
    comprobar(str_contains($html,'Respuesta incorporada'),'Respuesta Gmail aprobada se incorpora');
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$ticket")->fetchColumn()==='en_curso','Correo del cliente devuelve la incidencia a trabajo');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$ticket AND mensaje='Continuacion' AND autor='cliente' AND interno=0")->fetchColumn()===1,'Respuesta queda visible como comentario del cliente');
    peticion('admin_correo_entrante.php',$admin,$post);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$ticket")->fetchColumn()===1,'Doble aprobacion no duplica comentarios');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM cambios_estado WHERE id_incidencia=$ticket AND estado_anterior='esperando_cliente'")->fetchColumn()===1,'Transicion de espera se registra una sola vez');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM trabajos_ia WHERE tipo='clasificar' AND JSON_EXTRACT(payload,'$.id_incidencia')=$ticket")->fetchColumn()===1,'Respuesta no reclasifica la incidencia');
    $pdo->exec("INSERT INTO gmail_entradas (buzon,mensaje_id,hilo_id,remitente,asunto,cuerpo) VALUES ('soporte@pruebas.test','correo_prueba_3','hilo_prueba_1','otro@pruebas.test','Respuesta','AJENA_GMAIL')");
    $ajena=(int)$pdo->lastInsertId();$post['id']=$ajena;
    [, $html]=peticion('admin_correo_entrante.php',$admin,$post);
    comprobar(str_contains($html,'no pertenece al cliente'),'Hilo compartido no permite mezclar empresas');
    comprobar($pdo->query("SELECT estado FROM gmail_entradas WHERE id=$ajena")->fetchColumn()==='pendiente','Rechazo conserva el correo pendiente');
    $pdo->exec("UPDATE gmail_entradas SET remitente='cliente@pruebas.test' WHERE id=$ajena");
    foreach(['resuelta','cerrada'] as $estado) {
        $pdo->exec("UPDATE incidencias SET estado='$estado' WHERE id=$ticket");
        [, $html]=peticion('admin_correo_entrante.php',$admin,$post);
        comprobar(str_contains($html,'resuelta o cerrada'),'Gmail no reabre una incidencia ' . $estado);
    }
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$ticket")->fetchColumn()===1,'Rechazos no dejan mensajes parciales');
    $pdo->exec("UPDATE incidencias SET estado='esperando_cliente' WHERE id=$ticket");
    $pdo->exec("CREATE TRIGGER fallo_gmail_prueba BEFORE UPDATE ON gmail_entradas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo simulado de integridad'");
    try {
        peticion('admin_correo_entrante.php',$admin,$post);
        comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$ticket")->fetchColumn()===1,'Fallo al marcar correo revierte el comentario');
        comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$ticket")->fetchColumn()==='esperando_cliente','Fallo al marcar correo revierte el cambio de estado');
        comprobar($pdo->query("SELECT estado FROM gmail_entradas WHERE id=$ajena")->fetchColumn()==='pendiente','Fallo permite revisar el correo de nuevo');
    } finally {$pdo->exec('DROP TRIGGER fallo_gmail_prueba');}
}
