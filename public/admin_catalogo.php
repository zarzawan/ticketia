<?php
// Catalogo que aporta contexto verificable a las recomendaciones comerciales de IA.
require_once __DIR__ . '/../src/arranque.php';

$aviso = '';
$error = '';
$editar = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'guardar') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $categoria = trim((string)($_POST['categoria'] ?? ''));
        $precioRaw = str_replace(',', '.', trim((string)($_POST['precio'] ?? '')));
        $precio = $precioRaw === '' ? null : filter_var($precioRaw, FILTER_VALIDATE_FLOAT);
        $caracteristicas = trim((string)($_POST['caracteristicas'] ?? ''));
        $esfuerzo = (string)($_POST['esfuerzo'] ?? 'medio');
        $activo = isset($_POST['activo']) ? 1 : 0;
        if ($nombre === '' || mb_strlen($nombre) > 150 || !in_array($esfuerzo, ['bajo', 'medio', 'alto'], true) || $precio === false || ($precio !== null && $precio < 0)) {
            $error = 'Revisa el nombre, el precio y el nivel de esfuerzo.';
        } elseif ($id !== null) {
            $pdo->prepare("UPDATE catalogo_productos SET nombre=:nombre, descripcion=:descripcion, categoria=:categoria, precio=:precio, caracteristicas=:caracteristicas, esfuerzo=:esfuerzo, activo=:activo WHERE id=:id")
                ->execute(compact('nombre', 'descripcion', 'categoria', 'precio', 'caracteristicas', 'esfuerzo', 'activo', 'id'));
            auditar($pdo, 'editar_producto', "producto #$id");
            $aviso = 'Producto actualizado.';
        } else {
            $pdo->prepare("INSERT INTO catalogo_productos (nombre, descripcion, categoria, precio, caracteristicas, esfuerzo, activo) VALUES (:nombre,:descripcion,:categoria,:precio,:caracteristicas,:esfuerzo,:activo)")
                ->execute(compact('nombre', 'descripcion', 'categoria', 'precio', 'caracteristicas', 'esfuerzo', 'activo'));
            auditar($pdo, 'crear_producto', $nombre);
            $aviso = 'Producto anadido al contexto comercial de la IA.';
        }
    } elseif ($accion === 'estado') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $pdo->prepare("UPDATE catalogo_productos SET activo = 1 - activo WHERE id = :id")->execute([':id' => $id]);
            auditar($pdo, 'estado_producto', "producto #$id");
            $aviso = 'Disponibilidad actualizada.';
        }
    }
}
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM catalogo_productos WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['editar']]);
    $editar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$productos = $pdo->query("SELECT * FROM catalogo_productos ORDER BY activo DESC, categoria, nombre")->fetchAll(PDO::FETCH_ASSOC);
$activos = count(array_filter($productos, static fn(array $p): bool => (int)$p['activo'] === 1));

ui_admin_cabecera('Catalogo', 'Productos y servicios que la IA puede recomendar con contexto real.', 'admin_catalogo.php');
?>
<?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>

<section class="admin-hero catalog-admin-hero">
    <div><span class="admin-hero-kicker">Contexto de IA</span><h2><?= $activos ?> productos activos</h2><p>Solo los activos se envian al modelo al generar recomendaciones comerciales.</p></div>
    <div class="admin-hero-actions"><a class="card-button secondary-button" href="ver_catalogo.php">Vista del equipo</a></div>
</section>

<div class="split-2 admin-catalog-layout">
    <section class="incidencia-box compact-box">
        <h2><?= $editar ? 'Editar producto' : 'Nuevo producto' ?></h2>
        <form method="POST" class="form-stack">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">
            <label>Nombre<input name="nombre" maxlength="150" required value="<?= ui_e($editar['nombre'] ?? '') ?>"></label>
            <label>Descripcion<textarea name="descripcion" rows="4"><?= ui_e($editar['descripcion'] ?? '') ?></textarea></label>
            <div class="split-fields"><label>Categoria<input name="categoria" value="<?= ui_e($editar['categoria'] ?? '') ?>"></label><label>Precio<input name="precio" inputmode="decimal" value="<?= ui_e($editar['precio'] ?? '') ?>"></label></div>
            <label>Caracteristicas para la IA<textarea name="caracteristicas" rows="4" placeholder="Casos de uso, limites y cliente ideal"><?= ui_e($editar['caracteristicas'] ?? '') ?></textarea></label>
            <label>Esfuerzo<select name="esfuerzo"><?php foreach (['bajo', 'medio', 'alto'] as $nivel): ?><option value="<?= $nivel ?>" <?= ($editar['esfuerzo'] ?? 'medio') === $nivel ? 'selected' : '' ?>><?= ucfirst($nivel) ?></option><?php endforeach; ?></select></label>
            <label class="composer-check"><input type="checkbox" name="activo" value="1" <?= !$editar || (int)$editar['activo'] === 1 ? 'checked' : '' ?>> Disponible para recomendaciones</label>
            <div class="composer-row"><button class="card-button" type="submit">Guardar</button><?php if ($editar): ?><a class="card-button secondary-button" href="admin_catalogo.php">Cancelar</a><?php endif; ?></div>
        </form>
    </section>

    <section class="incidencia-box compact-box catalog-admin-list">
        <h2>Catalogo disponible</h2>
        <?php if (!$productos): ?><div class="empty-state"><strong>Catalogo vacio</strong><span>Anade productos para activar la asistencia comercial.</span></div><?php endif; ?>
        <?php foreach ($productos as $producto): ?>
            <article class="catalog-admin-row <?= (int)$producto['activo'] === 0 ? 'is-disabled' : '' ?>">
                <div><span><?= ui_e($producto['categoria'] ?: 'Sin categoria') ?> · <?= ui_e(ucfirst((string)$producto['esfuerzo'])) ?></span><strong><?= ui_e($producto['nombre']) ?></strong><small><?= ui_e($producto['descripcion'] ?? '') ?></small></div>
                <div><a href="admin_catalogo.php?editar=<?= (int)$producto['id'] ?>">Editar</a><form method="POST"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$producto['id'] ?>"><button type="submit" class="link-button"><?= (int)$producto['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button></form></div>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<?php ui_admin_pie(); ?>
