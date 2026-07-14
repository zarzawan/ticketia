<?php
require_once __DIR__ . '/../src/arranque.php';

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$recuperacion = cuenta_recuperacion_buscar($pdo, $token);
$error = '';
$completado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $repetir = (string)($_POST['repetir_password'] ?? '');
    if ($password !== $repetir) {
        $error = 'Las contrasenas no coinciden.';
    } else {
        $resultado = cuenta_recuperacion_restablecer($pdo, $token, $password);
        $completado = $resultado['ok'];
        $error = (string)($resultado['error'] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Nueva contrasena</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body class="login-body">
<div class="login-shell">
    <div class="login-card">
        <div class="login-marca">
            <h1>Nueva contrasena</h1>
            <p class="subtitulo">El enlace solo puede utilizarse una vez.</p>
        </div>
        <?php if ($completado): ?>
            <div class="success-message">Contrasena actualizada. Las sesiones anteriores han sido revocadas.</div>
            <p class="login-pie"><a class="login-boton auth-link-button" href="login.php">Iniciar sesion</a></p>
        <?php elseif ($recuperacion === null): ?>
            <div class="login-error">El enlace no es valido o ha caducado.</div>
            <p class="help-line login-pie"><a href="solicitar_recuperacion.php">Solicitar un enlace nuevo</a></p>
        <?php else: ?>
            <?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>
            <form method="POST" class="form-stack">
                <?= csrf_campo() ?>
                <input type="hidden" name="token" value="<?= ui_e($token) ?>">
                <div class="filter-field">
                    <label class="filter-label" for="password">Nueva contrasena</label>
                    <input type="password" id="password" name="password" required minlength="<?= CUENTA_PASSWORD_MIN ?>" autocomplete="new-password">
                </div>
                <div class="filter-field">
                    <label class="filter-label" for="repetir_password">Repetir contrasena</label>
                    <input type="password" id="repetir_password" name="repetir_password" required minlength="<?= CUENTA_PASSWORD_MIN ?>" autocomplete="new-password">
                </div>
                <input type="submit" value="Guardar contrasena" class="login-boton">
            </form>
        <?php endif; ?>
        <p class="help-line login-pie"><a href="login.php">Volver al inicio de sesion</a></p>
    </div>
</div>
</body>
</html>
