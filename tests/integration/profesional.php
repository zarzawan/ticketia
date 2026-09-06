<?php
// Solo se llama desde el banco con una base efimera.
function probar_profesional(PDO $pdo, string $admin, string $cliente, string $agente, string $raiz): void {
    $agente = iniciar('agente5@pruebas.test');
    foreach (['seguridad_cuenta','auth','soporte','asistente','conocimiento','reglas','correo'] as $archivo) require_once $raiz . '/src/' . $archivo . '.php';
    $pdo->exec("INSERT INTO incidencias (titulo,descripcion,cliente_id,creado_por,tipo,estado,idioma) VALUES ('PROFESIONAL_CORREO','Prueba',1,2,'Correo','abierta','es')");
    $id = (int)$pdo->lastInsertId();
    [, $html] = peticion("ver_incidencia.php?id=$id",$admin); $token = csrf($html);
    foreach (['admin_operacion.php','admin_reglas.php','admin_calendario.php'] as $pagina) {
        [$codigo] = peticion($pagina,$admin); comprobar($codigo===200,"Administracion nueva: $pagina");
        [$codigo] = peticion($pagina,$agente); comprobar($codigo===403,"Operador sin acceso: $pagina");
    }
    $datos = ['csrf'=>$token,'id_incidencia'=>$id,'mensaje'=>'BORRADOR_PRIVADO','accion_respuesta'=>'nota','borrador_version'=>0];
    [$codigo,$json] = peticion('borrador_soporte.php',$admin,$datos);
    comprobar($codigo===200 && (json_decode($json,true)['version'] ?? 0)===1,'Borrador privado guardado');
    [$codigo] = peticion('borrador_soporte.php',$admin,$datos); comprobar($codigo===409,'Conflicto entre pestanas');
    [, $html] = peticion("ver_incidencia.php?id=$id",$admin); comprobar(str_contains($html,'BORRADOR_PRIVADO'),'Recuperar borrador propio');
    [, $html] = peticion("ver_incidencia.php?id=$id",$agente); comprobar(!str_contains($html,'BORRADOR_PRIVADO'),'Borrador no visible a otro tecnico');
    [, $html] = peticion("portal_ver.php?id=$id",$cliente); comprobar(!str_contains($html,'BORRADOR_PRIVADO'),'Borrador no visible al cliente');
    [$codigo] = peticion('borrador_soporte.php',$admin,array_replace($datos,['csrf'=>'falso'])); comprobar($codigo===400,'CSRF en borrador');
    $huella = soporte_huella($pdo,$id);
    $pdo->exec("UPDATE incidencias SET estado='resuelta' WHERE id=$id");
    peticion('guardar_mensaje.php',$admin,['csrf'=>$token,'id_incidencia'=>$id,'mensaje'=>'NO_SOBRESCRIBIR','huella'=>$huella,'solicitud_id'=>str_repeat('a',32)]);
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='resuelta','Respuesta no sobrescribe resolucion concurrente');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id")->fetchColumn()===0,'Respuesta concurrente no se inserta');
    $pdo->exec("UPDATE incidencias SET estado='abierta' WHERE id=$id");
    $huella = soporte_huella($pdo,$id);
    $pdo->exec("UPDATE incidencias SET asignado_id=5 WHERE id=$id");
    peticion('guardar_mensaje.php',$admin,['csrf'=>$token,'id_incidencia'=>$id,'mensaje'=>'CONFLICTO_ACTIVO','huella'=>$huella,'solicitud_id'=>str_repeat('c',32)]);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id")->fetchColumn()===0,'Huella rechaza una actualizacion concurrente aunque siga activa');
    $pdo->exec("UPDATE incidencias SET asignado_id=NULL WHERE id=$id");
    $datos = ['csrf'=>$token,'id_incidencia'=>$id,'mensaje'=>'RESPUESTA_UNICA','solicitud_id'=>str_repeat('b',32)];
    peticion('guardar_mensaje.php',$admin,$datos); peticion('guardar_mensaje.php',$admin,$datos);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id")->fetchColumn()===1,'Respuesta ordinaria idempotente');
    peticion('acciones_masivas.php',$admin,['csrf'=>$token,'ids'=>[$id],'accion_masiva'=>'estado:abierta']);
    comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn()==='abierta','Cambio masivo conserva transaccion exterior');
    $stmt = $pdo->prepare("INSERT INTO mensajes (id_incidencia,autor,mensaje,interno) VALUES (?,'cliente',?,0)");
    for ($i=0;$i<61;$i++) $stmt->execute([$id,'HISTORIAL_' . $i]);
    $pagina = soporte_mensajes($pdo,$id,false);
    comprobar(count($pagina['mensajes'])===30 && $pagina['antes']>0,'Conversacion paginada');
    $anterior = soporte_mensajes($pdo,$id,false,$pagina['antes']);
    comprobar(count($anterior['mensajes'])===30 && !array_intersect(array_column($pagina['mensajes'],'id'),array_column($anterior['mensajes'],'id')),'Cursor sin duplicados');
    [, $html] = peticion("portal_ver.php?id=$id",$cliente); comprobar(str_contains($html,'Cargar mensajes anteriores') && !str_contains($html,'HISTORIAL_0<'),'Portal pagina historial');

    $pdo->exec("UPDATE trabajos_ia SET estado='completado'");
    $trabajo = trabajos_encolar($pdo,'clasificar',['id_incidencia'=>$id]);
    $primero = trabajos_reclamar($pdo);
    comprobar((int)$primero['id']===$trabajo && strlen($primero['reserva_token'])===32,'Reserva con propietario');
    $pdo->exec("UPDATE trabajos_ia SET reservado_hasta=NOW()-INTERVAL 1 MINUTE WHERE id=$trabajo");
    comprobar(trabajos_recuperar($pdo)===1,'Recuperar trabajo interrumpido');
    $segundo = trabajos_reclamar($pdo);
    trabajos_resolver($pdo,$primero,true);
    comprobar($pdo->query("SELECT estado FROM trabajos_ia WHERE id=$trabajo")->fetchColumn()==='en_curso','Reserva antigua no puede completar la nueva');
    trabajos_resolver($pdo,$segundo,true);
    comprobar($pdo->query("SELECT estado FROM trabajos_ia WHERE id=$trabajo")->fetchColumn()==='completado','Propietario actual completa');
    $trabajoCorreo = trabajos_encolar($pdo,'correo',['destinatarios'=>['sintetico@pruebas.test'],'asunto'=>'Prueba','html'=>'Prueba']);
    $correo = trabajos_reclamar($pdo); trabajos_resolver($pdo,$correo,true);
    comprobar($pdo->query("SELECT payload FROM trabajos_ia WHERE id=$trabajoCorreo")->fetchColumn()==='{}','No conservar cuerpo tras entrega');

    peticion('admin_reglas.php',$admin,['csrf'=>$token,'accion'=>'equipo','nombre'=>'Equipo de prueba','miembros'=>[1]]);
    $equipo = (int)$pdo->query("SELECT id FROM equipos_soporte WHERE nombre='Equipo de prueba'")->fetchColumn();
    comprobar($equipo>0,'Crear equipo');
    peticion('admin_reglas.php',$admin,['csrf'=>$token,'accion'=>'crear','nombre'=>'Reparto de prueba','equipo_id'=>$equipo,'cliente_id'=>1,'tipo'=>'Correo','prioridad'=>1]);
    $regla = (int)$pdo->query("SELECT id FROM reglas_asignacion WHERE nombre='Reparto de prueba'")->fetchColumn();
    peticion('admin_reglas.php',$admin,['csrf'=>$token,'accion'=>'activar','id'=>$regla]);
    comprobar((int)$pdo->query("SELECT activo FROM reglas_asignacion WHERE id=$regla")->fetchColumn()===0,'No activar sin simular');
    peticion('admin_reglas.php',$admin,['csrf'=>$token,'accion'=>'simular','id'=>$regla]);
    peticion('admin_reglas.php',$admin,['csrf'=>$token,'accion'=>'activar','id'=>$regla]);
    comprobar((int)$pdo->query("SELECT activo FROM reglas_asignacion WHERE id=$regla")->fetchColumn()===1,'Activar despues de simulacion');
    reglas_aplicar($pdo,$id);
    comprobar((int)$pdo->query("SELECT asignado_id FROM incidencias WHERE id=$id")->fetchColumn()===1,'Asignacion automatica');
    $pdo->exec("UPDATE incidencias SET asignado_id=5 WHERE id=$id"); reglas_aplicar($pdo,$id);
    comprobar((int)$pdo->query("SELECT asignado_id FROM incidencias WHERE id=$id")->fetchColumn()===5,'No sustituir asignacion manual');

    $ticket = $pdo->query("SELECT * FROM incidencias WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("INSERT INTO conocimiento (titulo,resumen,contenido,categoria,estado,visibilidad) VALUES
        ('PROFESIONAL_CORREO','PROFESIONAL_CORREO','FUENTE_PUBLICA','Correo','publicado','clientes'),
        ('PROFESIONAL_CORREO','PROFESIONAL_CORREO','FUENTE_INTERNA','Correo','publicado','interno'),
        ('PROFESIONAL_CORREO','PROFESIONAL_CORREO','FUENTE_BORRADOR','Correo','borrador','clientes')");
    $fuentes = json_encode(asistente_fuentes($pdo,$ticket));
    comprobar(str_contains($fuentes,'FUENTE_PUBLICA') && !str_contains($fuentes,'FUENTE_INTERNA') && !str_contains($fuentes,'FUENTE_BORRADOR'),'Solo conocimiento publicado para cliente en respuesta');
    [, $json] = peticion('copiloto_incidencia.php',$admin,['csrf'=>$token,'id_incidencia'=>$id]);
    comprobar(!empty(json_decode($json,true)['insight']['fuentes']),'Fuentes del copiloto por HTTP');
    comprobar((int)$pdo->query("SELECT hasta_id FROM memoria_ia WHERE incidencia_id=$id")->fetchColumn()>0,'Resumen antiguo acumulado y acotado');
    $articulo = (int)$pdo->query("SELECT id FROM conocimiento WHERE contenido='FUENTE_PUBLICA'")->fetchColumn();
    [, $html] = peticion("ayuda.php?id=$articulo",$cliente); $csrfCliente = csrf($html);
    [$codigo] = peticion('valorar_articulo.php',$cliente,['csrf'=>$csrfCliente,'id'=>$articulo,'util'=>'0']); comprobar($codigo===302,'Valorar utilidad de guia');
    peticion('valorar_articulo.php',$cliente,['csrf'=>$csrfCliente,'id'=>$articulo,'util'=>'1']);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM conocimiento_valoraciones WHERE articulo_id=$articulo")->fetchColumn()===1,'Una valoracion por cuenta y articulo');
    $interno = (int)$pdo->query("SELECT id FROM conocimiento WHERE contenido='FUENTE_INTERNA'")->fetchColumn();
    [$codigo] = peticion('valorar_articulo.php',$cliente,['csrf'=>$csrfCliente,'id'=>$interno,'util'=>'1']); comprobar($codigo===404,'No valorar conocimiento interno como cliente');
    $pdo->exec("INSERT INTO trabajos_ia (tipo,payload,estado,actualizado_en) VALUES ('clasificar','{}','completado',NOW()-INTERVAL 400 DAY)");
    $antiguo = (int)$pdo->lastInsertId();
    peticion('admin_operacion.php',$admin,['csrf'=>$token,'accion'=>'purgar','confirmar'=>'1']);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM trabajos_ia WHERE id=$antiguo")->fetchColumn()===1,'No purgar sin previsualizacion');
    peticion('admin_operacion.php',$admin,['csrf'=>$token,'accion'=>'previsualizar']);
    peticion('admin_operacion.php',$admin,['csrf'=>$token,'accion'=>'purgar','confirmar'=>'1']);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM trabajos_ia WHERE id=$antiguo")->fetchColumn()===0,'Limpieza tecnica confirmada');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM incidencias WHERE id=$id")->fetchColumn()===1,'Limpieza no borra incidencias');

    $config = ['activo'=>true,'inicio'=>'09:00','fin'=>'17:00','dias'=>[1,2,3,4,5],'festivos'=>['2026-09-07']];
    calendario_guardar($pdo,$config);
    dominio_sla_cargar_politicas($pdo);
    $pdo->exec("UPDATE incidencias SET fecha_creacion='2026-09-04 16:00:00',urgencia='leve',estado='abierta' WHERE id=$id");
    $pdo->exec("DELETE FROM mensajes WHERE id_incidencia=$id");
    $ahora = calendario_civil('2026-09-08 10:00:00');
    $fuente = bandeja_fuente_sql($pdo,$ahora);
    $sql = $pdo->query("SELECT * FROM $fuente WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $php = dominio_sla_calcular($sql,$ahora);
    comprobar($sql['sla_estado']===$php['estado'],'SLA laboral identico PHP/SQL');
    comprobar((int)$sql['sla_restante']===(int)$php['restante_segundos'],'Tiempo restante laboral identico PHP/SQL');
    foreach (['2026-09-04 16:30:00','2026-09-05 12:00:00','2026-09-07 14:00:00','2026-09-09 16:00:00','2026-09-15 16:00:00'] as $fecha) {
        $ahora = calendario_civil($fecha);
        $fuente = bandeja_fuente_sql($pdo,$ahora);
        $sql = $pdo->query("SELECT * FROM $fuente WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        $php = dominio_sla_calcular($sql,$ahora);
        comprobar($sql['sla_estado']===$php['estado'] && (int)$sql['sla_restante']===(int)$php['restante_segundos'],'Calendario consistente: '.$fecha);
    }
    [$codigo] = peticion("index.php?busqueda=$id",$admin); comprobar($codigo===200,'Bandeja HTTP con calendario activo');
    $inicioCalendario = microtime(true);
    [$codigo] = peticion('index.php?vista=kanban',$admin); comprobar($codigo===200,'Bandeja completa con calendario laboral y volumen');
    echo 'Calendario laboral: bandeja completa en ' . round(microtime(true)-$inicioCalendario,2) . " s.\n";
    $pdo->exec("DELETE FROM ajustes WHERE clave='calendario_servicio'"); calendario_cargar($pdo);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO mensajes (id_incidencia,autor,mensaje,interno) VALUES (?,'cliente',?,0)");
    for ($i=0;$i<10000;$i++) $stmt->execute([$id,'ESCALA_' . $i . ' ' . str_repeat('x',500)]);
    $pdo->commit();
    $inicio = microtime(true);
    comprobar(count(soporte_mensajes($pdo,$id,false)['mensajes'])===30,'Pagina acotada con 10000 mensajes');
    comprobar(count(iterator_to_array(asistente_historial_reciente($pdo,[$id])))===2,'Analisis acotado con 10000 mensajes');
    [, $html] = peticion("analisis.php?stream=1&busqueda=$id",$admin);
    comprobar(str_contains($html,'Finalizado correctamente'),'Streaming completo con conversacion extensa');
    echo 'Conversacion: 10000 mensajes, pagina y analisis HTTP en ' . round(microtime(true)-$inicio,2) . " s.\n";

    // Un servidor SMTP en puerto efimero demuestra la entrega sin usar servicios reales.
    require_once $raiz . '/vendor/autoload.php';
    $proceso = proc_open([PHP_BINARY,__DIR__.'/smtp_mock.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$raiz);
    $anteriores = []; $anterioresProceso = [];
    foreach (['SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_FROM'] as $clave) {
        $anteriores[$clave] = $_ENV[$clave] ?? null;
        $anterioresProceso[$clave] = getenv($clave);
    }
    try {
        fclose($pipes[0]);
        $direccion = trim(fgets($pipes[1]));
        comprobar((bool)preg_match('/^127\.0\.0\.1:(\d+)$/',$direccion,$m),'SMTP efimero local');
        $_ENV['SMTP_HOST']='127.0.0.1'; $_ENV['SMTP_PORT']=$m[1]; $_ENV['SMTP_SECURE']='none'; $_ENV['SMTP_USER']=''; $_ENV['SMTP_FROM']='pruebas@ticketia.test';
        foreach (array_keys($anteriores) as $clave) putenv($clave . '=' . $_ENV[$clave]);
        comprobar(correo_enviar_directo(['destino@pruebas.test'],'Prueba aislada','Mensaje sintetico','prueba-estable'),'Entrega SMTP real a receptor ficticio');
        fclose($pipes[1]); fclose($pipes[2]);
        comprobar(proc_close($proceso)===0,'SMTP acepto DATA'); $proceso = null;
        $pdo->exec("UPDATE trabajos_ia SET estado='completado'");
        comprobar(correo_enviar(['destino@pruebas.test'],'Cola de prueba','Mensaje sintetico'),'Correo encolado sin esperar SMTP');
        comprobar((int)$pdo->query("SELECT COUNT(*) FROM trabajos_ia WHERE tipo='correo' AND estado='pendiente'")->fetchColumn()===1,'Entrega pendiente registrada');
    } finally {
        if (is_resource($proceso)) { proc_terminate($proceso); proc_close($proceso); }
        foreach ($anteriores as $clave=>$valor) { if ($valor===null) unset($_ENV[$clave]); else $_ENV[$clave]=$valor; }
        foreach ($anterioresProceso as $clave=>$valor) putenv($valor===false ? $clave : $clave . '=' . $valor);
    }
}
