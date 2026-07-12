<?php
// lib/dominio.php
// Constantes de dominio y helpers de consulta compartidos por todas las paginas.
// Antes estas listas estaban duplicadas en 6+ ficheros; este es el unico punto de verdad.

function dominio_tipo_iconos(): array {
    return [
        'Servidores' => '🖥️',
        'Almacenamiento' => '💾',
        'Red y acceso' => '🌐',
        'Cloud' => '☁️',
        'Backups' => '📦',
        'Seguridad' => '🔒',
        'Sistemas' => '⚙️',
        'Web y dominios' => '🌍',
        'Correo' => '✉️',
        'Bases de datos' => '🗄️',
        'Software y apps' => '📱',
        'Usuarios y permisos' => '👥',
        'Monitorización' => '📊',
        'APIs y scripts' => '💻',
        'Cambios y mejoras' => '🔄',
        'Microsoft 365' => '📧',
        'ERP / CRM' => '📋',
        'Facturación' => '💳',
        'Quejas y reclamaciones' => '😡',
        'Comercial' => '💼'
    ];
}

function dominio_tipo_codigos(): array {
    return [
        'Servidores' => '[SV]',
        'Almacenamiento' => '[ALM]',
        'Red y acceso' => '[NET]',
        'Cloud' => '[CLD]',
        'Backups' => '[BKP]',
        'Seguridad' => '[SEC]',
        'Sistemas' => '[SYS]',
        'Web y dominios' => '[WEB]',
        'Correo' => '[MAIL]',
        'Bases de datos' => '[DB]',
        'Software y apps' => '[APP]',
        'Usuarios y permisos' => '[USR]',
        'Monitorización' => '[MON]',
        'APIs y scripts' => '[API]',
        'Cambios y mejoras' => '[CHG]',
        'Microsoft 365' => '[M365]',
        'ERP / CRM' => '[ERP]',
        'Facturación' => '[FIN]',
        'Quejas y reclamaciones' => '[QR]',
        'Comercial' => '[COM]'
    ];
}

function dominio_tipos(): array {
    return array_keys(dominio_tipo_iconos());
}

/** Lista de tipos en formato "'A', 'B', 'C'" para incrustar en prompts. */
function dominio_tipos_para_prompt(): string {
    return "'" . implode("', '", dominio_tipos()) . "'";
}

function dominio_urgencias(): array {
    return ['critico', 'urgente', 'leve'];
}

function dominio_estados(): array {
    return ['abierta', 'en_curso', 'cerrada'];
}

function dominio_ordenes(): array {
    return ['id_desc', 'recientes', 'antiguas', 'urgencia'];
}

function dominio_niveles_servicio(): array {
    return [
        'estandar' => 'Estandar',
        'preferente' => 'Preferente',
        'premium' => 'Premium',
    ];
}

/** Sustituye la cache de politicas SLA. Util tambien para pruebas unitarias. */
function dominio_sla_establecer_politicas(array $filas): void {
    $politicas = [];
    foreach ($filas as $fila) {
        $nivel = (string)($fila['nivel_cliente'] ?? '');
        $tipo = (string)($fila['tipo_incidencia'] ?? '*');
        $urgencia = (string)($fila['urgencia'] ?? '');
        if (!isset(dominio_niveles_servicio()[$nivel]) || !in_array($urgencia, dominio_urgencias(), true)) {
            continue;
        }
        $politicas[$nivel][$tipo][$urgencia] = [
            'primera_respuesta' => max(1, (int)($fila['primera_respuesta_horas'] ?? 1)),
            'resolucion' => max(1, (int)($fila['resolucion_horas'] ?? 1)),
        ];
    }
    $GLOBALS['ticketia_sla_politicas'] = $politicas;
}

