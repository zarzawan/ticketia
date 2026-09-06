<?php

function operacion_retencion(PDO $pdo): array {
    return [
        'trabajos' => dominio_ajuste_entero($pdo,'retencion_trabajos',90,7,3650),
        'auditoria' => dominio_ajuste_entero($pdo,'retencion_auditoria',0,0,3650),
    ];
}

function operacion_estimar(PDO $pdo): array {
    $politica = operacion_retencion($pdo);
    $resultado = [];
    foreach (['trabajos'=>['trabajos_ia','actualizado_en',"estado = 'completado'"], 'auditoria'=>['auditoria','fecha','1=1']] as $clave=>[$tabla,$fecha,$filtro]) {
        $dias = $politica[$clave];
        $stmt = $pdo->query("SELECT COUNT(*) AS cantidad FROM $tabla WHERE $filtro" . ($dias > 0 ? " AND $fecha < NOW() - INTERVAL $dias DAY" : ' AND 1=0'));
        $resultado[$clave] = (int)$stmt->fetchColumn();
    }
    return $resultado;
}

function operacion_alertas(PDO $pdo): array {
    $alertas = [];
    if (!correo_activo()) $alertas[] = ['titulo'=>'Correo sin configurar','detalle'=>'Las notificaciones externas estan desactivadas.','url'=>'admin_ajustes.php'];
    $restauracion = $pdo->query("SELECT valor FROM ajustes WHERE clave='restauracion_declarada'")->fetchColumn();
    if (!$restauracion || strtotime($restauracion) < time()-90*86400) $alertas[] = ['titulo'=>'Restauracion sin comprobar recientemente','detalle'=>'Registra una prueba real de recuperacion en un entorno aislado.','url'=>'admin_operacion.php'];
    $fechaDb = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    if (abs(calendario_civil($fechaDb)->getTimestamp()-calendario_civil(date('Y-m-d H:i:s'))->getTimestamp()) > 60) {
        $alertas[] = ['titulo'=>'Relojes del servicio desalineados','detalle'=>'Revisa la hora y zona de PHP y MySQL antes de confiar en los SLA.','url'=>'admin_calendario.php'];
    }
    if (!soporte_esquema_disponible($pdo) || !reglas_disponibles($pdo)) $alertas[] = ['titulo'=>'Migraciones pendientes','detalle'=>'Hay funciones nuevas que requieren actualizar el esquema.','url'=>'admin_ajustes.php'];
    $worker = trabajos_worker_salud($pdo);
    if ($worker['estado'] !== 'reciente') $alertas[] = ['titulo'=>$worker['etiqueta'],'detalle'=>$worker['detalle'],'url'=>'admin_ajustes.php'];
    $filas = $pdo->query("SELECT tipo, COUNT(*) AS total FROM trabajos_ia WHERE estado='fallido' GROUP BY tipo")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $fila) $alertas[] = ['titulo'=>(int)$fila['total'] . ($fila['tipo']==='correo' ? ' correos fallidos' : ' tareas de IA fallidas'),'detalle'=>'Revisa el motivo antes de reintentar.','url'=>'admin_operacion.php'];
    if (trabajos_reservas_disponibles($pdo)) {
        $atascados = (int)$pdo->query("SELECT COUNT(*) FROM trabajos_ia WHERE estado='en_curso' AND (reservado_hasta < NOW() OR (reservado_hasta IS NULL AND actualizado_en < NOW() - INTERVAL 15 MINUTE))")->fetchColumn();
        if ($atascados) $alertas[] = ['titulo'=>"$atascados tareas interrumpidas",'detalle'=>'El worker recupera automaticamente las reservas vencidas.','url'=>'admin_operacion.php'];
    }
    $fuente = bandeja_fuente_sql($pdo);
    $sla = (int)$pdo->query("SELECT COUNT(*) FROM $fuente WHERE estado IN ('abierta','en_curso') AND sla_estado='vencido'")->fetchColumn();
    if ($sla) $alertas[] = ['titulo'=>"$sla incidencias con SLA vencido",'detalle'=>'Prioriza las respuestas y resoluciones pendientes.','url'=>'index.php?cola=sla'];
    return $alertas;
}
