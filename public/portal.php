<?php
// Portal: trabajo pendiente, ayuda contextual e historial separado.
require_once __DIR__ . '/../src/arranque.php';
$usuario = auth_usuario();
$vista = in_array($_GET['vista'] ?? '', ['historial', 'respuesta', 'resueltas'], true) ? $_GET['vista'] : 'activas';
if (($_GET['estado'] ?? '') === 'resuelta') $vista = 'resueltas';
$busqueda = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 180));
$cursor = max(0, (int)($_GET['antes_de'] ?? 0));
$ambitoSql = $usuario['cliente_id'] !== null ? 'i.cliente_id = :ambito' : 'i.creado_por = :ambito';
$ambito = $usuario['cliente_id'] ?? $usuario['id'];
$ultimoAutor = '(SELECT m.autor FROM mensajes m WHERE m.id_incidencia=i.id AND m.interno=0 ORDER BY m.fecha DESC,m.id DESC LIMIT 1)';
$stmt = $pdo->prepare("SELECT SUM(i.estado IN ('abierta','en_curso','esperando_cliente','resuelta')) AS activas, SUM(i.estado='resuelta') AS resueltas, SUM(i.estado='cerrada') AS historial, SUM(i.estado IN ('abierta','en_curso','esperando_cliente') AND $ultimoAutor='tecnico') AS respuesta FROM incidencias i WHERE $ambitoSql");
$stmt->execute([':ambito'=>$ambito]); $stats = $stmt->fetch(PDO::FETCH_ASSOC);
$sql = "SELECT i.id,i.titulo,i.estado,i.fecha_creacion,$ultimoAutor AS ultimo_autor,
    COALESCE((SELECT MAX(m.fecha) FROM mensajes m WHERE m.id_incidencia=i.id AND m.interno=0),i.fecha_creacion) AS ultima_actividad
    FROM incidencias i WHERE $ambitoSql";
$params = [':ambito'=>$ambito];
$sql .= match ($vista) {
    'historial' => " AND i.estado='cerrada'",
    'resueltas' => " AND i.estado='resuelta'",
    'respuesta' => " AND i.estado IN ('abierta','en_curso','esperando_cliente') AND $ultimoAutor='tecnico'",
    default => " AND i.estado IN ('abierta','en_curso','esperando_cliente','resuelta')",
};
if ($busqueda !== '') {
    $sql .= ' AND (i.titulo LIKE :titulo OR i.descripcion LIKE :descripcion OR i.id = :numero)';
    $params += [':titulo'=>'%' . $busqueda . '%', ':descripcion'=>'%' . $busqueda . '%', ':numero'=>ctype_digit(ltrim($busqueda, '#')) ? (int)ltrim($busqueda, '#') : 0];
}
if ($cursor > 0) { $sql .= ' AND i.id < :cursor'; $params[':cursor'] = $cursor; }
$sql .= ' ORDER BY i.id DESC LIMIT 26';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hayMas = count($tickets) > 25; if ($hayMas) array_pop($tickets);
$articulos = conocimiento_buscar($pdo, '', true, 3);
$nombre = explode(' ', trim($usuario['nombre']))[0];
ui_portal_cabecera('Mis solicitudes');
?>
<section class="help-hero portal-welcome"><div><span class="section-label">Estamos para ayudarte</span><h1>Hola, <?= ui_e($nombre) ?>.</h1><p>Un lugar para resolver tus dudas y seguir tus solicitudes.</p></div><a class="card-button" href="portal.php?nueva=1#nuevaSolicitud">+ Nueva solicitud</a></section>
<?php if (isset($_GET['ok'])): ?><div class="success-message" role="status">Hemos recibido tu solicitud. Puedes seguirla en el listado.</div><?php endif; ?>
<?php if (isset($_GET['error'])): ?><div class="login-error" role="alert">Revisa el titulo (hasta 255 caracteres) y la descripcion antes de enviar.</div><?php endif; ?>
<div class="request-types" aria-label="Como podemos ayudarte">
    <button type="button" data-plantilla-solicitud="Que ocurre:&#10;&#10;Desde cuando:&#10;&#10;A quien afecta:&#10;&#10;Que he probado:"><?= ui_icono('ajustes') ?><span><strong>Algo no funciona</strong><small>Comunica un problema</small></span><b>&rarr;</b></button>
    <button type="button" data-plantilla-solicitud="Que necesito:&#10;&#10;Para que lo necesito:&#10;&#10;Fecha deseada:"><?= ui_icono('personas') ?><span><strong>Necesito ayuda</strong><small>Acceso, consulta o solicitud</small></span><b>&rarr;</b></button>
    <a href="ayuda.php"><?= ui_icono('libro') ?><span><strong>Buscar una solucion</strong><small>Guias de nuestro equipo</small></span><b>&rarr;</b></a>
