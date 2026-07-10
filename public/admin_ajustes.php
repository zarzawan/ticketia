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
$limite_dia = (int)($_ENV['LLM_MAX_LLAMADAS_DIA'] ?? 0);
$llamadas_pago_hoy = (int)$pdo->query(
    "SELECT COUNT(*) FROM llm_logs WHERE proveedor <> 'local' AND DATE(fecha) = CURDATE()"
)->fetchColumn();

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
            <div class="detail-row"><span>Incidencias</span><strong><?= (int)$contadores['incidencias'] ?></strong></div>
            <div class="detail-row"><span>Mensajes</span><strong><?= (int)$contadores['mensajes'] ?></strong></div>
            <div class="detail-row"><span>Usuarios / Empresas</span><strong><?= (int)$contadores['usuarios'] ?> / <?= (int)$contadores['clientes'] ?></strong></div>
            <div class="detail-row"><span>Adjuntos</span><strong><?= (int)$contadores['adjuntos'] ?> (<?= ui_e(adjuntos_formato_tamano((int)$contadores['adjuntos_bytes'])) ?>)</strong></div>
            <div class="detail-row"><span>Llamadas IA registradas</span><strong><?= (int)$contadores['llamadas_ia'] ?></strong></div>
        </div>
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
