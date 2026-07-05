<?php
// src/adjuntos.php
// Adjuntos de incidencias: se almacenan fuera del docroot (almacen/adjuntos)
// con nombre aleatorio y se sirven siempre via descargar_adjunto.php.

/** ext => mimes aceptados (verificados con finfo sobre el contenido real). */
const ADJUNTOS_PERMITIDOS = [
    'png' => ['image/png'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],
    'pdf' => ['application/pdf'],
    'txt' => ['text/plain'],
    'log' => ['text/plain', 'application/octet-stream'],
    'csv' => ['text/plain', 'text/csv', 'application/csv'],
    'zip' => ['application/zip', 'application/x-zip-compressed'],
    'doc' => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xls' => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
];

function adjuntos_directorio(): string {
    return TICKETIA_RAIZ . '/almacen/adjuntos';
}

function adjuntos_max_bytes(): int {
    return max(1, (int)($_ENV['ADJUNTOS_MAX_MB'] ?? 10)) * 1024 * 1024;
}

function adjuntos_formato_tamano(int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

/** Adjuntos de una incidencia, con el nombre de quien los subio. */
function adjuntos_de(PDO $pdo, int $id_incidencia): array {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.nombre_original, a.mime, a.tamano, a.fecha, u.nombre AS usuario_nombre
         FROM adjuntos a
         LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.id_incidencia = :id
         ORDER BY a.fecha ASC"
    );
    $stmt->execute([':id' => $id_incidencia]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Valida y guarda un fichero subido ($_FILES['...']) para una incidencia.
 * Devuelve ['ok' => bool, 'error' => string|null].
 */
function adjuntos_guardar(PDO $pdo, int $id_incidencia, array $fichero): array {
    if (($fichero['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $mensajes = [
            UPLOAD_ERR_INI_SIZE => 'El fichero supera el limite del servidor.',
            UPLOAD_ERR_FORM_SIZE => 'El fichero supera el limite permitido.',
            UPLOAD_ERR_NO_FILE => 'No se ha seleccionado ningun fichero.',
        ];
        return ['ok' => false, 'error' => $mensajes[$fichero['error']] ?? 'Error al subir el fichero.'];
    }

    $tamano = (int)$fichero['size'];
    if ($tamano <= 0 || $tamano > adjuntos_max_bytes()) {
        return ['ok' => false, 'error' => 'El fichero supera el maximo de ' . adjuntos_formato_tamano(adjuntos_max_bytes()) . '.'];
    }

    $nombre_original = trim((string)$fichero['name']);
    $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    if (!isset(ADJUNTOS_PERMITIDOS[$extension])) {
        return ['ok' => false, 'error' => "Tipo de fichero no permitido (.$extension). Permitidos: " . implode(', ', array_keys(ADJUNTOS_PERMITIDOS)) . '.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($fichero['tmp_name']);
    if (!in_array($mime, ADJUNTOS_PERMITIDOS[$extension], true)) {
        return ['ok' => false, 'error' => 'El contenido del fichero no coincide con su extension.'];
    }

    $directorio = adjuntos_directorio();
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true)) {
        return ['ok' => false, 'error' => 'No se pudo crear el directorio de adjuntos.'];
    }

    $nombre_disco = bin2hex(random_bytes(20)) . '.' . $extension;
    if (!move_uploaded_file($fichero['tmp_name'], $directorio . '/' . $nombre_disco)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el fichero.'];
    }

    $usuario = auth_usuario();
    $pdo->prepare(
        "INSERT INTO adjuntos (id_incidencia, usuario_id, nombre_original, nombre_disco, mime, tamano)
         VALUES (:incidencia, :usuario, :original, :disco, :mime, :tamano)"
    )->execute([
        ':incidencia' => $id_incidencia,
        ':usuario' => $usuario['id'] ?? null,
        ':original' => mb_substr($nombre_original, 0, 255),
        ':disco' => $nombre_disco,
        ':mime' => mb_substr($mime, 0, 100),
        ':tamano' => $tamano,
    ]);

    return ['ok' => true, 'error' => null];
}