/** Carga una vez las politicas configurables; antes de migrar usa los valores historicos. */
function dominio_sla_cargar_politicas(PDO $pdo): void {
    try {
        $filas = $pdo->query(
            "SELECT nivel_cliente, tipo_incidencia, urgencia, primera_respuesta_horas, resolucion_horas
             FROM sla_politicas WHERE activo = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        dominio_sla_establecer_politicas($filas);
    } catch (Throwable $e) {
        dominio_sla_establecer_politicas([]);
    }
}

/** Objetivos operativos por urgencia, nivel de cliente y tipo de incidencia. */
function dominio_sla_objetivos(string $urgencia, string $tipo_incidencia = '', string $nivel_cliente = 'estandar'): array {
    $objetivos = [
        'critico' => ['primera_respuesta' => 1, 'resolucion' => 4],
        'urgente' => ['primera_respuesta' => 4, 'resolucion' => 16],
        'leve' => ['primera_respuesta' => 8, 'resolucion' => 48],
    ];
    $urgencia = in_array($urgencia, dominio_urgencias(), true) ? $urgencia : 'leve';
    $nivel_cliente = isset(dominio_niveles_servicio()[$nivel_cliente]) ? $nivel_cliente : 'estandar';
    $politicas = $GLOBALS['ticketia_sla_politicas'] ?? [];
    $tipo = trim($tipo_incidencia);

    if ($tipo !== '' && isset($politicas[$nivel_cliente][$tipo][$urgencia])) {
        return $politicas[$nivel_cliente][$tipo][$urgencia] + ['nivel' => $nivel_cliente, 'tipo_politica' => $tipo];
    }
    if (isset($politicas[$nivel_cliente]['*'][$urgencia])) {
        return $politicas[$nivel_cliente]['*'][$urgencia] + ['nivel' => $nivel_cliente, 'tipo_politica' => '*'];
    }
    if (isset($politicas['estandar']['*'][$urgencia])) {
        return $politicas['estandar']['*'][$urgencia] + ['nivel' => 'estandar', 'tipo_politica' => '*'];
    }
    return $objetivos[$urgencia] + ['nivel' => $nivel_cliente, 'tipo_politica' => '*'];
}

/** Calcula un SLA derivado y explicable. $ahora permite pruebas deterministas. */
function dominio_sla_calcular(array $incidencia, ?DateTimeImmutable $ahora = null): array {
    $ahora = $ahora ?? new DateTimeImmutable();
    try {
        $creada = new DateTimeImmutable((string)($incidencia['fecha_creacion'] ?? 'now'));
    } catch (Exception $e) {
        $creada = $ahora;
    }

    $objetivos = dominio_sla_objetivos(
        (string)($incidencia['urgencia'] ?? 'leve'),
        (string)($incidencia['tipo'] ?? ''),
        (string)($incidencia['nivel_servicio'] ?? 'estandar')
    );
    $limiteRespuesta = $creada->modify('+' . $objetivos['primera_respuesta'] . ' hours');
    $limiteResolucion = $creada->modify('+' . $objetivos['resolucion'] . ' hours');
    $cerrada = (string)($incidencia['estado'] ?? '') === 'cerrada';
    $referencia = $ahora;
    if ($cerrada && !empty($incidencia['fecha_cierre'])) {
        try {
            $referencia = new DateTimeImmutable((string)$incidencia['fecha_cierre']);
        } catch (Exception $e) {
            $referencia = $ahora;
        }
    }

    $duracionResolucion = max(1, $limiteResolucion->getTimestamp() - $creada->getTimestamp());
    $consumidoResolucion = max(0, $referencia->getTimestamp() - $creada->getTimestamp());
    $porcentajeResolucion = (int)round(($consumidoResolucion / $duracionResolucion) * 100);
    $restanteResolucion = $limiteResolucion->getTimestamp() - $referencia->getTimestamp();
    $estadoResolucion = $referencia > $limiteResolucion ? 'vencido' : ($porcentajeResolucion >= 75 ? 'riesgo' : 'ok');
    if ($cerrada && $estadoResolucion !== 'vencido') {
        $estadoResolucion = 'cumplido';
    }

    $primeraRespuesta = null;
    if (!empty($incidencia['primera_respuesta'])) {
        try {
            $primeraRespuesta = new DateTimeImmutable((string)$incidencia['primera_respuesta']);
        } catch (Exception $e) {
            $primeraRespuesta = null;
        }
    }
    $referenciaRespuesta = $primeraRespuesta ?? $referencia;
    $duracionRespuesta = max(1, $limiteRespuesta->getTimestamp() - $creada->getTimestamp());
    $consumidoRespuesta = max(0, $referenciaRespuesta->getTimestamp() - $creada->getTimestamp());
    $porcentajeRespuesta = (int)round(($consumidoRespuesta / $duracionRespuesta) * 100);
    if ($referenciaRespuesta > $limiteRespuesta) {
        $estadoRespuesta = 'vencido';
    } elseif ($primeraRespuesta !== null) {
        $estadoRespuesta = 'cumplido';
    } elseif ($porcentajeRespuesta >= 75) {
        $estadoRespuesta = 'riesgo';
    } else {
        $estadoRespuesta = 'ok';
    }

    if ($estadoResolucion === 'vencido' || $estadoRespuesta === 'vencido') {
        $estado = 'vencido';
    } elseif ($estadoResolucion === 'riesgo' || $estadoRespuesta === 'riesgo') {
        $estado = 'riesgo';
    } elseif ($cerrada) {
        $estado = 'cumplido';
    } else {
        $estado = 'ok';
    }

    $objetivoActual = $primeraRespuesta === null && in_array($estadoRespuesta, ['riesgo', 'vencido'], true)
        ? 'primera_respuesta'
        : 'resolucion';
    $porcentaje = $objetivoActual === 'primera_respuesta' ? $porcentajeRespuesta : $porcentajeResolucion;
    $restanteSegundos = $objetivoActual === 'primera_respuesta'
        ? $limiteRespuesta->getTimestamp() - $referenciaRespuesta->getTimestamp()
        : $restanteResolucion;

    return [
        'estado' => $estado,
        'porcentaje' => min(100, max(0, $porcentaje)),
        'objetivo_respuesta_horas' => $objetivos['primera_respuesta'],
        'objetivo_resolucion_horas' => $objetivos['resolucion'],
        'limite_respuesta' => $limiteRespuesta->format('Y-m-d H:i:s'),
        'limite_resolucion' => $limiteResolucion->format('Y-m-d H:i:s'),
        'restante_segundos' => $restanteSegundos,
        'objetivo_actual' => $objetivoActual,
        'estado_respuesta' => $estadoRespuesta,
        'estado_resolucion' => $estadoResolucion,
        'porcentaje_respuesta' => min(100, max(0, $porcentajeRespuesta)),
        'porcentaje_resolucion' => min(100, max(0, $porcentajeResolucion)),
        'nivel_servicio' => $objetivos['nivel'],
        'tipo_politica' => $objetivos['tipo_politica'],
    ];
}

function dominio_duracion_humana(int $segundos): string {
    $pasado = $segundos < 0;
    $horas = intdiv(abs($segundos), 3600);
    $dias = intdiv($horas, 24);
    $horas %= 24;
    $texto = $dias > 0 ? $dias . 'd ' . $horas . 'h' : max(1, $horas) . 'h';
    return $pasado ? 'Vencido hace ' . $texto : $texto . ' restantes';
}

/** Determina quien debe realizar el siguiente movimiento publico del hilo. */
function dominio_turno_atencion(?string $ultimo_autor, string $estado): array {
    if ($estado === 'cerrada') {
        return ['clave' => 'resuelto', 'label' => 'Resuelto'];
    }
    if ($ultimo_autor === 'tecnico') {
        return ['clave' => 'cliente', 'label' => 'Esperando al cliente'];
    }
    return ['clave' => 'equipo', 'label' => 'Requiere respuesta'];
}

/** Desglose transparente para que la puntuacion operativa sea verificable. */
function dominio_prioridad_operativa_desglose(array $incidencia, ?DateTimeImmutable $ahora = null): array {
    $urgencia = (string)($incidencia['urgencia'] ?? 'leve');
    $puntos = ['critico' => 55, 'urgente' => 30, 'leve' => 10][$urgencia] ?? 10;
    $urgencia_label = ['critico' => 'Critica', 'urgente' => 'Urgente', 'leve' => 'Leve'][$urgencia] ?? ucfirst($urgencia);
    $factores = [['label' => 'Urgencia ' . $urgencia_label, 'puntos' => $puntos]];
    $sla = dominio_sla_calcular($incidencia, $ahora);
    if ($sla['estado'] === 'vencido') {
        $puntos += 30;
        $factores[] = ['label' => 'SLA vencido', 'puntos' => 30];
    } elseif ($sla['estado'] === 'riesgo') {
        $puntos += 15;
        $factores[] = ['label' => 'SLA en riesgo', 'puntos' => 15];
    }
    if (empty($incidencia['asignado_id'])) {
        $puntos += 10;
        $factores[] = ['label' => 'Sin responsable', 'puntos' => 10];
    }
    if (($incidencia['ultimo_autor'] ?? null) !== 'tecnico' && ($incidencia['estado'] ?? '') !== 'cerrada') {
        $puntos += 10;
        $factores[] = ['label' => 'Requiere respuesta del equipo', 'puntos' => 10];
    }
    return ['total' => min(100, $puntos), 'factores' => $factores];
}

/** Puntuacion de 0 a 100 para ordenar la atencion del equipo. */
function dominio_prioridad_operativa(array $incidencia, ?DateTimeImmutable $ahora = null): int {
    return dominio_prioridad_operativa_desglose($incidencia, $ahora)['total'];
}

/**
 * Anade a $sql/$params las condiciones de filtro comunes del panel.
 * $f admite: busqueda, tipo, urgencia, estado, desde, hasta.
 * $prefix permite usarlo con alias de tabla (ej: 'i.').
 */
function dominio_append_filtros(string &$sql, array &$params, array $f, string $prefix = ''): void {
    if (!empty($f['tipo'])) {
        $sql .= " AND {$prefix}tipo = :tipo";
        $params[':tipo'] = $f['tipo'];
    }

    if (!empty($f['urgencia'])) {
        $sql .= " AND {$prefix}urgencia = :urgencia";
        $params[':urgencia'] = $f['urgencia'];
    }

    if (!empty($f['estado'])) {
        $sql .= " AND {$prefix}estado = :estado_filtro";
        $params[':estado_filtro'] = $f['estado'];
    }

    if (!empty($f['desde'])) {
        $sql .= " AND DATE({$prefix}fecha_creacion) >= :desde";
        $params[':desde'] = $f['desde'];
    }

    if (!empty($f['hasta'])) {
        $sql .= " AND DATE({$prefix}fecha_creacion) <= :hasta";
        $params[':hasta'] = $f['hasta'];
    }

    if (!empty($f['asignado'])) {
        if ($f['asignado'] === 'sin_asignar') {
            $sql .= " AND {$prefix}asignado_id IS NULL";
        } else {
            $sql .= " AND {$prefix}asignado_id = :asignado";
            $params[':asignado'] = (int)$f['asignado'];
        }
    }

    $busqueda = trim((string)($f['busqueda'] ?? ''));
    if ($busqueda !== '') {
        if (is_numeric($busqueda)) {
            $sql .= " AND {$prefix}id = :id_busqueda";
            $params[':id_busqueda'] = (int)$busqueda;
        } else {
            $sql .= " AND ({$prefix}titulo LIKE :busqueda OR {$prefix}resumen LIKE :busqueda OR {$prefix}descripcion LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }
    }
}

function dominio_order_by(string $orden): string {
    switch ($orden) {
        case 'recientes':
            return 'fecha_creacion DESC';
        case 'antiguas':
            return 'fecha_creacion ASC';
        case 'urgencia':
            return "FIELD(urgencia, 'critico', 'urgente', 'leve'), fecha_creacion DESC";
        case 'id_desc':
        default:
            return 'id DESC';
    }
}

/**
 * Cambia el estado de una incidencia dejando rastro en cambios_estado y
 * manteniendo fecha_cierre. Devuelve false si la incidencia no existe.
 */
function incidencia_cambiar_estado(PDO $pdo, int $id_incidencia, string $nuevo_estado): bool {
    if (!in_array($nuevo_estado, dominio_estados(), true)) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT estado FROM incidencias WHERE id = :id");
    $stmt->execute([':id' => $id_incidencia]);
    $actual = $stmt->fetchColumn();
    if ($actual === false) {
        return false;
    }
    if ($actual === $nuevo_estado) {
        return true;
    }

    $sql = "UPDATE incidencias SET estado = :estado, fecha_cierre = " .
        ($nuevo_estado === 'cerrada' ? 'NOW()' : 'NULL') .
        " WHERE id = :id";
    $pdo->prepare($sql)->execute([':estado' => $nuevo_estado, ':id' => $id_incidencia]);

    $usuario = function_exists('auth_usuario') ? auth_usuario() : null;
    $pdo->prepare(
        "INSERT INTO cambios_estado (id_incidencia, usuario_id, estado_anterior, estado_nuevo)
         VALUES (:id, :usuario, :anterior, :nuevo)"
    )->execute([
        ':id' => $id_incidencia,
        ':usuario' => $usuario['id'] ?? null,
        ':anterior' => $actual,
        ':nuevo' => $nuevo_estado,
    ]);

    if (function_exists('correo_notificar_estado')) {
        correo_notificar_estado($pdo, $id_incidencia, (string)$actual, $nuevo_estado);
    }

    return true;
}

/**
 * true si un usuario con rol cliente puede ver la incidencia: pertenece a su
 * empresa o, si no tiene empresa asignada, la creo el mismo.
 */
function incidencia_visible_para_cliente(PDO $pdo, int $id_incidencia, array $usuario): bool {
    $stmt = $pdo->prepare("SELECT cliente_id, creado_por FROM incidencias WHERE id = :id");
    $stmt->execute([':id' => $id_incidencia]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        return false;
    }
    $empresa = $usuario['cliente_id'] ?? null;
    if ($empresa !== null) {
        return (int)$fila['cliente_id'] === (int)$empresa;
    }
    return $fila['creado_por'] !== null && (int)$fila['creado_por'] === (int)($usuario['id'] ?? 0);
}
