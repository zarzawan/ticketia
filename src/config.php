<?php
// src/config.php
// Configuracion desde variables de entorno (.env). No editar credenciales aqui:
// copia .env.example a .env y ajusta los valores de tu instalacion.

$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_port = $_ENV['DB_PORT'] ?? '3306';
$db_name = $_ENV['DB_NAME'] ?? 'ticketia';
$db_user = $_ENV['DB_USER'] ?? 'ticketia';
$db_pass = $_ENV['DB_PASS'] ?? '';

$dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('TicketIA: error de conexion a BD: ' . $e->getMessage());
    die('No se pudo conectar a la base de datos. Revisa la configuracion del fichero .env y que la base de datos exista (php bin/instalar.php).');
}

// ---------------------------------------------------------------------------
// Proveedores de IA. Solo se ofrecen los que esten configurados en .env.
// 'chat'      -> llamadas sin streaming (clasificacion, recomendaciones)
// 'stream'    -> analisis largos servidos por SSE
// 'traductor' -> traducciones de tickets y mensajes
// 'temperature' => null significa no enviar el parametro.
// ---------------------------------------------------------------------------

$llm_provider = $_ENV['LLM_PROVIDER'] ?? 'local';

$llm_config = [
    'connect_timeout' => (int)($_ENV['LLM_CONNECT_TIMEOUT'] ?? 5),
    'timeout_chat' => (int)($_ENV['LLM_TIMEOUT'] ?? 120),
    'timeout_traductor' => (int)($_ENV['LLM_TIMEOUT_TRADUCTOR'] ?? 60),
];

// IA local: cualquier servidor compatible con la API de OpenAI
// (LM Studio: http://localhost:1234/v1/..., Ollama: http://localhost:11434/v1/...)
$llm_local_endpoint = $_ENV['LLM_LOCAL_ENDPOINT'] ?? 'http://localhost:1234/v1/chat/completions';
$llm_local_model = $_ENV['LLM_LOCAL_MODEL'] ?? '';
if ($llm_local_endpoint !== '') {
    $llm_config['local'] = [
        'label' => 'IA local',
        'endpoint' => $llm_local_endpoint,
        'api_key' => $_ENV['LLM_LOCAL_API_KEY'] ?? null,
        'chat' => ['model' => $llm_local_model, 'token_param' => 'max_tokens', 'max_tokens' => 5000, 'temperature' => 0.1],
        'stream' => ['model' => $llm_local_model, 'token_param' => 'max_tokens', 'max_tokens' => 10000, 'temperature' => 0.1],
        'traductor' => ['model' => $llm_local_model, 'token_param' => 'max_tokens', 'max_tokens' => 5000, 'temperature' => 0.7],
    ];
}

if (!empty($_ENV['OPENAI_API_KEY'])) {
    $llm_config['openai'] = [
        'label' => 'OpenAI',
        'endpoint' => 'https://api.openai.com/v1/chat/completions',
        'api_key' => $_ENV['OPENAI_API_KEY'],
        'chat' => ['model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-5', 'token_param' => 'max_completion_tokens', 'max_tokens' => 5000, 'temperature' => null],
        'stream' => ['model' => $_ENV['OPENAI_MODEL_STREAM'] ?? 'gpt-4.1-mini', 'token_param' => 'max_tokens', 'max_tokens' => 10000, 'temperature' => 0.1],
        'traductor' => ['model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-5', 'token_param' => 'max_completion_tokens', 'max_tokens' => 5000, 'temperature' => null],
    ];
}

if (!empty($_ENV['XAI_API_KEY'])) {
    $llm_config['xai'] = [
        'label' => 'xAI',
        'endpoint' => 'https://api.x.ai/v1/chat/completions',
        'api_key' => $_ENV['XAI_API_KEY'],
        'chat' => ['model' => $_ENV['XAI_MODEL'] ?? 'grok-3', 'token_param' => 'max_tokens', 'max_tokens' => 5000, 'temperature' => 0.1],
        'stream' => ['model' => $_ENV['XAI_MODEL_STREAM'] ?? 'grok-3-mini-beta', 'token_param' => 'max_tokens', 'max_tokens' => 10000, 'temperature' => 0.1],
        'traductor' => ['model' => $_ENV['XAI_MODEL'] ?? 'grok-3', 'token_param' => 'max_tokens', 'max_tokens' => 5000, 'temperature' => 0.1],
    ];
}

// Si el proveedor configurado no existe, usar el primero disponible.
if (!isset($llm_config[$llm_provider])) {
    foreach (['local', 'openai', 'xai'] as $candidato) {
        if (isset($llm_config[$candidato])) {
            $llm_provider = $candidato;
            break;
        }
    }
}

// Override del proveedor elegido desde la UI (cambiar_proveedor.php).
// Si la tabla ajustes no existe todavia (instalacion a medias), se ignora.
try {
    $ajuste_proveedor = $pdo->query("SELECT valor FROM ajustes WHERE clave = 'llm_provider'")->fetchColumn();
    if (is_string($ajuste_proveedor) && isset($llm_config[$ajuste_proveedor])) {
        $llm_provider = $ajuste_proveedor;
    }
} catch (PDOException $e) {
    // tabla ajustes aun no creada
}
