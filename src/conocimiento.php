<?php
// Conocimiento editorial: la IA propone; una persona revisa y publica.

function conocimiento_disponible(PDO $pdo): bool {
    try {
        $pdo->query('SELECT id FROM conocimiento LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function conocimiento_validar(array $datos): ?string {
    foreach (['titulo' => 180, 'resumen' => 400, 'contenido' => 30000, 'categoria' => 80] as $campo => $limite) {
        $texto = trim((string)($datos[$campo] ?? ''));
        if ($texto === '' || mb_strlen($texto) > $limite) {
            return "El campo $campo es obligatorio y admite hasta $limite caracteres.";
        }
    }
    if (!in_array($datos['estado'] ?? '', ['borrador', 'publicado', 'archivado'], true)
        || !in_array($datos['visibilidad'] ?? '', ['interno', 'clientes'], true)) {
        return 'Estado o audiencia no validos.';
    }
    return null;
}

/** La misma restriccion se aplica a busquedas, sugerencias y detalle. */
function conocimiento_visible(array $articulo, bool $esCliente): bool {
    return ($articulo['estado'] ?? '') === 'publicado'
        && (!$esCliente || ($articulo['visibilidad'] ?? '') === 'clientes');
}

function conocimiento_buscar(PDO $pdo, string $consulta, bool $esCliente, int $limite = 12, int $antesDe = 0): array {
    if (!conocimiento_disponible($pdo)) return [];
    $limite = max(1, min(51, $limite));
    $sql = "SELECT id, titulo, resumen, categoria, actualizado_en FROM conocimiento WHERE estado = 'publicado'";
    $params = [];
    if ($esCliente) $sql .= " AND visibilidad = 'clientes'";
    $consulta = trim(mb_substr($consulta, 0, 180));
    if ($consulta !== '') {
        $sql .= ' AND MATCH(titulo, resumen, contenido) AGAINST (:consulta IN NATURAL LANGUAGE MODE)';
        $params[':consulta'] = $consulta;
    }
    if ($antesDe > 0) {
        $sql .= ' AND id < :cursor';
        $params[':cursor'] = $antesDe;
    }
    $sql .= " ORDER BY id DESC LIMIT $limite";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function portal_siguiente_accion(string $estado, ?string $ultimoAutor): array {
    if ($estado === 'cerrada') return ['titulo' => 'Solicitud finalizada', 'detalle' => 'Puedes consultar la solucion y la conversacion en el historial.', 'tono' => 'neutral'];
    if ($estado === 'resuelta') return ['titulo' => 'Confirma la solucion', 'detalle' => 'El equipo ha propuesto una solucion. Dinos si ya funciona.', 'tono' => 'success'];
    if ($ultimoAutor === 'tecnico') return ['titulo' => 'Tienes una respuesta', 'detalle' => 'Revisa el mensaje del equipo y responde si necesita mas informacion.', 'tono' => 'attention'];
    return ['titulo' => 'El equipo esta trabajando', 'detalle' => 'Hemos recibido tu solicitud. Puedes aportar mas informacion en la conversacion.', 'tono' => 'neutral'];
}
