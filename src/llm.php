<?php
// llm/llm.php
// Capa unica de acceso a proveedores LLM (local / OpenAI / xAI).
// Sustituye a los antiguos client.php, client_openai.php, client_grok.php
// y client_traductor*.php. El proveedor activo se decide en config.php
// ($llm_provider + $llm_config); las paginas solo usan las fachadas
// LLMClient y LLMClientTraductor, igual que antes.


/**
 * Elimina el razonamiento de los modelos "reasoner" de una respuesta completa.
 * Cubre dos formatos:
 *  - Etiquetas <think>...</think> (DeepSeek-R1, Qwen3...).
 *  - Canales estilo harmony/gpt-oss: <|channel|>analysis ... <|channel|>final<|message|> respuesta
 *    (tambien las variantes degradadas <|channel>thought ... <channel|> que emite LM Studio).
 */
function llm_strip_razonamiento(string $texto): string {
    // Pares <think>...</think>
    $texto = preg_replace('/<(think|thinking|reasoning|thought)>[\s\S]*?<\/\1>/iu', '', $texto) ?? $texto;

    // Cierre sin apertura (respuesta parcial): quedarse con lo posterior al ultimo cierre
    if (preg_match('/<\/(think|thinking|reasoning|thought)>/iu', $texto)) {
        $partes = preg_split('/<\/(?:think|thinking|reasoning|thought)>/iu', $texto);
        $texto = is_array($partes) ? (string)end($partes) : $texto;
    }

    // Canales de razonamiento: desde el marcador hasta el siguiente canal (o el final)
    $texto = preg_replace('/<\|?channel\|?>\s*(thought|thinking|analysis|commentary)\b[\s\S]*?(?=<\|?channel\|?>|$)/iu', '', $texto) ?? $texto;

    // Cabecera del canal de respuesta y tokens sueltos del formato harmony
    $texto = preg_replace('/<\|?channel\|?>\s*(final|answer)\b\s*/iu', '', $texto) ?? $texto;
    $texto = preg_replace('/<\|(message|end|return|start)\|>\s*(assistant\b)?/iu', '', $texto) ?? $texto;
    $texto = preg_replace('/<\|?channel\|?>/iu', '', $texto) ?? $texto;

    return trim($texto);
}

/**
 * Filtro incremental de pares <think>...</think> para streaming. Mantiene estado
 * entre chunks ($st = ['buf' => '', 'dentro' => false]) y devuelve solo el texto
 * visible. Retiene una cola corta por si una etiqueta llega partida en dos chunks.
 */
function llm_filtrar_razonamiento_chunk(string $chunk, array &$st): string {
    $st['buf'] .= $chunk;
    $salida = '';

    while (true) {
        if ($st['dentro']) {
            if (preg_match('/<\/(think|thinking|reasoning|thought)>/i', $st['buf'], $m, PREG_OFFSET_CAPTURE)) {
                $st['buf'] = substr($st['buf'], $m[0][1] + strlen($m[0][0]));
                $st['dentro'] = false;
                continue;
            }
            // Conservar el final del buffer por si el cierre llega partido.
            $st['buf'] = substr($st['buf'], -16);
            return $salida;
        }

        if (preg_match('/<(think|thinking|reasoning|thought)>/i', $st['buf'], $m, PREG_OFFSET_CAPTURE)) {
            $salida .= substr($st['buf'], 0, $m[0][1]);
            $st['buf'] = substr($st['buf'], $m[0][1] + strlen($m[0][0]));
            $st['dentro'] = true;
            continue;
        }

        // Quitar tokens sueltos del formato harmony que lleguen completos.
        $st['buf'] = preg_replace('/<\|(message|end|return|start|channel)\|>/i', '', $st['buf']) ?? $st['buf'];

        // Emitir todo menos una cola que pueda ser el inicio de un marcador partido.
        $plantillas = [
            '<think>', '<thinking>', '<reasoning>', '<thought>',
            '</think>', '</thinking>', '</reasoning>', '</thought>',
            '<|message|>', '<|end|>', '<|return|>', '<|start|>', '<|channel|>'
        ];
        $len = strlen($st['buf']);
        $retener = 0;
        for ($k = min(12, $len); $k > 0; $k--) {
            $cola = substr($st['buf'], -$k);
            foreach ($plantillas as $p) {
                if (stripos($p, $cola) === 0) {
                    $retener = $k;
                    break 2;
                }
            }
        }

        $salida .= substr($st['buf'], 0, $len - $retener);
        $st['buf'] = $retener > 0 ? substr($st['buf'], -$retener) : '';
        return $salida;
    }
}

