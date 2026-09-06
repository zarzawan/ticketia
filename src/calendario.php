<?php
// DATETIME historico representa hora civil del servicio. No reinterpreta datos antiguos.
// UTC aqui es un eje aritmetico sin DST, no una afirmacion sobre la zona original.

function calendario_civil(string $fecha): DateTimeImmutable {
    return new DateTimeImmutable($fecha, new DateTimeZone('UTC'));
}

function calendario_cargar(PDO $pdo): void {
    $GLOBALS['ticketia_calendario'] = null;
    try {
        $json = $pdo->query("SELECT valor FROM ajustes WHERE clave = 'calendario_servicio'")->fetchColumn();
        $config = $json === false || $json === '' ? null : json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($config) && !empty($config['activo'])) $GLOBALS['ticketia_calendario'] = $config;
    } catch (PDOException | JsonException $e) { /* El modo historico funciona sin migracion. */ }
}

function calendario_validar(array $c): bool {
    return isset($c['inicio'], $c['fin'], $c['dias'], $c['festivos'])
        && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $c['inicio'])
        && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $c['fin'])
        && $c['inicio'] < $c['fin'] && count($c['dias']) >= 1
        && !array_diff($c['dias'], [1,2,3,4,5,6,7]) && count($c['festivos']) <= 500
        && array_all_calendario_fechas($c['festivos']);
}

function array_all_calendario_fechas(array $fechas): bool {
    foreach ($fechas as $fecha) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$fecha, new DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $fecha) return false;
    }
    return true;
}

function calendario_intervalo(DateTimeImmutable $dia, array $config): array {
    $fecha = $dia->format('Y-m-d');
    $inicio = calendario_civil($fecha . ' ' . $config['inicio']);
    $fin = calendario_civil($fecha . ' ' . $config['fin']);
    if (!in_array((int)$dia->format('N'), $config['dias'], true) || in_array($fecha, $config['festivos'], true)) $fin = $inicio;
    return [$inicio, $fin];
}

function calendario_limite(DateTimeImmutable $inicio, int $horas, ?array $config = null): DateTimeImmutable {
    $config ??= $GLOBALS['ticketia_calendario'] ?? null;
    if (!$config) return $inicio->modify('+' . $horas . ' hours');
    $pendiente = $horas * 3600;
    $cursor = calendario_civil($inicio->format('Y-m-d H:i:s'));
    // 8760 horas con un dia semanal y jornada corta puede exceder un ano.
    for ($i = 0; $i < 36525; $i++) {
        [$abre, $cierra] = calendario_intervalo($cursor, $config);
        $desde = max($cursor->getTimestamp(), $abre->getTimestamp());
        $disponible = max(0, $cierra->getTimestamp() - $desde);
        if ($disponible >= $pendiente && $disponible > 0) return $cursor->setTimestamp($desde + $pendiente);
        $pendiente -= $disponible;
        $cursor = $cursor->modify('tomorrow')->setTime(0,0);
    }
    throw new RuntimeException('El calendario no cubre el objetivo solicitado.');
}

function calendario_segundos(DateTimeImmutable $desde, DateTimeImmutable $hasta): int {
    $config = $GLOBALS['ticketia_calendario'] ?? null;
    if (!$config) return $hasta->getTimestamp() - $desde->getTimestamp();
    if ($hasta < $desde) return -calendario_segundos($hasta, $desde);
    $total = 0; $dia = $desde->setTime(0,0);
    while ($dia <= $hasta) {
        [$abre,$cierra] = calendario_intervalo($dia, $config);
        $total += max(0, min($hasta->getTimestamp(),$cierra->getTimestamp()) - max($desde->getTimestamp(),$abre->getTimestamp()));
        $dia = $dia->modify('+1 day');
    }
    return $total;
}

/** Posicion acumulada indexada: SQL y PHP usan exactamente las mismas jornadas. */
function calendario_posicion_sql(string $fecha): string {
    return "(SELECT acumulado_inicio + LEAST(GREATEST(TIMESTAMPDIFF(SECOND, apertura, $fecha),0), acumulado_fin-acumulado_inicio)
        FROM calendario_servicio WHERE fecha = DATE($fecha))";
}

function calendario_limite_sql(string $fecha, string $horas): string {
    $posicion = calendario_posicion_sql($fecha);
    return "(SELECT DATE_ADD(apertura, INTERVAL ($posicion + ($horas)*3600 - acumulado_inicio) SECOND)
        FROM calendario_servicio WHERE acumulado_fin >= $posicion + ($horas)*3600 AND acumulado_fin > acumulado_inicio
        ORDER BY acumulado_fin, fecha LIMIT 1)";
}

function calendario_guardar(PDO $pdo, array $config): void {
    if (!calendario_validar($config)) throw new RuntimeException('Horario o festivos no validos.');
    // Minimo 4 h semanales garantiza cubrir objetivos maximos dentro del horizonte.
    $minutos = ((int)substr($config['fin'],0,2)-(int)substr($config['inicio'],0,2))*60
        + (int)substr($config['fin'],3,2)-(int)substr($config['inicio'],3,2);
    if ($minutos * count($config['dias']) < 240) throw new RuntimeException('Configura al menos cuatro horas semanales.');
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM calendario_servicio');
        $acumulado = 0;
        $stmt = $pdo->prepare('INSERT INTO calendario_servicio VALUES (?,?,?,?,?)');
        for ($dia = calendario_civil('1970-01-01'); $dia < calendario_civil('2200-01-01'); $dia = $dia->modify('+1 day')) {
            [$abre,$cierra] = calendario_intervalo($dia,$config);
            $fin = $acumulado + $cierra->getTimestamp() - $abre->getTimestamp();
            $stmt->execute([$dia->format('Y-m-d'),$abre->format('Y-m-d H:i:s'),$cierra->format('Y-m-d H:i:s'),$acumulado,$fin]);
            $acumulado = $fin;
        }
        $pdo->prepare("INSERT INTO ajustes (clave,valor) VALUES ('calendario_servicio',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
            ->execute([json_encode($config)]);
        $pdo->commit();
        calendario_cargar($pdo);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
