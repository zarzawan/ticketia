<?php
require_once __DIR__ . '/../src/arranque.php';

$tipo_iconos = dominio_tipo_iconos();

// Definir opciones para los filtros
$tipos = dominio_tipos();
$urgencias = dominio_urgencias();
$estados = dominio_estados();

// Obtener parámetros de búsqueda y filtros
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_tipo = isset($_GET['filtro_tipo']) && in_array($_GET['filtro_tipo'], $tipos) ? $_GET['filtro_tipo'] : '';
$filtro_urgencia = isset($_GET['filtro_urgencia']) && in_array($_GET['filtro_urgencia'], $urgencias) ? $_GET['filtro_urgencia'] : '';
$filtro_estado = isset($_GET['filtro_estado']) && in_array($_GET['filtro_estado'], $estados) ? $_GET['filtro_estado'] : '';

// Obtener datos para los gráficos
$sql_tipos = "SELECT tipo, COUNT(*) as total FROM incidencias WHERE tipo IS NOT NULL GROUP BY tipo";
$stmt_tipos = $pdo->query($sql_tipos);
$datos_tipos = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

$sql_urgencias = "SELECT urgencia, COUNT(*) as total FROM incidencias GROUP BY urgencia";
$stmt_urgencias = $pdo->query($sql_urgencias);
$datos_urgencias = $stmt_urgencias->fetchAll(PDO::FETCH_ASSOC);

$sql_estados = "SELECT estado, COUNT(*) as total FROM incidencias GROUP BY estado";
$stmt_estados = $pdo->query($sql_estados);
$datos_estados = $stmt_estados->fetchAll(PDO::FETCH_ASSOC);

