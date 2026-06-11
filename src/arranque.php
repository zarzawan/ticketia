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
require __DIR__ . '/dominio.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/structured_output.php';
require __DIR__ . '/llm.php';
require __DIR__ . '/clasificacion.php';
