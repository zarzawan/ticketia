<?php
// Portal de cliente: sus tickets y alta de tickets nuevos.
// El guard de arranque.php garantiza que solo llega aqui el rol cliente.
require_once __DIR__ . '/../src/arranque.php';

$usuario = auth_usuario();
$mensaje_exito = isset($_GET['ok']) && $_GET['ok'] == '1';

$filtro_estado = in_array($_GET['estado'] ?? '', dominio_estados(), true) ? (string)$_GET['estado'] : '';

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
            SUM(estado = 'cerrada') AS cerradas
     FROM incidencias WHERE $ambito_sql"
);
$stats->execute([':ambito' => $ambito_valor]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

$sql = "SELECT id, titulo, estado, urgencia, fecha_creacion, fecha_cierre,
               (SELECT COUNT(*) FROM mensajes m WHERE m.id_incidencia = incidencias.id AND m.interno = 0) AS mensajes
        FROM incidencias WHERE $ambito_sql";
$params = [':ambito' => $ambito_valor];
if ($filtro_estado !== '') {
    $sql .= " AND estado = :estado";
    $params[':estado'] = $filtro_estado;
}
$sql .= " ORDER BY fecha_creacion DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
<body>
<div class="container">
<div class="page-shell">
    <header class="page-header">
        <div>
            <h1>Soporte</h1>
            <p class="subtitulo">Tus tickets y solicitudes en un solo sitio.</p>
        </div>
        <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
    </header>

    <?php if ($mensaje_exito): ?>
        <div class="success-message">Tu ticket se ha creado correctamente. El equipo de soporte lo atendera lo antes posible.</div>
    <?php endif; ?>

    <section class="stat-strip">
        <a class="stat <?= $filtro_estado === '' ? 'stat-activo' : '' ?>" href="portal.php"><span class="stat-value"><?= (int)$stats['total'] ?></span><span class="stat-label">Todos</span></a>
        <a class="stat <?= $filtro_estado === 'abierta' ? 'stat-activo' : '' ?>" href="portal.php?estado=abierta"><span class="stat-value"><?= (int)$stats['abiertas'] ?></span><span class="stat-label">Abiertos</span></a>
        <a class="stat <?= $filtro_estado === 'en_curso' ? 'stat-activo' : '' ?>" href="portal.php?estado=en_curso"><span class="stat-value"><?= (int)$stats['en_curso'] ?></span><span class="stat-label">En curso</span></a>
        <a class="stat <?= $filtro_estado === 'cerrada' ? 'stat-activo' : '' ?>" href="portal.php?estado=cerrada"><span class="stat-value"><?= (int)$stats['cerradas'] ?></span><span class="stat-label">Cerrados</span></a>
    </section>

    <div class="incidencia-box compact-box">
        <h2>Abrir un ticket nuevo</h2>
        <p class="help-line" style="margin-top:-6px; margin-bottom:14px;">Puedes escribir en tu idioma: el equipo lo recibira y te respondera igualmente.</p>
        <form action="guardar_incidencia.php" method="POST" accept-charset="UTF-8" class="form-stack alta-form">
            <?= csrf_campo() ?>
            <div class="filter-field">
                <label class="filter-label" for="titulo">Titulo</label>
                <input type="text" id="titulo" name="titulo" required autocomplete="off" placeholder="Resume el problema en una linea">
            </div>
            <div class="filter-field">
                <label class="filter-label" for="descripcion">Descripcion</label>
                <textarea id="descripcion" name="descripcion" rows="5" required placeholder="Que ocurre, desde cuando y a quien afecta"></textarea>
            </div>
            <div>
                <input type="submit" value="Crear ticket">
            </div>
        </form>
    </div>

    <div class="incidencia-box compact-box">
        <h2>Mis tickets (<?= count($tickets) ?>)</h2>
        <?php if (empty($tickets)): ?>
            <p class="help-line">Todavia no hay tickets<?= $filtro_estado !== '' ? ' con ese estado' : '' ?>. Crea el primero con el formulario de arriba.</p>
        <?php else: ?>
            <table class="logs-table">
                <thead>
                    <tr><th>#</th><th>Titulo</th><th>Estado</th><th>Respuestas</th><th>Creado</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td>#<?= (int)$t['id'] ?></td>
                            <td><a href="portal_ver.php?id=<?= (int)$t['id'] ?>"><?= ui_e($t['titulo']) ?></a></td>
                            <td><span class="status-pill pill-estado pill-<?= ui_e(ui_estado_class((string)$t['estado'])) ?>"><?= ui_e(ui_estado_label((string)$t['estado'])) ?></span></td>
                            <td><?= (int)$t['mensajes'] ?></td>
                            <td><?= ui_e(date('d/m/Y', strtotime((string)$t['fecha_creacion']))) ?></td>
                            <td><a class="card-button secondary-button boton-mini" href="portal_ver.php?id=<?= (int)$t['id'] ?>">Abrir</a></td>
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
