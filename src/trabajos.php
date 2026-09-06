<?php
// src/trabajos.php
// Cola de trabajos IA sobre la tabla trabajos_ia. Los trabajos se encolan
// cuando una tarea de IA falla en linea (o directamente, si se prefiere
// diferir), y bin/worker.php los procesa con reintentos y backoff.

const TRABAJOS_BACKOFF_MINUTOS = [1 => 2, 2 => 10, 3 => 30]; // por numero de intento

/** Encola un trabajo. $payload se guarda como JSON. */
function trabajos_encolar(PDO $pdo, string $tipo, array $payload, int $max_intentos = 3): int {
    $pdo->prepare(
        "INSERT INTO trabajos_ia (tipo, payload, max_intentos) VALUES (:tipo, :payload, :max)"
    )->execute([
        ':tipo' => $tipo,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ':max' => $max_intentos,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Reclama de forma atomica el siguiente trabajo pendiente ya programado.
 * Devuelve la fila del trabajo o null si no hay nada que hacer.
 */
function trabajos_reclamar(PDO $pdo): ?array {
    $pdo->beginTransaction();
    try {
        $trabajo = $pdo->query(
            "SELECT * FROM trabajos_ia
             WHERE estado = 'pendiente' AND programado_para <= NOW()
             ORDER BY id LIMIT 1 FOR UPDATE"
        )->fetch(PDO::FETCH_ASSOC);

        if (!$trabajo) {
            $pdo->commit();
            return null;
        }

        $pdo->prepare(
            "UPDATE trabajos_ia SET estado = 'en_curso', intentos = intentos + 1 WHERE id = :id"
        )->execute([':id' => $trabajo['id']]);
        $pdo->commit();

        $trabajo['intentos'] = (int)$trabajo['intentos'] + 1;
        return $trabajo;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Marca el resultado de un trabajo: completado, o pendiente/fallido segun reintentos. */
function trabajos_resolver(PDO $pdo, array $trabajo, bool $exito, string $error = ''): void {
    if ($exito) {
        $pdo->prepare("UPDATE trabajos_ia SET estado = 'completado', ultimo_error = NULL WHERE id = :id")
            ->execute([':id' => $trabajo['id']]);
        return;
    }

    $intentos = (int)$trabajo['intentos'];
    if ($intentos >= (int)$trabajo['max_intentos']) {
        $pdo->prepare("UPDATE trabajos_ia SET estado = 'fallido', ultimo_error = :e WHERE id = :id")
            ->execute([':e' => mb_substr($error, 0, 255), ':id' => $trabajo['id']]);
        return;
    }

    $minutos = TRABAJOS_BACKOFF_MINUTOS[$intentos] ?? 30;
    $pdo->prepare(
        "UPDATE trabajos_ia SET estado = 'pendiente', ultimo_error = :e,
                programado_para = NOW() + INTERVAL :m MINUTE
         WHERE id = :id"
    )->execute([':e' => mb_substr($error, 0, 255), ':m' => $minutos, ':id' => $trabajo['id']]);
}

/**
 * Ejecuta un trabajo segun su tipo. Devuelve [exito, error].
 * Tipos soportados:
 *   - clasificar: {"id_incidencia": N} -> clasificacion IA del ticket
 */
function trabajos_ejecutar(PDO $pdo, array $trabajo): array {
    gobierno_ia_contexto_establecer(null);
    $payload = json_decode((string)$trabajo['payload'], true) ?: [];

    switch ($trabajo['tipo']) {
        case 'clasificar':
            $id = (int)($payload['id_incidencia'] ?? 0);
            gobierno_ia_contexto_establecer($id);
            $stmt = $pdo->prepare("SELECT titulo, descripcion FROM incidencias WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $incidencia = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$incidencia) {
                return [true, '']; // el ticket ya no existe: nada que hacer
            }
            $clasificacion = clasificar_incidencia((string)$incidencia['titulo'], (string)$incidencia['descripcion']);
            if ($clasificacion === null) {
                return [false, 'La clasificacion IA no devolvio resultado (proveedor caido o respuesta invalida)'];
            }
            clasificacion_aplicar($pdo, $id, $clasificacion);
            return [true, ''];

        default:
            return [false, "Tipo de trabajo desconocido: {$trabajo['tipo']}"];
    }
}

/**
 * Procesa hasta $lote trabajos pendientes. Devuelve resumen
 * ['procesados' => n, 'completados' => n, 'reintentos' => n, 'fallidos' => n].
 */
function trabajos_procesar_lote(PDO $pdo, int $lote = 10, ?callable $latido = null): array {
    $resumen = ['procesados' => 0, 'completados' => 0, 'reintentos' => 0, 'fallidos' => 0];

    for ($i = 0; $i < $lote; $i++) {
        if ($latido !== null) $latido();
        $trabajo = trabajos_reclamar($pdo);
        if ($trabajo === null) {
            break;
        }
        $resumen['procesados']++;

        try {
            [$exito, $error] = trabajos_ejecutar($pdo, $trabajo);
        } catch (Throwable $e) {
            [$exito, $error] = [false, $e->getMessage()];
        }

        trabajos_resolver($pdo, $trabajo, $exito, $error);
        if ($latido !== null) $latido();
        if ($exito) {
            $resumen['completados']++;
        } elseif ((int)$trabajo['intentos'] >= (int)$trabajo['max_intentos']) {
            $resumen['fallidos']++;
        } else {
            $resumen['reintentos']++;
        }
    }

    return $resumen;
}

/** Contadores de la cola para el panel de ajustes. */
function trabajos_estado(PDO $pdo): array {
    try {
        $filas = $pdo->query(
            "SELECT estado, COUNT(*) AS n FROM trabajos_ia GROUP BY estado"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return []; // migracion aun no aplicada
    }
    return [
        'pendiente' => (int)($filas['pendiente'] ?? 0),
        'en_curso' => (int)($filas['en_curso'] ?? 0),
        'completado' => (int)($filas['completado'] ?? 0),
        'fallido' => (int)($filas['fallido'] ?? 0),
    ];
}

/** Reencola los trabajos fallidos (reset de intentos). */
function trabajos_reintentar_fallidos(PDO $pdo): int {
    $stmt = $pdo->prepare(
        "UPDATE trabajos_ia SET estado = 'pendiente', intentos = 0, programado_para = NOW() WHERE estado = 'fallido'"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/** Ultima actividad CLI, no inventario de procesos. Una sola clave, sin historial ilimitado. */
function trabajos_worker_latido(PDO $pdo, string $modo): void {
    try {
        $pdo->prepare("INSERT INTO ajustes (clave, valor) VALUES ('worker_ultimo_latido', JSON_OBJECT('fecha', UNIX_TIMESTAMP(), 'modo', :modo))
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([':modo' => $modo === 'bucle' ? 'bucle' : 'puntual']);
    } catch (PDOException $e) {
        // La observabilidad no debe bloquear el procesamiento de la cola.
        error_log('TicketIA: no se pudo registrar la actividad del worker.');
    }
}

function trabajos_worker_umbral(): int {
    return max(60, min(86400, (int)entorno_valor('WORKER_ALERTA_SEGUNDOS', '600')));
}

/** Un latido reciente no garantiza que el proceso siga vivo ni que los trabajos tengan exito. */
function trabajos_worker_evaluar(?array $latido, int $ahora, int $umbral): array {
    $fecha = (int)($latido['fecha'] ?? 0);
    if ($fecha <= 0 || $fecha > $ahora + 5 || !in_array($latido['modo'] ?? '', ['bucle', 'puntual'], true)) {
        return ['estado' => 'sin_datos', 'tono' => 'unknown', 'etiqueta' => 'Sin senal registrada',
            'detalle' => 'Todavia no hay actividad del procesador automatico. Revisa su programacion.', 'fecha' => null];
    }
    $edad = max(0, $ahora - $fecha);
    $reciente = $edad <= $umbral;
    $detalle = ($latido['modo'] === 'bucle' ? 'Ultima senal del servicio' : 'Ultima ejecucion puntual')
        . ': hace ' . ($edad < 60 ? $edad . ' s' : (int)floor($edad / 60) . ' min') . '.';
    if (!$reciente) $detalle .= ' Revisa el servicio, su programacion y los registros.';
    return ['estado' => $reciente ? 'reciente' : 'sin_senal', 'tono' => $reciente ? 'ok' : 'warn',
        'etiqueta' => $reciente ? 'Actividad reciente' : 'Sin actividad reciente', 'detalle' => $detalle, 'fecha' => $fecha];
}

function trabajos_worker_salud(PDO $pdo): array {
    try {
        $fila = $pdo->query("SELECT UNIX_TIMESTAMP() AS ahora, (SELECT valor FROM ajustes WHERE clave = 'worker_ultimo_latido') AS latido")->fetch(PDO::FETCH_ASSOC);
        $latido = json_decode((string)($fila['latido'] ?? ''), true);
        return trabajos_worker_evaluar(is_array($latido) ? $latido : null, (int)$fila['ahora'], trabajos_worker_umbral());
    } catch (PDOException $e) {
        return ['estado' => 'no_disponible', 'tono' => 'warn', 'etiqueta' => 'Estado no disponible',
            'detalle' => 'No se ha podido consultar la actividad del procesador.', 'fecha' => null];
    }
}
