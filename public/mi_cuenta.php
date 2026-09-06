<?php
require_once __DIR__ . '/../src/arranque.php';

$usuarioSesion = auth_usuario();
$usuarioId = (int)($usuarioSesion['id'] ?? 0);
$volver = auth_es('cliente') ? 'portal.php' : 'index.php';
$aviso = '';
$error = '';
$codigosNuevos = [];
$migracionPendiente = false;

try {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, email, hash_password, sesion_version, totp_secreto_cifrado,
                totp_activado_en, totp_ultimo_periodo
         FROM usuarios WHERE id = :id"
    );
    $stmt->execute([':id' => $usuarioId]);
    $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cuenta = null;
    $migracionPendiente = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cuenta) {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'iniciar_2fa') {
        if (!cuenta_app_key_disponible()) {
            $error = 'El administrador debe configurar APP_KEY antes de activar 2FA.';
        } else {
            $_SESSION['configuracion_2fa'] = [
                'secreto' => cuenta_totp_nuevo_secreto(),
                'creado_en' => time(),
            ];
        }
    }

    if ($accion === 'cancelar_2fa') {
        unset($_SESSION['configuracion_2fa']);
    }

    if ($accion === 'activar_2fa') {
        $configuracion = $_SESSION['configuracion_2fa'] ?? null;
        if (!is_array($configuracion) || (int)($configuracion['creado_en'] ?? 0) < time() - 600) {
            unset($_SESSION['configuracion_2fa']);
            $error = 'La configuracion ha caducado. Empieza de nuevo.';
        } else {
            $resultado = cuenta_2fa_activar(
                $pdo,
                $usuarioId,
                (string)$configuracion['secreto'],
                (string)($_POST['codigo'] ?? '')
            );
            if ($resultado['ok']) {
                unset($_SESSION['configuracion_2fa']);
                $codigosNuevos = $resultado['codigos'];
                $aviso = 'Segundo factor activado. Guarda ahora los codigos de recuperacion.';
                auditar($pdo, 'activar_2fa', '');
                $cuenta['totp_activado_en'] = date('Y-m-d H:i:s');
            } else {
                $error = (string)$resultado['error'];
            }
        }
    }

    if ($accion === 'nuevos_codigos' && !empty($cuenta['totp_activado_en'])) {
        if (!cuenta_2fa_consumir_codigo($pdo, $cuenta, (string)($_POST['codigo'] ?? ''))) {
            $error = 'El codigo de seguridad es incorrecto o ya se ha utilizado.';
        } else {
            try {
                $codigosNuevos = cuenta_2fa_nuevos_codigos($pdo, $usuarioId);
                $aviso = 'Se han sustituido todos los codigos de recuperacion anteriores.';
                auditar($pdo, 'regenerar_codigos_2fa', '');
            } catch (Throwable $e) {
                $error = 'No se pudieron generar los codigos.';
            }
        }
    }

    if ($accion === 'desactivar_2fa' && !empty($cuenta['totp_activado_en'])) {
        $passwordCorrecta = password_verify((string)($_POST['password'] ?? ''), (string)$cuenta['hash_password']);
        $codigoCorrecto = $passwordCorrecta
            && cuenta_2fa_consumir_codigo($pdo, $cuenta, (string)($_POST['codigo'] ?? ''));
        if (!$passwordCorrecta || !$codigoCorrecto) {
            $error = 'La contrasena o el codigo de seguridad no son correctos.';
        } else {
            try {
                cuenta_2fa_desactivar($pdo, $usuarioId);
                $_SESSION['usuario']['sesion_version'] = (int)($_SESSION['usuario']['sesion_version'] ?? 1) + 1;
                $cuenta['totp_activado_en'] = null;
                unset($_SESSION['configuracion_2fa']);
                $aviso = 'Segundo factor desactivado.';
                auditar($pdo, 'desactivar_2fa', '');
            } catch (Throwable $e) {
                $error = 'No se pudo desactivar el segundo factor.';
            }
        }
    }
}

$configuracion = $_SESSION['configuracion_2fa'] ?? null;
if (is_array($configuracion) && (int)($configuracion['creado_en'] ?? 0) < time() - 600) {
    unset($_SESSION['configuracion_2fa']);
    $configuracion = null;
}

$codigosDisponibles = 0;
if ($cuenta && !empty($cuenta['totp_activado_en'])) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM codigos_recuperacion_2fa WHERE usuario_id = :id");
        $stmt->execute([':id' => $usuarioId]);
        $codigosDisponibles = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $migracionPendiente = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Mi cuenta</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css?v=<?= filemtime(__DIR__ . '/estilos.css') ?>">
