<?php
require_once __DIR__ . '/../src/arranque.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null) {
    header('Location: index.php');
    exit;
}

$sql_incidencia = "SELECT incidencias.*,
    (SELECT nombre FROM usuarios u WHERE u.id = incidencias.asignado_id) AS asignado_nombre,
    (SELECT nombre FROM usuarios u2 WHERE u2.id = incidencias.creado_por) AS creador_nombre,
    (SELECT nombre FROM clientes c WHERE c.id = incidencias.cliente_id) AS cliente_nombre
    FROM incidencias WHERE id = :id";
$stmt_incidencia = $pdo->prepare($sql_incidencia);
$stmt_incidencia->execute([':id' => $id]);
$incidencia = $stmt_incidencia->fetch(PDO::FETCH_ASSOC);

if (!$incidencia) {
    header('Location: index.php');
    exit;
}

$asignables = usuarios_asignables($pdo);

$sql_mensajes = "SELECT m.autor, m.mensaje, m.fecha, m.interno, u.nombre AS usuario_nombre
                  FROM mensajes m
                  LEFT JOIN usuarios u ON u.id = m.usuario_id
                  WHERE m.id_incidencia = :id ORDER BY m.fecha ASC";
$stmt_mensajes = $pdo->prepare($sql_mensajes);
$stmt_mensajes->execute([':id' => $id]);
$mensajes = $stmt_mensajes->fetchAll(PDO::FETCH_ASSOC);

$adjuntos = adjuntos_de($pdo, (int)$id);

$sql_cambios = "SELECT c.estado_anterior, c.estado_nuevo, c.fecha, u.nombre AS usuario_nombre
                FROM cambios_estado c
                LEFT JOIN usuarios u ON u.id = c.usuario_id
                WHERE c.id_incidencia = :id ORDER BY c.fecha ASC";
$stmt_cambios = $pdo->prepare($sql_cambios);
$stmt_cambios->execute([':id' => $id]);
$cambios_estado = $stmt_cambios->fetchAll(PDO::FETCH_ASSOC);

$sql_reaperturas = "SELECT motivo, fecha FROM reaperturas WHERE id_incidencia = :id ORDER BY fecha ASC";
$stmt_reaperturas = $pdo->prepare($sql_reaperturas);
$stmt_reaperturas->execute([':id' => $id]);
$reaperturas = $stmt_reaperturas->fetchAll(PDO::FETCH_ASSOC);

$fecha_creacion = new DateTime($incidencia['fecha_creacion']);
$hoy = new DateTime();
$dias_abierta = $fecha_creacion->diff($hoy)->days;
$antiguedad_label = $dias_abierta > 30 ? 'Antigua' : ($dias_abierta > 15 ? 'Media' : 'Reciente');

$mostrar_traduccion = isset($_GET['traducir']) && $_GET['traducir'] === 'es';
$traduccion = [
    'titulo' => $incidencia['titulo'],
    'descripcion' => $incidencia['descripcion'],
    'mensajes' => $mensajes
];

