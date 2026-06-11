<?php
// src/auth.php
// Autenticacion, autorizacion, CSRF y auditoria.
// El guard global de arranque.php protege todas las paginas de public/.

const AUTH_MAX_INTENTOS = 5;        // intentos fallidos antes de bloquear
const AUTH_MINUTOS_BLOQUEO = 15;    // duracion del bloqueo

function auth_sesion_iniciar(): void {
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }
    session_name('ticketia_sesion');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/** Usuario autenticado de la sesion (id, nombre, email, rol, cliente_id) o null. */
function auth_usuario(): ?array {
    return $_SESSION['usuario'] ?? null;
}

function auth_es(string ...$roles): bool {
    $usuario = auth_usuario();
    return $usuario !== null && in_array($usuario['rol'], $roles, true);
}

function auth_peticion_es_ajax(): bool {
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));
}

function auth_requerir_login(): void {
    if (auth_usuario() !== null) {
        return;
    }
    if (auth_peticion_es_ajax()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Sesion caducada. Recarga la pagina.']);
        exit;
    }
    $volver = (string)($_SERVER['REQUEST_URI'] ?? '');
    header('Location: login.php' . ($volver !== '' ? '?volver=' . urlencode($volver) : ''));
    exit;
}

function auth_requerir_rol(string ...$roles): void {
    if (auth_es(...$roles)) {
        return;
    }
    if (auth_peticion_es_ajax()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'No tienes permisos para esta accion.']);
        exit;
    }
    http_response_code(403);
    die('No tienes permisos para acceder a esta pagina. <a href="index.php">Volver</a>');
}

/**
 * Intenta iniciar sesion. Devuelve ['ok' => bool, 'error' => string|null].
 * Bloquea la cuenta tras AUTH_MAX_INTENTOS fallos consecutivos.
 */
function auth_login(PDO $pdo, string $email, string $password): array {
    $generico = 'Credenciales incorrectas.';

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || !(int)$usuario['activo']) {
        // Verificacion ficticia para igualar tiempos y no revelar si el email existe.
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        auditar($pdo, 'login_fallido', "email: $email");
        return ['ok' => false, 'error' => $generico];
    }

    if ($usuario['bloqueado_hasta'] !== null && strtotime($usuario['bloqueado_hasta']) > time()) {
        return ['ok' => false, 'error' => 'Cuenta bloqueada temporalmente por intentos fallidos. Prueba en unos minutos.'];
    }

    if (!password_verify($password, $usuario['hash_password'])) {
        $intentos = (int)$usuario['intentos_fallidos'] + 1;
        $bloqueo = $intentos >= AUTH_MAX_INTENTOS
            ? date('Y-m-d H:i:s', time() + AUTH_MINUTOS_BLOQUEO * 60)
            : null;
        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = :i, bloqueado_hasta = :b WHERE id = :id")
            ->execute([':i' => $intentos, ':b' => $bloqueo, ':id' => $usuario['id']]);
        auditar($pdo, 'login_fallido', "email: $email (intento $intentos)");
        return ['ok' => false, 'error' => $generico];
    }

    // Exito: actualizar hash si el algoritmo ha mejorado, resetear contadores.
    if (password_needs_rehash($usuario['hash_password'], auth_algoritmo_hash())) {
        $pdo->prepare("UPDATE usuarios SET hash_password = :h WHERE id = :id")
            ->execute([':h' => password_hash($password, auth_algoritmo_hash()), ':id' => $usuario['id']]);
    }
    $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = :id")
        ->execute([':id' => $usuario['id']]);

    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id' => (int)$usuario['id'],
        'nombre' => $usuario['nombre'],
        'email' => $usuario['email'],
        'rol' => $usuario['rol'],
        'cliente_id' => $usuario['cliente_id'] !== null ? (int)$usuario['cliente_id'] : null,
    ];
    auditar($pdo, 'login', '');

    return ['ok' => true, 'error' => null];
}

function auth_logout(PDO $pdo): void {
    if (auth_usuario() !== null) {
        auditar($pdo, 'logout', '');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_algoritmo_hash(): string {
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

/** Operadores y administradores activos, para asignaciones. */
function usuarios_asignables(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, nombre FROM usuarios WHERE activo = 1 AND rol IN ('admin','operador') ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo oculto para incluir en cada formulario POST. */
function csrf_campo(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Verifica el token en peticiones POST (campo 'csrf' o cabecera X-CSRF). */
function csrf_verificar(): void {
    $recibido = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
    if ($recibido !== '' && hash_equals(csrf_token(), $recibido)) {
        return;
    }
    if (auth_peticion_es_ajax()) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Token de seguridad invalido. Recarga la pagina.']);
        exit;
    }
    http_response_code(400);
    die('Token de seguridad invalido o caducado. Vuelve atras, recarga la pagina e intentalo de nuevo.');
}

// ---------------------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------------------

/** Registra una accion en la tabla auditoria. Nunca rompe la peticion. */
function auditar(PDO $pdo, string $accion, string $detalle = ''): void {
    try {
        $usuario = auth_usuario();
        $pdo->prepare(
            "INSERT INTO auditoria (usuario_id, usuario_email, accion, detalle, ip)
             VALUES (:id, :email, :accion, :detalle, :ip)"
        )->execute([
            ':id' => $usuario['id'] ?? null,
            ':email' => $usuario['email'] ?? null,
            ':accion' => mb_substr($accion, 0, 80),
            ':detalle' => mb_substr($detalle, 0, 500),
            ':ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (PDOException $e) {
        error_log('TicketIA auditoria: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Cabeceras de seguridad
// ---------------------------------------------------------------------------

function seguridad_cabeceras(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'");
}
