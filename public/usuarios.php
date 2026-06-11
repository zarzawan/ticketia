<?php
// Gestion de usuarios (solo admin; el guard de arranque.php lo garantiza).
require_once __DIR__ . '/../src/arranque.php';

$aviso = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $yo = auth_usuario();

    if ($accion === 'crear') {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $rol = (string)($_POST['rol'] ?? 'operador');

        if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Nombre y email validos son obligatorios.';
        } elseif (strlen($password) < 10) {
            $error = 'La contrasena debe tener al menos 10 caracteres.';
        } elseif (!in_array($rol, ['admin', 'operador', 'comercial', 'cliente'], true)) {
            $error = 'Rol no valido.';
        } else {
            try {
                $pdo->prepare(
                    "INSERT INTO usuarios (nombre, email, hash_password, rol) VALUES (:n, :e, :h, :r)"
                )->execute([
                    ':n' => $nombre,
                    ':e' => $email,
                    ':h' => password_hash($password, auth_algoritmo_hash()),
                    ':r' => $rol,
                ]);
                auditar($pdo, 'crear_usuario', "$email ($rol)");
                $aviso = "Usuario $email creado.";
            } catch (PDOException $e) {
                $error = 'Ya existe un usuario con ese email.';
            }
        }
    }

    if ($accion === 'estado') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id && $id !== (int)$yo['id']) {
            $pdo->prepare("UPDATE usuarios SET activo = 1 - activo WHERE id = :id")->execute([':id' => $id]);
            auditar($pdo, 'cambiar_estado_usuario', "usuario #$id");
            $aviso = 'Estado actualizado.';
        } else {
            $error = 'No puedes desactivar tu propia cuenta.';
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

$usuarios = $pdo->query(
    "SELECT id, nombre, email, rol, activo, ultimo_acceso, creado_en FROM usuarios ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$auditoria = $pdo->query(
    "SELECT fecha, usuario_email, accion, detalle, ip FROM auditoria ORDER BY id DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Usuarios</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div class="container">
    <div class="page-shell">
        <header class="page-header">
            <div>
                <h1>Usuarios</h1>
                <p class="subtitulo">Cuentas, roles y registro de actividad.</p>
            </div>
            <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
        </header>

        <div class="page-tools">
            <a href="index.php" class="card-button secondary-button">‹ Volver</a>
        </div>

        <?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>

        <div class="incidencia-box compact-box">
            <h2>Cuentas</h2>
            <table class="logs-table">
                <thead>
                    <tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>Ultimo acceso</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?= ui_e($u['nombre']) ?></td>
                            <td><?= ui_e($u['email']) ?></td>
                            <td><?= ui_e(ucfirst($u['rol'])) ?></td>
                            <td><?= $u['activo'] ? 'Activo' : 'Desactivado' ?></td>
                            <td><?= ui_e($u['ultimo_acceso'] ?? 'Nunca') ?></td>
                            <td>
                                <div class="usuarios-acciones">
                                    <form method="POST" onsubmit="return confirm('Cambiar el estado de esta cuenta?');">
                                        <?= csrf_campo() ?>
                                        <input type="hidden" name="accion" value="estado">
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="card-button secondary-button boton-mini"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
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

        <div class="incidencia-box compact-box">
            <h2>Crear usuario</h2>
            <form method="POST" class="filter-form-modern">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="crear">
                <div class="filter-field">
                    <label class="filter-label" for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" required>
                </div>
                <div class="filter-field">
                    <label class="filter-label" for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="filter-field">
                    <label class="filter-label" for="password">Contrasena (min. 10)</label>
                    <input type="password" id="password" name="password" required minlength="10">
                </div>
                <div class="filter-field">
                    <label class="filter-label" for="rol">Rol</label>
                    <select name="rol" id="rol">
                        <option value="operador">Operador</option>
                        <option value="comercial">Comercial</option>
                        <option value="admin">Admin</option>
                        <option value="cliente">Cliente</option>
                    </select>
                </div>
                <div class="filter-actions-inline">
                    <button type="submit" class="filter-button">Crear</button>
                </div>
            </form>
            <p class="help-line">El rol cliente tendra acceso al portal de cliente cuando este disponible.</p>
        </div>

        <div class="incidencia-box compact-box">
            <h2>Ultima actividad</h2>
            <table class="logs-table">
                <thead>
                    <tr><th>Fecha</th><th>Usuario</th><th>Accion</th><th>Detalle</th><th>IP</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($auditoria as $a): ?>
                        <tr>
                            <td><?= ui_e($a['fecha']) ?></td>
                            <td><?= ui_e($a['usuario_email'] ?? '-') ?></td>
                            <td><?= ui_e($a['accion']) ?></td>
                            <td><?= ui_e($a['detalle'] ?? '') ?></td>
                            <td><?= ui_e($a['ip'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
