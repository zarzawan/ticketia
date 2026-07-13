<?php
// Portada operativa de administracion: salud, alertas y actividad reciente.
require_once __DIR__ . '/../src/arranque.php';

$resumen = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM usuarios WHERE activo = 1) AS usuarios_activos,
        (SELECT COUNT(*) FROM clientes WHERE activo = 1) AS empresas_activas,
        (SELECT COUNT(*) FROM incidencias WHERE estado IN ('abierta','en_curso')) AS tickets_abiertos,
        (SELECT COUNT(*) FROM incidencias WHERE estado IN ('abierta','en_curso') AND urgencia = 'critico') AS tickets_criticos,
        (SELECT COUNT(*) FROM incidencias WHERE estado IN ('abierta','en_curso') AND asignado_id IS NULL) AS sin_asignar,
        (SELECT COUNT(*) FROM incidencias WHERE estado IN ('abierta','en_curso') AND fecha_creacion < NOW() - INTERVAL 48 HOUR) AS fuera_objetivo,
        (SELECT COUNT(*) FROM incidencias WHERE estado = 'resuelta') AS por_confirmar,
        (SELECT COUNT(*) FROM incidencias WHERE fecha_archivo IS NOT NULL) AS archivadas,
        (SELECT COUNT(*) FROM trabajos_ia WHERE estado IN ('pendiente','en_curso')) AS cola_ia,
        (SELECT COUNT(*) FROM trabajos_ia WHERE estado = 'fallido') AS fallos_ia"
)->fetch(PDO::FETCH_ASSOC);

