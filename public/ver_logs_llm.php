<?php
// Centro de control IA: fiabilidad, consumo, adopcion y trazabilidad.
require_once __DIR__ . '/../src/arranque.php';

if (!gobierno_ia_esquema_disponible($pdo)) {
    ui_admin_cabecera('Control IA', 'La migracion de gobierno IA esta pendiente.', 'ver_logs_llm.php');
    echo '<div class="warning"><strong>Actualizacion necesaria.</strong> Ejecuta <code>php vendor/bin/phinx migrate -e principal</code> para activar las metricas y el feedback.</div>';
    ui_admin_pie();
    exit;
}

$periodos = [1 => '24 horas', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias'];
$dias = (int)($_GET['dias'] ?? 7);
if (!isset($periodos[$dias])) {
    $dias = 7;
}
$proveedor = trim((string)($_GET['proveedor'] ?? ''));
$modo = in_array($_GET['modo'] ?? '', ['chat', 'stream', 'traductor'], true) ? (string)$_GET['modo'] : '';
$estado = in_array($_GET['estado'] ?? '', ['exito', 'error'], true) ? (string)$_GET['estado'] : '';
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = 50;

$proveedores = $pdo->query("SELECT DISTINCT proveedor FROM llm_logs ORDER BY proveedor")
    ->fetchAll(PDO::FETCH_COLUMN);
if ($proveedor !== '' && !in_array($proveedor, $proveedores, true)) {
    $proveedor = '';
}

$where = ["l.fecha >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)"];
$params = [];
if ($proveedor !== '') {
    $where[] = 'l.proveedor = :proveedor';
    $params[':proveedor'] = $proveedor;
}
if ($modo !== '') {
    $where[] = 'l.modo = :modo';
    $params[':modo'] = $modo;
}
if ($estado !== '') {
    $where[] = 'l.exito = :exito';
    $params[':exito'] = $estado === 'exito' ? 1 : 0;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(l.exito = 1) AS exitos,
            SUM(l.exito = 0) AS errores,
            ROUND(AVG(l.duracion_ms)) AS media_ms,
            MAX(l.duracion_ms) AS max_ms,
            SUM(COALESCE(l.tokens_entrada, 0)) AS tokens_entrada,
            SUM(COALESCE(l.tokens_salida, 0)) AS tokens_salida,
            SUM(l.proveedor <> 'local') AS llamadas_pago
     FROM llm_logs l WHERE {$whereSql}"
);
$stmt->execute($params);
$resumen = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$total = (int)($resumen['total'] ?? 0);
$totalPaginas = max(1, (int)ceil($total / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;
$tasaExito = $total > 0 ? (int)round(((int)$resumen['exitos'] / $total) * 100) : 0;
$tokensTotal = (int)($resumen['tokens_entrada'] ?? 0) + (int)($resumen['tokens_salida'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT DATE(l.fecha) AS dia, COUNT(*) AS llamadas, SUM(l.exito = 0) AS errores,
            SUM(COALESCE(l.tokens_entrada, 0) + COALESCE(l.tokens_salida, 0)) AS tokens
     FROM llm_logs l WHERE {$whereSql}
     GROUP BY DATE(l.fecha) ORDER BY dia"
);
$stmt->execute($params);
$tendencia = $stmt->fetchAll(PDO::FETCH_ASSOC);
$maxLlamadas = max([1, ...array_map(fn(array $fila): int => (int)$fila['llamadas'], $tendencia)]);

$stmt = $pdo->prepare(
    "SELECT l.proveedor, l.modo, COUNT(*) AS llamadas,
            ROUND(AVG(l.duracion_ms)) AS media_ms, MAX(l.duracion_ms) AS max_ms,
            SUM(l.exito = 0) AS errores,
            SUM(COALESCE(l.tokens_entrada, 0) + COALESCE(l.tokens_salida, 0)) AS tokens
     FROM llm_logs l WHERE {$whereSql}
     GROUP BY l.proveedor, l.modo ORDER BY llamadas DESC"
);
$stmt->execute($params);
$agregados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(l.error, ''), CONCAT('HTTP ', COALESCE(l.http_code, 0))) AS causa,
            COUNT(*) AS total, MAX(l.fecha) AS ultima
     FROM llm_logs l WHERE {$whereSql} AND l.exito = 0
     GROUP BY causa ORDER BY total DESC, ultima DESC LIMIT 8"
);
$stmt->execute($params);
$erroresFrecuentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT l.fecha, l.proveedor, l.modo, l.modelo, l.origen, l.duracion_ms,
            l.http_code, l.exito, l.tokens_entrada, l.tokens_salida, l.error,
            l.incidencia_id, u.nombre AS usuario_nombre
     FROM llm_logs l
     LEFT JOIN usuarios u ON u.id = l.usuario_id
     WHERE {$whereSql}
     ORDER BY l.id DESC LIMIT {$porPagina} OFFSET {$offset}"
);
$stmt->execute($params);
$ultimas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$feedback = ['registros' => 0, 'valoraciones' => 0, 'utiles' => 0, 'usados' => 0, 'enviados' => 0];
$feedbackReciente = [];
if (gobierno_ia_esquema_disponible($pdo)) {
    $stmt = $pdo->query(
        "SELECT COUNT(*) AS registros,
                SUM(valoracion IS NOT NULL) AS valoraciones,
                SUM(valoracion = 1) AS utiles,
                SUM(borrador_usado = 1) AS usados,
                SUM(borrador_enviado = 1) AS enviados
         FROM feedback_ia WHERE actualizado_en >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)"
    );
    $feedback = $stmt->fetch(PDO::FETCH_ASSOC) ?: $feedback;
    $feedbackReciente = $pdo->query(
        "SELECT f.actualizado_en, f.valoracion, f.borrador_usado, f.borrador_enviado,
                f.incidencia_id, u.nombre AS usuario_nombre, i.titulo
         FROM feedback_ia f
         LEFT JOIN usuarios u ON u.id = f.usuario_id
         INNER JOIN incidencias i ON i.id = f.incidencia_id
         WHERE f.actualizado_en >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
         ORDER BY f.actualizado_en DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
}
$valoraciones = (int)($feedback['valoraciones'] ?? 0);
$tasaUtilidad = $valoraciones > 0 ? (int)round(((int)$feedback['utiles'] / $valoraciones) * 100) : 0;
$borradoresUsados = (int)($feedback['usados'] ?? 0);
$tasaEnvio = $borradoresUsados > 0 ? (int)round(((int)$feedback['enviados'] / $borradoresUsados) * 100) : 0;
$cola = trabajos_estado($pdo);
$salud_worker = trabajos_worker_salud($pdo);

ui_admin_cabecera('Control IA', 'Fiabilidad, consumo, adopcion y errores en una sola vista.', 'ver_logs_llm.php');
?>

<section class="ia-control-kpis" aria-label="Indicadores de inteligencia artificial">
    <article><span>Llamadas</span><strong><?= $total ?></strong><small><?= (int)($resumen['llamadas_pago'] ?? 0) ?> a proveedores de pago</small></article>
    <article class="<?= $tasaExito < 90 && $total > 0 ? 'is-warning' : '' ?>"><span>Fiabilidad</span><strong><?= $tasaExito ?>%</strong><small><?= (int)($resumen['errores'] ?? 0) ?> errores</small></article>
    <article><span>Latencia media</span><strong><?= (int)($resumen['media_ms'] ?? 0) ?> ms</strong><small>Maximo <?= (int)($resumen['max_ms'] ?? 0) ?> ms</small></article>
    <article><span>Tokens</span><strong><?= number_format($tokensTotal, 0, ',', '.') ?></strong><small><?= number_format((int)($resumen['tokens_salida'] ?? 0), 0, ',', '.') ?> de salida</small></article>
    <article><span>Briefs utiles</span><strong><?= $valoraciones ? $tasaUtilidad . '%' : '—' ?></strong><small><?= $valoraciones ?> valoraciones</small></article>
    <article><span>Borradores enviados</span><strong><?= $borradoresUsados ? $tasaEnvio . '%' : '—' ?></strong><small><?= (int)($feedback['enviados'] ?? 0) ?> de <?= $borradoresUsados ?> usados</small></article>
</section>

<section class="incidencia-box compact-box ia-control-filtros">
    <form method="GET" class="page-tools">
        <label><span>Periodo</span><select name="dias"><?php foreach ($periodos as $valor => $etiqueta): ?><option value="<?= $valor ?>" <?= $dias === $valor ? 'selected' : '' ?>><?= $etiqueta ?></option><?php endforeach; ?></select></label>
        <label><span>Proveedor</span><select name="proveedor"><option value="">Todos</option><?php foreach ($proveedores as $opcion): ?><option value="<?= ui_e((string)$opcion) ?>" <?= $proveedor === $opcion ? 'selected' : '' ?>><?= ui_e((string)$opcion) ?></option><?php endforeach; ?></select></label>
        <label><span>Modo</span><select name="modo"><option value="">Todos</option><?php foreach (['chat', 'stream', 'traductor'] as $opcion): ?><option value="<?= $opcion ?>" <?= $modo === $opcion ? 'selected' : '' ?>><?= ucfirst($opcion) ?></option><?php endforeach; ?></select></label>
        <label><span>Resultado</span><select name="estado"><option value="">Todos</option><option value="exito" <?= $estado === 'exito' ? 'selected' : '' ?>>Correctas</option><option value="error" <?= $estado === 'error' ? 'selected' : '' ?>>Errores</option></select></label>
        <button type="submit" class="filter-button">Aplicar</button>
        <a href="ver_logs_llm.php" class="card-button secondary-button">Limpiar</a>
    </form>
</section>

<p class="help-line">Los filtros de proveedor, modo y resultado se aplican a las llamadas. El feedback se muestra para todo el equipo en el periodo elegido.</p>
<div class="ia-control-grid">
    <section class="admin-panel ia-control-wide">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Tendencia</span><h2>Actividad diaria</h2></div><span><?= ui_e($periodos[$dias]) ?></span></div>
        <?php if ($tendencia === []): ?>
            <div class="admin-empty"><strong>Sin actividad</strong><span>No hay llamadas con estos filtros.</span></div>
        <?php else: ?>
            <div class="ia-trend">
                <?php foreach ($tendencia as $dia): ?>
                    <?php $ancho = max(4, (int)round(((int)$dia['llamadas'] / $maxLlamadas) * 100)); ?>
                    <div class="ia-trend-row">
                        <time><?= ui_e(date('d/m', strtotime((string)$dia['dia']))) ?></time>
                        <span class="ia-trend-bar"><i style="width:<?= $ancho ?>%"></i></span>
                        <strong><?= (int)$dia['llamadas'] ?></strong>
                        <small><?= (int)$dia['errores'] ?> errores &middot; <?= number_format((int)$dia['tokens'], 0, ',', '.') ?> tokens</small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Cola</span><h2>Procesamiento</h2></div><a href="admin_ajustes.php">Gestionar</a></div>
        <?= ui_worker_estado($salud_worker) ?>
        <div class="ia-queue-list">
            <?php foreach (['pendiente' => 'Pendientes', 'en_curso' => 'En curso', 'completado' => 'Completados', 'fallido' => 'Fallidos'] as $clave => $etiqueta): ?>
                <div><span><?= $etiqueta ?></span><strong><?= (int)($cola[$clave] ?? 0) ?></strong></div>
            <?php endforeach; ?>
        </div>
        <p class="help-line">Retencion automatica: <?= gobierno_ia_retencion_dias() ?> dias.</p>
    </section>

    <section class="admin-panel ia-control-wide">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Proveedores</span><h2>Rendimiento por modo</h2></div></div>
        <?php if ($agregados === []): ?><div class="admin-empty"><strong>Sin datos</strong><span>Cambia los filtros o prueba la conexion IA.</span></div><?php else: ?>
            <div class="table-scroll"><table class="logs-table"><thead><tr><th>Proveedor</th><th>Modo</th><th>Llamadas</th><th>Fiabilidad</th><th>Media</th><th>Maximo</th><th>Tokens</th></tr></thead><tbody>
            <?php foreach ($agregados as $fila): ?>
                <?php $fiabilidad = (int)$fila['llamadas'] > 0 ? (int)round((((int)$fila['llamadas'] - (int)$fila['errores']) / (int)$fila['llamadas']) * 100) : 0; ?>
                <tr><td><strong><?= ui_e($fila['proveedor']) ?></strong></td><td><?= ui_e($fila['modo']) ?></td><td><?= (int)$fila['llamadas'] ?></td><td><span class="ia-health <?= $fiabilidad < 90 ? 'warn' : '' ?>"><?= $fiabilidad ?>%</span></td><td><?= (int)$fila['media_ms'] ?> ms</td><td><?= (int)$fila['max_ms'] ?> ms</td><td><?= number_format((int)$fila['tokens'], 0, ',', '.') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Calidad</span><h2>Feedback reciente</h2></div></div>
        <?php if ($feedbackReciente === []): ?><div class="admin-empty"><strong>Aun sin feedback</strong><span>Las valoraciones apareceran al usar el copiloto.</span></div><?php else: ?>
            <div class="ia-feedback-list">
                <?php foreach ($feedbackReciente as $fila): ?>
                    <a href="ver_incidencia.php?id=<?= (int)$fila['incidencia_id'] ?>"><span><strong>#<?= (int)$fila['incidencia_id'] ?> <?= ui_e($fila['titulo']) ?></strong><small><?= ui_e($fila['usuario_nombre'] ?? 'Usuario eliminado') ?> &middot; <?= ui_e($fila['actualizado_en']) ?></small></span><b><?= (int)$fila['valoracion'] === 1 ? 'Util' : ((int)$fila['valoracion'] === -1 ? 'No util' : ((int)$fila['borrador_enviado'] === 1 ? 'Enviado' : 'Usado')) ?></b></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($erroresFrecuentes !== []): ?>
<section class="incidencia-box compact-box">
    <div class="section-head"><div><span class="admin-section-kicker">Diagnostico</span><h2>Errores frecuentes</h2></div></div>
    <div class="ia-error-grid"><?php foreach ($erroresFrecuentes as $fila): ?><article><strong><?= (int)$fila['total'] ?> veces</strong><p><?= ui_e($fila['causa']) ?></p><small>Ultimo: <?= ui_e($fila['ultima']) ?></small></article><?php endforeach; ?></div>
</section>
<?php endif; ?>

<section class="incidencia-box compact-box">
    <div class="section-head"><div><span class="admin-section-kicker">Trazabilidad</span><h2>Ultimas llamadas</h2></div><span class="help-line">Pagina <?= $pagina ?> de <?= $totalPaginas ?></span></div>
    <?php if ($ultimas === []): ?><p class="info">Sin llamadas registradas.</p><?php else: ?>
        <div class="table-scroll"><table class="logs-table"><thead><tr><th>Fecha</th><th>Proveedor</th><th>Modelo y modo</th><th>Origen</th><th>Contexto</th><th>Latencia</th><th>Tokens</th><th>Estado</th></tr></thead><tbody>
        <?php foreach ($ultimas as $fila): ?>
            <tr class="<?= $fila['exito'] ? '' : 'log-error' ?>"><td><?= ui_e($fila['fecha']) ?></td><td><?= ui_e($fila['proveedor']) ?></td><td><strong><?= ui_e($fila['modelo']) ?></strong><small><?= ui_e($fila['modo']) ?></small></td><td><?= ui_e($fila['origen']) ?></td><td><?= $fila['incidencia_id'] ? '<a href="ver_incidencia.php?id=' . (int)$fila['incidencia_id'] . '">#' . (int)$fila['incidencia_id'] . '</a>' : '-' ?><?= $fila['usuario_nombre'] ? '<small>' . ui_e($fila['usuario_nombre']) . '</small>' : '' ?></td><td><?= (int)$fila['duracion_ms'] ?> ms</td><td><?= number_format((int)$fila['tokens_entrada'] + (int)$fila['tokens_salida'], 0, ',', '.') ?></td><td><span class="ia-health <?= $fila['exito'] ? '' : 'warn' ?>"><?= $fila['exito'] ? 'OK' : 'Error' ?></span><?php if (!$fila['exito']): ?><small title="<?= ui_e((string)$fila['error']) ?>"><?= ui_e(mb_strimwidth((string)$fila['error'], 0, 45, '...')) ?></small><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($totalPaginas > 1): ?>
            <nav class="pagination" aria-label="Paginas de actividad IA">
                <?php if ($pagina > 1): ?><a class="card-button secondary-button" href="?<?= ui_e(http_build_query(array_merge($_GET, ['pagina' => $pagina - 1]))) ?>">Anterior</a><?php endif; ?>
                <?php if ($pagina < $totalPaginas): ?><a class="card-button secondary-button" href="?<?= ui_e(http_build_query(array_merge($_GET, ['pagina' => $pagina + 1]))) ?>">Siguiente</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php ui_admin_pie(); ?>
