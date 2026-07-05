<?php
// src/arranque.php
// Punto unico de inicio: todas las paginas de public/ hacen
//   require_once __DIR__ . '/../src/arranque.php';
// y reciben configuracion, conexion PDO, dominio, UI y capa LLM.

define('TICKETIA_RAIZ', dirname(__DIR__));

require TICKETIA_RAIZ . '/vendor/autoload.php';

// Variables de entorno desde .env (safeLoad: no falla si no existe;
// en ese caso valen las variables de entorno reales del sistema).
Dotenv\Dotenv::createImmutable(TICKETIA_RAIZ)->safeLoad();

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/dominio.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/structured_output.php';
require __DIR__ . '/llm.php';
require __DIR__ . '/clasificacion.php';
require __DIR__ . '/adjuntos.php';

// ---------------------------------------------------------------------------
// Guard global: toda pagina de public/ exige sesion salvo las publicas;
// todo POST exige token CSRF; las paginas de administracion exigen rol admin.
// ---------------------------------------------------------------------------

auth_sesion_iniciar();
seguridad_cabeceras();

if (PHP_SAPI !== 'cli') {
    $pagina_actual = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $paginas_publicas = ['login.php'];

    if (!in_array($pagina_actual, $paginas_publicas, true)) {
        auth_requerir_login();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            csrf_verificar();
        }

        $paginas_admin = ['admin_usuarios.php', 'admin_clientes.php', 'admin_auditoria.php', 'admin_ajustes.php', 'cambiar_proveedor.php', 'reprocesar_incidencias.php', 'ver_logs_llm.php'];
        if (in_array($pagina_actual, $paginas_admin, true)) {
            auth_requerir_rol('admin');
        }

        // El portal de cliente llega en una fase posterior: de momento el rol
        // cliente no tiene acceso al panel interno.
        if (auth_es('cliente')) {
            http_response_code(403);
            die('El portal de cliente estara disponible proximamente.');
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_verificar();
    }
}