if ($mostrar_traduccion && ($incidencia['idioma'] ?? 'es') !== 'es') {
    // Cache de traducciones: una sola llamada al LLM por contenido. El hash
    // invalida la cache cuando cambian titulo, descripcion o mensajes.
    $contenido_hash = sha1(json_encode([$incidencia['titulo'], $incidencia['descripcion'], $mensajes]));

    $stmt_cache = $pdo->prepare("SELECT payload FROM traducciones WHERE id_incidencia = :id AND idioma_destino = 'es' AND contenido_hash = :hash");
    $stmt_cache->execute([':id' => $id, ':hash' => $contenido_hash]);
    $payload_cache = $stmt_cache->fetchColumn();

    $decoded = $payload_cache !== false ? json_decode((string)$payload_cache, true) : null;

    if (!is_array($decoded) || !isset($decoded['titulo'], $decoded['descripcion'], $decoded['mensajes']) || !is_array($decoded['mensajes'])) {
        $contexto_traduccion = "Titulo: \"{$incidencia['titulo']}\"\nDescripcion: \"{$incidencia['descripcion']}\"\nMensajes:\n";
        foreach ($mensajes as $m) {
            $contexto_traduccion .= "[{$m['fecha']}] {$m['autor']}: {$m['mensaje']}\n";
        }

        $pregunta_traduccion = "Traduce el titulo, la descripcion y los mensajes al espanol. Devuelve exclusivamente un JSON con las claves 'titulo' (string), 'descripcion' (string) y 'mensajes' (array de objetos con 'autor', 'mensaje' y 'fecha'). No incluyas markdown ni texto adicional.";
        $traduccion_response = LLMClientTraductor::getResponse($contexto_traduccion, $pregunta_traduccion);
        $decoded = json_decode((string)$traduccion_response, true);

        if (is_array($decoded) && isset($decoded['titulo'], $decoded['descripcion'], $decoded['mensajes']) && is_array($decoded['mensajes'])) {
            $stmt_save = $pdo->prepare(
                "INSERT INTO traducciones (id_incidencia, idioma_destino, contenido_hash, payload)
                 VALUES (:id, 'es', :hash, :payload)
                 ON DUPLICATE KEY UPDATE contenido_hash = VALUES(contenido_hash), payload = VALUES(payload), fecha = NOW()"
            );
            $stmt_save->execute([
                ':id' => $id,
                ':hash' => $contenido_hash,
                ':payload' => json_encode($decoded, JSON_UNESCAPED_UNICODE)
            ]);
        } else {
            $decoded = null;
        }
    }

    if (is_array($decoded)) {
        // La traduccion cacheada solo lleva autor/mensaje/fecha: recuperar
        // interno y nombre de usuario del original por posicion.
        foreach ($decoded['mensajes'] as $i => $m) {
            $decoded['mensajes'][$i]['interno'] = $mensajes[$i]['interno'] ?? 0;
            $decoded['mensajes'][$i]['usuario_nombre'] = $mensajes[$i]['usuario_nombre'] ?? null;
        }
        $traduccion = $decoded;
    }
}

$timeline = [];
$timeline[] = [
    'tipo' => 'creacion',
    'titulo' => 'Incidencia creada',
    'descripcion' => $incidencia['titulo'],
    'fecha' => $incidencia['fecha_creacion']
];

foreach ($traduccion['mensajes'] as $mensaje) {
    $autor = strtolower((string)($mensaje['autor'] ?? 'tecnico'));
    $es_interno_msg = !empty($mensaje['interno']);
    $quien = (string)($mensaje['usuario_nombre'] ?? ($autor === 'cliente' ? 'cliente' : 'tecnico'));
    $timeline[] = [
        'tipo' => $es_interno_msg ? 'nota-interna' : ($autor === 'cliente' ? 'mensaje-cliente' : 'mensaje-tecnico'),
        'titulo' => ($es_interno_msg ? 'Nota interna de ' : 'Mensaje de ') . $quien,
        'descripcion' => (string)($mensaje['mensaje'] ?? ''),
        'fecha' => (string)($mensaje['fecha'] ?? '')
    ];
}

foreach ($cambios_estado as $cambio) {
    $timeline[] = [
        'tipo' => 'estado',
        'titulo' => 'Estado: ' . ui_estado_label((string)($cambio['estado_anterior'] ?? '')) . ' -> ' . ui_estado_label((string)$cambio['estado_nuevo']),
        'descripcion' => $cambio['usuario_nombre'] !== null ? 'por ' . $cambio['usuario_nombre'] : '',
        'fecha' => (string)$cambio['fecha']
    ];
}

foreach ($reaperturas as $reapertura) {
    $timeline[] = [
        'tipo' => 'reapertura',
        'titulo' => 'Incidencia reabierta',
        'descripcion' => (string)($reapertura['motivo'] ?? ''),
        'fecha' => (string)($reapertura['fecha'] ?? '')
    ];
}

if (!empty($incidencia['fecha_cierre']) && empty($cambios_estado)) {
    $timeline[] = [
        'tipo' => 'cierre',
        'titulo' => 'Incidencia cerrada',
        'descripcion' => 'Cierre registrado en el sistema.',
        'fecha' => $incidencia['fecha_cierre']
    ];
}

usort($timeline, function ($a, $b) {
    return strcmp((string)($a['fecha'] ?? ''), (string)($b['fecha'] ?? ''));
});

