<?php
// Administracion de usuarios: filtros, edicion, bloqueos y estadisticas.
// Solo rol admin (garantizado por el guard de arranque.php).
require_once __DIR__ . '/../src/arranque.php';

$aviso = '';
$error = '';
$yo = auth_usuario();

/** true si el usuario indicado es el ultimo administrador activo. */
function es_ultimo_admin(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> :id"
    );
    $stmt->execute([':id' => $id]);
    return (int)$stmt->fetchColumn() === 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'crear' || $accion === 'editar') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $rol = (string)($_POST['rol'] ?? 'operador');
        $cliente_id = filter_input(INPUT_POST, 'cliente_id', FILTER_VALIDATE_INT) ?: null;
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Nombre y email validos son obligatorios.';
        } elseif (!in_array($rol, ['admin', 'operador', 'comercial', 'cliente'], true)) {
            $error = 'Rol no valido.';
        } elseif ($rol !== 'cliente' && $cliente_id !== null) {
            $error = 'La empresa solo puede asignarse a usuarios con rol cliente. Cambia el rol a "Cliente" o deja la empresa en blanco.';
        }

        if ($error === '' && $accion === 'crear') {
            if (strlen($password) < 10) {
                $error = 'La contrasena debe tener al menos 10 caracteres.';
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO usuarios (nombre, email, hash_password, rol, cliente_id)
                         VALUES (:n, :e, :h, :r, :c)"
                    )->execute([
                        ':n' => $nombre, ':e' => $email,
                        ':h' => password_hash($password, auth_algoritmo_hash()),
                        ':r' => $rol, ':c' => $cliente_id,
                    ]);
                    auditar($pdo, 'crear_usuario', "$email ($rol)");
                    $aviso = "Usuario $email creado.";
                } catch (PDOException $e) {
                    $error = 'Ya existe un usuario con ese email.';
                }
            }
        }

        if ($error === '' && $accion === 'editar' && $id > 0) {
            // Protecciones: no degradar/desactivar al ultimo admin ni a uno mismo.
            if ($id === (int)$yo['id'] && ($rol !== 'admin' || !$activo)) {
                $error = 'No puedes quitarte el rol admin ni desactivar tu propia cuenta.';
            } elseif (($rol !== 'admin' || !$activo) && es_ultimo_admin($pdo, $id)) {
                $stmt = $pdo->prepare("SELECT rol FROM usuarios WHERE id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() === 'admin') {
                    $error = 'No puedes degradar ni desactivar al ultimo administrador activo.';
                }
            }

            if ($error === '') {
                try {
                    $pdo->prepare(
                        "UPDATE usuarios SET nombre = :n, email = :e, rol = :r, cliente_id = :c, activo = :a WHERE id = :id"
                    )->execute([
                        ':n' => $nombre, ':e' => $email, ':r' => $rol,
                        ':c' => $cliente_id, ':a' => $activo, ':id' => $id,
                    ]);
                    // Si deja de ser asignable (rol no operativo o cuenta
                    // desactivada), sus tickets abiertos vuelven a la cola.
                    if (!in_array($rol, ['admin', 'operador'], true) || !$activo) {
                        $desasignadas = $pdo->prepare(
                            "UPDATE incidencias SET asignado_id = NULL WHERE asignado_id = :id AND estado <> 'cerrada'"
                        );
                        $desasignadas->execute([':id' => $id]);
                        if ($desasignadas->rowCount() > 0) {
                            auditar($pdo, 'desasignar_tickets', "usuario #$id: " . $desasignadas->rowCount() . ' tickets abiertos a la cola');
                            $aviso = $desasignadas->rowCount() . ' tickets abiertos del usuario han vuelto a la cola de sin asignar. ';
                        }
                    }
                    if (strlen($password) >= 10) {
                        $pdo->prepare("UPDATE usuarios SET hash_password = :h WHERE id = :id")
                            ->execute([':h' => password_hash($password, auth_algoritmo_hash()), ':id' => $id]);
                    } elseif ($password !== '') {
                        $error = 'La contrasena no se cambio: debe tener al menos 10 caracteres.';
                    }
                    auditar($pdo, 'editar_usuario', "usuario #$id ($email)");
                    if ($error === '') {
                        $aviso .= 'Usuario actualizado.';
                    }
                } catch (PDOException $e) {
                    $error = 'Ya existe otro usuario con ese email.';
                }
            }
        }
    }

    if ($accion === 'estado') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id === (int)$yo['id']) {
            $error = 'No puedes desactivar tu propia cuenta.';
        } elseif (es_ultimo_admin($pdo, $id)) {
            $stmt = $pdo->prepare("SELECT rol, activo FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u && $u['rol'] === 'admin' && (int)$u['activo'] === 1) {
                $error = 'No puedes desactivar al ultimo administrador activo.';
            }
        }
        if ($error === '' && $id) {
            $pdo->prepare("UPDATE usuarios SET activo = 1 - activo WHERE id = :id")->execute([':id' => $id]);
            auditar($pdo, 'cambiar_estado_usuario', "usuario #$id");
            $aviso = 'Estado actualizado.';
        }
    }

    if ($accion === 'desbloquear') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id")
                ->execute([':id' => $id]);
            auditar($pdo, 'desbloquear_usuario', "usuario #$id");
            $aviso = 'Cuenta desbloqueada.';
        }
    }

    if ($accion === 'password') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $password = (string)($_POST['password'] ?? '');
        if (!$id || strlen($password) < 10) {
            $error = 'La nueva contrasena debe tener al menos 10 caracteres.';
        } else {
            $pdo->prepare("UPDATE usuarios SET hash_password = :h, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id")
                ->execute([':h' => password_hash($password, auth_algoritmo_hash()), ':id' => $id]);
            auditar($pdo, 'reset_password', "usuario #$id");
            $aviso = 'Contrasena actualizada.';
        }
    }
}

