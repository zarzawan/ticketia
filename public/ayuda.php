<?php
require_once __DIR__ . '/../src/arranque.php';
$esCliente = auth_es('cliente');
$consulta = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 180));
$id = max(0, (int)($_GET['id'] ?? 0));
$articulo = null;
if ($id > 0 && conocimiento_disponible($pdo)) {
    $stmt = $pdo->prepare('SELECT titulo,resumen,contenido,categoria,estado,visibilidad,actualizado_en FROM conocimiento WHERE id=?');
    $stmt->execute([$id]);
    $articulo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$articulo || !conocimiento_visible($articulo, $esCliente)) $articulo = null;
}
if ($id > 0 && !$articulo) http_response_code(404);
$articulos = $id === 0 ? conocimiento_buscar($pdo, $consulta, $esCliente, 13, max(0, (int)($_GET['antes_de'] ?? 0))) : [];
$hayMas = count($articulos) > 12;
if ($hayMas) array_pop($articulos);
ui_portal_cabecera($articulo['titulo'] ?? 'Guias y soluciones', 'ayuda');
?>
<?php if ($id > 0): ?>
<a class="back-link" href="ayuda.php">&larr; Todas las guias</a>
<?php if (!$articulo): ?><section class="admin-panel admin-empty"><h1>Articulo no disponible</h1><p>Puede haberse retirado o no estar disponible para tu cuenta.</p></section><?php else: ?>
<article class="knowledge-article"><span class="section-label"><?= ui_e($articulo['categoria']) ?> &middot; Actualizado el <?= ui_e(date('d/m/Y', strtotime($articulo['actualizado_en']))) ?></span><h1><?= ui_e($articulo['titulo']) ?></h1><p class="article-intro"><?= ui_e($articulo['resumen']) ?></p><div class="article-content"><?= ui_e($articulo['contenido']) ?></div></article>
<?php endif; ?>
<?php else: ?>
<section class="help-hero"><span class="section-label">Centro de conocimiento</span><h1>Encuentra una solucion.</h1><p>Guias revisadas por nuestro equipo para ayudarte paso a paso.</p><form method="GET" class="help-search"><input type="search" name="q" value="<?= ui_e($consulta) ?>" placeholder="Que necesitas resolver?" aria-label="Buscar en las guias"><button class="card-button">Buscar</button></form></section>
<div class="section-head"><h2><?= $consulta ? 'Resultados para tu busqueda' : 'Guias y soluciones' ?></h2><?php if ($consulta): ?><a href="ayuda.php">Limpiar busqueda</a><?php endif; ?></div>
<?php if (!$articulos): ?><div class="admin-panel admin-empty"><span class="empty-icon"><?= ui_icono('libro') ?></span><strong><?= $consulta ? 'No hemos encontrado una guia' : 'Estamos preparando nuestras primeras guias' ?></strong><span>El equipo de soporte puede ayudarte con tu solicitud.</span></div><?php else: ?>
<div class="knowledge-grid"><?php foreach ($articulos as $fila): ?><a class="knowledge-card" href="ayuda.php?id=<?= (int)$fila['id'] ?>"><span class="section-label"><?= ui_e($fila['categoria']) ?></span><h2><?= ui_e($fila['titulo']) ?></h2><p><?= ui_e($fila['resumen']) ?></p><span class="article-link">Leer guia &rarr;</span></a><?php endforeach; ?></div>
<?php if ($hayMas): ?><a class="card-button secondary-button" href="?<?= ui_e(http_build_query(['q'=>$consulta,'antes_de'=>(int)end($articulos)['id']])) ?>">Mas guias</a><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($esCliente): ?><section class="help-contact"><div><h2>Necesitas mas ayuda?</h2><p>Cuentanos que ocurre. El equipo te acompanara hasta resolverlo.</p></div><a class="card-button" href="portal.php?nueva=1#nuevaSolicitud">Crear solicitud</a></section><?php elseif (auth_es('admin')): ?><a class="card-button secondary-button" href="admin_conocimiento.php">Gestionar conocimiento</a><?php endif; ?>
<?php ui_portal_pie(); ?>
