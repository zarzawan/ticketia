<?php
// Extension del banco aislado: volumen, equivalencia SLA y actividad real del CLI.
function probar_operacion(PDO $pdo, string $admin, array $entorno, string $preparacion, string $raiz): void {
    require_once $raiz . '/src/entorno.php';
    require_once $raiz . '/src/dominio.php';
    require_once $raiz . '/src/calendario.php';
    require_once $raiz . '/src/bandeja.php';
    require_once $raiz . '/src/trabajos.php';
    comprobar(str_starts_with((string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'ticketia_pruebas_'), 'Solo base de pruebas');
    $pdo->exec("UPDATE clientes SET nivel_servicio = 'premium' WHERE id = 2");
    $pdo->exec("INSERT INTO sla_politicas (nivel_cliente,tipo_incidencia,urgencia,primera_respuesta_horas,resolucion_horas,activo)
        VALUES ('premium','Correo','critico',2,6,1) ON DUPLICATE KEY UPDATE primera_respuesta_horas=2,resolucion_horas=6,activo=1");
    dominio_sla_cargar_politicas($pdo);
    $ahora = new DateTimeImmutable('2026-09-06 12:00:00');
    $insertar = $pdo->prepare('INSERT INTO incidencias (titulo,descripcion,urgencia,tipo,cliente_id,estado,fecha_creacion,fecha_resolucion,fecha_cierre) VALUES (?,?,?,?,?,?,?,?,?)');
    $mensaje = $pdo->prepare("INSERT INTO mensajes (id_incidencia,autor,mensaje,interno,fecha) VALUES (?,'tecnico','Prueba SLA',?,?)");
    // Incluye el borde de redondeo, respuesta tardia, nota privada y cierre congelado.
    foreach (['critico', 'urgente', 'leve'] as $urgencia) foreach ([1,2,null] as $clienteId) foreach (['Correo','Software y apps'] as $tipo) foreach (['abierta','resuelta','cerrada'] as $estado) foreach (['pendiente','publica','interna'] as $modo) {
        $objetivos = dominio_sla_objetivos($urgencia, $tipo, $clienteId === 2 ? 'premium' : 'estandar');
        $segundos = $modo === 'pendiente' ? (int)ceil($objetivos['primera_respuesta'] * 3600 * .745) : $objetivos['resolucion'] * 3600 + 1;
        $creada = $ahora->modify("-$segundos seconds");
        $final = $estado === 'abierta' ? null : $creada->modify('+30 minutes')->format('Y-m-d H:i:s');
        $insertar->execute(['MATRIZ_SLA','Prueba',$urgencia,$tipo,$clienteId,$estado,$creada->format('Y-m-d H:i:s'),$final,$estado === 'cerrada' ? $final : null]);
        $id = (int)$pdo->lastInsertId();
        if ($modo !== 'pendiente') $mensaje->execute([$id, $modo === 'interna' ? 1 : 0, $creada->modify('+' . ($objetivos['primera_respuesta'] * 3600 + 1) . ' seconds')->format('Y-m-d H:i:s')]);
    }
    $fuente = bandeja_fuente_sql($pdo, $ahora);
    $filas = $pdo->query("SELECT * FROM $fuente WHERE titulo = 'MATRIZ_SLA'")->fetchAll(PDO::FETCH_ASSOC);
    comprobar(count($filas) === 162, 'Matriz completa de SLA');
    foreach ($filas as $fila) {
        $sla = dominio_sla_calcular($fila, $ahora);
        comprobar($sla['estado'] === $fila['sla_estado'] && $sla['restante_segundos'] === (int)$fila['sla_restante']
            && dominio_prioridad_operativa($fila, $ahora) === (int)$fila['prioridad_operativa'], 'Equivalencia SQL/dominio SLA #' . $fila['id']);
    }
    $pdo->exec("DELETE FROM incidencias WHERE titulo = 'MATRIZ_SLA'");
    $antigua = (new DateTimeImmutable())->modify('-3 days')->format('Y-m-d H:i:s');
    $insertar->execute(['VOLUMEN_ANTIGUA_EN_RIESGO','Prueba','critico','Correo',1,'abierta',$antigua,null,null]);
    $idAntigua = (int)$pdo->lastInsertId();
    $ahoraTexto = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    for ($i = 0; $i < 1001; $i++) $insertar->execute(['VOLUMEN_RECIENTE_' . $i,'Prueba','leve','Correo',1,'abierta',$ahoraTexto,null,null]);
    for ($i = 0; $i < 2500; $i++) $insertar->execute(['VOLUMEN_CERRADA_' . $i,'Prueba','critico','Correo',1,'cerrada',$antigua,$ahoraTexto,$ahoraTexto]);
    $pdo->commit();
    $inicio = microtime(true);
    [, $html] = peticion('index.php?cola=sla&busqueda=VOLUMEN_', $admin);
    comprobar(str_contains($html, 'VOLUMEN_ANTIGUA_EN_RIESGO') && str_contains($html, 'Mostrando 1 de 1'), 'SLA encuentra la antigua mas alla de 500 filas y cuenta bien');
    [, $html] = peticion('index.php?cola=sla&vista=kanban&busqueda=VOLUMEN_', $admin);
    comprobar(str_contains($html, 'VOLUMEN_ANTIGUA_EN_RIESGO'), 'Kanban SLA sin perdida por limite');
    [, $html] = peticion('index.php?vista=lista&busqueda=VOLUMEN_&limite=20&pagina=9999', $admin);
    comprobar(str_contains($html, 'Pagina 51 de 51') && str_contains($html, 'VOLUMEN_ANTIGUA_EN_RIESGO'), 'Ultima pagina accesible y acotada');
    [, $html] = peticion('index.php?busqueda=VOLUMEN_&vista=kanban&limite=20&pagina_abierta=9999', $admin);
    comprobar(str_contains($html, 'Pagina 51 de 51') && str_contains($html, 'VOLUMEN_ANTIGUA_EN_RIESGO'), 'Kanban pagina por columna');
    foreach (['prioridad&direccion=desc','sla&direccion=asc'] as $orden) {
        [, $html] = peticion('index.php?vista=lista&busqueda=VOLUMEN_&limite=20&orden_columna=' . $orden, $admin);
        comprobar(str_contains($html, 'VOLUMEN_ANTIGUA_EN_RIESGO'), 'Orden global antes de paginar: ' . $orden);
    }
    [, $csv] = peticion('exportar_csv.php?cola=sla&busqueda=VOLUMEN_', $admin);
    comprobar(str_contains($csv, 'VOLUMEN_ANTIGUA_EN_RIESGO') && !str_contains($csv, 'VOLUMEN_RECIENTE_'), 'CSV respeta la cola SLA');
    echo 'Volumen: 1002 activas y 2500 cerradas, 7 consultas HTTP en ' . round(microtime(true) - $inicio, 2) . " s.\n";
    // No confundir una cola vacia ni el boton manual con un servicio arrancado.
    [, $html] = peticion('admin_ajustes.php', $admin);
    comprobar(str_contains($html, 'Sin senal registrada'), 'Administracion no inventa actividad');
    [, $html] = peticion('admin_ajustes.php', $admin, ['accion'=>'procesar_cola','csrf'=>csrf($html)]);
    comprobar(str_contains($html, 'Sin senal registrada'), 'Procesado manual no registra actividad automatica');
    $log = tempnam(sys_get_temp_dir(), 'ticketia_worker_');
    $proceso = null;
    try {
        $comando = [PHP_BINARY, '-d', $preparacion, 'bin/worker.php', '--lote=1'];
        $proceso = proc_open($comando, [0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$log,'a']], $pipes, $raiz, $entorno);
        fclose($pipes[0]);
        comprobar(proc_close($proceso) === 0, 'Worker puntual termina correctamente'); $proceso = null;
        $salud = trabajos_worker_salud($pdo);
        comprobar($salud['estado'] === 'reciente' && str_contains($salud['detalle'], 'puntual'), 'Worker CLI real registra actividad');
        // Una senal antigua debe generar advertencia aunque no haya trabajos pendientes.
        $pdo->exec("UPDATE ajustes SET valor = JSON_OBJECT('fecha', UNIX_TIMESTAMP()-90000, 'modo','bucle') WHERE clave='worker_ultimo_latido'");
        [, $html] = peticion('admin_inicio.php', $admin);
        comprobar(str_contains($html, 'Sin actividad reciente'), 'Advertencia visible por senal caducada');
        $comando[] = '--bucle';
        $proceso = proc_open($comando, [0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$log,'a']], $pipes, $raiz, $entorno);
        fclose($pipes[0]);
        for ($i = 0; $i < 40; $i++) { if (trabajos_worker_salud($pdo)['estado'] === 'reciente') break; usleep(100000); }
        $salud = trabajos_worker_salud($pdo);
        comprobar($salud['estado'] === 'reciente' && str_contains($salud['detalle'], 'servicio'), 'Servicio registra senal con cola vacia');
        comprobar((int)$pdo->query("SELECT COUNT(*) FROM ajustes WHERE clave='worker_ultimo_latido'")->fetchColumn() === 1, 'Actividad sin acumular registros');
    } finally {
        if (is_resource($proceso)) { proc_terminate($proceso); proc_close($proceso); }
        if (is_file($log)) unlink($log);
    }
}
