<?php
// src/correo.php
// Notificaciones por email con PHPMailer sobre SMTP.
// Todo es opcional: sin SMTP_HOST en .env no se envia nada y la aplicacion
// funciona igual. Los envios son "best effort": un fallo de correo nunca
// rompe la peticion, solo queda en el error_log.

use PHPMailer\PHPMailer\PHPMailer;

/** true si hay servidor SMTP configurado en el entorno. */
function correo_activo(): bool {
    return trim((string)entorno_valor('SMTP_HOST', '')) !== '';
}

/** URL base publica de la aplicacion (para los enlaces de los correos). */
function correo_url_base(): string {
    $base = trim((string)entorno_valor('APP_URL', ''));
    return $base !== '' ? rtrim($base, '/') : '';
}

/**
 * Envia un correo HTML a uno o varios destinatarios (array de emails o
 * de filas con 'email' y opcionalmente 'nombre'). Devuelve true si se
 * acepto el envio. Nunca lanza excepciones.
 */
function correo_enviar(array $destinatarios, string $asunto, string $html): bool {
    if (!correo_activo() || $destinatarios === []) {
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host = (string)entorno_valor('SMTP_HOST', '');
        $mail->Port = (int)entorno_valor('SMTP_PORT', 587);
        $mail->Timeout = 10;

        $usuario = trim((string)entorno_valor('SMTP_USER', ''));
        if ($usuario !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $usuario;
            $mail->Password = (string)entorno_valor('SMTP_PASS', '');
        }

        $seguridad = strtolower(trim((string)entorno_valor('SMTP_SECURE', 'tls')));
        if ($seguridad === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($seguridad === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $desde = trim((string)entorno_valor('SMTP_FROM', '')) ?: 'ticketia@localhost';
        $mail->setFrom($desde, trim((string)entorno_valor('SMTP_FROM_NAME', '')) ?: 'TicketIA');

        $enviados = 0;
        foreach ($destinatarios as $destinatario) {
            $email = is_array($destinatario) ? (string)($destinatario['email'] ?? '') : (string)$destinatario;
            $nombre = is_array($destinatario) ? (string)($destinatario['nombre'] ?? '') : '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($email, $nombre);
                $enviados++;
            }
        }
        if ($enviados === 0) {
            return false;
        }

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = correo_plantilla($asunto, $html);
        $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));

        return $mail->send();
    } catch (Throwable $e) {
        error_log('TicketIA correo: ' . $e->getMessage());
        return false;
    }
}

/** Envoltorio HTML minimo y comun para todos los correos. */
function correo_plantilla(string $titulo, string $cuerpo): string {
    $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    return "<div style=\"font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:0 auto;padding:24px;\">"
        . "<h2 style=\"color:#1d1d1f;font-size:18px;margin:0 0 4px;\">TicketIA</h2>"
        . "<p style=\"color:#6e6e73;font-size:13px;margin:0 0 20px;\">{$t}</p>"
        . "<div style=\"background:#f5f5f7;border-radius:12px;padding:18px;color:#1d1d1f;font-size:14px;line-height:1.5;\">{$cuerpo}</div>"
        . "<p style=\"color:#6e6e73;font-size:12px;margin-top:20px;\">Este es un mensaje automatico de TicketIA; no respondas a este correo.</p>"
        . "</div>";
}

/** Enlace HTML al ticket segun el rol del destinatario (portal o panel). */
function correo_enlace_ticket(int $id_incidencia, string $rol): string {
    $base = correo_url_base();
    if ($base === '') {
        return '';
    }
    $pagina = $rol === 'cliente' ? 'portal_ver.php' : 'ver_incidencia.php';
    $url = "$base/$pagina?id=$id_incidencia";
    return "<p style=\"margin:14px 0 0;\"><a href=\"$url\" style=\"color:#0071e3;\">Ver el ticket #$id_incidencia</a></p>";
}

// ---------------------------------------------------------------------------
// Notificaciones concretas. Todas silenciosas y sin efectos si no hay SMTP.
// ---------------------------------------------------------------------------

