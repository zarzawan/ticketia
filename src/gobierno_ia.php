<?php
// Gobierno y trazabilidad de las funciones IA. Mantiene el contexto de la
// peticion, registra adopcion del copiloto y aplica la retencion de logs.

const GOBIERNO_IA_TIPO_COPILOTO = 'copiloto';

function gobierno_ia_contexto_establecer(?int $incidenciaId): void {
    $GLOBALS['ticketia_ia_incidencia_id'] = $incidenciaId !== null && $incidenciaId > 0
        ? $incidenciaId
        : null;
}

function gobierno_ia_contexto_incidencia(): ?int {
    $id = $GLOBALS['ticketia_ia_incidencia_id'] ?? null;
    return is_int($id) && $id > 0 ? $id : null;
}

function gobierno_ia_hash_valido(string $hash): bool {
    return preg_match('/^[a-f0-9]{40}$/i', $hash) === 1;
}

function gobierno_ia_retencion_dias(): int {
    return min(730, max(7, (int)entorno_valor('LLM_LOG_RETENTION_DIAS', 90)));
}

function gobierno_ia_esquema_disponible(PDO $pdo): bool {
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback_ia'"
        );
        return (int)$stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        return false;
    }
}

/** Guarda solo los campos proporcionados y conserva el feedback anterior. */
function gobierno_ia_feedback_guardar(
    PDO $pdo,
    int $usuarioId,
    int $incidenciaId,
    string $contenidoHash,
    ?int $valoracion = null,
    bool $borradorUsado = false,
    bool $borradorEnviado = false
): bool {
    if ($usuarioId <= 0 || $incidenciaId <= 0 || !gobierno_ia_hash_valido($contenidoHash)
        || ($valoracion !== null && !in_array($valoracion, [-1, 1], true))) {
        return false;
    }

    try {
        $actual = $pdo->prepare(
            "SELECT 1 FROM copiloto_ia WHERE id_incidencia = :incidencia AND contenido_hash = :hash"
        );
        $actual->execute([':incidencia' => $incidenciaId, ':hash' => strtolower($contenidoHash)]);
        if ($actual->fetchColumn() === false) {
            return false;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO feedback_ia
                (usuario_id, incidencia_id, tipo, contenido_hash, valoracion, borrador_usado, borrador_enviado)
             VALUES (:usuario, :incidencia, 'copiloto', :hash, :valoracion, :usado, :enviado)
             ON DUPLICATE KEY UPDATE
                valoracion = COALESCE(VALUES(valoracion), valoracion),
                borrador_usado = GREATEST(borrador_usado, VALUES(borrador_usado)),
                borrador_enviado = GREATEST(borrador_enviado, VALUES(borrador_enviado)),
                actualizado_en = NOW()"
        );
        return $stmt->execute([
            ':usuario' => $usuarioId,
            ':incidencia' => $incidenciaId,
            ':hash' => strtolower($contenidoHash),
            ':valoracion' => $valoracion,
            ':usado' => $borradorUsado ? 1 : 0,
            ':enviado' => $borradorEnviado ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('TicketIA feedback IA: ' . $e->getMessage());
        return false;
    }
}

function gobierno_ia_feedback_usuario(PDO $pdo, int $usuarioId, int $incidenciaId, string $contenidoHash): ?array {
    if ($usuarioId <= 0 || $incidenciaId <= 0 || !gobierno_ia_hash_valido($contenidoHash)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT valoracion, borrador_usado, borrador_enviado
             FROM feedback_ia
             WHERE usuario_id = :usuario AND incidencia_id = :incidencia
               AND tipo = 'copiloto' AND contenido_hash = :hash"
        );
        $stmt->execute([
            ':usuario' => $usuarioId,
            ':incidencia' => $incidenciaId,
            ':hash' => strtolower($contenidoHash),
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** El worker elimina por lotes los logs que superan la politica de retencion. */
function gobierno_ia_mantenimiento(PDO $pdo): int {
    $dias = gobierno_ia_retencion_dias();
    try {
        return $pdo->exec(
            "DELETE FROM llm_logs WHERE fecha < DATE_SUB(NOW(), INTERVAL {$dias} DAY) LIMIT 5000"
        );
    } catch (PDOException $e) {
        return 0;
    }
}