// Función para mostrar incidencias
function mostrarIncidencias($pdo, $busqueda, $filtro_tipo, $filtro_urgencia, $filtro_estado) {
    global $tipo_iconos, $estados;

    $sql = "SELECT * FROM incidencias WHERE 1=1";
    $params = [];

    if ($filtro_estado) {
        $sql .= " AND estado = :estado";
        $params[':estado'] = $filtro_estado;
    }

    if ($filtro_tipo) {
        $sql .= " AND tipo = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }

    if ($filtro_urgencia) {
        $sql .= " AND urgencia = :urgencia";
        $params[':urgencia'] = $filtro_urgencia;
    }

    if ($busqueda) {
        if (is_numeric($busqueda)) {
            $sql .= " AND id = :id";
            $params[':id'] = (int)$busqueda;
        } else {
            $sql .= " AND (titulo LIKE :busqueda OR resumen LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }
    }

    $sql .= " ORDER BY fecha_creacion DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $incidencias_por_estado = [];
    foreach ($estados as $estado) {
        $incidencias_por_estado[$estado] = [];
    }
    foreach ($incidencias as $incidencia) {
        $incidencias_por_estado[$incidencia['estado']][] = $incidencia;
    }

    $hay_resultados = false;
    foreach ($estados as $estado) {
        if ($filtro_estado && $filtro_estado !== $estado) {
            continue;
        }

        $estado_titulo = ucfirst(str_replace("_", " ", $estado));
        $estado_clase = str_replace("_", "-", $estado);
        echo "<h2>$estado_titulo</h2>";

        $incidencias_estado = $incidencias_por_estado[$estado];
        if (empty($incidencias_estado)) {
            echo "<p>No hay incidencias en estado $estado_titulo.</p>";
            continue;
        }

        $hay_resultados = true;
        echo "<div class='incidencia-grid'>";
        foreach ($incidencias_estado as $incidencia) {
            $fecha_creacion = new DateTime($incidencia['fecha_creacion']);
            $hoy = new DateTime();
            $dias_abierta = $fecha_creacion->diff($hoy)->days;
            $antiguedad_label = $dias_abierta > 30 ? '🔥 Antigua' : ($dias_abierta > 15 ? '🕒 Media' : '🆕 Reciente');

            $urgencia_clase = $incidencia['urgencia'] ?? 'leve';

            $tags = [];
            if ($dias_abierta > 30) $tags[] = '🔥 Antigua';
            elseif ($dias_abierta > 15) $tags[] = '🕒 Media';
            else $tags[] = '🆕 Reciente';

            if ($incidencia['urgencia'] === 'critico') {
                $tags[] = '🚨 Crítico';
            } elseif ($incidencia['urgencia'] === 'urgente') {
                $tags[] = '⚠️ Urgente';
            } elseif ($incidencia['urgencia'] === 'leve') {
                $tags[] = '✅ Leve';
            }

            if (!empty($incidencia['tipo'])) {
                $icono = $tipo_iconos[$incidencia['tipo']] ?? '🖥️';
                $tags[] = "$icono " . htmlspecialchars($incidencia['tipo']);
            }

            echo "<div class='incidencia-card $estado_clase $urgencia_clase' title='Creada: {$incidencia['fecha_creacion']}'>";
            echo "<div class='incidencia-header'>";
            echo "<span class='incidencia-id'>#{$incidencia['id']}</span>";
            echo "<span class='incidencia-status'>$estado_titulo</span>";
            echo "</div>";
            echo "<h4>" . htmlspecialchars($incidencia['titulo']) . "</h4>";
            echo "<p>" . htmlspecialchars($incidencia['resumen'] ?? 'Sin resumen disponible.') . "</p>";
            echo "<p class='incidencia-meta'>";
            echo "<span>Fecha: {$incidencia['fecha_creacion']}</span>";
            echo "<span>Antigüedad: $dias_abierta días ($antiguedad_label)</span>";
            echo "</p>";
            echo "<p class='incidencia-tags'>" . implode(', ', $tags) . "</p>";
            echo "<a href='ver_incidencia.php?id={$incidencia['id']}' class='card-button'>Ver detalle</a>";
            echo "</div>";
        }
        echo "</div>";
    }

    if (!$hay_resultados && ($busqueda || $filtro_tipo || $filtro_urgencia || $filtro_estado)) {
        echo "<p>No se encontraron incidencias que coincidan con los filtros seleccionados.</p>";
    }
}

// Procesar incidencias para el análisis de LLM.
// Tres consultas separadas en lugar del doble LEFT JOIN original, que generaba
// un producto cartesiano (mensajes x reaperturas por incidencia).
try {
    $sql = "SELECT * FROM incidencias WHERE 1=1";
    $params = [];

    if ($filtro_estado) {
        $sql .= " AND estado = :estado";
        $params[':estado'] = $filtro_estado;
    }
    if ($filtro_tipo) {
        $sql .= " AND tipo = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }
    if ($filtro_urgencia) {
        $sql .= " AND urgencia = :urgencia";
        $params[':urgencia'] = $filtro_urgencia;
    }
    if ($busqueda) {
        if (is_numeric($busqueda)) {
            $sql .= " AND id = :id";
            $params[':id'] = (int)$busqueda;
        } else {
            $sql .= " AND (titulo LIKE :busqueda OR resumen LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }
    }

    $sql .= " ORDER BY FIELD(estado, 'abierta', 'en_curso', 'cerrada'), fecha_creacion ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        error_log('Advertencia: No se encontraron incidencias en la base de datos');
    }

    $incidencias = [];
    foreach ($rows as $row) {
        $incidencias[$row['id']] = [
            'id' => $row['id'],
            'titulo' => $row['titulo'],
            'descripcion' => $row['descripcion'],
            'estado' => $row['estado'],
            'fecha_creacion' => $row['fecha_creacion'],
            'fecha_cierre' => $row['fecha_cierre'],
            'urgencia' => $row['urgencia'] ?? 'leve',
            'tipo' => $row['tipo'],
            'mensajes' => [],
            'reaperturas' => []
        ];
    }

    if (!empty($incidencias)) {
        $ids = array_keys($incidencias);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt_m = $pdo->prepare("SELECT id_incidencia, autor, mensaje, fecha FROM mensajes WHERE id_incidencia IN ($placeholders) ORDER BY fecha ASC");
        $stmt_m->execute($ids);
        foreach ($stmt_m->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $incidencias[$m['id_incidencia']]['mensajes'][] = [
                'autor' => $m['autor'],
                'mensaje' => $m['mensaje'],
                'fecha' => $m['fecha']
            ];
        }

        $stmt_r = $pdo->prepare("SELECT id_incidencia, motivo, fecha FROM reaperturas WHERE id_incidencia IN ($placeholders) ORDER BY fecha ASC");
        $stmt_r->execute($ids);
        foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $incidencias[$r['id_incidencia']]['reaperturas'][] = [
                'motivo' => $r['motivo'],
                'fecha' => $r['fecha']
            ];
        }
    }

    $lista_para_tabla = [];
    $resultado = [];
    $resumen = [
        'total' => count($incidencias),
        'abierta' => 0,
        'en_curso' => 0,
        'cerrada' => 0,
        'criticas' => 0,
        'sin_respuesta' => 0,
        'reabiertas' => 0
    ];

    foreach ($incidencias as $incidencia) {
        $id = $incidencia['id'];
        $dias = (new DateTime($incidencia['fecha_creacion']))->diff(new DateTime())->days;

        $dias_sin_respuesta = null;
        if ($incidencia['mensajes']) {
            $fecha_ultimo = end($incidencia['mensajes'])['fecha'];
            $dias_sin_respuesta = (new DateTime($fecha_ultimo))->diff(new DateTime())->days;
            if ($dias_sin_respuesta > 5) {
                $resumen['sin_respuesta']++;
            }
        }

        $tags = [];
        if ($dias > 10) $tags[] = '⏳ antigua';
        if (count($incidencia['reaperturas']) > 0) {
            $tags[] = '🔁 reabierta';
            $resumen['reabiertas']++;
        }
        if ($dias_sin_respuesta !== null && $dias_sin_respuesta > 5) $tags[] = '📬 sin respuesta';

        if ($incidencia['urgencia'] === 'critico') {
            $tags[] = '🚨 crítico';
            $resumen['criticas']++;
        } elseif ($incidencia['urgencia'] === 'urgente') {
            $tags[] = '⚠️ urgente';
        } elseif ($incidencia['urgencia'] === 'leve') {
            $tags[] = '✅ leve';
        }

        if (!empty($incidencia['tipo'])) {
            $icono = $tipo_iconos[$incidencia['tipo']] ?? '🖥️';
            $tags[] = "$icono " . htmlspecialchars($incidencia['tipo']);
        }

        $resumen[$incidencia['estado']]++;

        $lista_para_tabla[] = [
            'id' => $id,
            'titulo' => $incidencia['titulo'],
            'estado' => $incidencia['estado'],
            'dias' => $dias,
            'dias_sin_respuesta' => $dias_sin_respuesta,
            'urgencia' => $incidencia['urgencia'],
            'tipo' => $incidencia['tipo'],
            'tags' => $tags
        ];

        $resultado[] = [
            'id' => $id,
            'titulo' => $incidencia['titulo'],
            'descripcion' => $incidencia['descripcion'],
            'estado' => $incidencia['estado'],
            'fecha_creacion' => $incidencia['fecha_creacion'],
            'fecha_cierre' => $incidencia['fecha_cierre'],
            'dias_desde_creacion' => $dias,
            'dias_sin_respuesta' => $dias_sin_respuesta,
            'urgencia' => $incidencia['urgencia'],
            'tipo' => $incidencia['tipo'],
            'tags' => $tags,
            'mensajes' => $incidencia['mensajes'],
            'reaperturas' => $incidencia['reaperturas']
        ];
    }

    // Optimización del JSON
    // Tablas de código ultracortas
    $urgMap = ['critico' => 'c', 'urgente' => 'u', 'leve' => 'l'];
    $estMap = ['abierta' => 'a', 'en_curso' => 'e', 'cerrada' => 'z'];
    $tipoMap = [
        'Servidores' => 1, 'Red y acceso' => 2, 'Seguridad' => 3, 'Software y apps' => 4,
        'Microsoft 365' => 5, 'APIs y scripts' => 6, 'Correo' => 7, 'Bases de datos' => 8,
        'ERP / CRM' => 9, 'Backups' => 10, 'Web y dominios' => 11, 'Cloud' => 12, 'Usuarios y permisos' => 13
    ];
    $autorMap = ['c' => 'cliente', 't' => 'técnico'];
    $keyMap = [
        'id' => 'identificador',
        'u' => 'urgencia',
        's' => 'estado',
        'tp' => 'tipo',
        'fc' => 'fecha_creacion',
        't' => 'titulo',
        'd' => 'descripcion',
        'm' => 'mensajes',
        'rp' => 'reaperturas',
        'age' => 'dias_abierta',
        'sr' => 'dias_sin_respuesta',
        'tot' => 'total_incidencias',
        'crit' => 'incidencias_criticas',
        'urg' => 'incidencias_urgentes',
        'pctCrit' => 'porcentaje_criticas',
        'pctUrg' => 'porcentaje_urgentes'
    ];

    // Función para generar incidencia "slim"
    $slim = function(array $i) use ($urgMap, $estMap, $tipoMap): array {
        $msg = [];
        if (!empty($i['mensajes'])) {
            $seen = [];
            foreach ($i['mensajes'] as $m) {
                $txt = $m['mensaje'];
                if (!isset($seen[$txt])) {
                    $seen[$txt] = true;
                    $msg[] = [strtolower(substr($m['autor'], 0, 1)), $txt]; // Usar inicial del autor ("c" o "t")
                }
            }
        }

        $reaperturas = !empty($i['reaperturas']) ? array_column($i['reaperturas'], 'motivo') : [];

        // Calcular campos derivados
        $age = (new DateTime())->diff(new DateTime($i['fecha_creacion']))->days;
        $lastMsg = !empty($i['mensajes']) ? end($i['mensajes'])['fecha'] : $i['fecha_creacion'];
        $sinResp = (new DateTime())->diff(new DateTime($lastMsg))->days;

        return [
            'id' => (int)$i['id'],
            'u' => $urgMap[$i['urgencia']] ?? 'l',
            's' => $estMap[$i['estado']] ?? 'a',
            'tp' => $tipoMap[$i['tipo']] ?? 0,
            'fc' => $i['fecha_creacion'],
            't' => $i['titulo'],
            'd' => $i['descripcion'],
            'm' => $msg,
            'rp' => $reaperturas,
            'age' => $age, // Días abierta
            'sr' => $sinResp // Días sin respuesta
        ];
    };

    // Convertir incidencias a formato slim
    $incSlim = array_map($slim, $resultado);

    // Calcular KPIs
    $tot = count($incSlim);
    $crit = 0;
    $urg = 0;
    foreach ($incSlim as $inc) {
        if ($inc['u'] === 'c') $crit++;
        if ($inc['u'] === 'u') $urg++;
    }
    $kpi = [
        'tot' => $tot,
        'crit' => $crit,
        'urg' => $urg,
        'pctCrit' => $tot > 0 ? round($crit / $tot * 100) : 0,
        'pctUrg' => $tot > 0 ? round($urg / $tot * 100) : 0
    ];

    // Prompt optimizado con las nuevas instrucciones
    $pregunta = <<<TXT
Eres un coordinador técnico senior. Analiza la lista de incidencias en "inc". Usa los mapas en "map" para interpretar las claves y sus valores. En "map"]["keys", encontrarás el significado de cada clave (por ejemplo, "u" es urgencia, "s" es estado). En "map"]["urg", "map"]["est", "map"]["tipo", y "map"]["aut", encontrarás los valores codificados (por ejemplo, u:"c" significa "crítico", s:"a" significa "abierta", aut:"c" significa "cliente"). En los mensajes ("m"), cada entrada es [autor, texto], donde "autor" is "c" (cliente) o "t" (técnico), según el mapa "aut".

Primero: crea la lista critIDs = [id for id in inc if id.u == 'c']. Al final del borrador, revisa que todos los IDs de critIDs estén presentes exactamente una vez en la sección “Incidencias críticas”. Si falta alguno, añádelo antes de enviar la respuesta.

Cuando cites una incidencia, usa siempre id, t y u exactamente como aparecen en el JSON.

Proporciona un resumen ejecutivo para priorizar tareas.

Incluye:

🔍 Análisis detallado:
1. Breve diagnóstico del estado general del sistema. En “meta.kpi” tienes los KPIs listos (tot, crit, urg, pctCrit, pctUrg). Úsalos tal cual para el diagnóstico.
2. Destaca incidencias críticas (por antigüedad [age > 10 días], contenido o falta de respuesta [sr > 5 días]). Debes mencionar **todas** las incidencias cuyo u:"c".
3. Señales de alarma o posibles escalados (por ejemplo, incidencias sin respuesta [sr > 5 días], reaperturas [rp], o patrones de problemas).
4. Recomendaciones concretas para el equipo técnico o responsables.
5. Incidencias conflictivas por mala actitud o insultos de clientes.

### 🚫  FORMATO PROHIBIDO  🚫  
- **No escribas** nunca las claves abreviadas ni sus códigos (`"u=c"`, `"s=a"`, `"age=0"`, `"sr=5"`, etc.).  
- Expresa siempre la información con su significado: “urgencia crítica”, “estado abierta”, “antigüedad de 12 días”, “5 días sin respuesta”…  
- Si mencionas un KPI, hazlo en lenguaje natural: “30 % de incidencias críticas”, “10 urgentes”, etc.

Responde de forma clara, profesional y orientada a la acción. Usa negritas, emojis y cualquier recurso visual útil.
TXT;

    // Generar JSON optimizado
    $llm_input = [
        'meta' => [
            'tot' => $tot,
            'ver' => '1.2',
            'gen' => gmdate('c'),
            'kpi' => $kpi
        ],
        'map' => [
            'keys' => $keyMap,
            'urg' => array_flip($urgMap),
            'est' => array_flip($estMap),
            'tipo' => array_flip($tipoMap),
            'aut' => $autorMap
        ],
        'inc' => $incSlim,
        'ask' => $pregunta
    ];

    $llm_json = json_encode(
        $llm_input,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Error al generar JSON: ' . json_last_error_msg());
        die('Error al generar JSON: ' . json_last_error_msg());
    }
} catch (PDOException $e) {
    error_log('Error de base de datos: ' . $e->getMessage());
    $resumen = [];
    $lista_para_tabla = [];
    $llm_json = json_encode(['error' => 'Error al conectar con la base de datos: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

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
    <title>TicketIA — Analisis IA</title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div class="container">
    <div class="page-shell">
        <header class="page-header">
            <div>
                <h1>Analisis de incidencias</h1>
                <p class="subtitulo">Resumen ejecutivo y priorizacion generado por IA.</p>
            </div>
            <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
        </header>

        <div class="page-tools">
            <a href="index.php" class="card-button secondary-button" title="Volver al panel principal">‹ Volver</a>
            <button class="card-button reload-analisis" title="Recargar analisis">Recargar</button>
            <button class="card-button secondary-button copy-resumen" title="Copiar resumen">Copiar</button>
            <button class="card-button secondary-button auto-scroll" title="Activar/desactivar autoscroll">Autoscroll: ON</button>
            <button class="card-button secondary-button download-resumen" title="Descargar resumen">Descargar .txt</button>
            <button class="card-button secondary-button clear-resumen" title="Limpiar contenido">Limpiar</button>
            <button class="card-button secondary-button stop-stream" title="Detener generacion">Detener</button>
            <span class="status-pill" id="streamStatus">Generando analisis...</span>
            <span class="status-pill" id="streamMetrics">0 caracteres</span>
        </div>

        <div class="incidencia-box compact-box">
            <h2>Resumen generado por IA</h2>
            <div class="analysis-stream pretty" id="resumen-content">
                <div class="loading" id="loading">Generando analisis...</div>
                <div class="analysis-content markdown-compact" id="resumen-render"></div>
            </div>
        </div>

        <div class="incidencia-box compact-box">
            <h2>Datos enviados al modelo</h2>
            <div class="json-card">
                <button class="toggle-json card-button" title="Mostrar/Ocultar JSON">Mostrar JSON</button>
                <pre class="json-content" style="display: none;"><?php echo htmlspecialchars(json_encode($llm_input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            </div>
        </div>
    </div>
    <button class="scroll-top card-button" title="Volver arriba">Arriba</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const loading = document.getElementById('loading');
    const rendered = document.getElementById('resumen-render');
    const copyButton = document.querySelector('.copy-resumen');
    const reloadButton = document.querySelector('.reload-analisis');
    const jsonToggle = document.querySelector('.toggle-json');
    const jsonContent = document.querySelector('.json-content');
    const stopButton = document.querySelector('.stop-stream');
    const autoScrollButton = document.querySelector('.auto-scroll');
    const downloadButton = document.querySelector('.download-resumen');
    const clearButton = document.querySelector('.clear-resumen');
    const streamStatus = document.getElementById('streamStatus');
    const streamMetrics = document.getElementById('streamMetrics');
    const streamPanel = document.getElementById('resumen-content');

    const themeToggle = document.getElementById('themeToggle');
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

    let rawText = '';
    let autoScroll = true;
    let streamStoppedByUser = false;

    const updateMetrics = () => {
        const chars = rawText.length;
        const words = rawText.trim() ? rawText.trim().split(/\s+/).length : 0;
        streamMetrics.textContent = `${chars} caracteres - ${words} palabras`;
    };

    const renderPretty = () => {
        if (window.marked && window.DOMPurify) {
            marked.setOptions({ gfm: true, breaks: true });
            rendered.innerHTML = DOMPurify.sanitize(marked.parse(rawText || ''));
        } else {
            rendered.textContent = rawText;
            rendered.style.whiteSpace = 'pre-wrap';
        }

        if (autoScroll) {
            streamPanel.scrollTop = streamPanel.scrollHeight;
        }
    };

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(() => false);
        }
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        let success = false;
        try { success = document.execCommand('copy'); } catch (err) { success = false; }
        document.body.removeChild(textarea);
        return Promise.resolve(success);
    }

    copyButton.addEventListener('click', () => {
        if (!rawText.trim()) {
            alert('No hay texto para copiar. Espera a que el analisis este completo.');
            return;
        }
        copyToClipboard(rawText).then((success) => {
            if (success) {
                copyButton.textContent = 'Copiado';
                setTimeout(() => { copyButton.textContent = 'Copiar'; }, 1500);
            }
        });
    });

    reloadButton.addEventListener('click', () => window.location.reload());

    autoScrollButton.addEventListener('click', () => {
        autoScroll = !autoScroll;
        autoScrollButton.textContent = `Autoscroll: ${autoScroll ? 'ON' : 'OFF'}`;
    });

    downloadButton.addEventListener('click', () => {
        const blob = new Blob([rawText || ''], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `analisis-incidencias-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.txt`;
        a.click();
        URL.revokeObjectURL(url);
    });

    clearButton.addEventListener('click', () => {
        rawText = '';
        renderPretty();
        updateMetrics();
    });

    jsonToggle.addEventListener('click', () => {
        const isVisible = jsonContent.style.display === 'block';
        jsonContent.style.display = isVisible ? 'none' : 'block';
        jsonToggle.textContent = isVisible ? 'Mostrar JSON' : 'Ocultar JSON';
    });

    const scrollTopBtn = document.querySelector('.scroll-top');
    window.addEventListener('scroll', () => {
        scrollTopBtn.style.display = window.scrollY > 300 ? 'block' : 'none';
    });
    scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    const source = new EventSource('<?php echo $_SERVER['PHP_SELF']; ?>?stream=1');

    if (stopButton) {
        stopButton.addEventListener('click', () => {
            streamStoppedByUser = true;
            source.close();
            loading.style.display = 'none';
            streamStatus.textContent = 'Generacion detenida';
        });
    }

    source.onmessage = (event) => {
        if (event.data === '[?? Finalizado correctamente]') {
            source.close();
            loading.style.display = 'none';
            streamStatus.textContent = 'Analisis completado';
            return;
        }
        try {
            const data = JSON.parse(event.data);
            rawText += data;
            renderPretty();
            updateMetrics();
            if (loading.style.display !== 'none') {
                loading.style.display = 'none';
            }
        } catch (e) {
            rawText += `\n[Error al parsear datos: ${e.message}]`;
            renderPretty();
            updateMetrics();
        }
    };

    source.onerror = () => {
        source.close();
        loading.style.display = 'none';
        if (!streamStoppedByUser) {
            streamStatus.textContent = 'Conexion cerrada';
        }
    };

    updateMetrics();
});
</script>
</body>
</html>