/**
 * Filtro de razonamiento con estado para streaming SSE. Ademas de los pares
 * <think>, gestiona el formato de canales harmony/gpt-oss, donde el modelo
 * abre con un canal de razonamiento y la respuesta llega tras el siguiente
 * marcador de canal:
 *   <|channel|>analysis ... <|channel|>final<|message|> respuesta
 *   <|channel>thought ... <channel|> respuesta   (variante observada en LM Studio)
 */
class LLMFiltroRazonamiento {
    private string $buf = '';
    private string $estado = 'inicio'; // inicio | canal | normal
    private array $par = ['buf' => '', 'dentro' => false];
    private string $emitido = '';
    private string $total = '';

    private const DECIDIR_MAX = 48;

    public function procesar(string $chunk): string {
        $this->total .= $chunk;
        $this->buf .= $chunk;
        $salida = '';

        while (true) {
            if ($this->estado === 'normal') {
                $pendiente = $this->buf;
                $this->buf = '';
                $salida .= llm_filtrar_razonamiento_chunk($pendiente, $this->par);
                break;
            }

            if ($this->estado === 'inicio') {
                // Decidir si la respuesta arranca con un marcador de razonamiento.
                $recortado = ltrim($this->buf);
                if ($recortado === '') {
                    break;
                }
                if ($recortado[0] !== '<') {
                    $this->estado = 'normal';
                    continue;
                }

                if (preg_match('/^<\|?channel\|?>\s*(thought|thinking|analysis|commentary)\b/i', $recortado)) {
                    $this->buf = (string)preg_replace('/^\s*<\|?channel\|?>\s*[a-z]+/i', '', $this->buf, 1);
                    $this->estado = 'canal';
                    continue;
                }
                if (preg_match('/^<\|?channel\|?>\s*(final|answer)\b/i', $recortado)) {
                    $this->buf = (string)preg_replace('/^\s*<\|?channel\|?>\s*[a-z]+\s*(<\|?message\|?>)?/i', '', $this->buf, 1);
                    $this->estado = 'normal';
                    continue;
                }
                if (preg_match('/^<\|?start\|?>\s*(assistant)?\s*/i', $recortado, $m) && strlen($m[0]) < strlen($recortado)) {
                    $this->buf = substr($recortado, strlen($m[0]));
                    continue;
                }
                if (preg_match('/^<(think|thinking|reasoning|thought)>/i', $recortado)) {
                    $this->estado = 'normal'; // lo gestiona el filtro de pares
                    continue;
                }
                if (strlen($recortado) >= self::DECIDIR_MAX) {
                    $this->estado = 'normal';
                    continue;
                }
                break; // posible marcador incompleto: esperar mas datos
            }

            // estado 'canal': descartando razonamiento hasta el siguiente marcador de canal
            if (!preg_match('/<\|?channel\|?>/i', $this->buf, $m, PREG_OFFSET_CAPTURE)) {
                $this->buf = substr($this->buf, -12);
                break;
            }

            $resto = substr($this->buf, $m[0][1] + strlen($m[0][0]));

            if (preg_match('/^\s*(thought|thinking|analysis|commentary)\b/i', $resto, $mm)) {
                $this->buf = substr($resto, strlen($mm[0]));
                continue; // otro canal de razonamiento: seguir descartando
            }
            if (preg_match('/^\s*(final|answer)\b/i', $resto, $mm)) {
                $tras = substr($resto, strlen($mm[0]));
                if (strlen($tras) < 12 && preg_match('/^\s*<?\|?[a-z]*$/i', $tras)) {
                    $this->buf = substr($this->buf, $m[0][1]); // <|message|> partido: esperar
                    break;
                }
                $this->buf = (string)preg_replace('/^\s*<\|?message\|?>/i', '', $tras, 1);
                $this->estado = 'normal';
                continue;
            }
            if (strlen($resto) < 12 && preg_match('/^\s*[a-z]*$/i', $resto)) {
                $this->buf = substr($this->buf, $m[0][1]); // palabra del canal partida: esperar
                break;
            }

            // Contenido directo tras el marcador (variante <channel|>respuesta)
            $this->buf = $resto;
            $this->estado = 'normal';
        }

        $this->emitido .= $salida;
        return $salida;
    }

