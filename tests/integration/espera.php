<?php
function probar_espera_cliente(PDO $pdo, string $cliente): void {
    $admin = iniciar('admin@pruebas.test');
    $pdo->exec("INSERT INTO incidencias (titulo,descripcion,cliente_id,creado_por,estado,idioma,urgencia,fecha_creacion)
        VALUES ('ESPERA_EXPLICITA_PRUEBA','Solicitud de prueba',1,2,'abierta','es','critico',NOW()-INTERVAL 2 DAY)");
    $id = (int)$pdo->lastInsertId();
    [, $html] = peticion("ver_incidencia.php?id=$id",$admin);
    comprobar(str_contains($html,'Pedir informacion y esperar'),'Accion de espera visible tras migrar');
    $datos = ['id_incidencia'=>$id,'mensaje'=>'Necesitamos el error exacto para continuar.','accion_respuesta'=>'esperar','solicitud_id'=>bin2hex(random_bytes(16)),'csrf'=>csrf($html)];
    [$codigo] = peticion('guardar_mensaje.php',$admin,$datos);
    comprobar($codigo===302 && $pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='esperando_cliente','Enviar peticion de informacion y esperar atomico');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id AND interno=0")->fetchColumn()===1,'Peticion publicada en la conversacion');
    peticion('guardar_mensaje.php',$admin,$datos);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id")->fetchColumn()===1,'Reenvio de peticion no duplica mensaje');
    $fuente = bandeja_fuente_sql($pdo);
    $contadores = bandeja_contar_colas($pdo,$fuente,['busqueda'=>'ESPERA_EXPLICITA_PRUEBA']);
    comprobar($contadores['todas']===1 && $contadores['espera']===1 && $contadores['accion']===0 && $contadores['sla']===1,'Espera visible pero no pendiente de respuesta tecnica; SLA continua');
    foreach (['index.php?cola=espera','index.php?vista=lista&cola=espera','portal.php','exportar_csv.php'] as $ruta) {
        [, $pagina] = peticion($ruta,$ruta==='portal.php' ? $cliente : $admin);
        comprobar(str_contains($pagina,'ESPERA_EXPLICITA_PRUEBA'),"Espera no desaparece de $ruta");
    }
    [, $html] = peticion("portal_ver.php?id=$id",$cliente);
    comprobar(str_contains($html,'Necesitamos tu informacion') && str_contains($html,'Necesitamos el error exacto'),'Cliente entiende que debe responder');
    $datosNota = ['id_incidencia'=>$id,'mensaje'=>'Nota interna sin cambiar espera','accion_respuesta'=>'nota','csrf'=>$datos['csrf']];
    peticion('guardar_mensaje.php',$admin,$datosNota);
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='esperando_cliente','Nota interna conserva espera');
    $respuesta = ['id_incidencia'=>$id,'mensaje'=>'El error es de conexion.','csrf'=>csrf($html),'solicitud_id'=>bin2hex(random_bytes(16))];
    peticion('guardar_mensaje.php',$cliente,$respuesta);
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='en_curso','Respuesta del cliente reanuda trabajo');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM cambios_estado WHERE id_incidencia=$id AND estado_anterior='esperando_cliente' AND estado_nuevo='en_curso'")->fetchColumn()===1,'Reanudacion registrada una sola vez');
    $datos['solicitud_id']=bin2hex(random_bytes(16));
    peticion('guardar_mensaje.php',$admin,$datos);
    peticion('guardar_mensaje.php',$cliente,$respuesta);
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='esperando_cliente','Reintento de respuesta vieja no cancela una nueva espera');
    [$codigo]=peticion('mover_incidencia_estado.php',$admin,['id_incidencia'=>$id,'estado'=>'esperando_cliente','csrf'=>$datos['csrf']]);
    comprobar($codigo===400,'No se puede esperar sin pedir informacion mediante arrastre');
    $otraPersona=iniciar('otro@pruebas.test'); [, $html]=peticion('portal.php',$otraPersona);
    peticion('guardar_mensaje.php',$otraPersona,['id_incidencia'=>$id,'mensaje'=>'No autorizado','csrf'=>csrf($html)]);
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='esperando_cliente','Otra empresa no puede reanudar la incidencia');
    $datos['accion_respuesta']='resolver'; $datos['mensaje']='Solucion documentada para prueba'; $datos['solicitud_id']=bin2hex(random_bytes(16));
    peticion('guardar_mensaje.php',$admin,$datos);
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='resuelta','Se puede proponer solucion desde espera');
}