$agentes = $pdo->query(
    "SELECT u.id, u.nombre, u.rol,
            SUM(i.estado = 'abierta') AS abiertas,
            SUM(i.estado = 'en_curso') AS en_curso,
            SUM(i.estado IN ('abierta','en_curso') AND i.urgencia = 'critico') AS criticas,
            SUM(i.estado IN ('abierta','en_curso')) AS total
     FROM usuarios u
     LEFT JOIN incidencias i ON i.asignado_id = u.id
     WHERE u.activo = 1 AND u.rol IN ('admin','operador')
     GROUP BY u.id, u.nombre, u.rol
     ORDER BY total DESC, u.nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$max_carga = max(1, ...array_map(static fn(array $a): int => (int)$a['total'], $agentes));

$prioritarias = $pdo->query(
    "SELECT i.id, i.titulo, i.estado, i.urgencia, i.fecha_creacion,
            u.nombre AS asignado_nombre, c.nombre AS empresa,
            TIMESTAMPDIFF(HOUR, i.fecha_creacion, NOW()) AS edad_horas
     FROM incidencias i
     LEFT JOIN usuarios u ON u.id = i.asignado_id
     LEFT JOIN clientes c ON c.id = i.cliente_id
     WHERE i.estado IN ('abierta','en_curso')
     ORDER BY (i.urgencia = 'critico') DESC, (i.asignado_id IS NULL) DESC, i.fecha_creacion ASC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

$actividad = $pdo->query(
    "SELECT fecha, usuario_email, accion, detalle FROM auditoria ORDER BY id DESC LIMIT 7"
)->fetchAll(PDO::FETCH_ASSOC);

$ia_hoy = $pdo->query(
    "SELECT COUNT(*) AS total, SUM(exito = 1) AS exitos, SUM(exito = 0) AS errores,
            ROUND(AVG(duracion_ms)) AS media_ms
     FROM llm_logs WHERE DATE(fecha) = CURDATE()"
)->fetch(PDO::FETCH_ASSOC);

ui_admin_cabecera('Resumen', 'Prioridades, equipo y salud del sistema en una sola vista.', 'admin_inicio.php');
?>

<section class="admin-hero">
    <div>
        <span class="admin-hero-kicker">Estado operativo</span>
        <h2><?= (int)$resumen['tickets_criticos'] > 0 ? (int)$resumen['tickets_criticos'] . ' incidencias criticas requieren atencion' : 'La operacion no tiene incidencias criticas' ?></h2>
        <p><?= (int)$resumen['sin_asignar'] ?> sin asignar y <?= (int)$resumen['fuera_objetivo'] ?> abiertas desde hace mas de 48 horas.</p>
    </div>
    <div class="admin-hero-actions">
        <a class="card-button" href="index.php?filtro_urgencia=critico">Revisar prioridades</a>
        <a class="card-button secondary-button" href="admin_usuarios.php">Gestionar equipo</a>
    </div>
</section>

<section class="admin-kpi-grid" aria-label="Indicadores principales">
    <a class="admin-kpi" href="index.php">
        <span class="admin-kpi-icon tone-blue">TI</span><span><small>Tickets abiertos</small><strong><?= (int)$resumen['tickets_abiertos'] ?></strong><em>Ver panel operativo</em></span>
    </a>
    <a class="admin-kpi <?= (int)$resumen['tickets_criticos'] > 0 ? 'is-alert' : '' ?>" href="index.php?filtro_urgencia=critico">
        <span class="admin-kpi-icon tone-red">CR</span><span><small>Criticos abiertos</small><strong><?= (int)$resumen['tickets_criticos'] ?></strong><em>Prioridad inmediata</em></span>
    </a>
    <a class="admin-kpi" href="index.php?filtro_asignado=sin_asignar">
        <span class="admin-kpi-icon tone-amber">SA</span><span><small>Sin asignar</small><strong><?= (int)$resumen['sin_asignar'] ?></strong><em>Pendientes de reparto</em></span>
    </a>
    <a class="admin-kpi" href="admin_ajustes.php">
        <span class="admin-kpi-icon tone-violet">IA</span><span><small>Cola de IA</small><strong><?= (int)$resumen['cola_ia'] ?></strong><em><?= (int)$resumen['fallos_ia'] ?> trabajos fallidos</em></span>
    </a>
    <a class="admin-kpi" href="admin_flujos.php">
        <span class="admin-kpi-icon tone-green">FL</span><span><small>Esperan confirmacion</small><strong><?= (int)$resumen['por_confirmar'] ?></strong><em><?= (int)$resumen['archivadas'] ?> en archivo</em></span>
    </a>
</section>

<div class="admin-dashboard-grid">
    <section class="admin-panel admin-panel-wide">
        <div class="admin-panel-head">
            <div><span class="admin-section-kicker">Atencion</span><h2>Incidencias prioritarias</h2></div>
            <a href="index.php">Ver todas</a>
        </div>
        <?php if (empty($prioritarias)): ?>
            <div class="admin-empty"><strong>Todo al dia</strong><span>No hay incidencias abiertas.</span></div>
        <?php else: ?>
            <div class="admin-ticket-list">
                <?php foreach ($prioritarias as $ticket): ?>
                    <a class="admin-ticket-row" href="ver_incidencia.php?id=<?= (int)$ticket['id'] ?>">
                        <span class="admin-priority-dot <?= ui_e($ticket['urgencia']) ?>"></span>
                        <span class="admin-ticket-main"><strong>#<?= (int)$ticket['id'] ?> <?= ui_e($ticket['titulo']) ?></strong><small><?= ui_e($ticket['empresa'] ?? 'Sin empresa') ?> · <?= ui_e($ticket['asignado_nombre'] ?? 'Sin asignar') ?></small></span>
                        <span class="admin-ticket-age"><strong><?= (int)$ticket['edad_horas'] < 24 ? (int)$ticket['edad_horas'] . ' h' : (int)floor((int)$ticket['edad_horas'] / 24) . ' d' ?></strong><small><?= ui_e(ui_estado_label($ticket['estado'])) ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Capacidad</span><h2>Carga del equipo</h2></div><a href="admin_usuarios.php">Usuarios</a></div>
        <div class="admin-workload">
            <?php foreach ($agentes as $agente): ?>
                <div class="admin-workload-row">
                    <span class="kanban-avatar"><?= ui_e(ui_iniciales($agente['nombre'])) ?></span>
                    <span class="admin-workload-main"><strong><?= ui_e($agente['nombre']) ?></strong><span class="admin-progress"><i style="width: <?= round(((int)$agente['total'] / $max_carga) * 100) ?>%"></i></span><small><?= (int)$agente['en_curso'] ?> en curso · <?= (int)$agente['abiertas'] ?> abiertas</small></span>
                    <strong class="admin-workload-total"><?= (int)$agente['total'] ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Sistema</span><h2>Salud de servicios</h2></div><a href="admin_ajustes.php">Ajustes</a></div>
        <div class="admin-health-list">
            <div><span class="health-dot ok"></span><span><strong>Aplicacion y base de datos</strong><small>Servicio disponible</small></span><b>Operativo</b></div>
            <div><span class="health-dot <?= (int)$resumen['fallos_ia'] > 0 ? 'warn' : 'ok' ?>"></span><span><strong>Cola de inteligencia artificial</strong><small><?= (int)$resumen['cola_ia'] ?> en proceso · <?= (int)$resumen['fallos_ia'] ?> fallidos</small></span><b><?= (int)$resumen['fallos_ia'] > 0 ? 'Revisar' : 'Operativa' ?></b></div>
            <div><span class="health-dot <?= (int)($ia_hoy['errores'] ?? 0) > 0 ? 'warn' : 'ok' ?>"></span><span><strong>Actividad IA de hoy</strong><small><?= (int)($ia_hoy['total'] ?? 0) ?> llamadas · <?= (int)($ia_hoy['media_ms'] ?? 0) ?> ms de media</small></span><b><?= (int)($ia_hoy['errores'] ?? 0) ?> errores</b></div>
        </div>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Trazabilidad</span><h2>Actividad reciente</h2></div><a href="admin_auditoria.php">Auditoria</a></div>
        <div class="admin-activity-list">
            <?php foreach ($actividad as $evento): ?>
                <div><span class="admin-activity-icon">·</span><span><strong><?= ui_e(str_replace('_', ' ', ucfirst($evento['accion']))) ?></strong><small><?= ui_e($evento['usuario_email'] ?? 'Sistema') ?> · <?= ui_e($evento['fecha']) ?></small></span></div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-panel admin-quick-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Accesos</span><h2>Acciones rapidas</h2></div></div>
        <div class="admin-quick-grid">
            <a href="admin_usuarios.php"><span>+</span><strong>Crear usuario</strong><small>Alta y permisos</small></a>
            <a href="admin_clientes.php"><span>+</span><strong>Nueva empresa</strong><small>Cliente del portal</small></a>
            <a href="admin_ajustes.php"><span>IA</span><strong>Probar IA</strong><small>Conexion y cola</small></a>
            <a href="admin_flujos.php"><span>FL</span><strong>Configurar flujo</strong><small>Cierre y archivo</small></a>
            <a href="admin_catalogo.php"><span>CA</span><strong>Editar catalogo</strong><small>Contexto comercial IA</small></a>
            <a href="admin_auditoria.php"><span>AU</span><strong>Auditar</strong><small>Revisar acciones</small></a>
        </div>
    </section>
</div>

<?php ui_admin_pie(); ?>