/** Datos minimos del ticket + creador, o null. */
function correo_datos_ticket(PDO $pdo, int $id_incidencia): ?array {
    $stmt = $pdo->prepare(
        "SELECT i.id, i.titulo, i.creado_por, i.asignado_id,
                uc.email AS creador_email, uc.nombre AS creador_nombre, uc.rol AS creador_rol, uc.activo AS creador_activo,
                ua.email AS asignado_email, ua.nombre AS asignado_nombre, ua.activo AS asignado_activo
         FROM incidencias i
         LEFT JOIN usuarios uc ON uc.id = i.creado_por
         LEFT JOIN usuarios ua ON ua.id = i.asignado_id
         WHERE i.id = :id"
    );
    $stmt->execute([':id' => $id_incidencia]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Administradores activos (para avisos de tickets nuevos o sin asignar). */
function correo_admins(PDO $pdo): array {
    return $pdo->query(
        "SELECT email, nombre FROM usuarios WHERE rol = 'admin' AND activo = 1"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Aviso al equipo cuando un cliente crea un ticket nuevo. */
function correo_notificar_nuevo_ticket(PDO $pdo, int $id_incidencia): void {
    if (!correo_activo()) {
        return;
    }
    $t = correo_datos_ticket($pdo, $id_incidencia);
    if ($t === null) {
        return;
    }
    $titulo = htmlspecialchars((string)$t['titulo'], ENT_QUOTES, 'UTF-8');
    $quien = htmlspecialchars((string)($t['creador_nombre'] ?? 'un usuario'), ENT_QUOTES, 'UTF-8');
    correo_enviar(
        correo_admins($pdo),
        "Nuevo ticket #{$t['id']}: {$t['titulo']}",
        "<p><strong>$quien</strong> ha creado el ticket <strong>#{$t['id']}</strong>:</p><p>$titulo</p>"
            . correo_enlace_ticket($id_incidencia, 'admin')
    );
}

/**
 * Aviso de mensaje nuevo (nunca notas internas): si escribe el equipo se avisa
 * al creador del ticket; si escribe el cliente, al asignado (o admins si no hay).
 */
function correo_notificar_mensaje(PDO $pdo, int $id_incidencia, bool $desde_cliente): void {
    if (!correo_activo()) {
        return;
    }
    $t = correo_datos_ticket($pdo, $id_incidencia);
    if ($t === null) {
        return;
    }
    $actor = function_exists('auth_usuario') ? auth_usuario() : null;
    $titulo = htmlspecialchars((string)$t['titulo'], ENT_QUOTES, 'UTF-8');

    if ($desde_cliente) {
        $destinatarios = ((int)($t['asignado_activo'] ?? 0) === 1 && !empty($t['asignado_email']))
            ? [['email' => $t['asignado_email'], 'nombre' => $t['asignado_nombre']]]
            : correo_admins($pdo);
        $rol_destino = 'operador';
    } else {
        if (empty($t['creador_email']) || (int)($t['creador_activo'] ?? 0) !== 1) {
            return;
        }
        $destinatarios = [['email' => $t['creador_email'], 'nombre' => $t['creador_nombre']]];
        $rol_destino = (string)($t['creador_rol'] ?? 'cliente');
    }

    // No avisarse a uno mismo.
    $destinatarios = array_values(array_filter($destinatarios, function ($d) use ($actor) {
        return ($d['email'] ?? '') !== ($actor['email'] ?? null);
    }));

    correo_enviar(
        $destinatarios,
        "Nueva respuesta en el ticket #{$t['id']}",
        "<p>Hay una nueva respuesta en el ticket <strong>#{$t['id']}</strong>:</p><p>$titulo</p>"
            . correo_enlace_ticket($id_incidencia, $rol_destino)
    );
}

/** Aviso al creador del ticket cuando cambia de estado. */
function correo_notificar_estado(PDO $pdo, int $id_incidencia, string $anterior, string $nuevo): void {
    if (!correo_activo()) {
        return;
    }
    $t = correo_datos_ticket($pdo, $id_incidencia);
    if ($t === null || empty($t['creador_email']) || (int)($t['creador_activo'] ?? 0) !== 1) {
        return;
    }
    $actor = function_exists('auth_usuario') ? auth_usuario() : null;
    if (($actor['email'] ?? null) === $t['creador_email']) {
        return;
    }
    $etiquetas = [
        'abierta' => 'Nueva',
        'en_curso' => 'En trabajo',
        'resuelta' => 'Solucion propuesta',
        'cerrada' => 'Cerrada',
    ];
    $de = $etiquetas[$anterior] ?? $anterior;
    $a = $etiquetas[$nuevo] ?? $nuevo;
    $titulo = htmlspecialchars((string)$t['titulo'], ENT_QUOTES, 'UTF-8');

    correo_enviar(
        [['email' => $t['creador_email'], 'nombre' => $t['creador_nombre']]],
        "El ticket #{$t['id']} ha pasado a: $a",
        "<p>El ticket <strong>#{$t['id']}</strong> ($titulo) ha cambiado de estado:</p><p><strong>$de → $a</strong></p>"
            . correo_enlace_ticket($id_incidencia, (string)($t['creador_rol'] ?? 'cliente'))
    );
}
