<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin');
require_once __DIR__ . '/../src/predicciones.php';
$ahora = new DateTimeImmutable();
$dias = in_array((int)($_GET['dias'] ?? 90), [30,90,180], true) ? (int)($_GET['dias'] ?? 90) : 90;
$id = max(0, (int)($_GET['cliente'] ?? 0));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 180);
$params = []; $condicion = '1=1';
if ($id) { $condicion .= ' AND id = :id'; $params[':id'] = $id; }
elseif ($q !== '') { $condicion .= ' AND nombre LIKE :q'; $params[':q'] = '%' . $q . '%'; }
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE $condicion"); $stmt->execute($params); $total = (int)$stmt->fetchColumn();
if ($id && !$total) { http_response_code(404); exit('Organizacion no encontrada.'); }
$pagina = min(max(1, (int)($_GET['pagina'] ?? 1)), max(1, (int)ceil($total / 25)));
$offset = ($pagina - 1) * 25;
$stmt = $pdo->prepare("SELECT id,nombre,activo FROM clientes WHERE $condicion ORDER BY nombre,id LIMIT 25 OFFSET $offset");
$stmt->execute($params); $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$metricas = predicciones_metricas($pdo, array_column($clientes,'id'), $dias, $ahora);
$serie = $id ? predicciones_serie($pdo, $id, $dias, $ahora) : [];
$historial = []; $siguiente = 0;
if ($id) {
    $antes = max(0, (int)($_GET['antes'] ?? 0));
    $sql = 'SELECT id,titulo,estado,tipo,fecha_creacion FROM incidencias WHERE cliente_id = :cliente';
    $parametros = [':cliente'=>$id];
    if ($antes) { $sql .= ' AND id < :antes'; $parametros[':antes'] = $antes; }
    $stmt = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 26'); $stmt->execute($parametros);
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($historial) > 25) { array_pop($historial); $siguiente = (int)end($historial)['id']; }
}
ui_admin_cabecera('Predicciones', $id ? $clientes[0]['nombre'] . ' · Historico y calidad del servicio' : 'Senales de retencion y oportunidades por organizacion.', 'admin_predicciones.php');
?>
<section class="admin-panel">
    <p><strong>Indicadores orientativos, no probabilidades de baja o compra.</strong> Reglas explicables basadas en soporte; no existe aun un modelo entrenado con bajas y ventas reales. No se contacta ni se cambia ningun cliente automaticamente.</p>
    <form method="GET" class="page-tools">
        <?php if ($id): ?><input type="hidden" name="cliente" value="<?= $id ?>"><a href="admin_predicciones.php">Todas las organizaciones</a><?php else: ?><label>Organizacion <input name="q" value="<?= ui_e($q) ?>" placeholder="Buscar por nombre"></label><?php endif; ?>
        <label>Periodo <select name="dias"><?php foreach ([30,90,180] as $opcion): ?><option value="<?= $opcion ?>" <?= $dias === $opcion ? 'selected' : '' ?>>Ultimos <?= $opcion ?> dias</option><?php endforeach; ?></select></label><button class="card-button">Aplicar</button>
    </form>
    <p>Calculado: <?= ui_e($ahora->format('d/m/Y H:i')) ?>. Incidencias creadas en el periodo; comparacion con los <?= $dias ?> dias anteriores. Los pendientes y SLA vencidos incluyen todo el historico activo.</p>
