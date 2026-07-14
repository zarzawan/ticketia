<?php
// Configuracion de Phinx (migraciones). Lee la conexion del fichero .env.

require __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
require_once __DIR__ . '/src/entorno.php';

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'principal',
        'principal' => [
            'adapter' => 'mysql',
            'host' => entorno_valor('DB_HOST', 'localhost'),
            'name' => entorno_valor('DB_NAME', 'ticketia'),
            'user' => entorno_valor('DB_USER', 'ticketia'),
            'pass' => entorno_valor('DB_PASS', ''),
            'port' => (int)entorno_valor('DB_PORT', 3306),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
