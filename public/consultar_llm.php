<?php
require_once __DIR__ . '/../src/arranque.php';

// Obtener ID de incidencia
$id_incidencia = isset($_GET['id']) ? intval($_GET['id']) : 0;
gobierno_ia_contexto_establecer($id_incidencia);

// Obtener la fecha, hora y huso horario actuales
date_default_timezone_set('Europe/Madrid');
$fecha_actual = date('Y-m-d H:i:s');
$huso_horario = date_default_timezone_get();

// Cargar incidencia
$sql = "SELECT * FROM incidencias WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id_incidencia]);
$incidencia = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$incidencia) {
    die("❌ Incidencia no encontrada.");
}

// Cargar mensajes
$sql_mensajes = "SELECT autor, mensaje, fecha FROM mensajes WHERE id_incidencia = :id ORDER BY fecha ASC";
$stmt = $pdo->prepare($sql_mensajes);
$stmt->execute([':id' => $id_incidencia]);
$mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cargar reaperturas
$sql_reap = "SELECT motivo, fecha FROM reaperturas WHERE id_incidencia = :id ORDER BY fecha ASC";
$stmt = $pdo->prepare($sql_reap);
$stmt->execute([':id' => $id_incidencia]);
$reaperturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Clase CSS según el estado
$estado_clase = str_replace('_', '-', $incidencia['estado']);
$estado_titulo = ucfirst(str_replace('_', ' ', $incidencia['estado']));

// Mapas de traducción para valores codificados
$urgMap = ['critico' => 'c', 'urgente' => 'u', 'leve' => 'l'];
$estMap = ['abierta' => 'a', 'en_curso' => 'e', 'esperando_cliente' => 'w', 'resuelta' => 'r', 'cerrada' => 'z'];
$autorMap = ['c' => 'cliente', 't' => 'técnico'];
$keyMap = [
    'id' => 'identificador',
    'u' => 'urgencia',
    's' => 'estado',
    'fc' => 'fecha_creacion',
    'fci' => 'fecha_cierre',
    't' => 'titulo',
    'd' => 'descripcion',
    'm' => 'mensajes',
    'rp' => 'reaperturas',
    'age' => 'dias_abierta',
    'sr' => 'dias_sin_respuesta',
    'tot' => 'total_mensajes',
    'rpt' => 'total_reaperturas'
];

// Crear el contexto optimizado en formato JSON
$incidencia_slim = [
    'id' => (int)$incidencia['id'],
    'u' => $urgMap[$incidencia['urgencia']] ?? 'l',
    's' => $estMap[$incidencia['estado']] ?? 'a',
    'fc' => $incidencia['fecha_creacion'],
    'fci' => $incidencia['fecha_cierre'] ?: null,
    't' => $incidencia['titulo'],
    'd' => $incidencia['descripcion'],
    'm' => array_map(function($m) {
        return [strtolower(substr($m['autor'], 0, 1)), $m['mensaje'], $m['fecha']];
    }, $mensajes),
    'rp' => array_map(function($r) {
        return [$r['motivo'], $r['fecha']];
    }, $reaperturas)
];

// Calcular campos derivados
$age = (new DateTime())->diff(new DateTime($incidencia['fecha_creacion']))->days;
$lastMsg = !empty($mensajes) ? end($mensajes)['fecha'] : $incidencia['fecha_creacion'];
$sinResp = (new DateTime())->diff(new DateTime($lastMsg))->days;

// Añadir campos derivados al JSON
$incidencia_slim['age'] = $age;
$incidencia_slim['sr'] = $sinResp;

// Calcular KPIs básicos
$kpi = [
    'tot' => count($mensajes),
    'rpt' => count($reaperturas)
];

$llm_input = [
    'meta' => [
        'currDate' => $fecha_actual,
        'tz' => $huso_horario,
        'ver' => '1.2',
        'gen' => gmdate('c'),
        'kpi' => $kpi
    ],
    'map' => [
        'keys' => $keyMap,
        'urg' => array_flip($urgMap),
        'est' => array_flip($estMap),
        'aut' => $autorMap
    ],
    'inc' => $incidencia_slim
];

// Serializar el JSON
$llm_json = json_encode(
    $llm_input,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('Error al generar JSON: ' . json_last_error_msg());
    die('Error al generar JSON: ' . json_last_error_msg());
}

// Prompt optimizado
$pregunta = <<<TXT
Eres un coordinador tecnico senior. Analiza la incidencia del nodo "inc" usando los mapas del nodo "map".

Reglas de interpretacion:
- Usa map["keys"] para entender claves abreviadas.
- Usa map["urg"], map["est"] y map["aut"] para decodificar valores.
- Cada entrada de "m" es [autor, texto, fecha], donde autor es "c" o "t".
- Usa meta.currDate y meta.tz para calcular retrasos.

Reglas de salida:
- Cuando cites la incidencia, menciona solo id y titulo tal como aparecen en JSON.
- Expresa urgencia y estado con su significado descriptivo (no codigos).
- Nunca muestres codigos internos como u=c, s=a, age=0, sr=5.
- No uses emojis ni caracteres especiales decorativos.

Incluye:
1. Diagnostico breve (estado y retrasos).
2. Problemas detectados (antiguedad, contenido, falta de respuesta).
3. Senales de alarma y posibles escalados.
4. Recomendaciones concretas para el equipo.
5. Incidencias conflictivas por mala actitud o insultos.

Responde de forma clara, profesional y orientada a la accion.
TXT;