// ---------------- Datos para la vista ----------------

$clientes = $pdo->query("SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$roles_validos = ['admin', 'operador', 'comercial', 'cliente'];
$filtro_q = trim((string)($_GET['q'] ?? ''));
$filtro_rol = in_array($_GET['rol'] ?? '', $roles_validos, true) ? (string)$_GET['rol'] : '';
$filtro_estado = in_array($_GET['estado'] ?? '', ['activos', 'desactivados', 'bloqueados'], true) ? (string)$_GET['estado'] : '';

$sql = "SELECT u.*, c.nombre AS empresa,
               (SELECT COUNT(*) FROM incidencias i WHERE i.asignado_id = u.id AND i.estado <> 'cerrada') AS tickets_abiertos
        FROM usuarios u
        LEFT JOIN clientes c ON c.id = u.cliente_id
        WHERE 1=1";
$params = [];
if ($filtro_q !== '') {
    $sql .= " AND (u.nombre LIKE :q OR u.email LIKE :q)";
    $params[':q'] = "%$filtro_q%";
}
if ($filtro_rol !== '') {
    $sql .= " AND u.rol = :rol";
    $params[':rol'] = $filtro_rol;
}
if ($filtro_estado === 'activos') {
    $sql .= " AND u.activo = 1";
} elseif ($filtro_estado === 'desactivados') {
    $sql .= " AND u.activo = 0";
} elseif ($filtro_estado === 'bloqueados') {
    $sql .= " AND u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()";
}
$sql .= " ORDER BY u.nombre";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(activo = 1) AS activos,
            SUM(rol = 'admin' AND activo = 1) AS admins,
            SUM(rol = 'operador' AND activo = 1) AS operadores,
            SUM(rol = 'cliente' AND activo = 1) AS clientes,
            SUM(bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueados
     FROM usuarios"
)->fetch(PDO::FETCH_ASSOC);

// Usuario en edicion (GET ?editar=ID)
$editando = null;
$editar_id = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($editar_id) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $editar_id]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

ui_admin_cabecera('Usuarios', 'Cuentas, roles, bloqueos y actividad.', 'admin_usuarios.php');
?>

<?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>

<section class="stat-strip">
    <div class="stat"><span class="stat-value"><?= (int)$stats['total'] ?></span><span class="stat-label">Total</span></div>
    <div class="stat"><span class="stat-value"><?= (int)$stats['activos'] ?></span><span class="stat-label">Activos</span></div>
    <div class="stat"><span class="stat-value"><?= (int)$stats['admins'] ?></span><span class="stat-label">Admins</span></div>
    <div class="stat"><span class="stat-value"><?= (int)$stats['operadores'] ?></span><span class="stat-label">Operadores</span></div>
    <div class="stat"><span class="stat-value"><?= (int)$stats['clientes'] ?></span><span class="stat-label">Clientes</span></div>
    <div class="stat <?= (int)$stats['bloqueados'] > 0 ? 'stat-alerta' : '' ?>"><span class="stat-value"><?= (int)$stats['bloqueados'] ?></span><span class="stat-label">Bloqueados</span></div>
</section>

