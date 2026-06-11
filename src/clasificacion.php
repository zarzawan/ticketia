<?php
// lib/clasificacion.php
// Clasificacion IA de incidencias (idioma, urgencia, tipo, recomendacion, resumen).
// Usada por guardar_incidencia.php (alta), reclasificar_incidencia.php (bajo demanda)
// y reprocesar_incidencias.php (lote).


const CLASIFICACION_RECOMENDACION_DEFECTO = 'Revisar la incidencia y asignar al equipo correspondiente.';

/**
 * Normaliza la recomendacion para guardarla en BD en formato Markdown legible.
 */
function normalizar_recomendacion_markdown(string $texto): string {
    $texto = trim(str_replace(["\r\n", "\r"], "\n", $texto));
    $texto = preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto;

    if ($texto === '') {
        return '';
    }

    // Si ya viene con estructura Markdown, no forzar transformaciones agresivas.
    if (preg_match('/(^|\n)\s*(#|- |\* |\d+\.\s+)/m', $texto)) {
        return $texto;
    }

    // Fallback: partir por frases y devolver una lista breve.
    $frases = preg_split('/(?<=[\.\!\?])\s+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($frases) || count($frases) === 0) {
        return $texto;
    }

    $frases = array_map('trim', $frases);
    $frases = array_values(array_filter($frases, fn($f) => $f !== ''));

    if (count($frases) === 0) {
        return $texto;
    }

    $salida = "## Recomendacion inicial\n";
    foreach ($frases as $frase) {
        $salida .= "- {$frase}\n";
    }

    return trim($salida);
}

/**
 * Pide al LLM la clasificacion de un ticket y devuelve un array ya validado
 * (idioma, urgencia, recomendacion, tipo, resumen) o null si el modelo fallo.
 */
function clasificar_incidencia(string $titulo, string $descripcion): ?array {
    $tipos_prompt = dominio_tipos_para_prompt();
    $contexto = "Título: \"$titulo\"\nDescripción: \"$descripcion\"";

    $pregunta = <<<EOT
Analiza la siguiente incidencia basada en su título y descripción. Detecta el idioma con precisión a partir del contenido proporcionado en el contexto. Responde SOLO con un objeto JSON válido que contenga exactamente estos campos:

- "idioma": Código de idioma del título y descripción (ej: 'es' para español, 'en' para inglés, 'fr' para francés, 'ca' para catalán). Basado únicamente en el idioma del contenido—no asumas basado en el prompt.
- "urgencia": Una de: 'critico', 'urgente', 'leve'.
- "recomendacion": Recomendacion inicial en Markdown (max 350 palabras), con este formato:
  "## Recomendacion inicial"
  "- Accion 1"
  "- Accion 2"
  "- Accion 3"
  No uses HTML.
- "tipo": Una de: $tipos_prompt. Si incluye solicitudes de información sobre productos, servicios, precios, reuniones o propuestas comerciales, clasifícalo como 'Comercial' (incluso si menciona temas técnicos). Ejemplo 1: 'Solicitud de información sobre ampliación de servicios cloud' -> 'Comercial'. Ejemplo 2: 'Consulta sobre migración a Microsoft 365' -> 'Comercial'.
- "resumen": Resumen de una línea (máx 75 caracteres) siempre en español.

Ejemplos para detección de idioma:
- Si título/descripción es "Server down, need urgent fix.", establece "idioma": "en".
- Si título/descripción es "Servidor caído, necesita arreglo urgente.", establece "idioma": "es".
- Si título/descripción es "Servidor caigut, necessita reparació urgent.", establece "idioma": "ca".

No agregues texto adicional fuera del JSON. Asegúrate de que el JSON sea válido.
EOT;

    $response = LLMClient::getResponse($contexto, $pregunta);
    $data = llm_extract_json_payload($response);
    if (!is_array($data)) {
        error_log("Clasificacion IA fallida. Respuesta recibida: " . ($response ?? 'null'));
        return null;
    }

    $idioma = trim($data['idioma'] ?? 'es');
    if ($idioma === '') {
        $idioma = 'es';
    }

    $urgencia = trim($data['urgencia'] ?? 'leve');
    if (!in_array($urgencia, dominio_urgencias(), true)) {
        $urgencia = 'leve';
    }

    $recomendacion = trim($data['recomendacion'] ?? '');
    $recomendacion = $recomendacion === ''
        ? normalizar_recomendacion_markdown(CLASIFICACION_RECOMENDACION_DEFECTO)
        : normalizar_recomendacion_markdown($recomendacion);

    $tipo = trim((string)($data['tipo'] ?? ''));
    if (!in_array($tipo, dominio_tipos(), true)) {
        $tipo = null;
    }

    $resumen = trim($data['resumen'] ?? '');
    if ($resumen === '') {
        $resumen = mb_substr($descripcion, 0, 75);
    }

    return [
        'idioma' => $idioma,
        'urgencia' => $urgencia,
        'recomendacion' => $recomendacion,
        'tipo' => $tipo,
        'resumen' => $resumen
    ];
}

/** Persiste una clasificacion validada sobre una incidencia existente. */
function clasificacion_aplicar(PDO $pdo, int $id_incidencia, array $c): void {
    $sql = "UPDATE incidencias SET urgencia = :urgencia, recomendacion = :recomendacion, tipo = :tipo, resumen = :resumen, idioma = :idioma WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':urgencia' => $c['urgencia'],
        ':recomendacion' => $c['recomendacion'],
        ':tipo' => $c['tipo'],
        ':resumen' => $c['resumen'],
        ':idioma' => $c['idioma'],
        ':id' => $id_incidencia
    ]);
}
