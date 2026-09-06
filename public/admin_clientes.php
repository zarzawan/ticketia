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

$consulta = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 180));
$estadoFiltro = in_array($_GET['estado'] ?? '', ['activas','inactivas'], true) ? $_GET['estado'] : '';
$condicion = '1=1'; $params = [];
if ($consulta !== '') { $condicion .= ' AND (c.nombre LIKE :nombre OR c.email_contacto LIKE :email)'; $params += [':nombre'=>'%' . $consulta . '%', ':email'=>'%' . $consulta . '%']; }
if ($estadoFiltro !== '') { $condicion .= ' AND c.activo = :activo'; $params[':activo'] = $estadoFiltro === 'activas' ? 1 : 0; }
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $condicion"); $stmt->execute($params); $total = (int)$stmt->fetchColumn();
$pagina = min(max(1, (int)($_GET['pagina'] ?? 1)), max(1, (int)ceil($total / 25))); $offset = ($pagina-1)*25;
$stmt = $pdo->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM usuarios u WHERE u.cliente_id = c.id) AS usuarios,
            (SELECT COUNT(*) FROM incidencias i WHERE i.cliente_id = c.id) AS tickets,
            (SELECT COUNT(*) FROM incidencias i2 WHERE i2.cliente_id = c.id AND i2.estado IN ('abierta','en_curso')) AS tickets_abiertos
     FROM clientes c WHERE $condicion ORDER BY c.nombre, c.id LIMIT 25 OFFSET $offset"
);
$stmt->execute($params);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editando = null;
$editar_id = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($editar_id) {
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id=?');
    $stmt->execute([$editar_id]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

ui_admin_cabecera('Organizaciones', 'Empresas, contactos y nivel de servicio en un solo lugar.', 'admin_clientes.php');
?>

<?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>

<details class="incidencia-box compact-box admin-editor" <?= $editando || $error !== '' || isset($_GET['nuevo']) ? 'open' : '' ?>>
    <summary><?= $editando ? 'Editar organizacion' : '+ Crear organizacion' ?><span>Contacto y nivel de servicio</span></summary>
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
</details>

<div class="incidencia-box compact-box">
    <div class="section-head"><h2>Organizaciones <span class="record-count"><?= $total ?></span></h2><form method="GET" class="page-tools"><input type="search" name="q" aria-label="Buscar organizaciones" placeholder="Nombre o contacto..." value="<?= ui_e($consulta) ?>"><select name="estado" aria-label="Estado de la organizacion"><option value="">Todos los estados</option><option value="activas" <?= $estadoFiltro === 'activas' ? 'selected' : '' ?>>Activas</option><option value="inactivas" <?= $estadoFiltro === 'inactivas' ? 'selected' : '' ?>>Desactivadas</option></select><button class="card-button secondary-button">Filtrar</button></form></div>
    <?php if (empty($clientes)): ?>
        <div class="admin-empty"><strong>No hay organizaciones en esta vista</strong><span>Crea una organizacion o cambia los filtros de busqueda.</span></div>
    <?php else: ?>
        <div class="table-scroll"><table class="logs-table">
            <thead>
                <tr><th>Nombre</th><th>Contacto</th><th>Nivel SLA</th><th>Usuarios</th><th>Tickets</th><th>Abiertos</th><th>Estado</th><th>Alta</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr class="<?= (int)$c['activo'] === 0 ? 'fila-apagada' : '' ?>">
                        <td><strong><?= ui_e($c['nombre']) ?></strong></td>
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
        </table></div>
    <?php endif; ?>
    <?= ui_paginacion($pagina, $total, 25, ['q'=>$consulta, 'estado'=>$estadoFiltro]) ?>
</div>

<?php ui_admin_pie(); ?>
