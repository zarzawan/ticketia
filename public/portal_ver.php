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
$paginaMensajes = soporte_mensajes($pdo, (int)$id, true, max(0, (int)($_GET['antes'] ?? 0)));
$mensajes = $paginaMensajes['mensajes'];

$adjuntos = adjuntos_de($pdo, (int)$id);

$id_incidencia = (int)$id;
$estado_label = ui_estado_label((string)$incidencia['estado']);
$es_activa = in_array((string)$incidencia['estado'], dominio_estados_activos(), true);

$stmt = $pdo->prepare('SELECT autor FROM mensajes WHERE id_incidencia = :id AND interno = 0 ORDER BY fecha DESC, id DESC LIMIT 1');
$stmt->execute([':id' => $id]);
$ultimoAutor = $stmt->fetchColumn() ?: null;
$siguienteAccion = portal_siguiente_accion($incidencia['estado'], $ultimoAutor);
$valoracion = null;
$puedeValorar = (int)$incidencia['creado_por'] === (int)$usuario['id']
    && in_array($incidencia['estado'], ['resuelta', 'cerrada'], true) && conocimiento_disponible($pdo);
if ($puedeValorar) {
    $stmt = $pdo->prepare('SELECT puntuacion,comentario FROM satisfaccion_servicio WHERE incidencia_id=? AND usuario_id=?');
    $stmt->execute([$id, $usuario['id']]);
    $valoracion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
ui_portal_cabecera('Solicitud #' . $id_incidencia);
?>
<a class="back-link" href="portal.php">&larr; Mis solicitudes</a>
<header class="request-detail-heading"><div><span class="section-label">Solicitud #<?= $id_incidencia ?></span><h1><?= ui_e($incidencia['titulo']) ?></h1><p>Creada el <?= ui_e(date('d/m/Y H:i', strtotime($incidencia['fecha_creacion']))) ?></p></div><span class="status-pill pill-estado pill-<?= ui_e(ui_estado_class($incidencia['estado'])) ?>"><?= ui_e($estado_label) ?></span></header>
<ol class="request-progress" aria-label="Progreso de la solicitud">
<?php $paso = array_search($incidencia['estado'], ['abierta', 'en_curso', 'resuelta', 'cerrada'], true); ?>
<?php foreach (['Recibida', 'En trabajo', 'Solucion propuesta', 'Finalizada'] as $indice=>$etiqueta): ?><li class="<?= $indice <= $paso ? 'is-complete' : '' ?>" <?= $indice === $paso ? 'aria-current="step"' : '' ?>><span><?= $indice + 1 ?></span><?= $etiqueta ?></li><?php endforeach; ?>
</ol>
<section class="next-action action-<?= $siguienteAccion['tono'] ?>"><div><?= ui_icono('flujo') ?></div><div><strong><?= ui_e($siguienteAccion['titulo']) ?></strong><p><?= ui_e($siguienteAccion['detalle']) ?></p></div></section>
<div class="request-detail-layout"><div>
    <?php if ($paginaMensajes['antes']): ?><a href="?id=<?= (int)$id ?>&amp;antes=<?= $paginaMensajes['antes'] ?>">Cargar mensajes anteriores</a><?php endif; ?>
    <?php if (!empty($_GET['antes'])): ?><a href="?id=<?= (int)$id ?>">Volver a los mas recientes</a><?php endif; ?>
    <?php if (isset($_GET['conflicto'])): ?><p class="login-error">La solicitud ha cambiado. Revisa su estado antes de volver a responder.</p><?php endif; ?>
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

    <details class="admin-panel request-description"><summary>Lo que nos contaste</summary>
        <div class="recomendacion-text" style="white-space:pre-wrap;"><?= ui_e($incidencia['descripcion']) ?></div>
    </details>

    <?php if (in_array($incidencia['estado'], ['resuelta', 'cerrada'], true)): ?>
        <section class="portal-resolution-card">
            <span class="support-eyebrow"><?= $incidencia['estado'] === 'resuelta' ? 'Solucion propuesta' : 'Solucion final' ?></span>
            <h2><?= ui_e(dominio_codigos_resolucion()[$incidencia['resolucion_codigo']] ?? 'El equipo ha resuelto la solicitud') ?></h2>
            <p><?= nl2br(ui_e((string)($incidencia['resolucion_notas'] ?? ''))) ?></p>
            <?php if ($incidencia['estado'] === 'resuelta'): ?><div class="portal-resolution-actions">
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
            </div><?php endif; ?>
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
            <p class="help-line"><?= $es_activa ? 'Todavia no hay respuestas. El equipo de soporte respondera lo antes posible.' : 'No se han registrado mensajes en esta solicitud.' ?></p>
        <?php endif; ?>

        <?php if ($es_activa): ?>
            <form action="guardar_mensaje.php" method="POST" class="composer">
                <input type="hidden" name="solicitud_id" value="<?= bin2hex(random_bytes(16)) ?>">
                <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                <label for="mensajeCliente" class="sr-only">Tu mensaje al equipo</label><textarea id="mensajeCliente" name="mensaje" rows="4" required placeholder="Escribe tu mensaje para el equipo de soporte..."></textarea>
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

    </div><aside class="request-detail-aside"><div class="incidencia-box compact-box" id="adjuntos">
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
                <label for="archivoCliente" class="sr-only">Archivo para adjuntar</label><input type="file" id="archivoCliente" name="adjunto" required>
                <span class="composer-spacer"></span>
                <input type="submit" value="Subir adjunto">
            </form>
            <p class="help-line">Maximo <?= ui_e(adjuntos_formato_tamano(adjuntos_max_bytes())) ?> por fichero.</p>
        <?php endif; ?>
    </div>
<?php if ($puedeValorar): ?>
<section class="admin-panel satisfaction-card" id="valoracion">
    <span class="section-label">Tu opinion cuenta</span><h2>Como ha sido la ayuda?</h2><p>Valora la atencion recibida. Es opcional y puedes cambiar tu valoracion.</p>
    <?php if (($_GET['valoracion'] ?? '') === 'ok'): ?><p class="success-message" role="status">Gracias por valorar el servicio.</p><?php elseif (($_GET['valoracion'] ?? '') === 'error'): ?><p class="login-error" role="alert">No se pudo guardar. Selecciona una puntuacion e intentalo de nuevo.</p><?php endif; ?>
    <form method="POST" action="valorar_servicio.php" class="form-stack">
        <?= csrf_campo() ?><input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>">
        <fieldset class="rating-options"><legend>De 1 (mala) a 5 (excelente)</legend><?php for ($nota=1; $nota<=5; $nota++): ?><label><input type="radio" name="puntuacion" value="<?= $nota ?>" <?= (int)($valoracion['puntuacion'] ?? 0) === $nota ? 'checked' : '' ?> required><span><?= $nota ?></span></label><?php endfor; ?></fieldset>
        <label for="comentarioValoracion">Comentario opcional</label><textarea name="comentario" id="comentarioValoracion" rows="3" maxlength="1000"><?= ui_e($valoracion['comentario'] ?? '') ?></textarea>
        <button class="card-button secondary-button">Guardar valoracion</button>
    </form>
</section>
<?php endif; ?>
<section class="portal-tip"><h3>Necesitas consultar algo?</h3><p>Encuentra respuestas paso a paso en nuestras guias.</p><a href="ayuda.php">Ir al centro de ayuda &rarr;</a></section>
</aside></div>
<?php ui_portal_pie(); ?>
