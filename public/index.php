<?php
require_once __DIR__ . '/../src/arranque.php';

$mensaje_exito = isset($_GET['ok']) && $_GET['ok'] == '1';

$ia_model = [];
switch ($llm_provider) {
    case 'openai':
        $ia_model = [
            'name' => 'OpenAI (ChatGPT)',
            'url' => 'analisis.php',
            'description' => 'Analisis profundo de incidencias con GPT.',
            'icon' => 'IA',
            'color' => '#2f7de1'
        ];
        break;
    case 'xai':
        $ia_model = [
            'name' => 'xAI (Grok)',
            'url' => 'analisis.php',
            'description' => 'Perspectiva alternativa con Grok.',
            'icon' => 'IA',
            'color' => '#007f5f'
        ];
        break;
    case 'local':
    default:
        $ia_model = [
            'name' => 'LLM Local',
            'url' => 'analisis.php',
            'description' => 'Analisis privado con modelo local.',
            'icon' => 'IA',
            'color' => '#0e9f6e'
        ];
        break;
}

$tipo_iconos = dominio_tipo_codigos();

$tipos = dominio_tipos();
$urgencias = dominio_urgencias();
$estados = dominio_estados();
$ordenes = dominio_ordenes();
$limites_validos = [20, 50, 100, 0];

