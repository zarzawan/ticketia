<?php
// Flujos de seguridad que necesitan persistencia: recuperacion de contrasena,
// segundo factor y codigos de emergencia.

const CUENTA_RECUPERACION_MINUTOS = 30;
const CUENTA_RECUPERACION_MAX_HORA = 3;
const CUENTA_CODIGOS_RECUPERACION = 8;

function cuenta_seguridad_esquema_disponible(PDO $pdo): bool {
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
               AND COLUMN_NAME = 'sesion_version'"
        );
        return (int)$stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        return false;
    }
}

function cuenta_recuperacion_hash(string $token): string {
    return hash('sha256', $token);
}

/** Solicita un enlace sin revelar si la cuenta existe. */
function cuenta_recuperacion_solicitar(PDO $pdo, string $email): void {
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !correo_activo() || correo_url_base() === '') {
        return;
    }

    $stmt = $pdo->prepare("SELECT id, nombre, email FROM usuarios WHERE email = :email AND activo = 1 LIMIT 1");
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        return;
    }

    try {
        $limite = $pdo->prepare(
            "SELECT COUNT(*) AS total, MAX(creado_en) AS ultima
             FROM recuperaciones_password
             WHERE usuario_id = :id AND creado_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $limite->execute([':id' => $usuario['id']]);
        $uso = $limite->fetch(PDO::FETCH_ASSOC) ?: [];
        $ultima = isset($uso['ultima']) ? strtotime((string)$uso['ultima']) : false;
        if ((int)($uso['total'] ?? 0) >= CUENTA_RECUPERACION_MAX_HORA
            || ($ultima !== false && $ultima > time() - 60)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $hash = cuenta_recuperacion_hash($token);
        $expira = date('Y-m-d H:i:s', time() + CUENTA_RECUPERACION_MINUTOS * 60);
        $pdo->prepare(
            "UPDATE recuperaciones_password SET usado_en = NOW()
             WHERE usuario_id = :id AND usado_en IS NULL"
        )->execute([':id' => $usuario['id']]);
        $pdo->prepare(
            "INSERT INTO recuperaciones_password (usuario_id, token_hash, expira_en)
             VALUES (:id, :hash, :expira)"
        )->execute([':id' => $usuario['id'], ':hash' => $hash, ':expira' => $expira]);
        $idToken = (int)$pdo->lastInsertId();

        $enlace = correo_url_base() . '/restablecer_contrasena.php?token=' . rawurlencode($token);
        $nombre = htmlspecialchars((string)$usuario['nombre'], ENT_QUOTES, 'UTF-8');
        $enlaceHtml = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');
        $enviado = correo_enviar_directo(
            [(string)$usuario['email']],
            'Restablecer acceso a TicketIA',
            "<p>Hola {$nombre}:</p>"
            . '<p>Se ha solicitado restablecer la contrasena de tu cuenta.</p>'
            . "<p><a href=\"{$enlaceHtml}\" style=\"display:inline-block;padding:10px 16px;background:#1769aa;color:#fff;text-decoration:none;border-radius:6px\">Crear nueva contrasena</a></p>"
            . '<p>El enlace caduca en ' . CUENTA_RECUPERACION_MINUTOS . ' minutos. Si no has hecho esta solicitud, puedes ignorar el mensaje.</p>'
        );
        if (!$enviado) {
            $pdo->prepare("DELETE FROM recuperaciones_password WHERE id = :id")
                ->execute([':id' => $idToken]);
            return;
        }
        auditar($pdo, 'solicitar_recuperacion', 'usuario #' . (int)$usuario['id']);
    } catch (PDOException $e) {
        error_log('TicketIA recuperacion: ' . $e->getMessage());
    }
}

function cuenta_recuperacion_buscar(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT r.id, r.usuario_id, r.expira_en, u.email
             FROM recuperaciones_password r
             INNER JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.token_hash = :hash AND r.usado_en IS NULL
               AND r.expira_en > NOW() AND u.activo = 1
             LIMIT 1"
        );
        $stmt->execute([':hash' => cuenta_recuperacion_hash($token)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function cuenta_recuperaciones_invalidar(PDO $pdo, int $usuarioId): void {
    try {
        $pdo->prepare(
            "UPDATE recuperaciones_password SET usado_en = NOW()
             WHERE usuario_id = :id AND usado_en IS NULL"
        )->execute([':id' => $usuarioId]);
    } catch (PDOException $e) {
        // La migracion puede estar pendiente durante un despliegue escalonado.
    }
}

/** Elimina tokens consumidos o caducados para que la tabla no crezca sin limite. */
function cuenta_seguridad_mantenimiento(PDO $pdo): int {
    try {
        return $pdo->exec(
            "DELETE FROM recuperaciones_password
             WHERE (usado_en IS NOT NULL AND usado_en < DATE_SUB(NOW(), INTERVAL 7 DAY))
                OR expira_en < DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 1000"
        );
    } catch (PDOException $e) {
        return 0;
    }
}

/** @return array{ok: bool, error: ?string} */
function cuenta_recuperacion_restablecer(PDO $pdo, string $token, string $password): array {
    $errorPassword = cuenta_password_error($password);
    if ($errorPassword !== null) {
        return ['ok' => false, 'error' => $errorPassword];
    }
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return ['ok' => false, 'error' => 'El enlace no es valido o ha caducado.'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT r.id, r.usuario_id
             FROM recuperaciones_password r
             INNER JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.token_hash = :hash AND r.usado_en IS NULL
               AND r.expira_en > NOW() AND u.activo = 1
             FOR UPDATE"
        );
        $stmt->execute([':hash' => cuenta_recuperacion_hash($token)]);
        $recuperacion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$recuperacion) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'El enlace no es valido o ha caducado.'];
        }

        $pdo->prepare(
            "UPDATE usuarios
             SET hash_password = :hash, intentos_fallidos = 0, bloqueado_hasta = NULL,
                 sesion_version = sesion_version + 1
             WHERE id = :id"
        )->execute([
            ':hash' => password_hash($password, auth_algoritmo_hash()),
            ':id' => $recuperacion['usuario_id'],
        ]);
        $pdo->prepare(
            "UPDATE recuperaciones_password SET usado_en = NOW()
             WHERE usuario_id = :id AND usado_en IS NULL"
        )->execute([':id' => $recuperacion['usuario_id']]);
        $pdo->commit();
        auditar($pdo, 'restablecer_password', 'usuario #' . (int)$recuperacion['usuario_id']);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TicketIA restablecer: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo actualizar la contrasena. Intentalo de nuevo.'];
    }
}