</head>
<body class="login-body account-body">
<div class="login-shell account-shell">
    <div class="login-card account-card">
        <div class="account-heading">
            <div>
                <span class="admin-eyebrow">Seguridad personal</span>
                <h1>Mi cuenta</h1>
                <p class="subtitulo"><?= ui_e((string)($usuarioSesion['email'] ?? '')) ?></p>
            </div>
            <a class="card-button secondary-button" href="<?= $volver ?>">Volver</a>
        </div>

        <?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>
        <?php if ($migracionPendiente): ?>
            <div class="login-error">La migracion de seguridad esta pendiente. Contacta con administracion.</div>
        <?php elseif ($cuenta): ?>
            <section class="account-section">
                <div class="section-head">
                    <div>
                        <h2>Segundo factor</h2>
                        <p class="help-line">Protege el acceso con una aplicacion TOTP, incluso si se filtra la contrasena.</p>
                    </div>
                    <span class="security-state <?= !empty($cuenta['totp_activado_en']) ? 'enabled' : '' ?>">
                        <?= !empty($cuenta['totp_activado_en']) ? 'Activado' : 'Desactivado' ?>
                    </span>
                </div>

                <?php if ($codigosNuevos !== []): ?>
                    <div class="recovery-codes" aria-live="polite">
                        <strong>Guardalos ahora: no volveran a mostrarse.</strong>
                        <div class="recovery-code-grid">
                            <?php foreach ($codigosNuevos as $codigo): ?><code><?= ui_e($codigo) ?></code><?php endforeach; ?>
                        </div>
                        <p class="help-line">Cada codigo sirve una sola vez cuando no tengas acceso a tu aplicacion.</p>
                    </div>
                <?php endif; ?>

                <?php if (empty($cuenta['totp_activado_en']) && !$configuracion): ?>
                    <form method="POST">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="iniciar_2fa">
                        <button type="submit" class="filter-button" <?= !cuenta_app_key_disponible() ? 'disabled' : '' ?>>Configurar segundo factor</button>
                    </form>
                    <?php if (!cuenta_app_key_disponible()): ?>
                        <p class="help-line">2FA no esta disponible hasta que administracion configure `APP_KEY`.</p>
                    <?php endif; ?>
                <?php elseif (empty($cuenta['totp_activado_en']) && is_array($configuracion)): ?>
                    <?php
                    $secreto = (string)$configuracion['secreto'];
                    $secretoVisible = trim(chunk_split($secreto, 4, ' '));
                    $uri = cuenta_2fa_uri((string)$cuenta['email'], $secreto);
                    ?>
                    <ol class="account-steps">
                        <li>Abre una aplicacion compatible como Aegis, 2FAS, Google Authenticator o Microsoft Authenticator.</li>
                        <li>Introduce esta clave manualmente: <code class="totp-secret"><?= ui_e($secretoVisible) ?></code></li>
                        <li>Tambien puedes <a href="<?= ui_e($uri) ?>">abrir la configuracion en una aplicacion compatible</a>.</li>
                    </ol>
                    <form method="POST" class="form-stack account-form">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="activar_2fa">
                        <div class="filter-field">
                            <label class="filter-label" for="codigo">Codigo de seis digitos</label>
                            <input type="text" id="codigo" name="codigo" required inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                        </div>
                        <div class="filter-actions-inline">
                            <button type="submit" class="filter-button">Confirmar y activar</button>
                        </div>
                    </form>
                    <form method="POST" class="account-cancel-form">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="cancelar_2fa">
                        <button type="submit" class="card-button secondary-button">Cancelar</button>
                    </form>
                <?php else: ?>
                    <p class="help-line">Activado el <?= ui_e((string)$cuenta['totp_activado_en']) ?>. Quedan <?= $codigosDisponibles ?> codigos de recuperacion.</p>
                    <details class="account-disclosure">
                        <summary>Generar nuevos codigos de recuperacion</summary>
                        <form method="POST" class="form-stack account-form">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="accion" value="nuevos_codigos">
                            <div class="filter-field">
                                <label class="filter-label" for="codigo_nuevos">Codigo actual</label>
                                <input type="text" id="codigo_nuevos" name="codigo" required autocomplete="one-time-code">
                            </div>
                            <button type="submit" class="card-button secondary-button">Sustituir codigos</button>
                        </form>
                    </details>
                    <details class="account-disclosure danger-disclosure">
                        <summary>Desactivar segundo factor</summary>
                        <form method="POST" class="form-stack account-form">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="accion" value="desactivar_2fa">
                            <div class="filter-field">
                                <label class="filter-label" for="password">Contrasena actual</label>
                                <input type="password" id="password" name="password" required autocomplete="current-password">
                            </div>
                            <div class="filter-field">
                                <label class="filter-label" for="codigo_desactivar">Codigo actual</label>
                                <input type="text" id="codigo_desactivar" name="codigo" required autocomplete="one-time-code">
                            </div>
                            <button type="submit" class="card-button danger-button">Desactivar</button>
                        </form>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
