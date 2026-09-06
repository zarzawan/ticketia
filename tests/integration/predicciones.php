<?php
function probar_predicciones(PDO $pdo, string $admin, string $cliente, string $agente): void {
    $agente = iniciar('agente5@pruebas.test');
    require_once __DIR__ . '/../../src/predicciones.php';
    $pdo->exec("INSERT INTO clientes (nombre) VALUES ('PREDICCIONES_AISLADAS')");
    $empresa = (int)$pdo->lastInsertId();
    $ids = [];
    $stmt = $pdo->prepare("INSERT INTO incidencias (cliente_id,titulo,descripcion,estado,tipo,fecha_creacion) VALUES (?,'PREDICCION_SINTETICA','Datos de prueba','cerrada','Comercial',NOW() - INTERVAL 2 DAY)");
    for ($i=0;$i<27;$i++) { $stmt->execute([$empresa]); $ids[] = (int)$pdo->lastInsertId(); }
    foreach (array_slice($ids,0,3) as $ticket) $pdo->exec("INSERT INTO satisfaccion_servicio (incidencia_id,usuario_id,puntuacion) VALUES ($ticket,2,5)");
    $ahora = new DateTimeImmutable();
    $m = predicciones_metricas($pdo,[$empresa],90,$ahora)[$empresa];
    comprobar((int)$m['recientes']===27 && (int)$m['historicas']===27 && (int)$m['valoradas']===3,'Metricas por empresa sin multiplicar filas');
    comprobar((float)$m['satisfaccion']===5.0 && predicciones_evaluar($m)['oportunidad']==='Revisar necesidades de ampliacion','Oportunidad explicable calculada con datos SQL');
    comprobar(array_sum(predicciones_serie($pdo,$empresa,90,$ahora))===27,'Serie mensual coherente con periodo');
    [$codigo,$html]=peticion("admin_predicciones.php?cliente=$empresa",$admin);
    comprobar($codigo===200 && str_contains($html,'Evolucion de incidencias') && str_contains($html,'Estado del historico'),'Graficos e historico accesibles para administrador');
    comprobar(substr_count($html,'PREDICCION_SINTETICA')===25 && !str_contains($html,'NO_VISIBLE_OTRA_EMPRESA'),'Historico limitado y aislado por cliente');
    preg_match('/&amp;antes=(\d+)/',$html,$cursor);
    comprobar(isset($cursor[1]),'Cursor del historico disponible');
    [, $segunda]=peticion("admin_predicciones.php?cliente=$empresa&antes=".$cursor[1],$admin);
    comprobar(substr_count($segunda,'PREDICCION_SINTETICA')===2,'Segunda pagina sin duplicados');
    foreach ([$cliente,$agente] as $jar) { [$codigo]=peticion('admin_predicciones.php',$jar); comprobar($codigo===403,'Predicciones comerciales solo para administracion'); }
    [$codigo]=peticion('admin_predicciones.php?cliente=2147483647',$admin); comprobar($codigo===404,'Empresa inexistente no abre cartera completa');
    [, $html]=peticion('admin_predicciones.php?q=NINGUNA_EMPRESA_COINCIDE',$admin); comprobar(str_contains($html,'No hay organizaciones'),'Estado vacio sin predicciones ficticias');
    [, $html]=peticion('index.php?filtro_asignado=sin_asignar&cola=accion',$admin);
    comprobar(str_contains($html,'data-cola="todas"') && !str_contains($html,"localStorage.getItem('incidencias_filtros')"),'Colas completas sin filtros restaurados de otra cuenta');
    $totales = bandeja_contar_colas($pdo,bandeja_fuente_sql($pdo),['busqueda'=>'PREDICCION_SINTETICA']);
    comprobar(array_sum($totales)===0,'Las cerradas no inflan ningun contador de cola');
    $pdo->exec("UPDATE incidencias SET estado='abierta',fecha_creacion=NOW() WHERE id=".$ids[0]);
    $totales = bandeja_contar_colas($pdo,bandeja_fuente_sql($pdo),['busqueda'=>'PREDICCION_SINTETICA','asignado'=>99999,'estado'=>'cerrada']);
    comprobar($totales['todas']===1 && $totales['accion']===1 && $totales['sin_asignar']===1 && $totales['confirmar']===0,'Contadores conservan busqueda y eliminan estado/responsable anterior');
}