function cuenta_2fa_uri(string $email, string $secreto): string {
    $etiqueta = rawurlencode('TicketIA:' . $email);
    return "otpauth://totp/{$etiqueta}?secret=" . rawurlencode($secreto)
        . '&issuer=TicketIA&algorithm=SHA1&digits=6&period=30';
}

/** Consume un TOTP o un codigo de recuperacion valido. */
function cuenta_2fa_consumir_codigo(PDO $pdo, array $usuario, string $codigo): bool {
    $usuarioId = (int)($usuario['id'] ?? 0);
    if ($usuarioId <= 0) {
        return false;
    }

    if (preg_match('/^\s*\d{6}\s*$/', $codigo)) {
        $cifrado = (string)($usuario['totp_secreto_cifrado'] ?? '');
        $secreto = $cifrado !== '' ? cuenta_secreto_descifrar($cifrado) : null;
        if ($secreto === null) {
            return false;
        }
        $periodo = cuenta_totp_verificar($secreto, $codigo);
        if ($periodo === null || $periodo <= (int)($usuario['totp_ultimo_periodo'] ?? -1)) {
            return false;
        }
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET totp_ultimo_periodo = :periodo
             WHERE id = :id AND (totp_ultimo_periodo IS NULL OR totp_ultimo_periodo < :periodo_compara)"
        );
        $stmt->execute([':periodo' => $periodo, ':periodo_compara' => $periodo, ':id' => $usuarioId]);
        return $stmt->rowCount() === 1;
    }

    $normalizado = cuenta_codigo_recuperacion_normalizar($codigo);
    if (strlen($normalizado) !== 10) {
        return false;
    }
    $stmt = $pdo->prepare(
        "DELETE FROM codigos_recuperacion_2fa WHERE usuario_id = :id AND codigo_hash = :hash"
    );
    $stmt->execute([':id' => $usuarioId, ':hash' => cuenta_codigo_recuperacion_hash($normalizado)]);
    return $stmt->rowCount() === 1;
}

