<?php
require_once __DIR__ . '/../src/arranque.php';

if (auth_usuario() !== null) {
    header('Location: ' . (auth_es('cliente') ? 'portal.php' : 'index.php'));
    exit;
}

$enviado = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cuenta_recuperacion_solicitar($pdo, (string)($_POST['email'] ?? ''));
    $enviado = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Recuperar acceso</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body class="login-body">
<div class="login-shell">
    <div class="login-card">
        <div class="login-marca">
            <h1>Recuperar acceso</h1>
            <p class="subtitulo">Te enviaremos un enlace si la cuenta existe y el correo esta configurado.</p>
        </div>
        <?php if ($enviado): ?>
            <div class="success-message">Solicitud recibida. Revisa tu correo y la carpeta de spam.</div>
        <?php else: ?>
            <form method="POST" class="form-stack">
                <?= csrf_campo() ?>
                <div class="filter-field">
                    <label class="filter-label" for="email">Email</label>
                    <input type="email" id="email" name="email" required autofocus autocomplete="email">
                </div>
                <input type="submit" value="Enviar enlace" class="login-boton">
            </form>
        <?php endif; ?>
        <p class="help-line login-pie"><a href="login.php">Volver al inicio de sesion</a></p>
    </div>
</div>
</body>
</html>
