<?php
// bin/worker.php — procesa la cola de trabajos IA (tabla trabajos_ia).
//
// Uso:
//   php bin/worker.php                    procesa hasta 10 trabajos y termina
//   php bin/worker.php --lote=50          procesa hasta 50 trabajos y termina
//   php bin/worker.php --bucle            bucle continuo (servicio/daemon)
//   php bin/worker.php --reintentar-fallidos   reencola los trabajos fallidos
//
// Programalo con cron / Programador de tareas de Windows cada pocos minutos,
// o dejalo en bucle como servicio.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Solo linea de comandos.');
}

require __DIR__ . '/../src/arranque.php';

$opciones = getopt('', ['lote::', 'bucle', 'reintentar-fallidos']);
$lote = max(1, (int)($opciones['lote'] ?? 10));
$bucle = array_key_exists('bucle', $opciones);

$ultimo_mantenimiento = 0;

if (array_key_exists('reintentar-fallidos', $opciones)) {
    $n = trabajos_reintentar_fallidos($pdo);
    echo "[worker] $n trabajos fallidos reencolados\n";
    if (!$bucle) {
        exit(0);
    }
}

do {
    // En modo puntual se ejecuta una vez; en modo servicio, cada hora.
    if (time() - $ultimo_mantenimiento >= 3600) {
        $mantenimiento = incidencias_ejecutar_mantenimiento($pdo);
        $ultimo_mantenimiento = time();
        if ($mantenimiento['cerradas'] > 0 || $mantenimiento['archivadas'] > 0) {
            echo sprintf(
                "[ciclo-vida] cerradas=%d archivadas=%d\n",
                $mantenimiento['cerradas'],
                $mantenimiento['archivadas']
            );
        }
    }
    $resumen = trabajos_procesar_lote($pdo, $lote);
    if ($resumen['procesados'] > 0) {
        echo sprintf(
            "[worker] %s procesados=%d completados=%d reintentos=%d fallidos=%d\n",
            date('Y-m-d H:i:s'),
            $resumen['procesados'],
            $resumen['completados'],
            $resumen['reintentos'],
            $resumen['fallidos']
        );
    }
    if ($bucle && $resumen['procesados'] === 0) {
        sleep(5);
    }
} while ($bucle);

exit(0);
