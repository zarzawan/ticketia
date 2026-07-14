<?php
require_once __DIR__ . '/../src/arranque.php';

if (auth_usuario() !== null) {
    header('Location: ' . (auth_es('cliente') ? 'portal.php' : 'index.php'));
    exit;
}

$pendiente = auth_2fa_pendiente();
if ($pendiente === null) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = trim((string)($_POST['codigo'] ?? ''));
    $volver = (string)($pendiente['volver'] ?? '');
    $resultado = auth_2fa_verificar($pdo, $codigo);
    if ($resultado['ok']) {
        $inicio = auth_es('cliente') ? 'portal.php' : 'index.php';
        $destino = (!auth_es('cliente') && $volver !== '' && str_starts_with($volver, '/') && !str_starts_with($volver, '//'))
            ? $volver
            : $inicio;
        header('Location: ' . $destino);
        exit;
    }
    $error = (string)$resultado['error'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Segundo factor</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body class="login-body">
<div class="login-shell">
    <div class="login-card">
        <div class="login-marca">
            <h1>Verifica tu identidad</h1>
            <p class="subtitulo">Introduce el codigo de tu aplicacion o uno de recuperacion.</p>
        </div>
        <?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>
        <form method="POST" class="form-stack">
            <?= csrf_campo() ?>
            <div class="filter-field">
                <label class="filter-label" for="codigo">Codigo de seguridad</label>
                <input type="text" id="codigo" name="codigo" required autofocus autocomplete="one-time-code"
                       inputmode="numeric" maxlength="16" placeholder="000000 o ABCDE-23456">
            </div>
            <input type="submit" value="Verificar" class="login-boton">
        </form>
        <p class="help-line login-pie"><a href="login.php">Volver al inicio de sesion</a></p>
    </div>
</div>
</body>
</html>
