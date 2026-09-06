<?php
require_once __DIR__ . '/../src/arranque.php';

// Si ya hay sesion, ir al panel (o al portal si es un cliente).
if (auth_usuario() !== null) {
    header('Location: ' . (auth_es('cliente') ? 'portal.php' : 'index.php'));
    exit;
}

$error = '';
$volver_param = (string)($_GET['volver'] ?? ($_POST['volver'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    unset($_SESSION['auth_2fa_pendiente']);
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Introduce tu email y tu contrasena.';
    } else {
        $resultado = auth_login($pdo, $email, $password);
        if ($resultado['ok']) {
            $volver = (string)($_POST['volver'] ?? '');
            $inicio = auth_es('cliente') ? 'portal.php' : 'index.php';
            // Solo rutas relativas internas, nunca URLs absolutas. Los clientes
            // van siempre a su portal (el guard les impide el panel interno).
            $destino = (!auth_es('cliente') && $volver !== '' && str_starts_with($volver, '/') && !str_starts_with($volver, '//'))
                ? $volver
                : $inicio;
            header('Location: ' . $destino);
            exit;
        }
        if (!empty($resultado['requiere_2fa'])) {
            $_SESSION['auth_2fa_pendiente']['volver'] = $volver_param;
            header('Location: verificar_2fa.php');
            exit;
        }
        $error = (string)$resultado['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Iniciar sesion</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css?v=<?= filemtime(__DIR__ . '/estilos.css') ?>">
</head>
<body class="login-body login-pro">
<div class="login-shell">
    <section class="login-story"><a class="login-wordmark" href="login.php"><span class="admin-brand-mark">T</span> TicketIA</a><div><span class="section-label">Soporte mas humano. Impulsado por IA.</span><h2>Menos friccion.<br>Mas soluciones.</h2><p>Personas, conversaciones y conocimiento, en un mismo lugar.</p><div class="login-steps"><span><?= ui_icono('personas') ?> Conecta con tu equipo</span><span><?= ui_icono('ia') ?> Resuelve con ayuda de IA</span><span><?= ui_icono('libro') ?> Comparte lo que funciona</span></div></div><small>Tu espacio de soporte, siempre a mano.</small></section>
    <div class="login-card">
        <div class="login-marca">
            <span class="section-label">Bienvenido a TicketIA</span>
            <h1>Vamos a resolverlo.</h1>
            <p class="subtitulo">Accede a tu espacio de trabajo o a tus solicitudes.</p>
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

        <p class="help-line login-pie"><a href="solicitar_recuperacion.php">He olvidado mi contrasena</a></p>
    </div>
</div>
</body>
</html>
