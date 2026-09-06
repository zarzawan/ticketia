<?php
// Bootstrap de tests: carga solo las piezas sin dependencias de BD ni sesion.
// Los tests unitarios cubren funciones puras (filtro de razonamiento, dominio,
// helpers de UI y adjuntos); los flujos completos se verifican sobre la app.

define('TICKETIA_RAIZ', dirname(__DIR__));

require TICKETIA_RAIZ . '/src/entorno.php';
require TICKETIA_RAIZ . '/src/seguridad_cuenta.php';
require TICKETIA_RAIZ . '/src/auth.php';
require TICKETIA_RAIZ . '/src/gobierno_ia.php';
require TICKETIA_RAIZ . '/src/conocimiento.php';
require TICKETIA_RAIZ . '/src/llm.php';
require TICKETIA_RAIZ . '/src/dominio.php';
require TICKETIA_RAIZ . '/src/bandeja.php';
require TICKETIA_RAIZ . '/src/trabajos.php';
require TICKETIA_RAIZ . '/src/ui.php';
require TICKETIA_RAIZ . '/src/adjuntos.php';