/** Activa TOTP y devuelve los codigos que solo se mostraran una vez. */
function cuenta_2fa_activar(PDO $pdo, int $usuarioId, string $secreto, string $codigo): array {
    $periodo = cuenta_totp_verificar($secreto, $codigo);
    $cifrado = cuenta_secreto_cifrar($secreto);
    if ($periodo === null) {
        return ['ok' => false, 'error' => 'El codigo no coincide. Revisa la hora del dispositivo.', 'codigos' => []];
    }
    if ($cifrado === null) {
        return ['ok' => false, 'error' => 'Configura APP_KEY antes de activar el segundo factor.', 'codigos' => []];
    }

    $codigos = [];
    for ($i = 0; $i < CUENTA_CODIGOS_RECUPERACION; $i++) {
        $codigos[] = cuenta_codigo_recuperacion_generar();
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE usuarios
             SET totp_secreto_cifrado = :secreto, totp_activado_en = NOW(), totp_ultimo_periodo = :periodo
             WHERE id = :id"
        )->execute([':secreto' => $cifrado, ':periodo' => $periodo, ':id' => $usuarioId]);
        $pdo->prepare("DELETE FROM codigos_recuperacion_2fa WHERE usuario_id = :id")
            ->execute([':id' => $usuarioId]);
        $insertar = $pdo->prepare(
            "INSERT INTO codigos_recuperacion_2fa (usuario_id, codigo_hash) VALUES (:id, :hash)"
        );
        foreach ($codigos as $codigoRecuperacion) {
            $insertar->execute([':id' => $usuarioId, ':hash' => cuenta_codigo_recuperacion_hash($codigoRecuperacion)]);
        }
        $pdo->commit();
        return ['ok' => true, 'error' => null, 'codigos' => $codigos];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TicketIA activar 2FA: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo activar el segundo factor.', 'codigos' => []];
    }
}

function cuenta_2fa_nuevos_codigos(PDO $pdo, int $usuarioId): array {
    $codigos = [];
    for ($i = 0; $i < CUENTA_CODIGOS_RECUPERACION; $i++) {
        $codigos[] = cuenta_codigo_recuperacion_generar();
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM codigos_recuperacion_2fa WHERE usuario_id = :id")
            ->execute([':id' => $usuarioId]);
        $insertar = $pdo->prepare(
            "INSERT INTO codigos_recuperacion_2fa (usuario_id, codigo_hash) VALUES (:id, :hash)"
        );
        foreach ($codigos as $codigo) {
            $insertar->execute([':id' => $usuarioId, ':hash' => cuenta_codigo_recuperacion_hash($codigo)]);
        }
        $pdo->commit();
        return $codigos;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function cuenta_2fa_desactivar(PDO $pdo, int $usuarioId): void {
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE usuarios
             SET totp_secreto_cifrado = NULL, totp_activado_en = NULL, totp_ultimo_periodo = NULL,
                 sesion_version = sesion_version + 1
             WHERE id = :id"
        )->execute([':id' => $usuarioId]);
        $pdo->prepare("DELETE FROM codigos_recuperacion_2fa WHERE usuario_id = :id")
            ->execute([':id' => $usuarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
