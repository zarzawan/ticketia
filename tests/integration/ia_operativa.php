<?php
function probar_ia_operativa(PDO $pdo, string $cliente): void {
    $empresa = (int)$pdo->query("SELECT id FROM clientes WHERE nombre='PREDICCIONES_AISLADAS'")->fetchColumn();
    $agente = iniciar('agente5@pruebas.test');
    [, $html] = peticion('ver_incidencia.php?id=1',$agente);
    [$codigo] = peticion('admin_ia_operativa.php',$agente,['modo'=>'reparto','csrf'=>csrf($html)]);
    comprobar($codigo===403,'Operador sin acceso a IA administrativa');
    [$codigo] = peticion('admin_ia_operativa.php',$cliente); comprobar($codigo===403,'Cliente sin acceso a predicciones IA');
    foreach (['prediccion','respuesta','reparto'] as $modo) {
        $admin = iniciar('admin@pruebas.test');
        [, $html] = peticion('respuestas.php?editar=recepcion',$admin);
        $datos = ['modo'=>$modo,'csrf'=>csrf($html),'cliente'=>$empresa,'dias'=>90,'titulo'=>'Recepcion','contenido'=>'Hemos recibido tu consulta.'];
        $antes = (int)$pdo->query('SELECT COUNT(*) FROM respuestas_reutilizables')->fetchColumn();
        [$codigo,$json] = peticion('admin_ia_operativa.php',$admin,$datos); $r = json_decode($json,true);
        comprobar($codigo===200 && ($r['ok'] ?? false) && !empty($r['datos'][$modo==='respuesta' ? 'contenido' : 'resumen']),"Consulta IA valida: $modo");
        comprobar((int)$pdo->query('SELECT COUNT(*) FROM respuestas_reutilizables')->fetchColumn()===$antes,'La IA no guarda respuestas ni aplica propuestas');
        [$codigo,$json] = peticion('admin_ia_operativa.php',$admin,$datos);
        comprobar($codigo===200 && (json_decode($json,true)['cache'] ?? false),'Reutiliza propuesta sin repetir coste');
        $datos['csrf']='invalido'; [$codigo] = peticion('admin_ia_operativa.php',$admin,$datos); comprobar($codigo===400,'IA exige CSRF');
    }
    $admin = iniciar('admin@pruebas.test');
    [, $html] = peticion('respuestas.php?editar=recepcion',$admin);
    [$codigo,$json] = peticion('admin_ia_operativa.php',$admin,['modo'=>'respuesta','titulo'=>'Prueba','contenido'=>'IA_FALLO_PRUEBA','csrf'=>csrf($html)]);
    comprobar($codigo===502 && !(json_decode($json,true)['ok'] ?? true),'Salida IA no estructurada falla de forma controlada');
    [, $html] = peticion('admin_reglas.php',$admin);
    comprobar(str_contains($html,'Reparto del trabajo') && str_contains($html,'Crear equipo') && str_contains($html,'Proponer organizacion con IA'),'Equipos y reparto muestra contenido y asistencia');
    $pdo->exec('RENAME TABLE equipos_miembros TO miembros_prueba_ausente');
    try {
        [$codigo,$html] = peticion('admin_reglas.php',$admin);
        comprobar($codigo===200 && str_contains($html,'necesita la migracion 14'),'Esquema parcial muestra instrucciones en lugar de pagina vacia');
    } finally { $pdo->exec('RENAME TABLE miembros_prueba_ausente TO equipos_miembros'); }
}
