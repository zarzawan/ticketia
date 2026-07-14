<?php
// Ajustes del sistema: proveedor IA, prueba de conexion, mantenimiento e informacion.
require_once __DIR__ . '/../src/arranque.php';

$aviso = '';
$error = '';
$resultado_prueba = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'probar_ia') {
        $inicio = microtime(true);
        $respuesta = LLMClient::getResponse('', 'Responde unicamente con la palabra: OK');
        $ms = (int)round((microtime(true) - $inicio) * 1000);
        if ($respuesta !== null && $respuesta !== '') {
            $resultado_prueba = ['ok' => true, 'ms' => $ms, 'respuesta' => mb_substr($respuesta, 0, 120)];
        } else {
            $resultado_prueba = ['ok' => false, 'ms' => $ms, 'respuesta' => 'Sin respuesta (revisa endpoint, modelo y clave en .env)'];
        }
        auditar($pdo, 'probar_ia', ($resultado_prueba['ok'] ? 'ok' : 'fallo') . " en {$ms}ms ($llm_provider)");
    }

    if ($accion === 'purgar_logs') {
        $borrados = $pdo->exec("DELETE FROM llm_logs WHERE fecha < NOW() - INTERVAL 30 DAY");
        auditar($pdo, 'purgar_logs_ia', "$borrados registros");
        $aviso = "Eliminados $borrados registros de actividad IA anteriores a 30 dias.";
    }

    if ($accion === 'procesar_cola') {
        $resumen = trabajos_procesar_lote($pdo, 10);
        auditar($pdo, 'procesar_cola_ia', json_encode($resumen));
        $aviso = "Cola procesada: {$resumen['procesados']} trabajos ({$resumen['completados']} completados, {$resumen['reintentos']} reintentos, {$resumen['fallidos']} fallidos).";
    }

    if ($accion === 'reintentar_fallidos') {
        $n = trabajos_reintentar_fallidos($pdo);
        auditar($pdo, 'reintentar_trabajos_ia', "$n trabajos");
        $aviso = "$n trabajos fallidos reencolados.";
    }

    if ($accion === 'guardar_sla') {
        $nivel = (string)($_POST['nivel_cliente'] ?? '');
        $tipo = trim((string)($_POST['tipo_incidencia'] ?? '*'));
        $urgencia = (string)($_POST['urgencia'] ?? '');
        $respuesta = filter_input(INPUT_POST, 'primera_respuesta_horas', FILTER_VALIDATE_INT);
        $resolucion = filter_input(INPUT_POST, 'resolucion_horas', FILTER_VALIDATE_INT);
        $tipos_validos = array_merge(['*'], dominio_tipos());

        if (!isset(dominio_niveles_servicio()[$nivel]) || !in_array($tipo, $tipos_validos, true) || !in_array($urgencia, dominio_urgencias(), true)) {
            $error = 'La combinacion de nivel, tipo y urgencia no es valida.';
        } elseif ($respuesta === false || $resolucion === false || $respuesta < 1 || $resolucion < $respuesta || $resolucion > 8760) {
            $error = 'Las horas deben ser positivas y la resolucion no puede ser menor que la primera respuesta.';
        } else {
            $pdo->prepare(
                "INSERT INTO sla_politicas (nivel_cliente, tipo_incidencia, urgencia, primera_respuesta_horas, resolucion_horas, activo)
                 VALUES (:nivel, :tipo, :urgencia, :respuesta, :resolucion, 1)
                 ON DUPLICATE KEY UPDATE primera_respuesta_horas = VALUES(primera_respuesta_horas),
                    resolucion_horas = VALUES(resolucion_horas), activo = 1"
            )->execute([
                ':nivel' => $nivel,
                ':tipo' => $tipo,
                ':urgencia' => $urgencia,
                ':respuesta' => $respuesta,
                ':resolucion' => $resolucion,
            ]);
            dominio_sla_cargar_politicas($pdo);
            auditar($pdo, 'guardar_sla', "$nivel / $tipo / $urgencia: {$respuesta}h / {$resolucion}h");
            $aviso = 'Politica SLA guardada.';
        }
    }

    if ($accion === 'eliminar_sla') {
        $id_sla = filter_input(INPUT_POST, 'id_sla', FILTER_VALIDATE_INT);
        if ($id_sla) {
            $stmt = $pdo->prepare("DELETE FROM sla_politicas WHERE id = :id AND tipo_incidencia <> '*'");
            $stmt->execute([':id' => $id_sla]);
            dominio_sla_cargar_politicas($pdo);
            auditar($pdo, 'eliminar_sla', "politica #$id_sla");
            $aviso = $stmt->rowCount() > 0 ? 'Excepcion SLA eliminada.' : 'Las politicas generales no se pueden eliminar; puedes editarlas.';
        }
    }
}