    /** Vacia las colas retenidas al terminar el stream. */
    public function finalizar(): string {
        $salida = '';
        if ($this->estado === 'normal') {
            $salida = llm_filtrar_razonamiento_chunk($this->buf, $this->par);
            if (!$this->par['dentro']) {
                $salida .= $this->par['buf'];
            }
            $this->par['buf'] = '';
        } elseif ($this->estado === 'inicio') {
            $salida = $this->buf;
        }
        $this->buf = '';

        $salida = preg_replace('/<\|(message|end|return|start|channel)\|>/i', '', $salida) ?? $salida;

        // Red de seguridad: si todo el stream fue razonamiento y no se emitio nada,
        // recuperar la respuesta desde el texto completo acumulado.
        if (trim($this->emitido . $salida) === '') {
            $salida = llm_strip_razonamiento($this->total);
        }

        $this->emitido .= $salida;
        return $salida;
    }
}

class LLMHttpClient {
    private array $provider;   // id, label, endpoint, api_key
    private array $modo;       // model, token_param, max_tokens, temperature
    private string $modoNombre; // chat | stream | traductor
    private string $systemPrompt;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(array $provider, array $modo, string $modoNombre, string $systemPrompt, int $timeout, int $connectTimeout) {
        $this->provider = $provider;
        $this->modo = $modo;
        $this->modoNombre = $modoNombre;
        $this->systemPrompt = $systemPrompt;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Registra la llamada en llm_logs (observabilidad de coste/latencia/errores).
     * Nunca debe romper la llamada principal: cualquier fallo se ignora.
     */
    private function log(float $inicio, ?int $httpCode, bool $exito, ?int $tokensIn, ?int $tokensOut, ?string $error): void {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO llm_logs (proveedor, modo, modelo, origen, duracion_ms, http_code, exito, tokens_entrada, tokens_salida, error)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->provider['id'] ?? 'desconocido',
                $this->modoNombre,
                $this->modo['model'],
                basename((string)($_SERVER['SCRIPT_NAME'] ?? 'cli')),
                (int)round((microtime(true) - $inicio) * 1000),
                $httpCode,
                $exito ? 1 : 0,
                $tokensIn,
                $tokensOut,
                $error !== null ? mb_substr($error, 0, 255) : null
            ]);
        } catch (PDOException $e) {
            // sin tabla llm_logs o BD caida: no interferir con la llamada
        }
    }

    private function buildPayload(string $contexto, string $pregunta, bool $stream): array {
        $payload = [
            'model' => $this->modo['model'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user', 'content' => "Contexto:\n$contexto\n\nPregunta: $pregunta"]
            ],
            $this->modo['token_param'] => $this->modo['max_tokens'],
        ];

        if ($this->modo['temperature'] !== null) {
            $payload['temperature'] = $this->modo['temperature'];
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    private function buildHeaders(): array {
        $headers = ['Content-Type: application/json'];
        if (!empty($this->provider['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $this->provider['api_key'];
        }
        return $headers;
    }

    /**
     * Limite de coste: LLM_MAX_LLAMADAS_DIA (0 o vacio = sin limite) corta las
     * llamadas a proveedores de pago cuando se alcanza el cupo diario, contado
     * sobre llm_logs. La IA local nunca se limita.
     */
    private function limiteDiarioAlcanzado(): bool {
        if (($this->provider['id'] ?? '') === 'local') {
            return false;
        }
        $limite = (int)($_ENV['LLM_MAX_LLAMADAS_DIA'] ?? 0);
        if ($limite <= 0) {
            return false;
        }
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            return false;
        }
        try {
            $hoy = (int)$pdo->query(
                "SELECT COUNT(*) FROM llm_logs WHERE proveedor <> 'local' AND DATE(fecha) = CURDATE()"
            )->fetchColumn();
            return $hoy >= $limite;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Llamada sin streaming. Devuelve el texto de la respuesta o null si falla. */
    public function getResponse(string $contexto, string $pregunta): ?string {
        $inicio = microtime(true);

        if ($this->limiteDiarioAlcanzado()) {
            error_log('TicketIA: limite diario de llamadas IA de pago alcanzado (LLM_MAX_LLAMADAS_DIA).');
            $this->log($inicio, null, false, null, null, 'Limite diario alcanzado');
            return null;
        }

        $ch = curl_init($this->provider['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_POSTFIELDS => json_encode($this->buildPayload($contexto, $pregunta, false)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $label = $this->provider['label'];

        if ($error) {
            error_log("Error de cURL al contactar con $label: $error");
            $this->log($inicio, null, false, null, null, $error);
            return null;
        }

        if ($http_code !== 200) {
            error_log("Error HTTP $http_code al contactar con $label: $response");
            $this->log($inicio, (int)$http_code, false, null, null, "HTTP $http_code");
            return null;
        }

        $json = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($json['choices'][0]['message']['content'])) {
            error_log("Respuesta de $label no valida: $response");
            $this->log($inicio, (int)$http_code, false, null, null, 'Respuesta sin choices[0].message.content');
            return null;
        }

        $this->log(
            $inicio,
            (int)$http_code,
            true,
            isset($json['usage']['prompt_tokens']) ? (int)$json['usage']['prompt_tokens'] : null,
            isset($json['usage']['completion_tokens']) ? (int)$json['usage']['completion_tokens'] : null,
            null
        );

        // Los modelos razonadores anteponen su pensamiento: devolver solo el resultado.
        return llm_strip_razonamiento(trim($json['choices'][0]['message']['content']));
    }

    /** Llamada con streaming: reenvia los chunks como SSE al navegador. */
    public function streamResponse(string $contexto, string $pregunta): void {
        // Liberar el lock de sesion: sin esto, un analisis largo bloquea
        // cualquier otra peticion del mismo navegador hasta que termina.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ignore_user_abort(true);
        set_time_limit(0);
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $inicio = microtime(true);
        $label = $this->provider['label'];

        if ($this->limiteDiarioAlcanzado()) {
            $this->log($inicio, null, false, null, null, 'Limite diario alcanzado');
            echo "data: " . json_encode(['error' => 'Limite diario de llamadas IA de pago alcanzado. Usa la IA local o espera a manana.']) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
            return;
        }

        $sseBuffer = '';
        $filtroRazonamiento = new LLMFiltroRazonamiento();

        $ch = curl_init($this->provider['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_POSTFIELDS => json_encode($this->buildPayload($contexto, $pregunta, true)),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            // Abortar si el stream queda parado mas de 120s (sin limitar la duracion total).
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$sseBuffer, &$filtroRazonamiento) {
                $sseBuffer .= $data;
                while (($pos = strpos($sseBuffer, "\n")) !== false) {
                    $line = rtrim(substr($sseBuffer, 0, $pos), "\r");
                    $sseBuffer = substr($sseBuffer, $pos + 1);

                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        // Vaciar la cola retenida por el filtro antes de cerrar.
                        $resto = $filtroRazonamiento->finalizar();
                        if ($resto !== '') {
                            echo "data: " . json_encode($resto) . "\n\n";
                        }
                        echo "data: \"[✔️ Finalizado correctamente]\"\n\n";
                        @ob_flush();
                        flush();
                        continue;
                    }

                    $decoded = json_decode($json, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }
                    if (isset($decoded['choices'][0]['delta']['content'])) {
                        // Filtrar el razonamiento de modelos reasoner (<think> y canales harmony).
                        $visible = $filtroRazonamiento->procesar((string)$decoded['choices'][0]['delta']['content']);
                        if ($visible !== '') {
                            echo "data: " . json_encode($visible) . "\n\n";
                            @ob_flush();
                            flush();
                            usleep(5000); // Suavidad en la animacion
                        }
                    }
                }
                return strlen($data);
            }
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            error_log("Error de cURL en streaming con $label: $error");
            echo "data: " . json_encode("Error en $label: $error") . "\n\n";
            @ob_flush();
            flush();
        } elseif ($http_code !== 200) {
            error_log("Error HTTP $http_code en streaming con $label");
            echo "data: " . json_encode("Error en $label: Código HTTP $http_code") . "\n\n";
            @ob_flush();
            flush();
        }

        $this->log(
            $inicio,
            $http_code ? (int)$http_code : null,
            $error === '' && $http_code === 200,
            null,
            null,
            $error !== '' ? $error : ($http_code !== 200 ? "HTTP $http_code" : null)
        );
    }
}

final class LLM {
    private const SYSTEM_CHAT = 'Eres un asistente profesional. Responde únicamente con la información solicitada, sin texto adicional ni explicaciones.';
    private const SYSTEM_STREAM = 'Eres un asistente profesional. Responde con claridad y utilidad.';
    private const SYSTEM_TRADUCTOR = 'Eres un asistente profesional de traducción. Responde únicamente con la información solicitada, en el formato indicado, sin texto adicional.';

    private static function config(): array {
        $config = $GLOBALS['llm_config'] ?? null;
        $provider = $GLOBALS['llm_provider'] ?? 'local';
        if (!is_array($config) || !isset($config[$provider])) {
            throw new RuntimeException("Proveedor LLM '$provider' sin configuracion en config.php");
        }
        $p = $config[$provider];
        $p['id'] = $provider;
        return [$config, $p];
    }

    public static function chat(): LLMHttpClient {
        [$config, $p] = self::config();
        return new LLMHttpClient($p, $p['chat'], 'chat', self::SYSTEM_CHAT, (int)$config['timeout_chat'], (int)$config['connect_timeout']);
    }

    public static function stream(): LLMHttpClient {
        [$config, $p] = self::config();
        return new LLMHttpClient($p, $p['stream'], 'stream', self::SYSTEM_STREAM, 0, (int)$config['connect_timeout']);
    }

    public static function traductor(): LLMHttpClient {
        [$config, $p] = self::config();
        return new LLMHttpClient($p, $p['traductor'], 'traductor', self::SYSTEM_TRADUCTOR, (int)$config['timeout_traductor'], (int)$config['connect_timeout']);
    }
}

// Fachadas con la misma firma estatica que los antiguos clientes,
// para no tocar los puntos de llamada existentes.
class LLMClient {
    public static function getResponse($contexto, $pregunta): ?string {
        return LLM::chat()->getResponse((string)$contexto, (string)$pregunta);
    }

    public static function streamResponse($contexto, $pregunta): void {
        LLM::stream()->streamResponse((string)$contexto, (string)$pregunta);
    }
}

class LLMClientTraductor {
    public static function getResponse($contexto, $pregunta): ?string {
        $content = LLM::traductor()->getResponse((string)$contexto, (string)$pregunta);
        if ($content === null) {
            return null;
        }
        // Limpiar envoltorio Markdown si el modelo lo anade
        return trim(preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content));
    }
}
