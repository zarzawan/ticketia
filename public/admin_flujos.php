<?php
// Configuracion del ciclo de vida y mantenimiento del historico.
require_once __DIR__ . '/../src/arranque.php';

$aviso = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'guardar') {
        $diasCierre = filter_input(INPUT_POST, 'dias_cierre', FILTER_VALIDATE_INT);
        $diasArchivo = filter_input(INPUT_POST, 'dias_archivo', FILTER_VALIDATE_INT);
        if ($diasCierre === false || $diasCierre < 1 || $diasCierre > 90 || $diasArchivo === false || $diasArchivo < 1 || $diasArchivo > 3650) {
            $error = 'Usa entre 1 y 90 dias para confirmar y entre 1 y 3650 dias para archivar.';
        } else {
            dominio_ajuste_guardar_entero($pdo, 'dias_cierre_automatico', (int)$diasCierre);
            dominio_ajuste_guardar_entero($pdo, 'dias_archivo_automatico', (int)$diasArchivo);
            auditar($pdo, 'guardar_ciclo_vida', "cierre {$diasCierre}d, archivo {$diasArchivo}d");
            $aviso = 'Reglas de ciclo de vida actualizadas.';
        }
    } elseif ($accion === 'ejecutar') {
        $resultado = incidencias_ejecutar_mantenimiento($pdo);
        auditar($pdo, 'mantenimiento_incidencias', json_encode($resultado));
        $aviso = "Mantenimiento completado: {$resultado['cerradas']} cerradas y {$resultado['archivadas']} archivadas.";
    }
}

$diasCierre = dominio_ajuste_entero($pdo, 'dias_cierre_automatico', 7, 1, 90);
$diasArchivo = dominio_ajuste_entero($pdo, 'dias_archivo_automatico', 30, 1, 3650);
$conteos = $pdo->query(
    "SELECT SUM(estado IN ('abierta','en_curso','esperando_cliente')) AS activas,
            SUM(estado = 'resuelta') AS resueltas,
            SUM(estado = 'cerrada' AND fecha_archivo IS NULL) AS cerradas,
            SUM(fecha_archivo IS NOT NULL) AS archivadas
     FROM incidencias"
)->fetch(PDO::FETCH_ASSOC) ?: [];

ui_admin_cabecera('Flujos', 'Cierre, confirmacion y archivo sin perder trazabilidad.', 'admin_flujos.php');
?>
<?php if ($aviso !== ''): ?><div class="success-message"><?= ui_e($aviso) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>

<section class="lifecycle-flow" aria-label="Ciclo de vida de una incidencia">
    <article><span>1</span><strong>Nueva</strong><small>Pendiente de primera respuesta</small></article>
    <i></i><article><span>2</span><strong>En trabajo</strong><small>Investigacion y conversacion</small></article>
    <i></i><article><span>3</span><strong>Solucion propuesta</strong><small>Confirmacion del cliente</small></article>
    <i></i><article><span>4</span><strong>Cerrada y archivada</strong><small>Fuera de la cola, siempre consultable</small></article>
</section>

<div class="admin-dashboard-grid lifecycle-admin-grid">
    <section class="admin-panel admin-panel-wide">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Automatizacion</span><h2>Reglas de cierre</h2></div></div>
        <form method="POST" class="form-stack lifecycle-settings-form">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar">
            <label for="dias_cierre"><strong>Confirmacion del cliente</strong><small>Dias que una solucion permanece pendiente antes de cerrarse automaticamente.</small></label>
            <div class="input-with-unit"><input id="dias_cierre" name="dias_cierre" type="number" min="1" max="90" value="<?= $diasCierre ?>" required><span>dias</span></div>
            <label for="dias_archivo"><strong>Salida al archivo</strong><small>Dias que un cierre reciente permanece visible antes de pasar al archivo logico.</small></label>
            <div class="input-with-unit"><input id="dias_archivo" name="dias_archivo" type="number" min="1" max="3650" value="<?= $diasArchivo ?>" required><span>dias</span></div>
            <button type="submit" class="card-button">Guardar reglas</button>
        </form>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Volumen</span><h2>Distribucion actual</h2></div><a href="archivo.php">Abrir historial</a></div>
        <div class="detail-list">
            <div class="detail-row"><span>Trabajo activo</span><strong><?= (int)($conteos['activas'] ?? 0) ?></strong></div>
            <div class="detail-row"><span>Esperan confirmacion</span><strong><?= (int)($conteos['resueltas'] ?? 0) ?></strong></div>
            <div class="detail-row"><span>Cierres recientes</span><strong><?= (int)($conteos['cerradas'] ?? 0) ?></strong></div>
            <div class="detail-row"><span>Archivadas</span><strong><?= (int)($conteos['archivadas'] ?? 0) ?></strong></div>
        </div>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-head"><div><span class="admin-section-kicker">Mantenimiento</span><h2>Ejecutar ahora</h2></div></div>
        <p class="help-line">El worker aplica estas reglas automaticamente. Esta accion permite comprobarlas sin esperar a la siguiente ejecucion programada.</p>
        <form method="POST" onsubmit="return confirm('Aplicar ahora las reglas de cierre y archivo?');">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="ejecutar">
            <button type="submit" class="card-button secondary-button">Procesar ciclo de vida</button>
        </form>
    </section>
</div>
<?php ui_admin_pie(); ?>
