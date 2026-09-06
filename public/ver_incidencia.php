<?php
require_once __DIR__ . '/../src/arranque.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null) {
    header('Location: index.php');
    exit;
}
gobierno_ia_contexto_establecer((int)$id);

$sql_incidencia = "SELECT incidencias.*,
    (SELECT nombre FROM usuarios u WHERE u.id = incidencias.asignado_id) AS asignado_nombre,
    (SELECT nombre FROM usuarios u2 WHERE u2.id = incidencias.creado_por) AS creador_nombre,
    (SELECT nombre FROM clientes c WHERE c.id = incidencias.cliente_id) AS cliente_nombre,
    COALESCE((SELECT nivel_servicio FROM clientes c WHERE c.id = incidencias.cliente_id), 'estandar') AS nivel_servicio
    FROM incidencias WHERE id = :id";
$stmt_incidencia = $pdo->prepare($sql_incidencia);
$stmt_incidencia->execute([':id' => $id]);
$incidencia = $stmt_incidencia->fetch(PDO::FETCH_ASSOC);

if (!$incidencia) {
    header('Location: index.php');
    exit;
}

$asignables = usuarios_asignables($pdo);

$sql_mensajes = "SELECT m.id, m.autor, m.mensaje, m.fecha, m.interno, u.nombre AS usuario_nombre
                  FROM mensajes m
                  LEFT JOIN usuarios u ON u.id = m.usuario_id
                  WHERE m.id_incidencia = :id ORDER BY m.fecha ASC";
$stmt_mensajes = $pdo->prepare($sql_mensajes);
$stmt_mensajes->execute([':id' => $id]);
$mensajes = $stmt_mensajes->fetchAll(PDO::FETCH_ASSOC);

$ultimo_autor_publico = null;
$primera_respuesta = null;
foreach ($mensajes as $mensaje_operativo) {
    if (!empty($mensaje_operativo['interno'])) {
        continue;
    }
    $ultimo_autor_publico = (string)$mensaje_operativo['autor'];
    if ($primera_respuesta === null && $ultimo_autor_publico === 'tecnico') {
        $primera_respuesta = (string)$mensaje_operativo['fecha'];
    }
}
$sla = dominio_sla_calcular($incidencia + ['primera_respuesta' => $primera_respuesta]);
$siguiente_paso = dominio_siguiente_paso($ultimo_autor_publico, (string)$incidencia['estado']);
$es_activa = in_array((string)$incidencia['estado'], dominio_estados_activos(), true);
$prioridad_info = dominio_prioridad_operativa_desglose($incidencia + ['ultimo_autor' => $ultimo_autor_publico]);
$prioridad_operativa = $prioridad_info['total'];

$stmt_copiloto = $pdo->prepare('SELECT contenido_hash, resumen, riesgo, sentimiento, siguiente_accion, respuesta_sugerida, confianza, actualizado_en FROM copiloto_ia WHERE id_incidencia = :id');
$stmt_copiloto->execute([':id' => $id]);
$copiloto = $stmt_copiloto->fetch(PDO::FETCH_ASSOC) ?: null;
$feedback_copiloto = null;
if ($copiloto && gobierno_ia_esquema_disponible($pdo)) {
    $feedback_copiloto = gobierno_ia_feedback_usuario(
        $pdo,
        (int)(auth_usuario()['id'] ?? 0),
        (int)$id,
        (string)$copiloto['contenido_hash']
    );
}

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
$fin_antiguedad = !empty($incidencia['fecha_resolucion'])
    ? new DateTime((string)$incidencia['fecha_resolucion'])
    : new DateTime();
$dias_abierta = $fecha_creacion->diff($fin_antiguedad)->days;
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
            $decoded['mensajes'][$i]['id'] = $mensajes[$i]['id'] ?? 0;
        }
        $traduccion = $decoded;
    }
}