<div class="incidencia-box compact-box">
    <div class="section-head">
        <h2><?= $editando ? 'Editar usuario' : 'Crear usuario' ?></h2>
        <?php if ($editando): ?>
            <a href="admin_usuarios.php" class="card-button secondary-button">Cancelar edicion</a>
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
            <label class="filter-label" for="email">Email</label>
            <input type="email" id="email" name="email" required value="<?= ui_e($editando['email'] ?? '') ?>">
        </div>
        <div class="filter-field">
            <label class="filter-label" for="password"><?= $editando ? 'Nueva contrasena (opcional)' : 'Contrasena (min. 10)' ?></label>
            <input type="password" id="password" name="password" <?= $editando ? '' : 'required' ?> minlength="10" autocomplete="new-password">
        </div>
        <div class="filter-field">
            <label class="filter-label" for="rol">Rol</label>
            <select name="rol" id="rol">
                <?php foreach ($roles_validos as $r): ?>
                    <option value="<?= $r ?>" <?= ($editando['rol'] ?? 'operador') === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label class="filter-label" for="cliente_id">Empresa (solo rol cliente)</label>
            <select name="cliente_id" id="cliente_id">
                <option value="">Sin empresa</option>
                <?php foreach ($clientes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)($editando['cliente_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= ui_e($c['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <script>
            (function () {
                const rol = document.getElementById('rol');
                const empresa = document.getElementById('cliente_id');
                function ajustarEmpresa() {
                    const esCliente = rol.value === 'cliente';
                    empresa.disabled = !esCliente;
                    if (!esCliente) empresa.value = '';
                }
                rol.addEventListener('change', ajustarEmpresa);
                ajustarEmpresa();
            })();
        </script>
        <?php if ($editando): ?>
            <div class="filter-field">
                <label class="filter-label">Estado</label>
                <label class="composer-check"><input type="checkbox" name="activo" value="1" <?= (int)$editando['activo'] === 1 ? 'checked' : '' ?>> Cuenta activa</label>
            </div>
        <?php endif; ?>
        <div class="filter-actions-inline">
            <button type="submit" class="filter-button"><?= $editando ? 'Guardar cambios' : 'Crear' ?></button>
        </div>
    </form>
</div>

<div class="incidencia-box compact-box">
    <div class="section-head">
        <h2>Cuentas (<?= count($usuarios) ?>)</h2>
        <form method="GET" class="page-tools">
            <input type="text" name="q" placeholder="Buscar nombre o email" value="<?= ui_e($filtro_q) ?>" style="max-width:220px;">
            <select name="rol" onchange="this.form.submit()">
                <option value="">Todos los roles</option>
                <?php foreach ($roles_validos as $r): ?>
                    <option value="<?= $r ?>" <?= $filtro_rol === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="estado" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="activos" <?= $filtro_estado === 'activos' ? 'selected' : '' ?>>Activos</option>
                <option value="desactivados" <?= $filtro_estado === 'desactivados' ? 'selected' : '' ?>>Desactivados</option>
                <option value="bloqueados" <?= $filtro_estado === 'bloqueados' ? 'selected' : '' ?>>Bloqueados</option>
            </select>
            <button type="submit" class="card-button secondary-button">Filtrar</button>
        </form>
    </div>
    <table class="logs-table">
        <thead>
            <tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Empresa</th><th>Estado</th><th>Tickets abiertos</th><th>Ultimo acceso</th><th>Acciones</th></tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <?php $bloqueado = $u['bloqueado_hasta'] !== null && strtotime($u['bloqueado_hasta']) > time(); ?>
                <tr class="<?= (int)$u['activo'] === 0 ? 'fila-apagada' : '' ?>">
                    <td><span class="kanban-avatar" style="margin-right:6px;"><?= ui_e(ui_iniciales($u['nombre'])) ?></span><?= ui_e($u['nombre']) ?></td>
                    <td><?= ui_e($u['email']) ?></td>
                    <td><?= ui_e(ucfirst($u['rol'])) ?></td>
                    <td><?= ui_e($u['empresa'] ?? '-') ?></td>
                    <td>
                        <?= (int)$u['activo'] === 1 ? 'Activo' : 'Desactivado' ?>
                        <?= $bloqueado ? ' <span class="badge-interna">Bloqueado</span>' : '' ?>
                    </td>
                    <td><?= (int)$u['tickets_abiertos'] ?></td>
                    <td><?= ui_e($u['ultimo_acceso'] ?? 'Nunca') ?></td>
                    <td>
                        <div class="usuarios-acciones">
                            <a class="card-button secondary-button boton-mini" href="admin_usuarios.php?editar=<?= (int)$u['id'] ?>">Editar</a>
                            <?php if ($bloqueado): ?>
                                <form method="POST">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="desbloquear">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="card-button secondary-button boton-mini">Desbloquear</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Cambiar el estado de esta cuenta?');">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion" value="estado">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="card-button secondary-button boton-mini"><?= (int)$u['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                            </form>
                            <form method="POST" onsubmit="var p = prompt('Nueva contrasena (minimo 10 caracteres):'); if (!p) return false; this.password.value = p; return true;">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion" value="password">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="password" value="">
                                <button type="submit" class="card-button secondary-button boton-mini">Contrasena</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php ui_admin_pie(); ?>