$id_incidencia = (int)$id;
$estado_label = ui_estado_label((string)$incidencia['estado']);
$recomendacion_inicial = trim((string)($incidencia['recomendacion'] ?? ''));
$productos_recomendados = [];
$guion_venta = '';
$recomendacion_fallida = isset($_GET['recomendacion']) && $_GET['recomendacion'] === 'error';
if (isset($_GET['recomendacion']) && !$recomendacion_fallida) {
    // Cargar la ultima recomendacion emitida desde el historial. Las filas de
    // una misma tanda comparten guion y fecha; producto vacio = solo guion de escalado.
    $stmt_reco = $pdo->prepare("SELECT producto, guion, fecha FROM recomendaciones_venta WHERE id_incidencia = :id ORDER BY fecha DESC, id DESC");
    $stmt_reco->execute([':id' => $id]);
    $filas_reco = $stmt_reco->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($filas_reco)) {
        $fecha_ultima = $filas_reco[0]['fecha'];
        $guion_venta = trim((string)$filas_reco[0]['guion']);
        foreach ($filas_reco as $fila) {
            if ($fila['fecha'] !== $fecha_ultima) {
                break;
            }
            $producto = trim((string)$fila['producto']);
            if ($producto !== '') {
                $productos_recomendados[] = $producto;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketIA — Incidencia #<?= $id_incidencia ?></title>
    <script>document.documentElement.setAttribute("data-theme", localStorage.getItem("incidencias_theme") || "light");</script>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div class="container">
    <div class="page-shell">
        <header class="page-header">
            <div class="detail-heading">
                <h1 class="detail-title"><?= ui_e($traduccion['titulo']) ?></h1>
                <p class="subtitulo detail-subline">
                    <span class="status-pill pill-estado pill-<?= ui_e(ui_estado_class((string)$incidencia['estado'])) ?>"><?= ui_e($estado_label) ?></span>
                    <span class="urg-<?= ui_e((string)($incidencia['urgencia'] ?? 'leve')) ?>"><?= ui_e(ui_urgencia_label((string)($incidencia['urgencia'] ?? 'leve'))) ?></span>
                    <span>#<?= $id_incidencia ?></span>
                    <?php if (!empty($incidencia['tipo'])): ?><span>· <?= ui_e($incidencia['tipo']) ?></span><?php endif; ?>
                    <span>· <?= (int)$dias_abierta ?> dias</span>
                </p>
            </div>
            <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="filter-button secondary" type="button">Cambiar tema</button></div>
        </header>

        <?php if (isset($_GET['reclasificada'])): ?>
            <?php if ($_GET['reclasificada'] === '1'): ?>
                <div class="success-message">Incidencia re-clasificada con IA correctamente.</div>
            <?php else: ?>
                <div class="success-message" style="border-left-color:#e03131;">No se pudo re-clasificar. Comprueba el proveedor IA e intentalo de nuevo.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="page-tools">
            <a href="index.php" class="card-button secondary-button" title="Volver al panel principal">‹ Volver</a>
            <a href="consultar_llm.php?id=<?= $id_incidencia ?>" class="card-button" title="Resumir incidencia con IA">Resumir con IA</a>
            <form action="reclasificar_incidencia.php" method="POST" style="display:inline;" onsubmit="return confirm('La IA recalculara urgencia, tipo, idioma, resumen y recomendacion. Continuar?');">
                <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                <button type="submit" class="card-button" title="Recalcular clasificacion IA">Re-clasificar con IA</button>
            </form>
            <button type="button" class="card-button secondary-button" id="copyTicketId">Copiar ID</button>
            <?php if (($incidencia['idioma'] ?? 'es') !== 'es'): ?>
                <a href="ver_incidencia.php?id=<?= $id_incidencia ?>&traducir=es" class="card-button" title="Traducir al espanol">Traducir al espanol</a>
            <?php endif; ?>
        </div>

        <div class="detail-layout">
            <div class="detail-main">
                <div class="incidencia-box compact-box">
                    <h2>Descripcion</h2>
                    <?= ui_render_markdown_block((string)$traduccion['descripcion'], 'recomendacion-text') ?>
                </div>

                <div class="incidencia-box compact-box">
                    <h2>Recomendacion inicial</h2>
                    <p class="help-line" style="margin-top:-8px; margin-bottom:10px;">Generada por IA al crear la incidencia.</p>
                    <div class="recomendacion-text markdown-compact detail-reco" id="recomendacion-inicial"></div>
                    <noscript>
                        <p class="recomendacion-text">
                            <?= !empty($recomendacion_inicial) ? nl2br(ui_e($recomendacion_inicial)) : 'No hay recomendacion inicial disponible.' ?>
                        </p>
                    </noscript>
                </div>

            <?php if (($incidencia['tipo'] ?? '') === 'Comercial'): ?>
                <div class="incidencia-box compact-box">
                    <div class="section-head">
                        <h2>Recomendacion de venta</h2>
                        <div class="page-tools">
                            <form action="recomendar_venta.php" method="POST">
                                <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                                <input type="submit" value="Obtener recomendacion">
                            </form>
                            <a href="ver_catalogo.php" target="_blank" class="card-button secondary-button">Ver catalogo</a>
                        </div>
                    </div>
                    <?php if ($recomendacion_fallida): ?>
                        <p class="help-line">No se pudo generar la recomendacion. Intentalo de nuevo en unos minutos.</p>
                    <?php elseif (isset($_GET['recomendacion'])): ?>
                        <p><strong>Productos recomendados:</strong></p>
                        <div class="recomendacion-text markdown-compact" id="productos-recomendados"></div>
                        <p style="margin-top:10px;"><strong>Guion de venta:</strong></p>
                        <div class="recomendacion-text markdown-compact" id="guion-venta"></div>
                        <noscript>
                            <p class="recomendacion-text">
                                <strong>Productos recomendados:</strong>
                                <?= !empty($productos_recomendados) ? ui_e(implode(', ', $productos_recomendados)) : 'Sin productos' ?><br>
                                <strong>Guion de venta:</strong> <?= !empty($guion_venta) ? nl2br(ui_e($guion_venta)) : 'Sin guion disponible.' ?>
                            </p>
                        </noscript>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

                <div class="incidencia-box compact-box">
                    <h2>Conversacion</h2>
                <?php if (!empty($traduccion['mensajes'])): ?>
                    <div class="mensajes-grid">
                        <?php foreach ($traduccion['mensajes'] as $mensaje): ?>
                            <?php
                            $is_cliente = strtolower((string)($mensaje['autor'] ?? '')) === 'cliente';
                            $es_interno_msg = !empty($mensaje['interno']);
                            $quien_msg = (string)($mensaje['usuario_nombre'] ?? ($is_cliente ? 'Cliente' : 'Tecnico'));
                            ?>
                            <div class="mensaje-card <?= $es_interno_msg ? 'interna' : ($is_cliente ? 'cliente' : 'tecnico') ?>">
                                <div class="mensaje-header">
                                    <span class="mensaje-autor"><?= ui_e($quien_msg) ?><?= $es_interno_msg ? ' <span class="badge-interna">Nota interna</span>' : '' ?></span>
                                    <span class="mensaje-fecha"><?= ui_e((string)($mensaje['fecha'] ?? '')) ?></span>
                                </div>
                                <?= ui_render_markdown_block((string)($mensaje['mensaje'] ?? ''), 'recomendacion-text') ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="help-line">Todavia no hay mensajes en esta incidencia.</p>
                <?php endif; ?>

                <?php if (($incidencia['estado'] ?? '') !== 'cerrada'): ?>
                    <form action="guardar_mensaje.php" method="POST" class="composer">
                        <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                        <textarea name="mensaje" id="mensaje" rows="4" required placeholder="Escribe la respuesta en espanol...<?= ($incidencia['idioma'] ?? 'es') !== 'es' ? ' Se traducira al idioma original al enviarla.' : '' ?>"></textarea>
                        <div class="composer-row">
                            <label class="composer-check" title="Solo visible para el equipo, nunca para el cliente. No se traduce.">
                                <input type="checkbox" name="interno" value="1"> Nota interna
                            </label>
                            <span class="help-line" id="sugerirEstado" hidden>Generando borrador con IA...</span>
                            <span class="composer-spacer"></span>
                            <button type="button" class="card-button secondary-button" id="sugerirRespuesta" data-id="<?= $id_incidencia ?>">Sugerir con IA</button>
                            <input type="submit" value="Enviar">
                        </div>
                    </form>
                <?php else: ?>
                    <p class="help-line">La incidencia esta cerrada y no se pueden anadir mas mensajes.</p>
                <?php endif; ?>
                </div>
                <div class="incidencia-box compact-box" id="adjuntos">
                    <h2>Adjuntos</h2>
                    <?php if (isset($_GET['adjunto'])): ?>
                        <div class="success-message">Adjunto subido correctamente.</div>
                    <?php elseif (isset($_GET['adjunto_error'])): ?>
                        <div class="login-error"><?= ui_e((string)$_GET['adjunto_error']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($adjuntos)): ?>
                        <ul class="adjuntos-lista">
                            <?php foreach ($adjuntos as $adj): ?>
                                <li>
                                    <a href="descargar_adjunto.php?id=<?= (int)$adj['id'] ?>"><?= ui_e($adj['nombre_original']) ?></a>
                                    <span class="adjunto-meta"><?= ui_e(adjuntos_formato_tamano((int)$adj['tamano'])) ?><?= $adj['usuario_nombre'] !== null ? ' - ' . ui_e($adj['usuario_nombre']) : '' ?> - <?= ui_e($adj['fecha']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="help-line">No hay adjuntos en esta incidencia.</p>
                    <?php endif; ?>
                    <form action="subir_adjunto.php" method="POST" enctype="multipart/form-data" class="composer-row adjuntos-form">
                        <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                        <input type="file" name="adjunto" required>
                        <span class="composer-spacer"></span>
                        <input type="submit" value="Subir adjunto">
                    </form>
                    <p class="help-line">Maximo <?= ui_e(adjuntos_formato_tamano(adjuntos_max_bytes())) ?> por fichero.</p>
                </div>
            </div>

            <aside class="detail-side">
                <div class="incidencia-box compact-box">
                    <h2>Detalles</h2>
                    <div class="detail-list">
                        <div class="detail-row">
                            <span>Estado</span>
                            <strong><span class="status-pill pill-estado pill-<?= ui_e(ui_estado_class((string)$incidencia['estado'])) ?>"><?= ui_e($estado_label) ?></span></strong>
                        </div>
                        <div class="detail-row">
                            <span>Urgencia</span>
                            <strong class="urg-<?= ui_e((string)($incidencia['urgencia'] ?? 'leve')) ?>"><?= ui_e(ui_urgencia_label((string)($incidencia['urgencia'] ?? 'leve'))) ?></strong>
                        </div>
                        <div class="detail-row">
                            <span>Creada</span>
                            <strong><?= ui_e($incidencia['fecha_creacion']) ?></strong>
                        </div>
                        <?php if (!empty($incidencia['fecha_cierre'])): ?>
                            <div class="detail-row">
                                <span>Cerrada</span>
                                <strong><?= ui_e($incidencia['fecha_cierre']) ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span>Edad</span>
                            <strong><?= (int)$dias_abierta ?> dias · <?= ui_e($antiguedad_label) ?></strong>
                        </div>
                        <div class="detail-row">
                            <span>Idioma</span>
                            <strong><?= ui_e($incidencia['idioma'] ?? 'es') ?></strong>
                        </div>
                        <?php if (!empty($incidencia['cliente_nombre'])): ?>
                            <div class="detail-row">
                                <span>Empresa</span>
                                <strong><?= ui_e($incidencia['cliente_nombre']) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($incidencia['creador_nombre'])): ?>
                            <div class="detail-row">
                                <span>Creada por</span>
                                <strong><?= ui_e($incidencia['creador_nombre']) ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span>Departamento</span>
                            <form action="actualizar_tipo_incidencia.php" method="POST" class="detail-row-form">
                                <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                                <select name="tipo" onchange="this.form.submit()" title="Se guarda automaticamente">
                                    <option value="" disabled <?= empty($incidencia['tipo']) ? 'selected' : '' ?>>Sin clasificar</option>
                                    <?php foreach (dominio_tipos() as $tipo): ?>
                                        <option value="<?= ui_e($tipo) ?>" <?= ($incidencia['tipo'] ?? '') === $tipo ? 'selected' : '' ?>>
                                            <?= ui_e($tipo) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div class="detail-row">
                            <span>Asignado</span>
                            <form action="asignar_incidencia.php" method="POST" class="detail-row-form">
                                <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                                <select name="asignado" onchange="this.form.submit()" title="Se guarda automaticamente">
                                    <option value="" <?= empty($incidencia['asignado_id']) ? 'selected' : '' ?>>Sin asignar</option>
                                    <?php foreach ($asignables as $asignable): ?>
                                        <option value="<?= (int)$asignable['id'] ?>" <?= (int)($incidencia['asignado_id'] ?? 0) === (int)$asignable['id'] ? 'selected' : '' ?>>
                                            <?= ui_e($asignable['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>

                    <?php if (($incidencia['estado'] ?? '') !== 'cerrada'): ?>
                        <form action="cerrar_incidencia.php" method="POST" class="detail-action" onsubmit="return confirm('Se cerrara la incidencia. Continuar?');">
                            <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                            <button type="submit" class="card-button danger-button">Cerrar incidencia</button>
                        </form>
                    <?php else: ?>
                        <form action="reabrir_incidencia.php" method="POST" class="detail-action form-stack" onsubmit="return confirm('Se reabrira la incidencia. Continuar?');">
                            <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                            <label class="filter-label" for="motivo">Motivo de la reapertura</label>
                            <textarea name="motivo" id="motivo" rows="3" required></textarea>
                            <input type="submit" value="Reabrir incidencia">
                        </form>
                    <?php endif; ?>
                </div>

                <div class="incidencia-box compact-box">
                    <h2>Actividad</h2>
                    <div class="timeline">
                        <?php foreach ($timeline as $event): ?>
                            <?= ui_render_timeline_item($event) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const renderCompactMarkdown = (target, raw, emptyText, parseWithBreaks = false) => {
        if (!target) return;
        const text = (raw || '').trim();
        if (!text) {
            target.textContent = emptyText;
            return;
        }

        if (window.marked && window.DOMPurify) {
            marked.setOptions({ gfm: true, breaks: parseWithBreaks });
            target.innerHTML = DOMPurify.sanitize(marked.parse(text));
            return;
        }

        target.textContent = text;
        target.style.whiteSpace = 'pre-wrap';
    };

    const normalizeSalesScript = (raw) => {
        return (raw || '')
            .replace(/\s+([0-9]+)\)\s+/g, '\n$1. ')
            .replace(/\s+(Con este enfoque cubrimos:)/i, '\n\n$1')
            .replace(/\s+(Siguiente paso:)/i, '\n\n$1')
            .trim();
    };

    document.querySelectorAll('.js-markdown').forEach((el) => {
        const raw = el.dataset.md || '';
        const emptyText = el.dataset.mdEmpty || '';
        renderCompactMarkdown(el, raw, emptyText, true);
    });

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

    const copyButton = document.getElementById('copyTicketId');
    if (copyButton) {
        copyButton.addEventListener('click', async () => {
            const idText = '<?= $id_incidencia ?>';
            try {
                await navigator.clipboard.writeText(idText);
                copyButton.textContent = 'ID copiado';
                setTimeout(() => { copyButton.textContent = 'Copiar ID'; }, 1500);
            } catch (e) {
                alert('No se pudo copiar el ID');
            }
        });
    }

    const recomendacionTarget = document.getElementById('recomendacion-inicial');
    if (recomendacionTarget) {
        let raw = <?= json_encode($recomendacion_inicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        raw = (raw || '')
            .replace(/^\s*#{1,6}\s*recomendaci[oó]n inicial\s*\n*/i, '')
            .trim();
        renderCompactMarkdown(recomendacionTarget, raw, 'No hay recomendacion inicial disponible.');
    }

    const productosTarget = document.getElementById('productos-recomendados');
    if (productosTarget) {
        const productos = <?= json_encode($productos_recomendados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const markdownProductos = Array.isArray(productos) && productos.length
            ? productos.map((p) => `- ${p}`).join('\n')
            : '';
        renderCompactMarkdown(productosTarget, markdownProductos, 'Sin productos recomendados.');
    }

    const guionTarget = document.getElementById('guion-venta');
    if (guionTarget) {
        const guionRaw = <?= json_encode($guion_venta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const guionNormalizado = normalizeSalesScript(guionRaw);
        renderCompactMarkdown(guionTarget, guionNormalizado, 'Sin guion disponible.', true);
    }

    const sugerirButton = document.getElementById('sugerirRespuesta');
    const sugerirEstado = document.getElementById('sugerirEstado');
    const mensajeTextarea = document.getElementById('mensaje');
    if (sugerirButton && mensajeTextarea) {
        sugerirButton.addEventListener('click', async () => {
            if (mensajeTextarea.value.trim() !== '' && !confirm('El borrador IA sustituira el texto actual del mensaje. Continuar?')) {
                return;
            }
            sugerirButton.disabled = true;
            if (sugerirEstado) sugerirEstado.hidden = false;
            try {
                const response = await fetch('sugerir_respuesta.php?id=' + sugerirButton.dataset.id);
                const result = await response.json();
                if (!result.ok || !result.sugerencia) {
                    throw new Error(result.error || 'Sin sugerencia');
                }
                mensajeTextarea.value = result.sugerencia;
                mensajeTextarea.focus();
            } catch (error) {
                alert('No se pudo generar la sugerencia: ' + error.message);
            } finally {
                sugerirButton.disabled = false;
                if (sugerirEstado) sugerirEstado.hidden = true;
            }
        });
    }

});
</script>
</body>
</html>