</div>
<details class="admin-panel new-request" id="nuevaSolicitud" <?= isset($_GET['nueva']) || isset($_GET['error']) ? 'open' : '' ?>>
    <summary>Nueva solicitud <span>Describe lo que necesitas</span></summary>
    <form action="guardar_incidencia.php" method="POST" class="form-stack request-form">
        <?= csrf_campo() ?><label for="titulo">Que necesitas resolver?</label><input id="titulo" name="titulo" maxlength="255" required placeholder="Por ejemplo: no puedo acceder al correo" data-sugerir-articulos autocomplete="off">
        <div id="ayudaSugerida" class="suggested-help" aria-live="polite" hidden></div>
        <label for="descripcion">Cuentanos un poco mas</label><textarea id="descripcion" name="descripcion" rows="6" maxlength="30000" required placeholder="Que ocurre, desde cuando y que has probado. Puedes escribir en tu idioma."></textarea>
        <div class="request-form-footer"><p>Podras adjuntar archivos y ampliar la informacion al abrir la solicitud.</p><button class="card-button" type="submit">Enviar solicitud</button></div>
    </form>
</details>
<div class="portal-workspace"><section class="admin-panel request-list-panel">
    <div class="section-head"><div><span class="section-label">Seguimiento</span><h2>Tus solicitudes</h2></div><span class="help-line"><?= $usuario['cliente_id'] !== null ? 'Solicitudes compartidas de tu organizacion' : 'Solo tus solicitudes' ?></span></div>
    <nav class="request-tabs" aria-label="Filtrar solicitudes"><?php foreach (['activas'=>'En seguimiento','respuesta'=>'Con respuesta','resueltas'=>'Confirmar solucion','historial'=>'Historial'] as $clave=>$etiqueta): ?><a href="portal.php?vista=<?= $clave ?>" <?= $vista === $clave ? 'aria-current="page"' : '' ?>><?= $etiqueta ?><span><?= (int)$stats[$clave] ?></span></a><?php endforeach; ?></nav>
    <form method="GET" class="request-search"><input type="hidden" name="vista" value="<?= $vista ?>"><input type="search" name="q" value="<?= ui_e($busqueda) ?>" placeholder="Buscar por asunto o numero..." aria-label="Buscar solicitudes"><button class="card-button secondary-button">Buscar</button><?php if ($busqueda): ?><a href="portal.php?vista=<?= $vista ?>">Limpiar</a><?php endif; ?></form>
    <?php if (!$tickets): ?><div class="admin-empty"><span class="empty-icon"><?= ui_icono('panel') ?></span><strong><?= $busqueda ? 'No hay coincidencias' : ($vista === 'historial' ? 'Aun no hay solicitudes cerradas' : 'Todo al dia en esta vista') ?></strong><span><?= $busqueda ? 'Prueba con otro asunto o numero de solicitud.' : 'Aqui veras las solicitudes y el siguiente paso de cada una.' ?></span></div><?php else: ?>
    <div class="request-list"><?php foreach ($tickets as $ticket): ?><?php $accion = portal_siguiente_accion($ticket['estado'], $ticket['ultimo_autor']); ?>
        <a class="request-row" href="portal_ver.php?id=<?= (int)$ticket['id'] ?>"><span class="request-number">#<?= (int)$ticket['id'] ?></span><span class="request-subject"><strong><?= ui_e($ticket['titulo']) ?></strong><small class="action-<?= $accion['tono'] ?>"><?= ui_e($accion['titulo']) ?></small></span><span class="request-status"><span class="status-pill pill-estado pill-<?= ui_e(ui_estado_class($ticket['estado'])) ?>"><?= ui_e(ui_estado_label($ticket['estado'])) ?></span><time><?= ui_e(date('d/m/Y H:i', strtotime($ticket['ultima_actividad']))) ?></time></span><span aria-hidden="true">&rsaquo;</span></a>
    <?php endforeach; ?></div>
    <?php endif; ?>
    <div class="pagination"><span><?= count($tickets) ?> solicitudes en esta pagina</span><?php if ($cursor): ?><a href="?<?= ui_e(http_build_query(['vista'=>$vista,'q'=>$busqueda])) ?>">Mas recientes</a><?php endif; ?><?php if ($hayMas): ?><a class="card-button secondary-button" href="?<?= ui_e(http_build_query(['vista'=>$vista,'q'=>$busqueda,'antes_de'=>(int)end($tickets)['id']])) ?>">Ver anteriores</a><?php endif; ?></div>
</section><aside class="portal-help-aside"><section class="admin-panel"><span class="section-label">Antes de abrir una solicitud</span><h2>Una guia puede ayudar</h2><?php if ($articulos): ?><?php foreach ($articulos as $articulo): ?><a class="aside-article" href="ayuda.php?id=<?= (int)$articulo['id'] ?>"><?= ui_icono('libro') ?><span><?= ui_e($articulo['titulo']) ?></span><span>&rarr;</span></a><?php endforeach; ?><?php else: ?><p>Estamos preparando las primeras soluciones. Mientras tanto, cuentanos que necesitas.</p><?php endif; ?><a href="ayuda.php">Explorar centro de ayuda &rarr;</a></section><section class="portal-tip"><h3>Tu solicitud, paso a paso</h3><p>Recibida &rarr; En trabajo &rarr; Solucion propuesta</p><p>Cuando recibas una solucion, podras confirmarla o pedir mas ayuda. Los casos finalizados permanecen en tu historial.</p></section></aside></div>
<?php ui_portal_pie(); ?>
