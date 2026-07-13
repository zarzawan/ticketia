<?php
// Portal de cliente: sus tickets y alta de tickets nuevos.
// El guard de arranque.php garantiza que solo llega aqui el rol cliente.
require_once __DIR__ . '/../src/arranque.php';

$usuario = auth_usuario();
$mensaje_exito = isset($_GET['ok']) && $_GET['ok'] == '1';

$vista = ($_GET['vista'] ?? '') === 'historial' ? 'historial' : 'activas';
$filtro_estado = in_array($_GET['estado'] ?? '', ['abierta', 'en_curso', 'resuelta'], true) ? (string)$_GET['estado'] : '';
$busqueda = trim((string)($_GET['q'] ?? ''));
$cursor = filter_input(INPUT_GET, 'antes_de', FILTER_VALIDATE_INT) ?: null;

// Ambito del cliente: los tickets de su empresa o, sin empresa, los suyos.
if ($usuario['cliente_id'] !== null) {
    $ambito_sql = "cliente_id = :ambito";
    $ambito_valor = $usuario['cliente_id'];
} else {
    $ambito_sql = "creado_por = :ambito";
    $ambito_valor = $usuario['id'];
}

$stats = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(estado = 'abierta') AS abiertas,
            SUM(estado = 'en_curso') AS en_curso,
            SUM(estado = 'resuelta') AS resueltas,
            SUM(estado = 'cerrada') AS cerradas
     FROM incidencias WHERE $ambito_sql"
);
$stats->execute([':ambito' => $ambito_valor]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

$sql = "SELECT id, titulo, descripcion, estado, urgencia, fecha_creacion, fecha_cierre,
               (SELECT COUNT(*) FROM mensajes m WHERE m.id_incidencia = incidencias.id AND m.interno = 0) AS mensajes,
               (SELECT MAX(m.fecha) FROM mensajes m WHERE m.id_incidencia = incidencias.id AND m.interno = 0) AS ultimo_mensaje
        FROM incidencias WHERE $ambito_sql";
$params = [':ambito' => $ambito_valor];
if ($filtro_estado !== '') {
    $sql .= " AND estado = :estado";
    $params[':estado'] = $filtro_estado;
} elseif ($vista === 'historial') {
    $sql .= " AND estado = 'cerrada'";
} else {
    $sql .= " AND estado IN ('abierta','en_curso','resuelta')";
}
if ($busqueda !== '') {
    $sql .= " AND (titulo LIKE :busqueda OR descripcion LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}
if ($cursor !== null) {
    $sql .= " AND id < :cursor";
    $params[':cursor'] = $cursor;
}
$sql .= " ORDER BY id DESC LIMIT 51";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hayMas = count($tickets) > 50;
if ($hayMas) array_pop($tickets);
$siguienteCursor = $hayMas && $tickets ? (int)end($tickets)['id'] : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Mis tickets</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body class="portal-body">
<div class="container">
<div class="page-shell">
    <header class="page-header portal-header">
        <div>
            <span class="portal-kicker">Centro de ayuda</span>
            <h1>Hola, <?= ui_e(explode(' ', trim((string)$usuario['nombre']))[0] ?? 'cliente') ?></h1>
            <p class="subtitulo">Consulta tus solicitudes o cuentanos en que podemos ayudarte.</p>
        </div>
        <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
    </header>

    <?php if ($mensaje_exito): ?>
        <div class="success-message">Tu ticket se ha creado correctamente. El equipo de soporte lo atendera lo antes posible.</div>
    <?php endif; ?>

    <section class="stat-strip portal-stats">
        <a class="stat <?= $vista === 'activas' && $filtro_estado === '' ? 'stat-activo' : '' ?>" href="portal.php"><span class="stat-value"><?= (int)$stats['abiertas'] + (int)$stats['en_curso'] + (int)$stats['resueltas'] ?></span><span class="stat-label">Activos</span></a>
        <a class="stat <?= $filtro_estado === 'abierta' ? 'stat-activo' : '' ?>" href="portal.php?estado=abierta"><span class="stat-value"><?= (int)$stats['abiertas'] ?></span><span class="stat-label">Nuevas</span></a>
        <a class="stat <?= $filtro_estado === 'resuelta' ? 'stat-activo' : '' ?>" href="portal.php?estado=resuelta"><span class="stat-value"><?= (int)$stats['resueltas'] ?></span><span class="stat-label">Confirmar solucion</span></a>
        <a class="stat <?= $vista === 'historial' ? 'stat-activo' : '' ?>" href="portal.php?vista=historial"><span class="stat-value"><?= (int)$stats['cerradas'] ?></span><span class="stat-label">Historial</span></a>
    </section>

    <div class="portal-layout">
    <div class="incidencia-box compact-box portal-new-ticket" id="nuevo-ticket">
        <span class="portal-kicker">Nueva solicitud</span>
        <h2>En que podemos ayudarte</h2>
        <p class="help-line">Describe lo ocurrido con el mayor detalle posible. Puedes escribir en tu idioma.</p>
        <form action="guardar_incidencia.php" method="POST" accept-charset="UTF-8" class="form-stack alta-form">
            <?= csrf_campo() ?>
            <div class="filter-field">
                <label class="filter-label" for="titulo">Titulo</label>
                <input type="text" id="titulo" name="titulo" required autocomplete="off" placeholder="Resume el problema en una linea">
            </div>
            <div class="filter-field">
                <label class="filter-label" for="descripcion">Descripcion</label>
                <textarea id="descripcion" name="descripcion" rows="6" required placeholder="Que ocurre, desde cuando, a quien afecta y que has probado"></textarea>
            </div>
            <div>
                <input type="submit" value="Enviar solicitud">
            </div>
        </form>
    </div>

    <div class="incidencia-box compact-box portal-ticket-panel">
        <div class="section-head portal-ticket-head">
            <div><span class="portal-kicker">Seguimiento</span><h2><?= $vista === 'historial' ? 'Historial' : 'Solicitudes activas' ?> <span class="portal-count"><?= count($tickets) ?></span></h2></div>
            <form method="GET" class="portal-search">
                <?php if ($vista === 'historial'): ?><input type="hidden" name="vista" value="historial"><?php endif; ?>
                <?php if ($filtro_estado !== ''): ?><input type="hidden" name="estado" value="<?= ui_e($filtro_estado) ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= ui_e($busqueda) ?>" placeholder="Buscar solicitudes" aria-label="Buscar solicitudes">
                <button type="submit" class="card-button secondary-button">Buscar</button>
                <?php if ($busqueda !== ''): ?><a href="portal.php<?= $vista === 'historial' ? '?vista=historial' : ($filtro_estado !== '' ? '?estado=' . urlencode($filtro_estado) : '') ?>">Limpiar</a><?php endif; ?>
            </form>
        </div>
        <?php if (empty($tickets)): ?>
            <div class="portal-empty"><span>✓</span><strong>No hay solicitudes<?= $filtro_estado !== '' ? ' con ese estado' : '' ?></strong><p><?= $busqueda !== '' ? 'Prueba con otros terminos de busqueda.' : 'Cuando necesites ayuda, puedes crear una nueva solicitud.' ?></p></div>
        <?php else: ?>
            <div class="portal-ticket-list">
                <?php foreach ($tickets as $t): ?>
                    <?php $ultima = $t['ultimo_mensaje'] ?: $t['fecha_creacion']; ?>
                    <a class="portal-ticket-card" href="portal_ver.php?id=<?= (int)$t['id'] ?>">
                        <span class="portal-ticket-indicator <?= ui_e(ui_estado_class((string)$t['estado'])) ?>"></span>
                        <span class="portal-ticket-content">
                            <span class="portal-ticket-meta"><b>#<?= (int)$t['id'] ?></b><span class="status-pill pill-estado pill-<?= ui_e(ui_estado_class((string)$t['estado'])) ?>"><?= ui_e(ui_estado_label((string)$t['estado'])) ?></span><?php if ($t['urgencia'] === 'critico'): ?><span class="portal-priority">Prioritario</span><?php endif; ?></span>
                            <strong><?= ui_e($t['titulo']) ?></strong>
                            <small><?= ui_e(mb_strimwidth((string)$t['descripcion'], 0, 115, '...')) ?></small>
                        </span>
                        <span class="portal-ticket-aside"><strong><?= (int)$t['mensajes'] ?></strong><small><?= (int)$t['mensajes'] === 1 ? 'mensaje' : 'mensajes' ?></small><time>Actualizado <?= ui_e(date('d/m/Y', strtotime((string)$ultima))) ?></time><b>Abrir &rsaquo;</b></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($siguienteCursor !== null): ?>
            <a class="history-more" href="portal.php?<?= ui_e(http_build_query(array_filter(['vista' => $vista === 'historial' ? 'historial' : null, 'estado' => $filtro_estado ?: null, 'q' => $busqueda ?: null, 'antes_de' => $siguienteCursor]))) ?>">Ver 50 anteriores</a>
        <?php endif; ?>
    </div>
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