$timeline = [];
$timeline[] = [
    'tipo' => 'creacion',
    'titulo' => 'Incidencia creada',
    'descripcion' => '',
    'fecha' => $incidencia['fecha_creacion'],
    'desplegable' => (string)$traduccion['descripcion'],
    'desplegable_texto' => 'texto de la incidencia',
];

foreach ($traduccion['mensajes'] as $mensaje) {
    $autor = strtolower((string)($mensaje['autor'] ?? 'tecnico'));
    $es_interno_msg = !empty($mensaje['interno']);
    $quien = (string)($mensaje['usuario_nombre'] ?? ($autor === 'cliente' ? 'cliente' : 'tecnico'));
    $timeline[] = [
        'tipo' => $es_interno_msg ? 'nota-interna' : ($autor === 'cliente' ? 'mensaje-cliente' : 'mensaje-tecnico'),
        'titulo' => ($es_interno_msg ? 'Nota interna de ' : 'Mensaje de ') . $quien,
        'descripcion' => '',
        'fecha' => (string)($mensaje['fecha'] ?? ''),
        'desplegable' => (string)($mensaje['mensaje'] ?? ''),
        'desplegable_texto' => $es_interno_msg ? 'nota' : 'mensaje',
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
$productos_recomendados = [];
$guion_venta = '';
$recomendacion_fallida = isset($_GET['recomendacion']) && $_GET['recomendacion'] === 'error';
if (($incidencia['tipo'] ?? '') === 'Comercial' && !$recomendacion_fallida) {
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
    <link rel="stylesheet" href="estilos.css?v=<?= filemtime(__DIR__ . '/estilos.css') ?>">
</head>
<body class="support-body detail-workspace-body support-simple-detail">
<div class="support-shell">
    <?= ui_support_nav('tickets') ?>
    <main class="support-main">
    <header class="support-topbar detail-topbar">
        <button id="supportMenuToggle" class="support-menu-toggle" type="button" aria-label="Abrir menu" aria-expanded="false">Menu</button>
        <div><span class="support-eyebrow">Incidencia #<?= $id_incidencia ?></span><strong class="detail-topbar-title">Atender incidencia</strong></div>
        <div class="usuario-zona"><?= ui_menu_usuario() ?><button id="themeToggle" class="theme-button" type="button">Tema</button></div>
    </header>
<div class="container support-content">
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

        </header>

        <a href="index.php" class="support-back-link">&larr; Volver a la bandeja</a>
        <?php if (($_GET['comentario'] ?? '') === 'ok'): ?><div class="success-message" role="status">Comentario guardado en la conversacion.</div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="login-error" role="alert">No se pudo guardar. Escribe un comentario de hasta 30.000 caracteres.</div><?php endif; ?>

        <?php if (($_GET['resolucion'] ?? '') === 'ok'): ?>
            <div class="success-message">Solucion guardada en la conversacion y propuesta al cliente. Pendiente de confirmacion.</div>
        <?php elseif (($_GET['resolucion'] ?? '') === 'error'): ?>
            <div class="login-error">No se pudo proponer la solucion. Revisa el codigo, las notas y el estado actual.</div>
        <?php endif; ?>

        <div class="detail-layout">
            <div class="detail-main">
                <div class="incidencia-box compact-box issue-text-card" id="descripcion">
                    <h2>Texto de la incidencia</h2>
                    <?= ui_render_markdown_block((string)$traduccion['descripcion'], 'recomendacion-text') ?>
                </div>

                <?php if (in_array((string)$incidencia['estado'], ['resuelta', 'cerrada'], true)): ?>
                    <section class="resolution-summary">
                        <div><span class="support-eyebrow">Resultado</span><h2><?= ui_e(dominio_codigos_resolucion()[$incidencia['resolucion_codigo']] ?? 'Solucion registrada') ?></h2></div>
                        <p><?= nl2br(ui_e((string)($incidencia['resolucion_notas'] ?? 'Sin notas de resolucion.'))) ?></p>
                        <?php if (($incidencia['estado'] ?? '') === 'resuelta'): ?><small>Esperando la confirmacion del cliente.</small><?php endif; ?>
                    </section>
                <?php endif; ?>


                <section class="incidencia-box compact-box conversation-panel" id="conversacion">
                    <h2>Conversacion</h2>
                <?php if (!empty($traduccion['mensajes'])): ?>
                    <div class="mensajes-grid">
                        <?php foreach ($traduccion['mensajes'] as $indice_mensaje => $mensaje): ?>
                            <?php
                            $is_cliente = strtolower((string)($mensaje['autor'] ?? '')) === 'cliente';
                            $es_interno_msg = !empty($mensaje['interno']);
                            $quien_msg = (string)($mensaje['usuario_nombre'] ?? ($is_cliente ? 'Cliente' : 'Tecnico'));
                            ?>
                            <?php if ($indice_mensaje === array_key_last($traduccion['mensajes'])): ?><span id="ultimoMensaje"></span><?php endif; ?>
                            <div class="mensaje-card <?= $es_interno_msg ? 'interna' : ($is_cliente ? 'cliente' : 'tecnico') ?>" id="mensaje-<?= (int)($mensaje['id'] ?? 0) ?>">
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

                <?php if ($es_activa): ?>
                <details class="copilot-card assistant-disclosure" id="copilotCard" data-contenido-hash="<?= ui_e((string)($copiloto['contenido_hash'] ?? '')) ?>">
                    <summary>Propuesta de IA <small>Revisa el borrador antes de enviarlo</small></summary>
                    <div class="assistant-content">
                    <header>
                        <div><h3>Ayuda para responder</h3></div>
                        <div class="copilot-actions">

                            <span id="copilotEstado" class="copilot-status"><?= $copiloto ? 'Actualizado ' . ui_e(date('d/m H:i', strtotime((string)$copiloto['actualizado_en']))) : 'Sin generar' ?></span>
                        </div>
                    </header>
                    <div class="copilot-draft" id="copilotDraft" <?= empty($copiloto['respuesta_sugerida']) ? 'hidden' : '' ?>>
                        <span>Borrador sugerido</span><p id="copilotRespuesta"><?= ui_e($copiloto['respuesta_sugerida'] ?? '') ?></p>
                        <?php if ($es_activa): ?><button type="button" class="card-button secondary-button" id="usarBorrador">Usar en respuesta</button><?php endif; ?>
                    </div>
                    <details class="assistant-context"><summary>Contexto, confianza y valoracion</summary>
                    <div class="copilot-grid <?= $copiloto ? '' : 'is-empty' ?>" id="copilotGrid">
                        <div class="copilot-main"><span>Resumen ejecutivo</span><p id="copilotResumen"><?= $copiloto ? ui_e($copiloto['resumen']) : 'Genera el brief para convertir toda la conversacion en un contexto operativo compacto.' ?></p></div>
                        <div class="copilot-signal"><span>Riesgo</span><strong id="copilotRiesgo" class="risk-<?= ui_e($copiloto['riesgo'] ?? 'sin-datos') ?>"><?= ui_e(ucfirst($copiloto['riesgo'] ?? 'Sin datos')) ?></strong></div>
                        <div class="copilot-signal"><span>Sentimiento</span><strong id="copilotSentimiento"><?= ui_e(ucfirst($copiloto['sentimiento'] ?? 'Sin datos')) ?></strong></div>
                        <div class="copilot-next"><span>Siguiente mejor accion</span><p id="copilotAccion"><?= $copiloto ? ui_e($copiloto['siguiente_accion']) : 'Pendiente de analisis.' ?></p></div>
                        <div class="copilot-confidence"><span>Confianza</span><strong id="copilotConfianza"><?= (int)($copiloto['confianza'] ?? 0) ?>%</strong></div>
                    </div>
                    <div class="copilot-feedback" id="copilotFeedback" <?= $copiloto ? '' : 'hidden' ?>>
                        <span>Este brief te ha ayudado?</span>
                        <div>
                            <button type="button" class="copilot-feedback-button <?= (int)($feedback_copiloto['valoracion'] ?? 0) === 1 ? 'is-active' : '' ?>" data-valoracion="1">Si, es util</button>
                            <button type="button" class="copilot-feedback-button <?= (int)($feedback_copiloto['valoracion'] ?? 0) === -1 ? 'is-active' : '' ?>" data-valoracion="-1">No ayuda</button>
                        </div>
                        <small id="copilotFeedbackEstado" aria-live="polite"><?= $feedback_copiloto ? 'Feedback guardado' : 'Tu valoracion mejora el control de calidad.' ?></small>
                    </div>

                    </details>
                    <?php if (($incidencia['tipo'] ?? '') === 'Comercial'): ?>
                        <section class="copilot-commercial">
                            <header>
                                <div><span class="copilot-kicker">Asistencia comercial</span><h3>Recomendacion comercial</h3></div>
                                <div class="copilot-actions">
                                    <form action="recomendar_venta.php" method="POST">
                                        <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                                        <button type="submit" class="card-button"><?= ($productos_recomendados || $guion_venta !== '') ? 'Actualizar recomendacion' : 'Obtener recomendacion' ?></button>
                                    </form>
                                    <a href="ver_catalogo.php" target="_blank" rel="noopener" class="card-button secondary-button">Ver catalogo</a>
                                </div>
                            </header>
                            <?php if ($recomendacion_fallida): ?>
                                <p class="copilot-commercial-empty">No se pudo generar la recomendacion. Intentalo de nuevo en unos minutos.</p>
                            <?php elseif ($productos_recomendados || $guion_venta !== ''): ?>
                                <div class="copilot-commercial-grid">
                                    <div><span>Productos recomendados</span><div class="recomendacion-text markdown-compact" id="productos-recomendados"></div></div>
                                    <div><span>Guion de venta</span><div class="recomendacion-text markdown-compact" id="guion-venta"></div></div>
                                </div>
                            <?php else: ?>
                                <p class="copilot-commercial-empty">Genera una recomendacion para cruzar el contexto de la incidencia con el catalogo disponible.</p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                    </div>
                </details>

                    <form action="guardar_mensaje.php" method="POST" class="composer conversation-composer" id="formRespuesta">
                        <div class="composer-heading"><label for="mensaje">Tu respuesta</label><button type="button" class="card-button secondary-button" id="generarCopiloto" data-id="<?= $id_incidencia ?>">Redactar con IA</button></div>
                        <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                        <input type="hidden" name="origen_ia" id="origenIa" value="">
                        <input type="hidden" name="contenido_hash_ia" id="contenidoHashIa" value="">
                        <textarea name="mensaje" id="mensaje" rows="5" maxlength="30000" required placeholder="Escribe la respuesta en espanol...<?= ($incidencia['idioma'] ?? 'es') !== 'es' ? ' Se traducira al idioma original al enviarla.' : '' ?>"></textarea>
                        <div class="composer-row">
                            <label class="reply-action-label" for="accionRespuesta">Al enviar</label>
                            <select name="accion_respuesta" id="accionRespuesta" aria-describedby="respuestaAyuda">
                                <option value="responder">Responder al cliente</option>
                                <option value="resolver">Proponer solucion</option>
                                <option value="nota">Guardar nota interna</option>
                            </select>
                            <button type="submit" class="card-button" id="enviarRespuesta">Enviar respuesta</button>
                        </div>
                        <p class="help-line" id="respuestaAyuda" aria-live="polite">Visible para el cliente. La incidencia seguira abierta.</p>
                        <details class="resolution-options" id="opcionesResolucion" hidden><summary>Tipo de solucion (opcional)</summary>
                            <label for="resolucion_codigo">Resultado</label>
                            <select name="resolucion_codigo" id="resolucion_codigo"><?php foreach (dominio_codigos_resolucion() as $codigo => $etiqueta): ?><option value="<?= ui_e($codigo) ?>"><?= ui_e($etiqueta) ?></option><?php endforeach; ?></select>
                        </details>
                    </form>
                <?php else: ?>
                    <p class="help-line">El trabajo esta finalizado. Reabre la incidencia para anadir una respuesta.</p>
                <?php endif; ?>
                </section>
                <details class="incidencia-box compact-box secondary-disclosure" id="adjuntos" <?= isset($_GET['adjunto']) || isset($_GET['adjunto_error']) ? 'open' : '' ?>>
                    <summary>Archivos adjuntos <small><?= count($adjuntos) ?> archivos</small></summary>
                    <div class="disclosure-content">
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
                    <?php if ($es_activa): ?>
                        <form action="subir_adjunto.php" method="POST" enctype="multipart/form-data" class="composer-row adjuntos-form">
                            <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                            <input type="file" name="adjunto" required>
                            <span class="composer-spacer"></span>
                            <input type="submit" value="Subir adjunto">
                        </form>
                        <p class="help-line">Maximo <?= ui_e(adjuntos_formato_tamano(adjuntos_max_bytes())) ?> por fichero.</p>
                    <?php endif; ?>
                    </div>
                </details>
            </div>

            <aside class="detail-side">
                <div class="incidencia-box compact-box">
                    <h2>Seguimiento</h2>
                    <span class="turn-badge <?= ui_e($siguiente_paso['clave']) ?>"><?= ui_e($siguiente_paso['label']) ?></span>
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
                    <p class="support-sla-summary">SLA <strong><?= ui_e(dominio_duracion_humana((int)$sla['restante_segundos'])) ?></strong></p>
                    <details class="secondary-disclosure"><summary>Mas datos de la incidencia</summary><div class="disclosure-content">
                            <?php if (($incidencia['idioma'] ?? 'es') !== 'es'): ?>
                                <a href="ver_incidencia.php?id=<?= $id_incidencia ?>&traducir=es" class="card-button secondary-button" title="Traducir titulo, descripcion y conversacion al espanol"><?= $mostrar_traduccion ? 'Traducido al espanol' : 'Traducir al espanol' ?></a>
                            <?php endif; ?>
                                <details class="ticket-header-score priority-explanation">
                <summary><span>Prioridad operativa</span><strong><?= $prioridad_operativa ?></strong><small>Ver calculo</small></summary>
                <div>
                    <?php foreach ($prioridad_info['factores'] as $factor): ?>
                        <span><?= ui_e($factor['label']) ?><b>+<?= (int)$factor['puntos'] ?></b></span>
                    <?php endforeach; ?>
                    <small>La puntuacion se limita a 100 y sirve para ordenar el trabajo; no cambia la urgencia declarada.</small>
                </div>
            </details>
                    <div class="detail-sla <?= ui_e((string)$sla['estado']) ?>">
                        <div class="detail-sla-heading">
                            <span>SLA · <?= ui_e(dominio_niveles_servicio()[$incidencia['nivel_servicio']] ?? 'Estandar') ?><?= $sla['tipo_politica'] !== '*' ? ' · ' . ui_e((string)$sla['tipo_politica']) : '' ?></span>
                            <strong><?= ui_e(dominio_duracion_humana((int)$sla['restante_segundos'])) ?></strong>
                        </div>
                        <div class="sla-progress"><i style="width: <?= (int)$sla['porcentaje'] ?>%"></i></div>
                        <div class="detail-sla-meta">
                            <small>Objetivo de <?= $sla['objetivo_actual'] === 'primera_respuesta' ? 'primera respuesta' : 'resolucion' ?>: <?= (int)($sla['objetivo_actual'] === 'primera_respuesta' ? $sla['objetivo_respuesta_horas'] : $sla['objetivo_resolucion_horas']) ?> h</small>
                            <span class="turn-badge <?= ui_e($siguiente_paso['clave']) ?>"><?= ui_e($siguiente_paso['label']) ?></span>
                        </div>
                        <small>Primera respuesta: <?= $primera_respuesta !== null ? ui_e(date('d/m H:i', strtotime($primera_respuesta))) : 'Pendiente' ?></small>
                    </div>
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
                            <span><?= $es_activa ? 'Edad' : 'Tiempo hasta solucion' ?></span>
                            <strong><?= (int)$dias_abierta ?> dias<?= $es_activa ? ' · ' . ui_e($antiguedad_label) : '' ?></strong>
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

                    </div>

                    </div></details>
                    <?php if (!$es_activa): ?>
                    <details class="reopen-disclosure"><summary>Reabrir incidencia</summary>

                        <form action="reabrir_incidencia.php" method="POST" class="detail-action form-stack">
                            <input type="hidden" name="id_incidencia" value="<?= $id_incidencia ?>"><?= csrf_campo() ?>
                            <label class="filter-label" for="motivo">Motivo de la reapertura</label>
                            <textarea name="motivo" id="motivo" rows="3" required></textarea>
                            <input type="submit" value="Reabrir incidencia">
                        </form>
                    </details>
                    <?php endif; ?>
                </div>

                <details class="incidencia-box compact-box secondary-disclosure">
                    <summary>Historial de actividad</summary>
                    <div class="timeline">
                        <?php foreach ($timeline as $event): ?>
                            <?= ui_render_timeline_item($event) ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            </aside>
        </div>
    </div>
</div>
</main>
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

    const supportMenuToggle = document.getElementById('supportMenuToggle');
    const supportSidebar = document.getElementById('supportSidebar');
    if (supportMenuToggle && supportSidebar) {
        supportMenuToggle.addEventListener('click', () => {
            const abierto = document.body.classList.toggle('support-menu-open');
            supportMenuToggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        });
    }

    const generarCopiloto = document.getElementById('generarCopiloto');
    const usarBorrador = document.getElementById('usarBorrador');
    const copilotRespuesta = document.getElementById('copilotRespuesta');
    const copilotCard = document.getElementById('copilotCard');
    const copilotFeedback = document.getElementById('copilotFeedback');
    const feedbackEstado = document.getElementById('copilotFeedbackEstado');
    const origenIa = document.getElementById('origenIa');
    const contenidoHashIa = document.getElementById('contenidoHashIa');
    const registrarFeedback = async (accion, valoracion = null) => {
        const hash = copilotCard?.dataset.contenidoHash || '';
        if (!hash) return false;
        const body = new URLSearchParams({
            id_incidencia: <?= json_encode($id_incidencia) ?>,
            contenido_hash: hash,
            accion,
            csrf: <?= json_encode(csrf_token()) ?>
        });
        if (valoracion !== null) body.set('valoracion', String(valoracion));
        try {
            const response = await fetch('feedback_ia.php', { method: 'POST', body });
            const data = await response.json();
            return response.ok && data.ok;
        } catch (error) {
            return false;
        }
    };
    const pintarCopiloto = (insight) => {
        document.getElementById('copilotResumen').textContent = insight.resumen || '';
        const riesgo = document.getElementById('copilotRiesgo');
        riesgo.textContent = (insight.riesgo || 'medio').replace(/^./, (c) => c.toUpperCase());
        riesgo.className = 'risk-' + (insight.riesgo || 'medio');
        document.getElementById('copilotSentimiento').textContent = (insight.sentimiento || 'neutral').replace(/^./, (c) => c.toUpperCase());
        document.getElementById('copilotAccion').textContent = insight.siguiente_accion || '';
        document.getElementById('copilotConfianza').textContent = `${parseInt(insight.confianza || 0, 10)}%`;
        copilotRespuesta.textContent = insight.respuesta_sugerida || '';
        document.getElementById('copilotDraft').hidden = !insight.respuesta_sugerida;
        document.getElementById('copilotGrid').classList.remove('is-empty');
        document.getElementById('copilotEstado').textContent = 'Brief actualizado ahora';
        generarCopiloto.textContent = 'Redactar con IA';
        copilotCard.open = true;
        copilotCard.dataset.contenidoHash = insight.contenido_hash || '';
        copilotFeedback.hidden = false;
        document.querySelectorAll('.copilot-feedback-button').forEach((boton) => boton.classList.remove('is-active'));
        feedbackEstado.textContent = 'Tu valoracion mejora el control de calidad.';
        if (origenIa) origenIa.value = '';
        if (contenidoHashIa) contenidoHashIa.value = '';
    };
    if (generarCopiloto) {
        generarCopiloto.addEventListener('click', async () => {
            generarCopiloto.disabled = true;
            copilotCard.open = true;
            document.getElementById('copilotEstado').textContent = 'Preparando un borrador. Todavia no se ha enviado.';
            try {
                const body = new URLSearchParams({
                    id_incidencia: generarCopiloto.dataset.id,
                    csrf: <?= json_encode(csrf_token()) ?>,
                    forzar: '1'
                });
                const response = await fetch('copiloto_incidencia.php', { method: 'POST', body });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo generar el brief');
                pintarCopiloto(data.insight);
            } catch (error) {
                document.getElementById('copilotEstado').textContent = error.message;
            } finally {
                generarCopiloto.disabled = false;
            }
        });
    }
    if (usarBorrador) {
        usarBorrador.addEventListener('click', () => {
            const mensaje = document.getElementById('mensaje');
            if (!mensaje) return;
            if (mensaje.value.trim() && !window.confirm('Sustituir el texto que ya has escrito por el borrador de IA?')) return;
            mensaje.value = copilotRespuesta.textContent.trim();
            document.getElementById('respuestaAyuda').textContent = 'Borrador de IA sin enviar. Revisalo y pulsa el boton de envio para guardarlo en la conversacion.';
            copilotCard.open = false;
            if (origenIa) origenIa.value = 'copiloto';
            if (contenidoHashIa) contenidoHashIa.value = copilotCard.dataset.contenidoHash || '';
            registrarFeedback('usar_borrador');
            mensaje.focus();
            mensaje.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
    document.querySelectorAll('.copilot-feedback-button').forEach((boton) => {
        boton.addEventListener('click', async () => {
            const valoracion = parseInt(boton.dataset.valoracion || '0', 10);
            feedbackEstado.textContent = 'Guardando...';
            const ok = await registrarFeedback('valorar', valoracion);
            if (!ok) {
                feedbackEstado.textContent = 'No se pudo guardar. Intentalo de nuevo.';
                return;
            }
            document.querySelectorAll('.copilot-feedback-button').forEach((otro) => otro.classList.toggle('is-active', otro === boton));
            feedbackEstado.textContent = 'Gracias. Feedback guardado.';
        });
    });

    const accionRespuesta = document.getElementById('accionRespuesta');
    accionRespuesta?.addEventListener('change', () => {
        const accion = accionRespuesta.value;
        document.getElementById('enviarRespuesta').textContent = accion === 'nota' ? 'Guardar nota' : (accion === 'resolver' ? 'Enviar solucion' : 'Enviar respuesta');
        document.getElementById('respuestaAyuda').textContent = accion === 'nota'
            ? 'Solo visible para el equipo. No se enviara al cliente.'
            : (accion === 'resolver' ? 'Se guardara como mensaje visible para el cliente y quedara pendiente de su confirmacion.' : 'Visible para el cliente. La incidencia seguira abierta.');
        document.getElementById('opcionesResolucion').hidden = accion !== 'resolver';
        document.getElementById('formRespuesta').classList.toggle('is-internal', accion === 'nota');
    });
    document.getElementById('formRespuesta')?.addEventListener('submit', () => {
        const boton = document.getElementById('enviarRespuesta');
        boton.disabled = true;
        boton.textContent = 'Guardando...';
    });

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

});
</script>
</body>
</html>
