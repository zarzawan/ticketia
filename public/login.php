<?php
require_once __DIR__ . '/../src/arranque.php';

// Si ya hay sesion, ir al panel.
if (auth_usuario() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Introduce tu email y tu contrasena.';
    } else {
        $resultado = auth_login($pdo, $email, $password);
        if ($resultado['ok']) {
            $volver = (string)($_POST['volver'] ?? '');
            // Solo rutas relativas internas, nunca URLs absolutas.
            $destino = ($volver !== '' && str_starts_with($volver, '/') && !str_starts_with($volver, '//'))
                ? $volver
                : 'index.php';
            header('Location: ' . $destino);
            exit;
        }
        $error = (string)$resultado['error'];
    }
}

$volver_param = (string)($_GET['volver'] ?? ($_POST['volver'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Iniciar sesion</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body class="login-body">
<div class="login-shell">
    <div class="login-card">
        <div class="login-marca">
            <h1>TicketIA</h1>
            <p class="subtitulo">Helpdesk con inteligencia artificial</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="login-error"><?= ui_e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="form-stack">
            <?= csrf_campo() ?>
            <input type="hidden" name="volver" value="<?= ui_e($volver_param) ?>">
            <div class="filter-field">
                <label class="filter-label" for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="username" autofocus
                       value="<?= ui_e((string)($_POST['email'] ?? '')) ?>">
            </div>
            <div class="filter-field">
                <label class="filter-label" for="password">Contrasena</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <input type="submit" value="Entrar" class="login-boton">
        </form>

        <p class="help-line login-pie">Si has olvidado tu contrasena, contacta con un administrador.</p>
    </div>
</div>
</body>
</html>