$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_tipo = isset($_GET['filtro_tipo']) && in_array($_GET['filtro_tipo'], $tipos, true) ? $_GET['filtro_tipo'] : '';
$filtro_urgencia = isset($_GET['filtro_urgencia']) && in_array($_GET['filtro_urgencia'], $urgencias, true) ? $_GET['filtro_urgencia'] : '';
$filtro_estado = isset($_GET['filtro_estado']) && in_array($_GET['filtro_estado'], $estados, true) ? $_GET['filtro_estado'] : '';
$filtro_desde = isset($_GET['filtro_desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['filtro_desde']) ? $_GET['filtro_desde'] : '';
$filtro_hasta = isset($_GET['filtro_hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['filtro_hasta']) ? $_GET['filtro_hasta'] : '';
$orden = isset($_GET['orden']) && in_array($_GET['orden'], $ordenes, true) ? $_GET['orden'] : 'id_desc';
$asignables = usuarios_asignables($pdo);
$asignables_ids = array_map(fn($u) => (string)$u['id'], $asignables);
$filtro_asignado = '';
if (isset($_GET['filtro_asignado'])) {
    $candidato_asignado = (string)$_GET['filtro_asignado'];
    if ($candidato_asignado === 'sin_asignar' || in_array($candidato_asignado, $asignables_ids, true)) {
        $filtro_asignado = $candidato_asignado;
    }
}
$limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 50;
if (!in_array($limite, $limites_validos, true)) {
    $limite = 50;
}

// Delegan en lib/dominio.php manteniendo la firma usada por esta pagina.
function appendCommonFilters(&$sql, &$params, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado = ''): void {
    dominio_append_filtros($sql, $params, [
        'busqueda' => $busqueda,
        'tipo' => $filtro_tipo,
        'urgencia' => $filtro_urgencia,
        'estado' => $filtro_estado,
        'desde' => $filtro_desde,
        'hasta' => $filtro_hasta,
        'asignado' => $filtro_asignado
    ]);
}

function appendFiltersWithoutTipo(&$sql, &$params, $busqueda, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado = ''): void {
    appendCommonFilters($sql, $params, $busqueda, '', $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
}

function getOrderByClause(string $orden): string {
    return dominio_order_by($orden);
}

function buildQueryUrl(array $updates = [], array $remove = []): string {
    $query = $_GET;

    foreach ($remove as $key) {
        unset($query[$key]);
    }

    foreach ($updates as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $qs = http_build_query($query);
    return 'index.php' . ($qs ? ('?' . $qs) : '');
}

function formatHoursToSpan($hours): string {
    if ($hours === null) {
        return '0h';
    }

    $totalHours = (int)round((float)$hours);
    if ($totalHours <= 0) {
        return '0h';
    }

    if ($totalHours < 24) {
        return $totalHours . 'h';
    }

    $dias = intdiv($totalHours, 24);
    $restoHoras = $totalHours % 24;

    return $dias . 'd ' . $restoHoras . 'h';
}

$sql_tipos = "SELECT tipo, COUNT(*) as total FROM incidencias WHERE tipo IS NOT NULL";
$params_tipos = [];
appendCommonFilters($sql_tipos, $params_tipos, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
$sql_tipos .= " GROUP BY tipo ORDER BY total DESC";
$stmt_tipos = $pdo->prepare($sql_tipos);
$stmt_tipos->execute($params_tipos);
$datos_tipos = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

$sql_departamentos = "SELECT tipo, COUNT(*) as total FROM incidencias WHERE tipo IS NOT NULL";
$params_departamentos = [];
appendFiltersWithoutTipo($sql_departamentos, $params_departamentos, $busqueda, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
$sql_departamentos .= " GROUP BY tipo ORDER BY tipo ASC";
$stmt_departamentos = $pdo->prepare($sql_departamentos);
$stmt_departamentos->execute($params_departamentos);
$datos_departamentos = $stmt_departamentos->fetchAll(PDO::FETCH_ASSOC);
$departamento_totales = array_fill_keys($tipos, 0);
foreach ($datos_departamentos as $departamento) {
    $tipo_dep = (string)($departamento['tipo'] ?? '');
    if (array_key_exists($tipo_dep, $departamento_totales)) {
        $departamento_totales[$tipo_dep] = (int)$departamento['total'];
    }
}

$sql_urgencias = "SELECT urgencia, COUNT(*) as total FROM incidencias WHERE 1=1";
$params_urgencias = [];
appendCommonFilters($sql_urgencias, $params_urgencias, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
$sql_urgencias .= " GROUP BY urgencia";
$stmt_urgencias = $pdo->prepare($sql_urgencias);
$stmt_urgencias->execute($params_urgencias);
$datos_urgencias = $stmt_urgencias->fetchAll(PDO::FETCH_ASSOC);

$sql_estados = "SELECT estado, COUNT(*) as total FROM incidencias WHERE 1=1";
$params_estados = [];
appendCommonFilters($sql_estados, $params_estados, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
$sql_estados .= " GROUP BY estado";
$stmt_estados = $pdo->prepare($sql_estados);
$stmt_estados->execute($params_estados);
$datos_estados = $stmt_estados->fetchAll(PDO::FETCH_ASSOC);

$sql_kpi = "
    SELECT
        COUNT(*) AS total,
        SUM(estado = 'abierta') AS abiertas,
        SUM(estado = 'en_curso') AS en_curso,
        SUM(estado = 'cerrada') AS cerradas,
        SUM(urgencia = 'critico') AS criticas,
        SUM(urgencia = 'critico' AND estado <> 'cerrada') AS criticas_abiertas,
        SUM(estado <> 'cerrada' AND TIMESTAMPDIFF(HOUR, fecha_creacion, NOW()) >= 48) AS abiertas_48h,
        SUM(asignado_id IS NULL AND estado <> 'cerrada') AS sin_asignar,
        SUM(tipo = 'Comercial') AS comerciales,
        AVG(CASE WHEN fecha_cierre IS NOT NULL THEN TIMESTAMPDIFF(HOUR, fecha_creacion, fecha_cierre) END) AS ttr_horas,
        AVG(CASE WHEN estado <> 'cerrada' THEN TIMESTAMPDIFF(HOUR, fecha_creacion, NOW()) END) AS edad_media_abiertas_h,
        MAX(CASE WHEN estado <> 'cerrada' THEN TIMESTAMPDIFF(HOUR, fecha_creacion, NOW()) END) AS incidencia_mas_antigua_h
    FROM incidencias
    WHERE 1=1
";
$params_kpi = [];
appendCommonFilters($sql_kpi, $params_kpi, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
$stmt_kpi = $pdo->prepare($sql_kpi);
$stmt_kpi->execute($params_kpi);
$kpi = $stmt_kpi->fetch(PDO::FETCH_ASSOC) ?: [];

$kpi_total = (int)($kpi['total'] ?? 0);
$kpi_cerradas = (int)($kpi['cerradas'] ?? 0);
$kpi_criticas = (int)($kpi['criticas'] ?? 0);
$kpi_ratio_cierre = $kpi_total > 0 ? round(($kpi_cerradas / $kpi_total) * 100, 1) : 0;
$kpi_ratio_criticas = $kpi_total > 0 ? round(($kpi_criticas / $kpi_total) * 100, 1) : 0;

$sql_trend_creadas = "SELECT DATE(fecha_creacion) AS dia, COUNT(*) AS total FROM incidencias WHERE 1=1";
$params_trend_creadas = [];
appendCommonFilters($sql_trend_creadas, $params_trend_creadas, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
$sql_trend_creadas .= " GROUP BY DATE(fecha_creacion) ORDER BY dia DESC LIMIT 14";
$stmt_trend_creadas = $pdo->prepare($sql_trend_creadas);
$stmt_trend_creadas->execute($params_trend_creadas);
$rows_trend_creadas = array_reverse($stmt_trend_creadas->fetchAll(PDO::FETCH_ASSOC));

$sql_trend_cerradas = "SELECT DATE(fecha_cierre) AS dia, COUNT(*) AS total FROM incidencias WHERE fecha_cierre IS NOT NULL";
$params_trend_cerradas = [];
appendCommonFilters($sql_trend_cerradas, $params_trend_cerradas, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado, $filtro_desde, $filtro_hasta, $filtro_asignado);
$sql_trend_cerradas .= " GROUP BY DATE(fecha_cierre) ORDER BY dia DESC LIMIT 14";
$stmt_trend_cerradas = $pdo->prepare($sql_trend_cerradas);
$stmt_trend_cerradas->execute($params_trend_cerradas);
$rows_trend_cerradas = array_reverse($stmt_trend_cerradas->fetchAll(PDO::FETCH_ASSOC));

$creadas_por_dia = [];
foreach ($rows_trend_creadas as $row) {
    $dia = (string)($row['dia'] ?? '');
    if ($dia !== '') {
        $creadas_por_dia[$dia] = (int)$row['total'];
    }
}

$cerradas_por_dia = [];
foreach ($rows_trend_cerradas as $row) {
    $dia = (string)($row['dia'] ?? '');
    if ($dia !== '') {
        $cerradas_por_dia[$dia] = (int)$row['total'];
    }
}

$trend_labels = array_values(array_unique(array_merge(array_keys($creadas_por_dia), array_keys($cerradas_por_dia))));
sort($trend_labels);
$trend_data_creadas = [];
$trend_data_cerradas = [];
foreach ($trend_labels as $label) {
    $trend_data_creadas[] = (int)($creadas_por_dia[$label] ?? 0);
    $trend_data_cerradas[] = (int)($cerradas_por_dia[$label] ?? 0);
}

$kanban_data = [
    'abierta' => [],
    'en_curso' => [],
    'cerrada' => []
];
$estados_kanban = $filtro_estado ? [$filtro_estado] : $estados;
$kanban_limit = $limite === 0 ? 200 : min($limite, 100);

foreach ($estados_kanban as $estado_kanban) {
    $sql_kanban = "SELECT id, titulo, resumen, tipo, urgencia, estado, fecha_creacion, asignado_id,
                          (SELECT nombre FROM usuarios u WHERE u.id = incidencias.asignado_id) AS asignado_nombre
                   FROM incidencias WHERE estado = :estado";
    $params_kanban = [':estado' => $estado_kanban];

    appendCommonFilters($sql_kanban, $params_kanban, $busqueda, $filtro_tipo, $filtro_urgencia, '', $filtro_desde, $filtro_hasta, $filtro_asignado);
    $sql_kanban .= " ORDER BY " . getOrderByClause($orden) . " LIMIT " . (int)$kanban_limit;

    $stmt_kanban = $pdo->prepare($sql_kanban);
    $stmt_kanban->execute($params_kanban);
    $kanban_data[$estado_kanban] = $stmt_kanban->fetchAll(PDO::FETCH_ASSOC);
}

// Totales reales por estado (con filtros) para el contador "mostrando X de Y".
$totales_por_estado = array_fill_keys($estados, 0);
foreach ($datos_estados as $dato_estado) {
    $clave_estado = (string)($dato_estado['estado'] ?? '');
    if (array_key_exists($clave_estado, $totales_por_estado)) {
        $totales_por_estado[$clave_estado] = (int)$dato_estado['total'];
    }
}

// Siguiente tramo del selector de limite para el boton "Ver mas".
$siguiente_limite = null;
if ($limite === 20) {
    $siguiente_limite = 50;
} elseif ($limite === 50) {
    $siguiente_limite = 100;
} elseif ($limite === 100) {
    $siguiente_limite = 0;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Panel</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <script>
    // Persistencia de filtros: al volver al panel sin parametros se restaura
    // el ultimo filtro aplicado (guardado en localStorage). "Limpiar" lo borra.
    (function () {
        var clavesFiltro = ['busqueda', 'filtro_tipo', 'filtro_urgencia', 'filtro_estado', 'filtro_desde', 'filtro_hasta', 'filtro_asignado', 'orden', 'limite'];
        var actuales = new URLSearchParams(window.location.search);
        var tieneFiltros = clavesFiltro.some(function (k) { return actuales.has(k); });

        if (tieneFiltros) {
            var soloFiltros = new URLSearchParams();
            clavesFiltro.forEach(function (k) {
                if (actuales.has(k) && actuales.get(k) !== '') {
                    soloFiltros.set(k, actuales.get(k));
                }
            });
            localStorage.setItem('incidencias_filtros', soloFiltros.toString());
            return;
        }

        var guardados = localStorage.getItem('incidencias_filtros');
        if (guardados) {
            // Conservar parametros de estado (ok, error, proveedor_ok) al restaurar.
            var destino = new URLSearchParams(guardados);
            actuales.forEach(function (v, k) { destino.set(k, v); });
            window.location.replace('index.php?' + destino.toString());
        }
    })();
    </script>
    <link rel="stylesheet" href="estilos.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="container">
    <header class="page-header">
        <div>
            <h1>TicketIA</h1>
            <p class="subtitulo">Gestion, analisis y seguimiento en una sola vista.</p>
        </div>
        <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
    </header>

    <?php if ($mensaje_exito): ?>
        <div class="success-message">Incidencia registrada correctamente.</div>
    <?php endif; ?>

    <div class="top-row-grid">
        <div class="incidencia-box" id="filtros">
            <h2>Filtros y departamentos</h2>
            <form method="GET" action="index.php" class="filter-form-modern" id="formFiltros">
                <input type="hidden" name="filtro_tipo" value="<?= ui_e($filtro_tipo) ?>">
                <input type="hidden" name="filtro_estado" value="<?= ui_e($filtro_estado) ?>">

                <div class="filter-field">
                    <label class="filter-label" for="busqueda">Buscar</label>
                    <input type="text" id="busqueda" name="busqueda" value="<?= ui_e($busqueda) ?>" placeholder="Buscar por titulo, descripcion o ID...">
                </div>

                <div class="filter-field">
                    <label class="filter-label" for="filtro_urgencia">Urgencia</label>
                    <select id="filtro_urgencia" name="filtro_urgencia">
                        <option value="">Todas las urgencias</option>
                        <?php foreach ($urgencias as $urgencia): ?>
                            <option value="<?= ui_e($urgencia) ?>" <?= $filtro_urgencia === $urgencia ? 'selected' : '' ?>>
                                <?= ui_e(ui_urgencia_label($urgencia)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label class="filter-label" for="filtro_asignado">Asignado</label>
                    <select id="filtro_asignado" name="filtro_asignado">
                        <option value="">Todos</option>
                        <option value="sin_asignar" <?= $filtro_asignado === 'sin_asignar' ? 'selected' : '' ?>>Sin asignar</option>
                        <?php foreach ($asignables as $asignable): ?>
                            <option value="<?= (int)$asignable['id'] ?>" <?= $filtro_asignado === (string)$asignable['id'] ? 'selected' : '' ?>>
                                <?= ui_e($asignable['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label class="filter-label" for="filtro_desde">Desde</label>
                    <input type="date" id="filtro_desde" name="filtro_desde" value="<?= ui_e($filtro_desde) ?>">
                </div>

                <div class="filter-field">
                    <label class="filter-label" for="filtro_hasta">Hasta</label>
                    <input type="date" id="filtro_hasta" name="filtro_hasta" value="<?= ui_e($filtro_hasta) ?>">
                </div>

                <div class="filter-field">
                    <label class="filter-label" for="orden">Orden</label>
                    <select name="orden" id="orden">
                        <option value="id_desc" <?= $orden === 'id_desc' ? 'selected' : '' ?>>ID desc</option>
                        <option value="recientes" <?= $orden === 'recientes' ? 'selected' : '' ?>>Mas recientes</option>
                        <option value="antiguas" <?= $orden === 'antiguas' ? 'selected' : '' ?>>Mas antiguas</option>
                        <option value="urgencia" <?= $orden === 'urgencia' ? 'selected' : '' ?>>Por urgencia</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label class="filter-label" for="limite">Limite</label>
                    <select name="limite" id="limite">
                        <option value="20" <?= $limite === 20 ? 'selected' : '' ?>>20 por estado</option>
                        <option value="50" <?= $limite === 50 ? 'selected' : '' ?>>50 por estado</option>
                        <option value="100" <?= $limite === 100 ? 'selected' : '' ?>>100 por estado</option>
                        <option value="0" <?= $limite === 0 ? 'selected' : '' ?>>Sin limite</option>
                    </select>
                </div>

                <div class="filter-actions-inline">
                    <button type="submit" class="filter-button">Aplicar</button>
                    <button type="button" class="filter-button reset-button" onclick="limpiarFiltros()">Limpiar</button>
                </div>
            </form>

            <p class="help-line">Filtro por departamento (cada categoria):</p>
            <div class="departamentos-pills">
                <a class="quick-pill <?= $filtro_tipo === '' ? 'active' : '' ?>" href="<?= ui_e(buildQueryUrl(['filtro_tipo' => ''], [])) ?>">
                    Todos
                </a>
                <?php
                $tipos_ordenados = $tipos;
                natcasesort($tipos_ordenados);
                foreach ($tipos_ordenados as $dep):
                ?>
                    <a class="quick-pill <?= $filtro_tipo === $dep ? 'active' : '' ?>" href="<?= ui_e(buildQueryUrl(['filtro_tipo' => $dep], [])) ?>">
                        <?= ui_e($dep) ?> <span class="pill-count"><?= (int)($departamento_totales[$dep] ?? 0) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="incidencia-box top-insights-box">
            <div class="top-insights-shell">
                <section class="dashboard-summary">
                    <div class="section-head dashboard-head">
                        <div>
                            <h2>Dashboard</h2>
                            <p class="help-line">Resumen operativo compacto del panel.</p>
                        </div>
                        <button type="button" class="filter-button secondary dashboard-toggle" id="toggleDashboardCharts" aria-expanded="false" aria-controls="dashboardCharts">
                            Ver graficos
                        </button>
                    </div>

                    <?php
                    // Cada estadistica es un enlace que aplica su filtro conservando el resto.
                    $fecha_48h = date('Y-m-d', strtotime('-2 days'));
                    $url_abiertas = buildQueryUrl(['filtro_estado' => 'abierta']);
                    $url_en_curso = buildQueryUrl(['filtro_estado' => 'en_curso']);
                    $url_criticas_abiertas = buildQueryUrl(['filtro_urgencia' => 'critico', 'filtro_estado' => '']);
                    $url_sin_asignar = buildQueryUrl(['filtro_asignado' => 'sin_asignar']);
                    $url_48h = buildQueryUrl(['filtro_hasta' => $fecha_48h, 'filtro_estado' => '']);
                    $url_todas = buildQueryUrl([
                        'busqueda' => '', 'filtro_tipo' => '', 'filtro_urgencia' => '', 'filtro_estado' => '',
                        'filtro_desde' => '', 'filtro_hasta' => '', 'filtro_asignado' => '', 'orden' => 'id_desc'
                    ]);
                    $url_cerradas = buildQueryUrl(['filtro_estado' => 'cerrada']);
                    $url_criticas = buildQueryUrl(['filtro_urgencia' => 'critico']);
                    ?>
                    <section class="stat-strip">
                        <a class="stat <?= $filtro_estado === 'abierta' ? 'stat-activo' : '' ?>" href="<?= ui_e($url_abiertas) ?>" title="Filtrar incidencias abiertas">
                            <span class="stat-value"><?= (int)($kpi['abiertas'] ?? 0) ?></span>
                            <span class="stat-label">Abiertas</span>
                        </a>
                        <a class="stat <?= $filtro_estado === 'en_curso' ? 'stat-activo' : '' ?>" href="<?= ui_e($url_en_curso) ?>" title="Filtrar incidencias en curso">
                            <span class="stat-value"><?= (int)($kpi['en_curso'] ?? 0) ?></span>
                            <span class="stat-label">En curso</span>
                        </a>
                        <a class="stat <?= (int)($kpi['criticas_abiertas'] ?? 0) > 0 ? 'stat-alerta' : '' ?> <?= $filtro_urgencia === 'critico' && $filtro_estado === '' ? 'stat-activo' : '' ?>" href="<?= ui_e($url_criticas_abiertas) ?>" title="Filtrar incidencias criticas">
                            <span class="stat-value"><?= (int)($kpi['criticas_abiertas'] ?? 0) ?></span>
                            <span class="stat-label">Criticas abiertas</span>
                        </a>
                        <a class="stat <?= $filtro_asignado === 'sin_asignar' ? 'stat-activo' : '' ?>" href="<?= ui_e($url_sin_asignar) ?>" title="Filtrar incidencias sin asignar">
                            <span class="stat-value"><?= (int)($kpi['sin_asignar'] ?? 0) ?></span>
                            <span class="stat-label">Sin asignar</span>
                        </a>
                        <a class="stat <?= $filtro_hasta === $fecha_48h ? 'stat-activo' : '' ?>" href="<?= ui_e($url_48h) ?>" title="Filtrar incidencias creadas hace mas de 48h">
                            <span class="stat-value"><?= (int)($kpi['abiertas_48h'] ?? 0) ?></span>
                            <span class="stat-label">Abiertas +48h</span>
                        </a>
                    </section>
                    <p class="stat-secondary">
                        <a href="<?= ui_e($url_todas) ?>" title="Ver todas las incidencias"><strong><?= $kpi_total ?></strong> totales</a>
                        <a href="<?= ui_e($url_cerradas) ?>" title="Filtrar incidencias cerradas"><strong><?= (int)($kpi['cerradas'] ?? 0) ?></strong> cerradas (<?= $kpi_ratio_cierre ?>%)</a>
                        <a href="<?= ui_e($url_criticas) ?>" title="Filtrar incidencias criticas"><strong><?= (int)($kpi['criticas'] ?? 0) ?></strong> criticas (<?= $kpi_ratio_criticas ?>%)</a>
                        <span>TTR medio <strong><?= formatHoursToSpan($kpi['ttr_horas'] ?? null) ?></strong></span>
                        <span>Edad media <strong><?= formatHoursToSpan($kpi['edad_media_abiertas_h'] ?? null) ?></strong></span>
                        <span>Mas antigua <strong><?= formatHoursToSpan($kpi['incidencia_mas_antigua_h'] ?? null) ?></strong></span>
                    </p>
                </section>

                <aside class="ia-rail">
                    <div>
                        <h3>Analisis IA</h3>
                        <p class="help-line">Resumen ejecutivo del backlog generado por IA.</p>
                    </div>
                    <div class="ia-rail-actions">
                        <a href="<?= ui_e($ia_model['url']) ?>" class="card-button">General</a>
                        <a href="analisis_seguridad.php" class="card-button secondary-button">Seguridad</a>
                    </div>
                    <form action="cambiar_proveedor.php" method="POST" class="ia-provider-form">
                        <?= csrf_campo() ?>
                        <label class="filter-label" for="selector_proveedor">Proveedor</label>
                        <select name="proveedor" id="selector_proveedor" onchange="this.form.submit()">
                            <?php foreach ($llm_config as $clave_proveedor => $conf_proveedor): ?>
                                <?php if (!is_array($conf_proveedor) || !isset($conf_proveedor['label'])) continue; ?>
                                <option value="<?= ui_e($clave_proveedor) ?>" <?= $llm_provider === $clave_proveedor ? 'selected' : '' ?>>
                                    <?= ui_e($conf_proveedor['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a href="ver_logs_llm.php" class="ia-rail-link">Ver actividad IA ›</a>
                </aside>
            </div>

            <section class="dashboard-charts-panel" id="dashboardCharts" hidden>
                <div class="dashboard-grid">
                    <div class="chart-container">
                        <h3>Por tipo</h3>
                        <canvas id="chart-tipos"></canvas>
                    </div>
                    <div class="chart-container">
                        <h3>Por urgencia</h3>
                        <canvas id="chart-urgencias"></canvas>
                    </div>
                    <div class="chart-container">
                        <h3>Por estado</h3>
                        <canvas id="chart-estados"></canvas>
                    </div>
                    <div class="chart-container chart-container-wide">
                        <h3>Evolucion diaria (creadas vs cerradas)</h3>
                        <canvas id="chart-evolucion"></canvas>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="incidencia-box">
        <div class="section-head">
            <h2>Kanban de incidencias</h2>
            <a class="card-button" href="<?= ui_e('exportar_csv.php?' . http_build_query($_GET)) ?>">Exportar CSV filtrado</a>
        </div>
        <p class="help-line">Arrastra tarjetas entre columnas para cambiar el estado. En columnas con muchas incidencias se activa vista en dos subcolumnas.</p>
        <div class="kanban-board" id="kanbanBoard">
            <?php foreach ($estados_kanban as $estado_columna): ?>
                <?php
                $estado_css = ui_estado_class($estado_columna);
                $total_en_columna = count($kanban_data[$estado_columna]);
                $dropzone_class = $total_en_columna > 9 ? 'kanban-dropzone split-view' : 'kanban-dropzone';
                ?>
                <?php $total_estado_bd = (int)($totales_por_estado[$estado_columna] ?? 0); ?>
                <section class="kanban-column <?= ui_e($estado_css) ?>" data-estado="<?= ui_e($estado_columna) ?>">
                    <header>
                        <h3><?= ui_e(ui_estado_label($estado_columna)) ?></h3>
                        <span class="kanban-count" data-total="<?= $total_estado_bd ?>"><?= $total_en_columna < $total_estado_bd ? $total_en_columna . ' de ' . $total_estado_bd : $total_en_columna ?></span>
                    </header>
                    <div class="<?= ui_e($dropzone_class) ?>">
                        <?php foreach ($kanban_data[$estado_columna] as $incidencia_k): ?>
                            <?= ui_render_kanban_card($incidencia_k, $asignables) ?>
                        <?php endforeach; ?>
                        <?php if ($total_en_columna < $total_estado_bd && $siguiente_limite !== null): ?>
                            <a class="card-button kanban-ver-mas" href="<?= ui_e(buildQueryUrl(['limite' => (string)$siguiente_limite])) ?>">
                                Ver mas (<?= $total_estado_bd - $total_en_columna ?> ocultas)
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="incidencia-box">
        <h2>Abrir nueva incidencia</h2>
        <p class="help-line" style="margin-top:-6px; margin-bottom:14px;">La IA clasificara urgencia, departamento e idioma automaticamente al crearla.</p>
        <form action="guardar_incidencia.php" method="POST" accept-charset="UTF-8" id="formNuevaIncidencia" class="form-stack alta-form">
            <?= csrf_campo() ?>
            <div class="filter-field">
                <label class="filter-label" for="titulo">Titulo</label>
                <input type="text" id="titulo" name="titulo" required autocomplete="off" placeholder="Resume el problema en una linea">
            </div>
            <div id="similaresBox" class="similares-box" hidden>
                <p class="help-line">Tickets similares ya existentes (revisa antes de crear un duplicado):</p>
                <ul id="similaresLista"></ul>
            </div>
            <div class="filter-field">
                <label class="filter-label" for="descripcion">Descripcion</label>
                <textarea id="descripcion" name="descripcion" rows="6" required placeholder="Que ocurre, desde cuando y a quien afecta"></textarea>
                <div class="help-line"><span id="contadorDescripcion">0</span> caracteres</div>
            </div>
            <div>
                <input type="submit" value="Crear incidencia">
            </div>
        </form>
    </div>

</div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

function limpiarFiltros() {
    localStorage.removeItem('incidencias_filtros');
    window.location.href = 'index.php';
}

const datosTipos = {
    labels: <?= json_encode(array_map(function ($dato) { return (string)$dato['tipo']; }, $datos_tipos), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
        label: 'Incidencias por tipo',
        data: <?= json_encode(array_map(function ($dato) { return (int)$dato['total']; }, $datos_tipos)) ?>,
        backgroundColor: ['#2f7de1', '#0e9f6e', '#f59f00', '#d9480f', '#7c3aed', '#0891b2', '#e03131'],
        borderWidth: 0,
        borderRadius: 8
    }]
};

const datosUrgencias = {
    labels: <?= json_encode(array_map(function ($dato) { return ui_urgencia_label((string)$dato['urgencia']); }, $datos_urgencias), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
        label: 'Incidencias por urgencia',
        data: <?= json_encode(array_map(function ($dato) { return (int)$dato['total']; }, $datos_urgencias)) ?>,
        backgroundColor: ['#e03131', '#f08c00', '#2f9e44'],
        borderWidth: 0,
        borderRadius: 8
    }]
};

const datosEstados = {
    labels: <?= json_encode(array_map(function ($dato) { return ui_estado_label((string)$dato['estado']); }, $datos_estados), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
        label: 'Incidencias por estado',
        data: <?= json_encode(array_map(function ($dato) { return (int)$dato['total']; }, $datos_estados)) ?>,
        backgroundColor: ['#1971c2', '#f08c00', '#2b8a3e'],
        borderWidth: 0,
        borderRadius: 8
    }]
};

const datosEvolucion = {
    labels: <?= json_encode($trend_labels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
        {
            label: 'Creadas',
            data: <?= json_encode($trend_data_creadas) ?>,
            borderColor: '#1b74d4',
            backgroundColor: 'rgba(27, 116, 212, 0.18)',
            pointBackgroundColor: '#1b74d4',
            tension: 0.25,
            fill: false
        },
        {
            label: 'Cerradas',
            data: <?= json_encode($trend_data_cerradas) ?>,
            borderColor: '#2f9e44',
            backgroundColor: 'rgba(47, 158, 68, 0.18)',
            pointBackgroundColor: '#2f9e44',
            tension: 0.25,
            fill: false
        }
    ]
};

const chartBaseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            ticks: { precision: 0 }
        }
    },
    plugins: {
        legend: { display: false }
    }
};

const lineChartOptions = Object.assign({}, chartBaseOptions, {
    plugins: {
        legend: { display: true, position: 'bottom' }
    }
});

document.addEventListener('DOMContentLoaded', () => {
    let dashboardChartsInitialized = false;
    const initDashboardCharts = () => {
        if (dashboardChartsInitialized) {
            return;
        }

        new Chart(document.getElementById('chart-tipos'), { type: 'bar', data: datosTipos, options: chartBaseOptions });
        new Chart(document.getElementById('chart-urgencias'), { type: 'bar', data: datosUrgencias, options: chartBaseOptions });
        new Chart(document.getElementById('chart-estados'), { type: 'bar', data: datosEstados, options: chartBaseOptions });
        new Chart(document.getElementById('chart-evolucion'), { type: 'line', data: datosEvolucion, options: lineChartOptions });
        dashboardChartsInitialized = true;
    };

    const chartsPanel = document.getElementById('dashboardCharts');
    const toggleChartsButton = document.getElementById('toggleDashboardCharts');
    if (chartsPanel && toggleChartsButton) {
        toggleChartsButton.addEventListener('click', () => {
            const isHidden = chartsPanel.hasAttribute('hidden');
            if (isHidden) {
                chartsPanel.removeAttribute('hidden');
                initDashboardCharts();
                toggleChartsButton.setAttribute('aria-expanded', 'true');
                toggleChartsButton.textContent = 'Ocultar graficos';
            } else {
                chartsPanel.setAttribute('hidden', '');
                toggleChartsButton.setAttribute('aria-expanded', 'false');
                toggleChartsButton.textContent = 'Ver graficos';
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            event.preventDefault();
            document.getElementById('busqueda').focus();
        }

        if (event.key.toLowerCase() === 'n' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            event.preventDefault();
            document.getElementById('titulo').focus();
        }
    });

    const descripcion = document.getElementById('descripcion');
    const contador = document.getElementById('contadorDescripcion');
    const actualizarContador = () => {
        contador.textContent = descripcion.value.length;
    };
    descripcion.addEventListener('input', actualizarContador);
    actualizarContador();

    const themeToggle = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('incidencias_theme', theme);
    };

    const savedTheme = localStorage.getItem('incidencias_theme') || 'light';
    applyTheme(savedTheme);

    themeToggle.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    });

    let draggedCard = null;
    let sourceColumn = null;

    const columns = document.querySelectorAll('.kanban-column');

    function attachCardListeners(card) {
        card.addEventListener('dragstart', () => {
            draggedCard = card;
            sourceColumn = card.closest('.kanban-column');
            card.classList.add('is-dragging');
        });

        card.addEventListener('dragend', () => {
            card.classList.remove('is-dragging');
        });
    }

    function refreshSplitView(column) {
        const dropzone = column.querySelector('.kanban-dropzone');
        const total = column.querySelectorAll('.kanban-card').length;
        dropzone.classList.toggle('split-view', total > 9);
    }

    function actualizarContadorKanban(column, deltaTotal = 0) {
        const countElement = column.querySelector('.kanban-count');
        const mostradas = column.querySelectorAll('.kanban-card').length;
        let total = parseInt(countElement.dataset.total || '0', 10);
        if (deltaTotal) {
            total = Math.max(0, total + deltaTotal);
            countElement.dataset.total = String(total);
        }
        countElement.textContent = mostradas < total ? `${mostradas} de ${total}` : String(mostradas);
        refreshSplitView(column);
    }

    function actualizarEstadoVisual(card, estado) {
        card.classList.remove('abierta', 'en_curso', 'cerrada');
        card.classList.add(estado);

        const botonEstado = card.querySelector('.js-change-state');
        if (botonEstado) {
            const nuevoDestino = estado === 'cerrada' ? 'en_curso' : 'cerrada';
            botonEstado.dataset.targetState = nuevoDestino;
            botonEstado.textContent = estado === 'cerrada' ? 'Reabrir' : 'Cerrar';
        }
    }

    document.querySelectorAll('.kanban-card').forEach(attachCardListeners);
    document.querySelectorAll('.kanban-controls select, .kanban-foot a').forEach((el) => {
        el.addEventListener('mousedown', (event) => event.stopPropagation());
        el.addEventListener('dragstart', (event) => event.stopPropagation());
    });

    columns.forEach((column) => {
        const dropzone = column.querySelector('.kanban-dropzone');

        dropzone.addEventListener('dragover', (event) => {
            event.preventDefault();
            column.classList.add('is-over');
        });

        dropzone.addEventListener('dragleave', () => {
            column.classList.remove('is-over');
        });

        dropzone.addEventListener('drop', async (event) => {
            event.preventDefault();
            column.classList.remove('is-over');

            if (!draggedCard || !sourceColumn) {
                return;
            }

            const targetState = column.dataset.estado;
            const sourceState = sourceColumn.dataset.estado;
            if (!targetState || targetState === sourceState) {
                return;
            }

            const incidenciaId = draggedCard.dataset.id;
            if (!incidenciaId) {
                return;
            }

            try {
                const payload = new URLSearchParams({
                    id_incidencia: incidenciaId,
                    estado: targetState,
                    csrf: CSRF_TOKEN
                });

                const response = await fetch('mover_incidencia_estado.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload.toString()
                });

                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'No se pudo actualizar el estado');
                }

                dropzone.appendChild(draggedCard);
                actualizarContadorKanban(sourceColumn, -1);
                actualizarContadorKanban(column, 1);
                actualizarEstadoVisual(draggedCard, targetState);
            } catch (error) {
                alert('Error al mover la incidencia: ' + error.message);
            }
        });
    });

    document.querySelectorAll('.js-change-state').forEach((button) => {
        button.addEventListener('click', async () => {
            const card = button.closest('.kanban-card');
            const currentColumn = button.closest('.kanban-column');
            const incidenciaId = button.dataset.id;
            const targetState = button.dataset.targetState;

            if (!card || !currentColumn || !incidenciaId || !targetState) {
                return;
            }

            button.disabled = true;
            try {
                const payload = new URLSearchParams({
                    id_incidencia: incidenciaId,
                    estado: targetState,
                    csrf: CSRF_TOKEN
                });

                const response = await fetch('mover_incidencia_estado.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload.toString()
                });

                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'No se pudo actualizar el estado');
                }

                const targetColumn = document.querySelector(`.kanban-column[data-estado="${targetState}"]`);
                if (targetColumn) {
                    targetColumn.querySelector('.kanban-dropzone').appendChild(card);
                    actualizarContadorKanban(currentColumn, -1);
                    actualizarContadorKanban(targetColumn, 1);
                } else {
                    card.remove();
                    actualizarContadorKanban(currentColumn, -1);
                }

                actualizarEstadoVisual(card, targetState);
            } catch (error) {
                alert('Error al cambiar estado: ' + error.message);
            } finally {
                button.disabled = false;
            }
        });
    });

    const inicialesDe = (nombre) => {
        const partes = nombre.trim().split(/\s+/).filter(Boolean);
        if (!partes.length) return '';
        let iniciales = partes[0][0];
        if (partes.length > 1) iniciales += partes[partes.length - 1][0];
        return iniciales.toUpperCase();
    };

    document.querySelectorAll('.kanban-asignado-form .kanban-select-asignado').forEach((select) => {
        select.addEventListener('change', async () => {
            const form = select.closest('.kanban-asignado-form');
            const card = select.closest('.kanban-card');
            const incidenciaId = form ? form.dataset.id : '';
            if (!incidenciaId) {
                return;
            }

            select.disabled = true;
            try {
                const payload = new URLSearchParams({
                    id_incidencia: incidenciaId,
                    asignado: select.value,
                    ajax: '1',
                    csrf: CSRF_TOKEN
                });

                const response = await fetch('asignar_incidencia.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: payload.toString()
                });

                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'No se pudo asignar');
                }

                const avatar = card ? card.querySelector('.kanban-avatar') : null;
                if (avatar) {
                    if (select.value === '') {
                        avatar.classList.add('is-empty');
                        avatar.textContent = '–';
                        avatar.title = 'Sin asignar';
                    } else {
                        avatar.classList.remove('is-empty');
                        avatar.textContent = inicialesDe(select.value);
                        avatar.title = select.value;
                    }
                }
            } catch (error) {
                alert('Error al asignar tecnico: ' + error.message);
            } finally {
                select.disabled = false;
            }
        });
    });

    // Deteccion de tickets similares al escribir el titulo del alta.
    const tituloInput = document.getElementById('titulo');
    const similaresBox = document.getElementById('similaresBox');
    const similaresLista = document.getElementById('similaresLista');
    if (tituloInput && similaresBox && similaresLista) {
        let similaresTimer = null;
        const buscarSimilares = async () => {
            const q = tituloInput.value.trim();
            if (q.length < 4) {
                similaresBox.hidden = true;
                return;
            }
            try {
                const response = await fetch('buscar_similares.php?q=' + encodeURIComponent(q));
                const result = await response.json();
                const resultados = (result && result.ok && Array.isArray(result.resultados)) ? result.resultados : [];
                if (!resultados.length) {
                    similaresBox.hidden = true;
                    return;
                }
                similaresLista.innerHTML = '';
                resultados.forEach((r) => {
                    const li = document.createElement('li');
                    const enlace = document.createElement('a');
                    enlace.href = 'ver_incidencia.php?id=' + r.id;
                    enlace.target = '_blank';
                    enlace.textContent = `#${r.id} ${r.titulo}`;
                    const detalle = document.createElement('span');
                    detalle.className = 'similares-meta';
                    detalle.textContent = ` (${r.estado}${r.resumen ? ' - ' + r.resumen : ''})`;
                    li.appendChild(enlace);
                    li.appendChild(detalle);
                    similaresLista.appendChild(li);
                });
                similaresBox.hidden = false;
            } catch (error) {
                similaresBox.hidden = true;
            }
        };
        tituloInput.addEventListener('input', () => {
            clearTimeout(similaresTimer);
            similaresTimer = setTimeout(buscarSimilares, 400);
        });
    }

    document.querySelectorAll('.kanban-tipo-form .kanban-select-tipo').forEach((select) => {
        select.addEventListener('change', async () => {
            const form = select.closest('.kanban-tipo-form');
            const card = select.closest('.kanban-card');
            const incidenciaId = form ? form.dataset.id : '';
            const tipo = select.value;
            const metaTipo = card ? card.querySelector('.kanban-meta-tipo') : null;

            if (!incidenciaId || !tipo) {
                return;
            }

            select.disabled = true;

            try {
                const payload = new URLSearchParams({
                    id_incidencia: incidenciaId,
                    tipo,
                    ajax: '1',
                    csrf: CSRF_TOKEN
                });

                const response = await fetch('actualizar_tipo_incidencia.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: payload.toString()
                });

                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || 'No se pudo cambiar el departamento');
                }

                if (metaTipo) {
                    metaTipo.textContent = tipo;
                }
            } catch (error) {
                alert('Error al cambiar departamento: ' + error.message);
            } finally {
                select.disabled = false;
            }
        });
    });

});
</script>
</body>
</html>
