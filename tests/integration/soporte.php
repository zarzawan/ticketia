<?php
// Flujos nuevos sobre la base desechable del banco HTTP.
function probar_soporte(PDO $pdo, string $admin, string $cliente): void {
    $pdo->exec("INSERT INTO incidencias (titulo,descripcion,cliente_id,creado_por,estado,idioma)
        VALUES ('SOPORTE_PRUEBA','Descripcion de prueba',1,2,'abierta','es')");
    $id = (int)$pdo->lastInsertId();
    $agente = iniciar('agente5@pruebas.test');
    [, $html] = peticion('index.php?busqueda=SOPORTE_PRUEBA', $agente);
    comprobar(str_contains($html, 'id="kanbanBoard"'), 'Kanban por defecto para el equipo');
    comprobar(strpos($html, "class='kanban-assignment'") < strpos($html, "class='kanban-controls-disclosure'"), 'Asignacion fuera del desplegable');
    $token = csrf($html);
    [$codigo, $json] = peticion('asignar_incidencia.php', $agente, ['id_incidencia'=>$id,'asignado'=>1,'ajax'=>'1','csrf'=>$token]);
    comprobar($codigo === 200 && (json_decode($json,true)['ok'] ?? false) && (int)$pdo->query("SELECT asignado_id FROM incidencias WHERE id=$id")->fetchColumn() === 1, 'Asignacion desde tarjeta guardada');
    [, $html] = peticion("ver_incidencia.php?id=$id", $agente);
    comprobar(substr_count($html, '<textarea name="mensaje"') === 1 && !str_contains($html, 'name="resolucion_notas"'), 'Un unico editor de respuesta y solucion');
    [$codigo] = peticion('guardar_mensaje.php', $agente, ['id_incidencia'=>$id,'mensaje'=>'NOTA_PRIVADA_SOPORTE','accion_respuesta'=>'nota','csrf'=>$token]);
    comprobar($codigo === 302 && $pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn() === 'abierta', 'Nota interna no resuelve ni inicia el trabajo');
    [, $html] = peticion("portal_ver.php?id=$id", $cliente);
    comprobar(!str_contains($html, 'NOTA_PRIVADA_SOPORTE'), 'Nota interna nunca visible en el portal');
    $datos = ['id_incidencia'=>$id,'mensaje'=>'SOLUCION_VISIBLE_Y_PERSISTENTE','accion_respuesta'=>'resolver','csrf'=>$token];
    [$codigo] = peticion('guardar_mensaje.php', $agente, $datos);
    comprobar($codigo === 302 && $pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn() === 'resuelta', 'Proponer solucion desde editor');
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id AND interno=0 AND mensaje LIKE '%SOLUCION_VISIBLE_Y_PERSISTENTE%'")->fetchColumn() === 1, 'Solucion almacenada como mensaje publico');
    foreach (['ver_incidencia.php','portal_ver.php'] as $pagina) {
        [, $html] = peticion("$pagina?id=$id", $pagina === 'portal_ver.php' ? $cliente : $agente);
        comprobar(str_contains($html,'Solucion propuesta:') && str_contains($html,'SOLUCION_VISIBLE_Y_PERSISTENTE'), "Solucion visible: $pagina");
    }
    peticion('guardar_mensaje.php', $agente, $datos);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id AND interno=0")->fetchColumn() === 1, 'Doble envio no duplica la solucion');
    peticion('reabrir_incidencia.php', $agente, ['id_incidencia'=>$id,'motivo'=>'Comprobar persistencia','csrf'=>$token]);
    [, $html] = peticion("ver_incidencia.php?id=$id", $agente);
    comprobar(str_contains($html,'SOLUCION_VISIBLE_Y_PERSISTENTE') && $pdo->query("SELECT resolucion_notas FROM incidencias WHERE id=$id")->fetchColumn() === null, 'Reapertura conserva el comentario aunque limpie los datos de resolucion');
    [, $html] = peticion("portal_ver.php?id=$id", $cliente);
    [$codigo] = peticion('guardar_mensaje.php', $cliente, ['id_incidencia'=>$id,'mensaje'=>'No permitido','accion_respuesta'=>'resolver','csrf'=>csrf($html)]);
    comprobar($codigo === 400 && $pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn() === 'en_curso', 'Cliente no puede usar la accion del equipo');
    // Un fallo al guardar el comentario debe deshacer tambien la transicion.
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM cambios_estado WHERE id_incidencia=$id")->fetchColumn();
    $pdo->exec("CREATE TRIGGER prueba_fallo_solucion BEFORE INSERT ON mensajes FOR EACH ROW
        BEGIN IF NEW.mensaje LIKE '%FALLO_ATOMICO%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo simulado'; END IF; END");
    try {
        peticion('cerrar_incidencia.php', $agente, ['id_incidencia'=>$id,'resolucion_codigo'=>'solucion_permanente','resolucion_notas'=>'FALLO_ATOMICO','csrf'=>$token]);
        comprobar($pdo->query("SELECT estado FROM incidencias WHERE id=$id")->fetchColumn() === 'en_curso'
            && (int)$pdo->query("SELECT COUNT(*) FROM cambios_estado WHERE id_incidencia=$id")->fetchColumn() === $antes, 'Solucion y comentario atomicos ante fallo');
    } finally { $pdo->exec('DROP TRIGGER prueba_fallo_solucion'); }
    peticion('cerrar_incidencia.php', $agente, ['id_incidencia'=>$id,'resolucion_codigo'=>'solucion_permanente','resolucion_notas'=>'SOLUCION_ENDPOINT_ANTERIOR','csrf'=>$token]);
    comprobar((int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE id_incidencia=$id AND mensaje LIKE '%SOLUCION_ENDPOINT_ANTERIOR%'")->fetchColumn() === 1, 'Endpoint anterior tambien registra el comentario');
}
