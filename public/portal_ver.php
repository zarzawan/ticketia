<?php
// Detalle de un ticket en el portal de cliente: sin datos internos
// (notas internas, asignaciones, recomendaciones IA ni herramientas del equipo).
require_once __DIR__ . '/../src/arranque.php';

$usuario = auth_usuario();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || !incidencia_visible_para_cliente($pdo, (int)$id, $usuario)) {
    header('Location: portal.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM incidencias WHERE id = :id");
$stmt->execute([':id' => $id]);
$incidencia = $stmt->fetch(PDO::FETCH_ASSOC);

// Solo la conversacion publica: las notas internas jamas salen del equipo.
$stmt = $pdo->prepare(
    "SELECT m.autor, m.mensaje, m.fecha, u.nombre AS usuario_nombre
     FROM mensajes m
     LEFT JOIN usuarios u ON u.id = m.usuario_id
     WHERE m.id_incidencia = :id AND m.interno = 0
     ORDER BY m.fecha ASC"
);
$stmt->execute([':id' => $id]);
$mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$adjuntos = adjuntos_de($pdo, (int)$id);

$id_incidencia = (int)$id;
$estado_label = ui_estado_label((string)$incidencia['estado']);
$es_activa = in_array((string)$incidencia['estado'], dominio_estados_activos(), true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Ticket #<?= $id_incidencia ?></title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div class="container">
<div class="page-shell">
    <header class="page-header">
        <div class="detail-heading">
            <h1 class="detail-title"><?= ui_e($incidencia['titulo']) ?></h1>
            <p class="subtitulo detail-subline">
                <span class="status-pill pill-estado pill-<?= ui_e(ui_estado_class((string)$incidencia['estado'])) ?>"><?= ui_e($estado_label) ?></span>
                <span>#<?= $id_incidencia ?></span>
                <span>· Creado el <?= ui_e(date('d/m/Y H:i', strtotime((string)$incidencia['fecha_creacion']))) ?></span>
                <?php if (!empty($incidencia['fecha_cierre'])): ?>
                    <span>· Cerrado el <?= ui_e(date('d/m/Y H:i', strtotime((string)$incidencia['fecha_cierre']))) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
    </header>

    <div class="page-tools">
        <a href="portal.php" class="card-button secondary-button">‹ Mis tickets</a>
    </div>

    <?php if (isset($_GET['ok'])): ?>
        <div class="success-message">Tu mensaje se ha enviado al equipo de soporte.</div>
    <?php endif; ?>
    <?php if (($_GET['confirmacion'] ?? '') === 'aceptada'): ?>
        <div class="success-message">Gracias. La solucion ha quedado confirmada y el ticket se ha cerrado.</div>
    <?php elseif (($_GET['confirmacion'] ?? '') === 'rechazada'): ?>
        <div class="success-message">La incidencia vuelve al equipo con tu comentario.</div>
    <?php elseif (($_GET['confirmacion'] ?? '') === 'motivo'): ?>
        <div class="login-error">Explica brevemente por que la solucion no ha funcionado.</div>
    <?php endif; ?>

    <div class="incidencia-box compact-box">
        <h2>Descripcion</h2>
        <div class="recomendacion-text" style="white-space:pre-wrap;"><?= ui_e($incidencia['descripcion']) ?></div>
    </div>

    <?php if (($incidencia['estado'] ?? '') === 'resuelta'): ?>
        <section class="portal-resolution-card">
            <span class="support-eyebrow">Solucion propuesta</span>
            <h2><?= ui_e(dominio_codigos_resolucion()[$incidencia['resolucion_codigo']] ?? 'El equipo ha resuelto la solicitud') ?></h2>
            <p><?= nl2br(ui_e((string)($incidencia['resolucion_notas'] ?? ''))) ?></p>
            <div class="portal-resolution-actions">
                <form action="confirmar_resolucion.php" method="POST">
                    <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><input type="hidden" name="decision" value="aceptar"><?= csrf_campo() ?>
                    <button type="submit" class="card-button">Si, esta solucionado</button>
                </form>
                <details>
                    <summary>No, necesito mas ayuda</summary>
                    <form action="confirmar_resolucion.php" method="POST" class="form-stack">
                        <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><input type="hidden" name="decision" value="rechazar"><?= csrf_campo() ?>
                        <label for="motivo">Que sigue fallando</label>
                        <textarea id="motivo" name="motivo" rows="3" required></textarea>
                        <button type="submit" class="card-button secondary-button">Devolver al equipo</button>
                    </form>
                </details>
            </div>
        </section>
    <?php endif; ?>

    <div class="incidencia-box compact-box">
        <h2>Conversacion</h2>
        <?php if (!empty($mensajes)): ?>
            <div class="mensajes-grid">
                <?php foreach ($mensajes as $mensaje): ?>
                    <?php
                    $is_cliente = strtolower((string)($mensaje['autor'] ?? '')) === 'cliente';
                    $quien_msg = $is_cliente
                        ? (string)($mensaje['usuario_nombre'] ?? 'Tu')
                        : 'Equipo de soporte';
                    ?>
                    <div class="mensaje-card <?= $is_cliente ? 'cliente' : 'tecnico' ?>">
                        <div class="mensaje-header">
                            <span class="mensaje-autor"><?= ui_e($quien_msg) ?></span>
                            <span class="mensaje-fecha"><?= ui_e((string)($mensaje['fecha'] ?? '')) ?></span>
                        </div>
                        <div class="recomendacion-text" style="white-space:pre-wrap;"><?= ui_e((string)($mensaje['mensaje'] ?? '')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="help-line">Todavia no hay respuestas. El equipo de soporte respondera lo antes posible.</p>
        <?php endif; ?>

        <?php if ($es_activa): ?>
            <form action="guardar_mensaje.php" method="POST" class="composer">
                <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                <textarea name="mensaje" rows="4" required placeholder="Escribe tu mensaje para el equipo de soporte..."></textarea>
                <div class="composer-row">
                    <span class="composer-spacer"></span>
                    <input type="submit" value="Enviar">
                </div>
            </form>
        <?php elseif (($incidencia['estado'] ?? '') === 'cerrada'): ?>
            <p class="help-line">Este ticket esta cerrado. Si el problema vuelve a aparecer, abre uno nuevo desde <a href="portal.php">Mis tickets</a>.</p>
        <?php else: ?>
            <p class="help-line">Revisa la solucion propuesta arriba para confirmar si necesitas mas ayuda.</p>
        <?php endif; ?>
    </div>

    <div class="incidencia-box compact-box" id="adjuntos">
        <h2>Adjuntos</h2>
        <?php if (isset($_GET['adjunto'])): ?>
            <div class="success-message">Adjunto subido correctamente.</div>
        <?php elseif (isset($_GET['adjunto_error'])): ?>
            <div class="login-error"><?= ui_e((string)$_GET['adjunto_error']) ?></div>
        <?php endif; ?>
        <?php if (!empty($adjuntos)): ?>
            <ul class="adjuntos-lista">
                <?php foreach ($adjuntos as $adj): ?>
                    <li>
                        <a href="descargar_adjunto.php?id=<?= (int)$adj['id'] ?>"><?= ui_e($adj['nombre_original']) ?></a>
                        <span class="adjunto-meta"><?= ui_e(adjuntos_formato_tamano((int)$adj['tamano'])) ?> - <?= ui_e($adj['fecha']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="help-line">No hay adjuntos en este ticket.</p>
        <?php endif; ?>
        <?php if ($es_activa): ?>
            <form action="subir_adjunto.php" method="POST" enctype="multipart/form-data" class="composer-row adjuntos-form">
                <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                <input type="file" name="adjunto" required>
                <span class="composer-spacer"></span>
                <input type="submit" value="Subir adjunto">
            </form>
            <p class="help-line">Maximo <?= ui_e(adjuntos_formato_tamano(adjuntos_max_bytes())) ?> por fichero.</p>
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