</section>
<?php if (!$clientes): ?><section class="admin-panel"><p>No hay organizaciones que coincidan con la busqueda.</p></section><?php endif; ?>
<?php if (!$id && $clientes): ?>
<section class="admin-panel"><h2>Cartera de clientes</h2><p>Ordenada por nombre. Abre una organizacion para ver sus graficos, historico, motivos y analisis IA.</p><div class="table-scroll"><table class="logs-table"><thead><tr><th>Organizacion</th><th>Incidencias del periodo</th><th>Riesgo orientativo</th><th>Satisfaccion</th><th>Seguimiento propuesto</th></tr></thead><tbody>
<?php foreach ($clientes as $c): $m=$metricas[(int)$c['id']] ?? []; $p=predicciones_evaluar($m); ?><tr><td><a href="?cliente=<?= (int)$c['id'] ?>&amp;dias=<?= $dias ?>"><?= ui_e($c['nombre']) ?></a><?= !(int)$c['activo'] ? ' (inactiva)' : '' ?></td><td><?= (int)($m['recientes'] ?? 0) ?></td><td><?= ui_e($p['riesgo']) ?></td><td><?= isset($m['satisfaccion']) ? number_format((float)$m['satisfaccion'],1) . '/5' : 'Sin datos' ?> · <?= (int)($m['valoradas'] ?? 0) ?> valoradas</td><td><?= ui_e($p['oportunidad']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php if ($id): ?>
<?php foreach ($clientes as $c): $m = $metricas[(int)$c['id']] ?? []; $p = predicciones_evaluar($m); ?>
<section class="admin-panel prediction-card">
    <div class="section-head"><h2><a href="admin_predicciones.php?cliente=<?= (int)$c['id'] ?>&amp;dias=<?= $dias ?>"><?= ui_e($c['nombre']) ?></a></h2><span><?= (int)$c['activo'] ? 'Organizacion activa' : 'Organizacion inactiva (no equivale a baja comercial)' ?></span></div>
    <div class="prediction-metrics">
        <div><small>Riesgo de desvinculacion</small><strong><?= ui_e($p['riesgo']) ?></strong><span><?= $p['puntos'] === null ? 'Minimo: 5 incidencias en el periodo' : $p['puntos'] . '/100 puntos de alerta, no porcentaje' ?></span></div>
        <div><small>Calidad percibida</small><strong><?= isset($m['satisfaccion']) ? number_format((float)$m['satisfaccion'],1) . '/5' : 'Sin valoraciones' ?></strong><span><?= (int)($m['valoradas'] ?? 0) ?> incidencias valoradas · cobertura <?= (int)$p['cobertura'] ?> %</span></div>
        <div><small>Actividad del periodo</small><strong><?= (int)($m['recientes'] ?? 0) ?> incidencias</strong><span><?= (int)($m['anteriores'] ?? 0) ?> en el periodo anterior; mas volumen no implica interes de compra</span></div>
        <div><small>Trabajo pendiente</small><strong><?= (int)($m['activas'] ?? 0) ?> activas</strong><span><?= (int)($m['vencidas'] ?? 0) ?> con SLA vencido · <?= (int)($m['historicas'] ?? 0) ?> en todo el historico</span></div>
    </div>
    <p><strong>Oportunidad:</strong> <?= ui_e($p['oportunidad']) ?>. <?= (int)($m['comerciales'] ?? 0) ?> consultas comerciales o de cambios; requieren revision humana.</p>
    <p><strong>Siguiente accion:</strong> <?= ui_e($p['accion']) ?></p>
    <?php if ($p['motivos']): ?><ul><?php foreach ($p['motivos'] as $motivo): ?><li><?= ui_e($motivo) ?></li><?php endforeach; ?></ul><?php else: ?><p>No se han detectado alertas con estas reglas. Esto no garantiza la continuidad del cliente.</p><?php endif; ?>
    <p><?= (int)($m['reabiertas'] ?? 0) ?> incidencias reabiertas del periodo · Primera respuesta media: <?= isset($m['respuesta_horas']) ? number_format((float)$m['respuesta_horas'],1) . ' horas naturales' : 'sin datos' ?> (<?= (int)($m['respondidas'] ?? 0) ?> incidencias respondidas; no incluye las que siguen sin respuesta).</p>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?= ui_paginacion($pagina, $total, 25, ['q'=>$q,'dias'=>$dias,'cliente'=>$id ?: '']) ?>
<?php if ($id): ?>
<section class="admin-panel" data-ia-operativa="prediccion" data-cliente="<?= $id ?>" data-dias="<?= $dias ?>" data-csrf="<?= ui_e(csrf_token()) ?>"><h2>Analista IA del cliente</h2><p>Interpreta las senales y propone un plan de seguimiento. Solo se consultan las metricas agregadas de esta organizacion, sin nombres, mensajes ni adjuntos, con el proveedor IA configurado. No calcula probabilidades validadas ni ejecuta acciones.</p><button type="button" class="card-button" data-generar>Analizar con IA</button><p class="reusable-preview" data-salida role="status">Genera el analisis cuando lo necesites. Las propuestas pueden contener errores: contrastalas con los indicadores y el historico.</p></section>
<script src="ia_operativa.js?v=<?= filemtime(__DIR__ . '/ia_operativa.js') ?>" defer></script>
<section class="admin-panel"><h2>Estado del historico</h2><p>Distribucion actual de todas las incidencias de esta organizacion, incluidas las archivadas.</p>
    <?php $m = $metricas[$id] ?? []; $estados_grafico = ['Abiertas'=>(int)($m['abiertas'] ?? 0), 'En curso'=>(int)($m['en_curso'] ?? 0), 'Esperando al cliente'=>(int)($m['esperando_cliente'] ?? 0), 'Resueltas'=>(int)($m['resueltas'] ?? 0), 'Cerradas'=>(int)($m['cerradas'] ?? 0)]; $maximo = max(1, ...array_values($estados_grafico)); ?>
    <div class="prediction-chart" role="group" aria-label="Incidencias por estado">
    <?php foreach ($estados_grafico as $estado_grafico=>$cantidad): ?><div class="prediction-bar"><strong><?= $cantidad ?></strong><div class="prediction-bar-track" aria-hidden="true"><span style="height:<?= (int)round(100*$cantidad/$maximo) ?>%"></span></div><small><?= ui_e($estado_grafico) ?></small></div><?php endforeach; ?>
    </div>
</section>
<section class="admin-panel"><h2>Evolucion de incidencias</h2><p>Altas por mes dentro del periodo elegido. Primer y ultimo mes pueden ser parciales; no es una proyeccion futura.</p>
    <div class="prediction-chart" role="img" aria-label="Incidencias creadas por mes. Valores detallados a continuacion.">
    <?php $maximo = max(1, ...array_values($serie)); foreach ($serie as $mes=>$cantidad): ?>
        <div class="prediction-bar"><strong><?= $cantidad ?></strong><div class="prediction-bar-track"><span style="height:<?= (int)round(100*$cantidad/$maximo) ?>%"></span></div><small><?= ui_e($mes) ?></small></div>
    <?php endforeach; ?></div>
    <details><summary>Ver valores del grafico</summary><table class="logs-table"><thead><tr><th>Mes</th><th>Incidencias</th></tr></thead><tbody><?php foreach ($serie as $mes=>$cantidad): ?><tr><td><?= ui_e($mes) ?></td><td><?= $cantidad ?></td></tr><?php endforeach; ?></tbody></table></details>
</section>
<section class="admin-panel"><h2>Historico completo del cliente</h2><p>Incluye abiertas, resueltas, cerradas y archivadas de cualquier fecha. Se muestran como maximo 25 por pagina; no se elimina informacion.</p><div class="table-scroll"><table class="logs-table"><thead><tr><th>Incidencia</th><th>Estado</th><th>Departamento</th><th>Creacion</th></tr></thead><tbody>
<?php foreach ($historial as $ticket): ?><tr><td><a href="ver_incidencia.php?id=<?= (int)$ticket['id'] ?>">#<?= (int)$ticket['id'] ?> <?= ui_e($ticket['titulo']) ?></a></td><td><?= ui_e($ticket['estado']) ?></td><td><?= ui_e($ticket['tipo'] ?? 'Sin clasificar') ?></td><td><?= ui_e($ticket['fecha_creacion']) ?></td></tr><?php endforeach; ?>
<?php if (!$historial): ?><tr><td colspan="4">No hay incidencias en esta pagina.</td></tr><?php endif; ?>
</tbody></table></div><div class="page-tools"><?php if (!empty($antes)): ?><a href="?cliente=<?= $id ?>&amp;dias=<?= $dias ?>">Mas recientes</a><?php endif; ?><?php if ($siguiente): ?><a href="?cliente=<?= $id ?>&amp;dias=<?= $dias ?>&amp;antes=<?= $siguiente ?>">Ver anteriores</a><?php endif; ?></div></section>
<?php endif; ?>
<details class="admin-panel"><summary>Como se calculan las senales y sus limites</summary><p>Version de reglas 1: +30 puntos si existe algun SLA vencido activo; +35 si la media de satisfaccion es menor de 3/5 con al menos 3 incidencias valoradas; +20 si se reabrieron al menos el 20 % de las incidencias del periodo (minimo 5); +15 si hay al menos 2 quejas. Riesgo alto: 50 puntos o mas; medio: 20-49; bajo: menos de 20. Con menos de 5 incidencias no se clasifica el riesgo, pero se muestran las alertas existentes.</p><p>Una posible ampliacion requiere al menos 5 incidencias, 3 valoradas con media de 4/5 o superior, 2 consultas comerciales o de cambios, y menos de 20 puntos de alerta. No demuestra intencion de compra. La clasificacion del departamento puede proceder de IA y debe comprobarse. Se promedia primero cada incidencia para no dar mas peso a las que tienen varias opiniones.</p><p>No se utilizan contratos, facturacion ni bajas confirmadas porque todavia no se registran aqui. Para predecir probabilidades fiables hay que incorporar esos resultados y validar el modelo con datos posteriores. Una empresa sin incidencias no se considera satisfecha ni perdida. Las incidencias sin organizacion no se atribuyen a ninguna empresa.</p></details>
<?php ui_admin_pie(); ?>
