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
    $payload = json_decode((string)$trabajo['payload'], true) ?: [];

    switch ($trabajo['tipo']) {
        case 'clasificar':
            $id = (int)($payload['id_incidencia'] ?? 0);
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
function trabajos_procesar_lote(PDO $pdo, int $lote = 10): array {
    $resumen = ['procesados' => 0, 'completados' => 0, 'reintentos' => 0, 'fallidos' => 0];

    for ($i = 0; $i < $lote; $i++) {
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
