<?php
// Visor de actividad IA: agregados por proveedor/modo (7 dias) y ultimas llamadas.
require_once __DIR__ . '/../src/arranque.php';

$agregados = $pdo->query(
    "SELECT proveedor, modo,
            COUNT(*) AS llamadas,
            ROUND(AVG(duracion_ms)) AS media_ms,
            MAX(duracion_ms) AS max_ms,
            SUM(exito = 0) AS errores,
            SUM(COALESCE(tokens_entrada, 0)) AS tokens_entrada,
            SUM(COALESCE(tokens_salida, 0)) AS tokens_salida
     FROM llm_logs
     WHERE fecha >= NOW() - INTERVAL 7 DAY
     GROUP BY proveedor, modo
     ORDER BY proveedor, modo"
)->fetchAll(PDO::FETCH_ASSOC);

$ultimas = $pdo->query(
    "SELECT fecha, proveedor, modo, modelo, origen, duracion_ms, http_code, exito, tokens_entrada, tokens_salida, error
     FROM llm_logs
     ORDER BY id DESC
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Actividad IA</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div class="container">
    <div class="page-shell">
        <header class="page-header">
            <div>
                <h1>Actividad IA</h1>
                <p class="subtitulo">Latencia, errores y volumen de llamadas a los proveedores LLM.</p>
            </div>
            <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
        </header>

        <div class="page-tools">
            <a href="index.php" class="card-button secondary-button">‹ Volver</a>
        </div>

        <div class="incidencia-box compact-box">
            <h2>Resumen ultimos 7 dias</h2>
            <?php if (empty($agregados)): ?>
                <p class="info">Todavia no hay llamadas registradas.</p>
            <?php else: ?>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Proveedor</th><th>Modo</th><th>Llamadas</th><th>Media (ms)</th>
                            <th>Max (ms)</th><th>Errores</th><th>Tokens entrada</th><th>Tokens salida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agregados as $a): ?>
                            <tr>
                                <td><?= ui_e($a['proveedor']) ?></td>
                                <td><?= ui_e($a['modo']) ?></td>
                                <td><?= (int)$a['llamadas'] ?></td>
                                <td><?= (int)$a['media_ms'] ?></td>
                                <td><?= (int)$a['max_ms'] ?></td>
                                <td><?= (int)$a['errores'] ?></td>
                                <td><?= (int)$a['tokens_entrada'] ?></td>
                                <td><?= (int)$a['tokens_salida'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="incidencia-box compact-box">
            <h2>Ultimas 50 llamadas</h2>
            <?php if (empty($ultimas)): ?>
                <p class="info">Sin llamadas registradas.</p>
            <?php else: ?>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Fecha</th><th>Proveedor</th><th>Modo</th><th>Modelo</th><th>Origen</th>
                            <th>ms</th><th>HTTP</th><th>OK</th><th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimas as $l): ?>
                            <tr class="<?= $l['exito'] ? '' : 'log-error' ?>">
                                <td><?= ui_e($l['fecha']) ?></td>
                                <td><?= ui_e($l['proveedor']) ?></td>
                                <td><?= ui_e($l['modo']) ?></td>
                                <td><?= ui_e($l['modelo']) ?></td>
                                <td><?= ui_e($l['origen']) ?></td>
                                <td><?= (int)$l['duracion_ms'] ?></td>
                                <td><?= $l['http_code'] !== null ? (int)$l['http_code'] : '-' ?></td>
                                <td><?= $l['exito'] ? 'Si' : 'No' ?></td>
                                <td><?= ui_e((string)($l['error'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('incidencias_theme', theme);
    };
    applyTheme(localStorage.getItem('incidencias_theme') || 'light');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    }
});
</script>
</body>
</html>