// Manejar la solicitud de streaming (el proveedor lo resuelve llm/llm.php)
if (isset($_GET['stream']) && $_GET['stream'] == 1) {
    try {
        LLMClient::streamResponse($llm_json, $pregunta);
        exit;
    } catch (Exception $e) {
        error_log('Excepción en streaming: ' . $e->getMessage());
        echo "data: " . json_encode("Error al obtener respuesta: " . $e->getMessage()) . "\n\n";
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumen de la incidencia #<?php echo $id_incidencia; ?></title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css?v=<?= filemtime(__DIR__ . '/estilos.css') ?>">
</head>
<body>
<div class="container">
    <div class="page-shell">
        <header class="page-header">
            <div>
                <h1>Resumen IA - Incidencia #<?php echo $id_incidencia; ?></h1>
                <p class="subtitulo">Resumen generado por IA para la incidencia seleccionada.</p>
            </div>
            <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
        </header>

        <div class="page-tools">
            <a href="ver_incidencia.php?id=<?php echo $id_incidencia; ?>" class="card-button secondary-button" title="Volver a la incidencia">‹ Volver</a>
            <button class="card-button reload-resumen" title="Recargar resumen">Recargar</button>
            <button class="card-button secondary-button copy-resumen" title="Copiar resumen">Copiar</button>
            <span class="status-pill" id="streamStatus">Generando resumen...</span>
            <span class="status-pill"><?php echo htmlspecialchars($estado_titulo); ?></span>
        </div>

        <div class="incidencia-box compact-box">
            <h2>Resumen de la incidencia</h2>
            <div class="analysis-stream pretty" id="resumen-content">
                <div class="loading" id="loading">Generando resumen...</div>
                <div class="analysis-content markdown-compact" id="resumen-text"></div>
            </div>
        </div>
    </div>

    <button class="scroll-top card-button" title="Volver arriba">Arriba</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const resumenText = document.getElementById('resumen-text');
    const loading = document.getElementById('loading');
    const copyButton = document.querySelector('.copy-resumen');
    const reloadButton = document.querySelector('.reload-resumen');
    const streamStatus = document.getElementById('streamStatus');
    const themeToggle = document.getElementById('themeToggle');
    let rawResponse = '';

    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('incidencias_theme', theme);
    };
    applyTheme(localStorage.getItem('incidencias_theme') || 'light');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    }
    function renderLLMResponse(text) {
        const normalizedText = (text ?? '')
            .replace(/\r\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n');

        if (window.marked && window.DOMPurify) {
            marked.setOptions({ gfm: true, breaks: false });
            const html = marked.parse(normalizedText);
            resumenText.innerHTML = DOMPurify.sanitize(html);
            resumenText.style.whiteSpace = 'normal';
            return;
        }
        resumenText.textContent = normalizedText;
        resumenText.style.whiteSpace = 'pre-wrap';
    }

    // Función para copiar texto con fallback
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(() => false);
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            let success = false;
            try {
                success = document.execCommand('copy');
            } catch (err) {
                success = false;
            }
            document.body.removeChild(textarea);
            return Promise.resolve(success);
        }
    }

    // Copiar resumen
    copyButton.addEventListener('click', () => {
        const text = rawResponse.trim();
        if (!text) {
            alert('No hay texto para copiar. Espera a que el resumen esté completo.');
            return;
        }
        copyToClipboard(text).then((success) => {
            if (success) {
                copyButton.textContent = 'Copiado';
                setTimeout(() => {
                    copyButton.textContent = 'Copiar';
                }, 2000);
            } else {
                alert('Error al copiar el resumen. Intenta de nuevo.');
            }
        });
    });

    // Recargar resumen
    reloadButton.addEventListener('click', () => {
        window.location.reload();
    });

    // Botón volver arriba
    const scrollTopBtn = document.querySelector('.scroll-top');
    window.addEventListener('scroll', () => {
        scrollTopBtn.style.display = window.scrollY > 300 ? 'block' : 'none';
    });
    scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Manejo de SSE para todos los modelos
    const source = new EventSource('<?php echo $_SERVER['PHP_SELF']; ?>?id=<?php echo $id_incidencia; ?>&stream=1');
    source.onmessage = (event) => {
        if (event.data === '[✔️ Finalizado correctamente]') {
            source.close();
            loading.style.display = 'none';
            if (streamStatus) {
                streamStatus.textContent = 'Resumen completado';
            }
            return;
        }
        try {
            const data = JSON.parse(event.data);
            rawResponse += data;
            renderLLMResponse(rawResponse);
            if (streamStatus) {
                streamStatus.textContent = 'Generando resumen...';
            }
        } catch (e) {
            console.error('Error al parsear datos:', e.message);
            rawResponse += '\n[Error al parsear datos: ' + e.message + ']';
            renderLLMResponse(rawResponse);
            if (streamStatus) {
                streamStatus.textContent = 'Error de formato en streaming';
            }
        }
    };

    source.onerror = () => {
        // No mostramos el mensaje de error, ya que es esperado cuando el servidor cierra la conexión
        source.close();
        loading.style.display = 'none';
        if (streamStatus && streamStatus.textContent !== 'Resumen completado') {
            streamStatus.textContent = 'Conexion cerrada';
        }
    };

    // Ocultar indicador de carga tras 15 segundos (máximo razonable para el streaming)
    let timeoutId = setTimeout(() => {
        loading.style.display = 'none';
        console.log('Temporizador de 15s activado: Ocultando loading.');
    }, 15000);

    // Detectar el final del streaming observando cambios
    const observer = new MutationObserver((mutations) => {
        console.log('Cambio detectado en #resumen-text. Longitud:', resumenText.textContent.trim().length);
        if (resumenText.textContent.trim().length > 20) {
            loading.style.display = 'none';
            clearTimeout(timeoutId);
            observer.disconnect();
            console.log('Contenido suficiente detectado: Ocultando loading.');
        }
    });
    observer.observe(resumenText, { childList: true, characterData: true, subtree: true });
});
</script>
</body>
</html>