// ---------------- Informacion del sistema ----------------

$version_bd = $pdo->query("SELECT VERSION()")->fetchColumn();
$tamano_bd = $pdo->query(
    "SELECT ROUND(SUM(data_length + index_length) / 1048576, 1)
     FROM information_schema.tables WHERE table_schema = DATABASE()"
)->fetchColumn();

$contadores = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM incidencias) AS incidencias,
            (SELECT COUNT(*) FROM mensajes) AS mensajes,
            (SELECT COUNT(*) FROM usuarios) AS usuarios,
            (SELECT COUNT(*) FROM clientes) AS clientes,
            (SELECT COUNT(*) FROM adjuntos) AS adjuntos,
            (SELECT COALESCE(SUM(tamano), 0) FROM adjuntos) AS adjuntos_bytes,
            (SELECT COUNT(*) FROM llm_logs) AS llamadas_ia"
)->fetch(PDO::FETCH_ASSOC);

$extensiones = ['pdo_mysql', 'curl', 'mbstring', 'fileinfo', 'openssl'];

$cola = trabajos_estado($pdo);
$limite_dia = (int)entorno_valor('LLM_MAX_LLAMADAS_DIA', 0);
$llamadas_pago_hoy = (int)$pdo->query(
    "SELECT COUNT(*) FROM llm_logs WHERE proveedor <> 'local' AND DATE(fecha) = CURDATE()"
)->fetchColumn();
$politicas_sla = $pdo->query(
    "SELECT * FROM sla_politicas
     ORDER BY FIELD(nivel_cliente, 'estandar', 'preferente', 'premium'),
              (tipo_incidencia = '*') DESC, tipo_incidencia,
              FIELD(urgencia, 'critico', 'urgente', 'leve')"
)->fetchAll(PDO::FETCH_ASSOC);

ui_admin_cabecera('Ajustes', 'Proveedor de IA, mantenimiento e informacion del sistema.', 'admin_ajustes.php');
?>

<?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>

