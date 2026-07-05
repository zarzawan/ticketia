<?php
// Visor de auditoria con filtros y paginacion.
require_once __DIR__ . '/../src/arranque.php';

$por_pagina = 50;

$filtro_q = trim((string)($_GET['q'] ?? ''));
$filtro_accion = trim((string)($_GET['accion'] ?? ''));
$filtro_desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['desde'] ?? '')) ? (string)$_GET['desde'] : '';
$filtro_hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['hasta'] ?? '')) ? (string)$_GET['hasta'] : '';
$pagina = max(1, (int)($_GET['pagina'] ?? 1));

$acciones = $pdo->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);

$where = " WHERE 1=1";
$params = [];
if ($filtro_q !== '') {
    $where .= " AND (usuario_email LIKE :q OR detalle LIKE :q)";
    $params[':q'] = "%$filtro_q%";
}
if ($filtro_accion !== '' && in_array($filtro_accion, $acciones, true)) {
    $where .= " AND accion = :accion";
    $params[':accion'] = $filtro_accion;
}
if ($filtro_desde !== '') {
    $where .= " AND DATE(fecha) >= :desde";
    $params[':desde'] = $filtro_desde;
}
if ($filtro_hasta !== '') {
    $where .= " AND DATE(fecha) <= :hasta";
    $params[':hasta'] = $filtro_hasta;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM auditoria $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$paginas = max(1, (int)ceil($total / $por_pagina));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $por_pagina;

$stmt = $pdo->prepare(
    "SELECT fecha, usuario_email, accion, detalle, ip FROM auditoria $where ORDER BY id DESC LIMIT $por_pagina OFFSET $offset"
);
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

function url_pagina(int $n): string {
    $q = $_GET;
    $q['pagina'] = $n;
    return 'admin_auditoria.php?' . http_build_query($q);
}

ui_admin_cabecera('Auditoria', 'Registro de acciones de los usuarios del sistema.', 'admin_auditoria.php');
?>

<div class="incidencia-box compact-box">
    <div class="section-head">
        <h2>Registros (<?= $total ?>)</h2>
        <form method="GET" class="page-tools">
            <input type="text" name="q" placeholder="Email o detalle" value="<?= ui_e($filtro_q) ?>" style="max-width:200px;">
            <select name="accion" onchange="this.form.submit()">
                <option value="">Todas las acciones</option>
                <?php foreach ($acciones as $a): ?>
                    <option value="<?= ui_e($a) ?>" <?= $filtro_accion === $a ? 'selected' : '' ?>><?= ui_e($a) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="desde" value="<?= ui_e($filtro_desde) ?>">
            <input type="date" name="hasta" value="<?= ui_e($filtro_hasta) ?>">
            <button type="submit" class="card-button secondary-button">Filtrar</button>
        </form>
    </div>

    <?php if (empty($registros)): ?>
        <p class="help-line">No hay registros con esos filtros.</p>
    <?php else: ?>
        <table class="logs-table">
            <thead>
                <tr><th>Fecha</th><th>Usuario</th><th>Accion</th><th>Detalle</th><th>IP</th></tr>
            </thead>
            <tbody>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td><?= ui_e($r['fecha']) ?></td>
                        <td><?= ui_e($r['usuario_email'] ?? '-') ?></td>
                        <td><?= ui_e($r['accion']) ?></td>
                        <td><?= ui_e($r['detalle'] ?? '') ?></td>
                        <td><?= ui_e($r['ip'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($paginas > 1): ?>
            <div class="page-tools" style="margin-top:12px; justify-content:center;">
                <?php if ($pagina > 1): ?>
                    <a class="card-button secondary-button boton-mini" href="<?= ui_e(url_pagina($pagina - 1)) ?>">‹ Anterior</a>
                <?php endif; ?>
                <span class="help-line">Pagina <?= $pagina ?> de <?= $paginas ?></span>
                <?php if ($pagina < $paginas): ?>
                    <a class="card-button secondary-button boton-mini" href="<?= ui_e(url_pagina($pagina + 1)) ?>">Siguiente ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php ui_admin_pie(); ?>
