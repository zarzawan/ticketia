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

require __DIR__ . '/entorno.php';
require __DIR__ . '/config.php';
require __DIR__ . '/seguridad_cuenta.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/dominio.php';
require __DIR__ . '/calendario.php';
require __DIR__ . '/soporte.php';
require __DIR__ . '/reglas.php';
require __DIR__ . '/bandeja.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/structured_output.php';
require __DIR__ . '/gobierno_ia.php';
require __DIR__ . '/conocimiento.php';
require __DIR__ . '/asistente.php';
require __DIR__ . '/llm.php';
require __DIR__ . '/clasificacion.php';
require __DIR__ . '/adjuntos.php';
require __DIR__ . '/correo.php';
require __DIR__ . '/cuentas.php';
require __DIR__ . '/trabajos.php';
require __DIR__ . '/operacion.php';

// Una sola lectura por peticion. Si la migracion SLA aun no se ha aplicado,
// dominio.php conserva automaticamente los objetivos historicos.
dominio_sla_cargar_politicas($pdo);
calendario_cargar($pdo);

// ---------------------------------------------------------------------------
// Guard global: toda pagina de public/ exige sesion salvo las publicas;
// todo POST exige token CSRF; las paginas de administracion exigen rol admin.
// ---------------------------------------------------------------------------

auth_sesion_iniciar();
auth_validar_sesion($pdo);
seguridad_cabeceras();

if (PHP_SAPI !== 'cli') {
    $pagina_actual = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $paginas_publicas = ['login.php', 'verificar_2fa.php', 'solicitar_recuperacion.php', 'restablecer_contrasena.php'];

    if (!in_array($pagina_actual, $paginas_publicas, true)) {
        auth_requerir_login();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            csrf_verificar();
        }

        $paginas_admin = ['admin_inicio.php', 'admin_usuarios.php', 'admin_clientes.php', 'admin_flujos.php', 'admin_catalogo.php', 'admin_auditoria.php', 'admin_ajustes.php', 'admin_conocimiento.php', 'cambiar_proveedor.php', 'reprocesar_incidencias.php', 'ver_logs_llm.php'];
        if (in_array($pagina_actual, $paginas_admin, true)) {
            auth_requerir_rol('admin');
        }
        if (in_array($pagina_actual, ['admin_operacion.php','admin_reglas.php','admin_calendario.php'], true)) auth_requerir_rol('admin');

        // El rol cliente solo accede a su portal y a los endpoints que este
        // usa; cualquier otra pagina lo devuelve al portal. La comprobacion
        // de propiedad del ticket la hace cada endpoint.
        $paginas_cliente = ['portal.php', 'portal_ver.php', 'ayuda.php', 'buscar_ayuda.php', 'valorar_servicio.php', 'valorar_articulo.php', 'mi_cuenta.php', 'guardar_incidencia.php', 'guardar_mensaje.php', 'subir_adjunto.php', 'descargar_adjunto.php', 'confirmar_resolucion.php', 'logout.php'];
        if (auth_es('cliente') && !in_array($pagina_actual, $paginas_cliente, true)) {
            header('Location: portal.php');
            exit;
        }

        // Y a la inversa: el portal es solo para el rol cliente.
        if (in_array($pagina_actual, ['portal.php', 'portal_ver.php'], true) && !auth_es('cliente')) {
            header('Location: index.php');
            exit;
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_verificar();
    }
}
