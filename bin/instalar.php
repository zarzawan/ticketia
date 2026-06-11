<?php
// bin/instalar.php — instalador de TicketIA por linea de comandos.
//
//   php bin/instalar.php             instala/actualiza el esquema
//   php bin/instalar.php --con-demo  ademas carga los datos de demostracion
//
// Requiere un fichero .env en la raiz (copia .env.example) y que el usuario
// de base de datos exista. Si la base de datos no existe, intenta crearla.

if (PHP_SAPI !== 'cli') {
    die("Este instalador se ejecuta por linea de comandos: php bin/instalar.php\n");
}

$raiz = dirname(__DIR__);
$conDemo = in_array('--con-demo', $argv, true);

echo "== Instalador de TicketIA ==\n\n";

// 1. Requisitos
$fallos = [];
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    $fallos[] = 'Se requiere PHP 8.1 o superior (tienes ' . PHP_VERSION . ')';
}
foreach (['pdo_mysql', 'curl', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        $fallos[] = "Falta la extension PHP '$ext'";
    }
}
if (!is_dir("$raiz/vendor")) {
    $fallos[] = "Faltan las dependencias: ejecuta 'composer install' primero";
}
if ($fallos) {
    echo "No se puede instalar:\n";
    foreach ($fallos as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "[ok] Requisitos de PHP\n";

// 2. Configuracion
if (!is_file("$raiz/.env")) {
    if (is_file("$raiz/.env.example")) {
        copy("$raiz/.env.example", "$raiz/.env");
        echo "[!] No existia .env: se ha creado a partir de .env.example.\n";
        echo "    Edita $raiz/.env con tus datos de base de datos y vuelve a ejecutar el instalador.\n";
        exit(1);
    }
    echo "No existe .env ni .env.example. Descarga el proyecto completo.\n";
    exit(1);
}

require "$raiz/vendor/autoload.php";
Dotenv\Dotenv::createImmutable($raiz)->safeLoad();

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '3306';
$name = $_ENV['DB_NAME'] ?? 'ticketia';
$user = $_ENV['DB_USER'] ?? 'ticketia';
$pass = $_ENV['DB_PASS'] ?? '';

echo "[ok] Configuracion leida (BD '$name' en $host:$port)\n";

// 3. Conexion al servidor y creacion de la BD si falta
try {
    $servidor = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "No se pudo conectar al servidor de base de datos: " . $e->getMessage() . "\n";
    echo "Revisa DB_HOST/DB_PORT/DB_USER/DB_PASS en .env\n";
    exit(1);
}

$nombreSeguro = str_replace('`', '', $name);
$servidor->exec("CREATE DATABASE IF NOT EXISTS `$nombreSeguro` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "[ok] Base de datos disponible\n";

// 4. Migraciones
echo "\n-- Migraciones --\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$raiz/vendor/bin/phinx") . ' migrate -e principal -c ' . escapeshellarg("$raiz/phinx.php"), $codigo);
if ($codigo !== 0) {
    echo "Las migraciones han fallado (codigo $codigo).\n";
    exit($codigo);
}

// 5. Datos de demostracion
if ($conDemo) {
    echo "\n-- Datos de demostracion --\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$raiz/vendor/bin/phinx") . ' seed:run -e principal -c ' . escapeshellarg("$raiz/phinx.php"), $codigo);
    if ($codigo !== 0) {
        echo "La carga de datos de demo ha fallado (codigo $codigo).\n";
        exit($codigo);
    }
}

// 6. Administrador inicial (solo si no existe ningun usuario).
//    Opciones: --admin-email=correo --admin-pass=contrasena
$bd = new PDO("mysql:host=$host;port=$port;dbname=$nombreSeguro;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$totalUsuarios = (int)$bd->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
if ($totalUsuarios === 0) {
    $adminEmail = 'admin@ticketia.local';
    $adminPass = null;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--admin-email=')) {
            $adminEmail = substr($arg, 14);
        }
        if (str_starts_with($arg, '--admin-pass=')) {
            $adminPass = substr($arg, 13);
        }
    }

    $generada = false;
    if ($adminPass === null || strlen($adminPass) < 10) {
        $adminPass = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Aa'), '=');
        $generada = true;
    }

    $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $bd->prepare("INSERT INTO usuarios (nombre, email, hash_password, rol) VALUES ('Administrador', :e, :h, 'admin')")
        ->execute([':e' => $adminEmail, ':h' => password_hash($adminPass, $algoritmo)]);

    echo "\n-- Administrador inicial --\n";
    echo "Email:      $adminEmail\n";
    if ($generada) {
        echo "Contrasena: $adminPass\n";
        echo "(generada automaticamente: apuntala y cambiala tras el primer acceso)\n";
    } else {
        echo "Contrasena: la indicada en --admin-pass\n";
    }
}

echo "\n== Instalacion completada ==\n";
echo "Apunta tu servidor web al directorio public/ y abre login.php\n";
if (!$conDemo) {
    echo "(Puedes cargar datos de ejemplo con: php bin/instalar.php --con-demo)\n";
}
