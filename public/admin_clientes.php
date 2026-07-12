<?php
// Administracion de empresas (clientes): CRUD y contadores de uso.
require_once __DIR__ . '/../src/arranque.php';

$aviso = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'crear' || $accion === 'editar') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $email_contacto = trim((string)($_POST['email_contacto'] ?? ''));
        $nivel_servicio = (string)($_POST['nivel_servicio'] ?? 'estandar');

        if ($nombre === '') {
            $error = 'El nombre de la empresa es obligatorio.';
        } elseif ($email_contacto !== '' && !filter_var($email_contacto, FILTER_VALIDATE_EMAIL)) {
            $error = 'El email de contacto no es valido.';
        } elseif (!isset(dominio_niveles_servicio()[$nivel_servicio])) {
            $error = 'El nivel de servicio no es valido.';
        } elseif ($accion === 'crear') {
            $pdo->prepare("INSERT INTO clientes (nombre, email_contacto, nivel_servicio) VALUES (:n, :e, :nivel)")
                ->execute([':n' => $nombre, ':e' => $email_contacto !== '' ? $email_contacto : null, ':nivel' => $nivel_servicio]);
            auditar($pdo, 'crear_cliente', $nombre);
            $aviso = "Empresa $nombre creada.";
        } elseif ($id > 0) {
            $pdo->prepare("UPDATE clientes SET nombre = :n, email_contacto = :e, nivel_servicio = :nivel WHERE id = :id")
                ->execute([':n' => $nombre, ':e' => $email_contacto !== '' ? $email_contacto : null, ':nivel' => $nivel_servicio, ':id' => $id]);
            auditar($pdo, 'editar_cliente', "empresa #$id ($nombre)");
            $aviso = 'Empresa actualizada.';
        }
    }

    if ($accion === 'estado') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $pdo->prepare("UPDATE clientes SET activo = 1 - activo WHERE id = :id")->execute([':id' => $id]);
            auditar($pdo, 'cambiar_estado_cliente', "empresa #$id");
            $aviso = 'Estado actualizado.';
        }
    }
}

$clientes = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM usuarios u WHERE u.cliente_id = c.id) AS usuarios,
            (SELECT COUNT(*) FROM incidencias i WHERE i.cliente_id = c.id) AS tickets,
            (SELECT COUNT(*) FROM incidencias i2 WHERE i2.cliente_id = c.id AND i2.estado <> 'cerrada') AS tickets_abiertos
     FROM clientes c ORDER BY c.nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$editando = null;
$editar_id = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($editar_id) {
    foreach ($clientes as $c) {
        if ((int)$c['id'] === $editar_id) {
            $editando = $c;
            break;
        }
    }
}

ui_admin_cabecera('Empresas', 'Clientes a los que pertenecen los usuarios del portal.', 'admin_clientes.php');
?>

<?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>

<div class="incidencia-box compact-box">
    <div class="section-head">
        <h2><?= $editando ? 'Editar empresa' : 'Crear empresa' ?></h2>
        <?php if ($editando): ?>
            <a href="admin_clientes.php" class="card-button secondary-button">Cancelar edicion</a>
        <?php endif; ?>
    </div>
    <form method="POST" class="filter-form-modern">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="<?= $editando ? 'editar' : 'crear' ?>">
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>
        <div class="filter-field">
            <label class="filter-label" for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" required value="<?= ui_e($editando['nombre'] ?? '') ?>">
        </div>
        <div class="filter-field">
            <label class="filter-label" for="email_contacto">Email de contacto (opcional)</label>
            <input type="email" id="email_contacto" name="email_contacto" value="<?= ui_e($editando['email_contacto'] ?? '') ?>">
        </div>
        <div class="filter-field">
            <label class="filter-label" for="nivel_servicio">Nivel de servicio</label>
            <select id="nivel_servicio" name="nivel_servicio">
                <?php foreach (dominio_niveles_servicio() as $clave => $etiqueta): ?>
                    <option value="<?= ui_e($clave) ?>" <?= ($editando['nivel_servicio'] ?? 'estandar') === $clave ? 'selected' : '' ?>><?= ui_e($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions-inline">
            <button type="submit" class="filter-button"><?= $editando ? 'Guardar' : 'Crear' ?></button>
        </div>
    </form>
</div>

<div class="incidencia-box compact-box">
    <h2>Empresas (<?= count($clientes) ?>)</h2>
    <?php if (empty($clientes)): ?>
        <p class="help-line">Todavia no hay empresas. Crea la primera para poder dar de alta usuarios con rol cliente.</p>
    <?php else: ?>
        <table class="logs-table">
            <thead>
                <tr><th>Nombre</th><th>Contacto</th><th>Nivel SLA</th><th>Usuarios</th><th>Tickets</th><th>Abiertos</th><th>Estado</th><th>Alta</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr class="<?= (int)$c['activo'] === 0 ? 'fila-apagada' : '' ?>">
                        <td><?= ui_e($c['nombre']) ?></td>
                        <td><?= ui_e($c['email_contacto'] ?? '-') ?></td>
                        <td><?= ui_e(dominio_niveles_servicio()[$c['nivel_servicio'] ?? 'estandar'] ?? 'Estandar') ?></td>
                        <td><?= (int)$c['usuarios'] ?></td>
                        <td><?= (int)$c['tickets'] ?></td>
                        <td><?= (int)$c['tickets_abiertos'] ?></td>
                        <td><?= (int)$c['activo'] === 1 ? 'Activa' : 'Desactivada' ?></td>
                        <td><?= ui_e($c['creado_en']) ?></td>
                        <td>
                            <div class="usuarios-acciones">
                                <a class="card-button secondary-button boton-mini" href="admin_clientes.php?editar=<?= (int)$c['id'] ?>">Editar</a>
                                <form method="POST" onsubmit="return confirm('Cambiar el estado de esta empresa?');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="estado">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="card-button secondary-button boton-mini"><?= (int)$c['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php ui_admin_pie(); ?>