<div class="split-2">
    <div class="incidencia-box compact-box">
        <h2>Inteligencia artificial</h2>
        <div class="detail-list">
            <div class="detail-row">
                <span>Proveedor activo</span>
                <form action="cambiar_proveedor.php" method="POST" class="detail-row-form">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="volver" value="admin_ajustes.php">
                    <select name="proveedor" onchange="this.form.submit()">
                        <?php foreach ($llm_config as $clave => $conf): ?>
                            <?php if (!is_array($conf) || !isset($conf['label'])) continue; ?>
                            <option value="<?= ui_e($clave) ?>" <?= $llm_provider === $clave ? 'selected' : '' ?>><?= ui_e($conf['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php foreach ($llm_config as $clave => $conf): ?>
                <?php if (!is_array($conf) || !isset($conf['label'])) continue; ?>
                <div class="detail-row">
                    <span><?= ui_e($conf['label']) ?></span>
                    <strong><?= ui_e($conf['chat']['model'] !== '' ? $conf['chat']['model'] : 'modelo por defecto del servidor') ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="POST" class="detail-action">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="probar_ia">
            <button type="submit" class="card-button">Probar conexion IA (<?= ui_e($llm_config[$llm_provider]['label'] ?? $llm_provider) ?>)</button>
        </form>
        <?php if ($resultado_prueba !== null): ?>
            <?php if ($resultado_prueba['ok']): ?>
                <div class="success-message" style="margin-top:10px;">Conexion correcta en <?= (int)$resultado_prueba['ms'] ?> ms. Respuesta: <?= ui_e($resultado_prueba['respuesta']) ?></div>
            <?php else: ?>
                <div class="login-error" style="margin-top:10px;">Fallo tras <?= (int)$resultado_prueba['ms'] ?> ms. <?= ui_e($resultado_prueba['respuesta']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="help-line">Los endpoints y claves se configuran en el fichero .env; los cambios de proveedor hechos aqui se guardan en la base de datos.</p>

        <h2 style="margin-top:18px;">Cola de trabajos IA</h2>
        <div class="detail-list">
            <div class="detail-row"><span>Pendientes</span><strong><?= (int)($cola['pendiente'] ?? 0) ?></strong></div>
            <div class="detail-row"><span>En curso</span><strong><?= (int)($cola['en_curso'] ?? 0) ?></strong></div>
            <div class="detail-row"><span>Completados</span><strong><?= (int)($cola['completado'] ?? 0) ?></strong></div>
            <div class="detail-row"><span>Fallidos</span><strong><?= (int)($cola['fallido'] ?? 0) ?></strong></div>
            <div class="detail-row">
                <span>Limite diario IA de pago</span>
                <strong><?= $limite_dia > 0 ? "$llamadas_pago_hoy / $limite_dia hoy" : 'Sin limite' ?><?= !empty($llm_solo_local) ? ' · solo-local activo' : '' ?></strong>
            </div>
        </div>
        <div class="page-tools" style="margin-top:10px;">
            <form method="POST">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="procesar_cola">
                <button type="submit" class="card-button secondary-button">Procesar cola ahora (10)</button>
            </form>
            <?php if ((int)($cola['fallido'] ?? 0) > 0): ?>
                <form method="POST">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="reintentar_fallidos">
                    <button type="submit" class="card-button secondary-button">Reencolar fallidos</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="help-line">Para procesado automatico programa <code>php bin/worker.php</code> (cron o Programador de tareas), o dejalo en bucle con <code>--bucle</code>.</p>
    </div>

    <div class="incidencia-box compact-box">
        <h2>Sistema</h2>
        <div class="detail-list">
            <div class="detail-row"><span>Version de PHP</span><strong><?= ui_e(PHP_VERSION) ?></strong></div>
            <div class="detail-row"><span>Base de datos</span><strong><?= ui_e((string)$version_bd) ?></strong></div>
            <div class="detail-row"><span>Tamano de la BD</span><strong><?= ui_e((string)$tamano_bd) ?> MB</strong></div>
            <div class="detail-row">
                <span>Extensiones</span>
                <strong>
                    <?php foreach ($extensiones as $ext): ?>
                        <span title="<?= $ext ?>"><?= extension_loaded($ext) ? '✓' : '✗' ?> <?= $ext ?>&nbsp;</span>
                    <?php endforeach; ?>
                </strong>
            </div>
            <div class="detail-row"><span>Hash de contrasenas</span><strong><?= defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt' ?></strong></div>
            <div class="detail-row">
                <span>Clave de aplicacion</span>
                <strong class="<?= cuenta_app_key_disponible() ? '' : 'texto-alerta' ?>">
                    <?= cuenta_app_key_disponible() ? 'Configurada · 2FA disponible' : 'Pendiente · 2FA desactivado' ?>
                </strong>
            </div>
            <div class="detail-row"><span>Incidencias</span><strong><?= (int)$contadores['incidencias'] ?></strong></div>
            <div class="detail-row"><span>Mensajes</span><strong><?= (int)$contadores['mensajes'] ?></strong></div>
            <div class="detail-row"><span>Usuarios / Empresas</span><strong><?= (int)$contadores['usuarios'] ?> / <?= (int)$contadores['clientes'] ?></strong></div>
            <div class="detail-row"><span>Adjuntos</span><strong><?= (int)$contadores['adjuntos'] ?> (<?= ui_e(adjuntos_formato_tamano((int)$contadores['adjuntos_bytes'])) ?>)</strong></div>
            <div class="detail-row"><span>Llamadas IA registradas</span><strong><?= (int)$contadores['llamadas_ia'] ?></strong></div>
        </div>
    </div>
</div>

<div class="incidencia-box compact-box">
    <div class="section-head">
        <div><h2>Politicas SLA</h2><p class="help-line">Los objetivos se miden en horas naturales. Una regla de tipo concreto prevalece sobre la regla general del nivel.</p></div>
    </div>
    <form method="POST" class="filter-form-modern">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="guardar_sla">
        <div class="filter-field">
            <label class="filter-label" for="sla_nivel">Nivel de cliente</label>
            <select id="sla_nivel" name="nivel_cliente" required>
                <?php foreach (dominio_niveles_servicio() as $clave => $etiqueta): ?>
                    <option value="<?= ui_e($clave) ?>"><?= ui_e($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label class="filter-label" for="sla_tipo">Tipo de incidencia</label>
            <select id="sla_tipo" name="tipo_incidencia" required>
                <option value="*">Todos (regla general)</option>
                <?php foreach (dominio_tipos() as $tipo): ?><option value="<?= ui_e($tipo) ?>"><?= ui_e($tipo) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label class="filter-label" for="sla_urgencia">Urgencia</label>
            <select id="sla_urgencia" name="urgencia" required>
                <?php foreach (dominio_urgencias() as $urgencia): ?><option value="<?= ui_e($urgencia) ?>"><?= ui_e(ui_urgencia_label($urgencia)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label class="filter-label" for="sla_respuesta">Primera respuesta (h)</label>
            <input id="sla_respuesta" name="primera_respuesta_horas" type="number" min="1" max="8760" value="4" required>
        </div>
        <div class="filter-field">
            <label class="filter-label" for="sla_resolucion">Resolucion (h)</label>
            <input id="sla_resolucion" name="resolucion_horas" type="number" min="1" max="8760" value="16" required>
        </div>
        <div class="filter-actions-inline"><button type="submit" class="filter-button">Guardar politica</button></div>
    </form>

    <div class="ticket-table-wrap" style="margin-top:16px;">
        <table class="logs-table">
            <thead><tr><th>Nivel</th><th>Tipo</th><th>Urgencia</th><th>Primera respuesta</th><th>Resolucion</th><th>Acciones</th></tr></thead>
            <tbody>
                <?php foreach ($politicas_sla as $politica): ?>
                    <tr>
                        <td><?= ui_e(dominio_niveles_servicio()[$politica['nivel_cliente']] ?? $politica['nivel_cliente']) ?></td>
                        <td><?= $politica['tipo_incidencia'] === '*' ? 'Todos' : ui_e($politica['tipo_incidencia']) ?></td>
                        <td><?= ui_e(ui_urgencia_label($politica['urgencia'])) ?></td>
                        <td><?= (int)$politica['primera_respuesta_horas'] ?> h</td>
                        <td><?= (int)$politica['resolucion_horas'] ?> h</td>
                        <td>
                            <?php if ($politica['tipo_incidencia'] !== '*'): ?>
                                <form method="POST" onsubmit="return confirm('Eliminar esta excepcion SLA?');">
                                    <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar_sla"><input type="hidden" name="id_sla" value="<?= (int)$politica['id'] ?>">
                                    <button type="submit" class="card-button secondary-button boton-mini">Eliminar</button>
                                </form>
                            <?php else: ?><span class="help-line">Base</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="incidencia-box compact-box">
    <h2>Mantenimiento</h2>
    <div class="page-tools">
        <form method="POST" onsubmit="return confirm('Eliminar los registros de actividad IA de mas de 30 dias?');">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="purgar_logs">
            <button type="submit" class="card-button secondary-button">Purgar actividad IA (+30 dias)</button>
        </form>
        <a class="card-button secondary-button" href="reprocesar_incidencias.php">Clasificar incidencias pendientes</a>
        <a class="card-button secondary-button" href="reprocesar_incidencias.php?todas=1" onclick="return confirm('Re-clasificar TODAS las incidencias con IA? Puede tardar y consumir tokens.');">Re-clasificar todo el historico</a>
    </div>
</div>

<?php ui_admin_pie(); ?>
