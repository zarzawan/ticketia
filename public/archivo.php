<?php
// Historico separado de la bandeja activa, paginado por cursor para crecer bien.
require_once __DIR__ . '/../src/arranque.php';

$vista = ($_GET['vista'] ?? '') === 'recientes' ? 'recientes' : 'archivo';
$busqueda = trim((string)($_GET['busqueda'] ?? ''));
$codigo = isset(dominio_codigos_resolucion()[$_GET['codigo'] ?? '']) ? (string)$_GET['codigo'] : '';
$cursor = filter_input(INPUT_GET, 'antes_de', FILTER_VALIDATE_INT) ?: null;
$limite = 50;

$resumen = $pdo->query(
    "SELECT
        SUM(estado = 'resuelta') AS por_confirmar,
        SUM(estado = 'cerrada' AND fecha_archivo IS NULL) AS cierres_recientes,
        SUM(estado = 'cerrada' AND fecha_archivo IS NOT NULL) AS archivadas
     FROM incidencias"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$sql = "SELECT i.id, i.titulo, i.resumen, i.resolucion_codigo, i.fecha_resolucion,
               i.fecha_cierre, i.fecha_archivo, c.nombre AS cliente_nombre,
               u.nombre AS asignado_nombre
        FROM incidencias i FORCE INDEX (idx_estado_id_archivo)
        LEFT JOIN clientes c ON c.id = i.cliente_id
        LEFT JOIN usuarios u ON u.id = i.asignado_id
        WHERE i.estado = 'cerrada' AND " . ($vista === 'archivo' ? 'i.fecha_archivo IS NOT NULL' : 'i.fecha_archivo IS NULL');
$params = [];
if ($cursor !== null) {
    $sql .= ' AND i.id < :cursor';
    $params[':cursor'] = $cursor;
}
if ($codigo !== '') {
    $sql .= ' AND i.resolucion_codigo = :codigo';
    $params[':codigo'] = $codigo;
}
if ($busqueda !== '') {
    if (ctype_digit($busqueda)) {
        $sql .= ' AND i.id = :id';
        $params[':id'] = (int)$busqueda;
    } else {
        $sql .= ' AND (i.titulo LIKE :buscar OR i.resumen LIKE :buscar OR i.resolucion_notas LIKE :buscar)';
        $params[':buscar'] = '%' . $busqueda . '%';
    }
}
$sql .= ' ORDER BY i.id DESC LIMIT ' . ($limite + 1);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hayMas = count($filas) > $limite;
if ($hayMas) {
    array_pop($filas);
}
$siguienteCursor = $hayMas && $filas ? (int)end($filas)['id'] : null;

function archivo_url(array $cambios): string {
    $query = array_merge($_GET, $cambios);
    foreach ($query as $clave => $valor) {
        if ($valor === '' || $valor === null) unset($query[$clave]);
    }
    return 'archivo.php' . ($query ? '?' . http_build_query($query) : '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Historial</title>
    <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('incidencias_theme') || 'light');</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body class="support-body">
<div class="support-shell">
    <?= ui_support_nav('archivo') ?>
    <main class="support-main">
        <header class="support-topbar">
            <button id="supportMenuToggle" class="support-menu-toggle" type="button">Menu</button>
            <div><span class="support-eyebrow">Memoria operativa</span><h1>Historial</h1><p class="subtitulo">Consulta cierres sin cargar la bandeja diaria.</p></div>
            <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="theme-button" type="button">Tema</button></div>
        </header>
        <div class="container support-content history-workspace">
            <section class="history-summary">
                <article><span>Esperan confirmacion</span><strong><?= (int)($resumen['por_confirmar'] ?? 0) ?></strong><a href="index.php?filtro_estado=resuelta">Revisar</a></article>
                <article><span>Cierres recientes</span><strong><?= (int)($resumen['cierres_recientes'] ?? 0) ?></strong></article>
                <article><span>Archivadas</span><strong><?= (int)($resumen['archivadas'] ?? 0) ?></strong></article>
            </section>

            <section class="incidencia-box history-panel">
                <header class="history-panel-head">
                    <div><span class="support-eyebrow">Solo lectura</span><h2><?= $vista === 'archivo' ? 'Archivo' : 'Cierres recientes' ?></h2></div>
                    <nav class="view-switch" aria-label="Tipo de historial">
                        <a class="<?= $vista === 'recientes' ? 'active' : '' ?>" href="<?= ui_e(archivo_url(['vista' => 'recientes', 'antes_de' => null])) ?>">Recientes</a>
                        <a class="<?= $vista === 'archivo' ? 'active' : '' ?>" href="<?= ui_e(archivo_url(['vista' => 'archivo', 'antes_de' => null])) ?>">Archivo</a>
                    </nav>
                </header>
                <form method="GET" class="history-filters">
                    <input type="hidden" name="vista" value="<?= ui_e($vista) ?>">
                    <input type="search" name="busqueda" value="<?= ui_e($busqueda) ?>" placeholder="Buscar por ID, titulo o solucion">
                    <select name="codigo"><option value="">Todos los resultados</option><?php foreach (dominio_codigos_resolucion() as $clave => $etiqueta): ?><option value="<?= ui_e($clave) ?>" <?= $codigo === $clave ? 'selected' : '' ?>><?= ui_e($etiqueta) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="card-button">Buscar</button>
                    <?php if ($busqueda !== '' || $codigo !== ''): ?><a class="card-button secondary-button" href="archivo.php?vista=<?= ui_e($vista) ?>">Limpiar</a><?php endif; ?>
                </form>

                <?php if (!$filas): ?>
                    <div class="empty-state"><strong>No hay incidencias aqui</strong><span>Los cierres apareceran cuando cumplan las reglas configuradas.</span></div>
                <?php else: ?>
                    <div class="table-scroll"><table class="history-table"><thead><tr><th>Incidencia</th><th>Resultado</th><th>Empresa</th><th>Resuelta</th><th><?= $vista === 'archivo' ? 'Archivada' : 'Cerrada' ?></th><th></th></tr></thead><tbody>
                    <?php foreach ($filas as $fila): ?>
                        <tr>
                            <td><strong>#<?= (int)$fila['id'] ?> <?= ui_e($fila['titulo']) ?></strong><small><?= ui_e($fila['resumen'] ?? '') ?></small></td>
                            <td><?= ui_e(dominio_codigos_resolucion()[$fila['resolucion_codigo']] ?? 'Sin clasificar') ?></td>
                            <td><?= ui_e($fila['cliente_nombre'] ?? 'Sin empresa') ?></td>
                            <td><?= ui_e($fila['fecha_resolucion'] ?? '—') ?></td>
                            <td><?= ui_e(($vista === 'archivo' ? $fila['fecha_archivo'] : $fila['fecha_cierre']) ?? '—') ?></td>
                            <td><a href="ver_incidencia.php?id=<?= (int)$fila['id'] ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
                <?php if ($siguienteCursor !== null): ?><a class="history-more" href="<?= ui_e(archivo_url(['antes_de' => $siguienteCursor])) ?>">Ver 50 anteriores</a><?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const tema = document.getElementById('themeToggle');
    if (tema) tema.addEventListener('click', () => { const n = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = n; localStorage.setItem('incidencias_theme', n); });
    const menu = document.getElementById('supportMenuToggle');
    if (menu) menu.addEventListener('click', () => document.body.classList.toggle('support-menu-open'));
});
</script>
</body></html>
