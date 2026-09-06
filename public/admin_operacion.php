<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin');
$aviso = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion = (string)($_POST['accion'] ?? '');
        if ($accion === 'retencion') {
            $trabajos = filter_var($_POST['trabajos'] ?? '',FILTER_VALIDATE_INT);
            $auditoria = filter_var($_POST['auditoria'] ?? '',FILTER_VALIDATE_INT);
            if ($trabajos === false || $trabajos < 7 || $trabajos > 3650 || $auditoria === false || ($auditoria !== 0 && $auditoria < 90) || $auditoria > 3650) throw new RuntimeException('Trabajos: 7-3650 dias. Auditoria: 0 (conservar) o 90-3650 dias.');
            dominio_ajuste_guardar_entero($pdo,'retencion_trabajos',$trabajos);
            dominio_ajuste_guardar_entero($pdo,'retencion_auditoria',$auditoria);
            unset($_SESSION['purga_operacion']); $aviso = 'Politica guardada. No se han eliminado registros.';
        } elseif ($accion === 'previsualizar') {
            $_SESSION['purga_operacion'] = ['politica'=>operacion_retencion($pdo),'hasta'=>time()+600];
            $aviso = 'Estimacion actualizada. Cada ejecucion elimina como maximo 1000 registros por categoria.';
        } elseif ($accion === 'purgar') {
            $previa = $_SESSION['purga_operacion'] ?? [];
            if (!isset($_POST['confirmar']) || ($previa['politica'] ?? []) !== operacion_retencion($pdo) || ($previa['hasta'] ?? 0) < time()) throw new RuntimeException('Previsualiza la politica y confirma la eliminacion.');
            $p = operacion_retencion($pdo);
            $n = $pdo->exec("DELETE FROM trabajos_ia WHERE estado='completado' AND actualizado_en < NOW() - INTERVAL {$p['trabajos']} DAY LIMIT 1000");
            if ($p['auditoria'] > 0) $n += $pdo->exec("DELETE FROM auditoria WHERE fecha < NOW() - INTERVAL {$p['auditoria']} DAY LIMIT 1000");
            unset($_SESSION['purga_operacion']); $aviso = "$n registros tecnicos eliminados. Incidencias y adjuntos conservados.";
        } elseif ($accion === 'reintentar') {
            $id = max(0,(int)($_POST['id'] ?? 0));
            $pdo->prepare("UPDATE trabajos_ia SET estado='pendiente',intentos=0,programado_para=NOW() WHERE id=? AND estado='fallido'")->execute([$id]);
            $aviso = 'Reintento solicitado. El worker lo ejecutara; un correo puede haberse entregado antes de un fallo de confirmacion SMTP.';
        } elseif ($accion === 'restauracion') {
            if (!isset($_POST['confirmar'])) throw new RuntimeException('Confirma que has probado la restauracion en un entorno aislado.');
            $pdo->prepare("INSERT INTO ajustes (clave,valor) VALUES ('restauracion_declarada',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([date('Y-m-d H:i:s')]);
            $aviso = 'Verificacion declarada registrada. TicketIA no ha ejecutado ni comprobado la copia.';
        }
        auditar($pdo,'operacion_administrativa',$accion);
    } catch (Throwable $e) { $error = $e instanceof PDOException ? 'No se pudo completar la operacion.' : $e->getMessage(); }
}
$politica = operacion_retencion($pdo); $estimacion = operacion_estimar($pdo); $alertas = operacion_alertas($pdo);
$trabajos = $pdo->query("SELECT id,tipo,estado,intentos,max_intentos,ultimo_error,programado_para FROM trabajos_ia WHERE estado <> 'completado' ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$restauracion = $pdo->query("SELECT valor FROM ajustes WHERE clave='restauracion_declarada'")->fetchColumn();
$volumen = $pdo->query("SELECT ROUND(SUM(DATA_LENGTH+INDEX_LENGTH)/1024/1024,1) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn();
$articulos = conocimiento_disponible($pdo) ? $pdo->query("SELECT id,titulo,actualizado_en FROM conocimiento WHERE estado='publicado' AND actualizado_en < NOW() - INTERVAL 180 DAY ORDER BY actualizado_en LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) : [];
$pocoUtiles = conocimiento_valoraciones_disponibles($pdo) ? $pdo->query("SELECT c.id,c.titulo,COUNT(*) AS total,SUM(v.util=0) AS negativas
    FROM conocimiento c JOIN conocimiento_valoraciones v ON v.articulo_id=c.id WHERE c.estado='publicado'
    GROUP BY c.id,c.titulo HAVING negativas > 0 ORDER BY negativas DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) : [];
ui_admin_cabecera('Necesita atencion','Salud del servicio, entregas y conservacion de datos.','admin_operacion.php');
?>
<?php if ($aviso): ?><p class="success-message"><?= ui_e($aviso) ?></p><?php endif; ?><?php if ($error): ?><p class="login-error"><?= ui_e($error) ?></p><?php endif; ?>
<section class="admin-panel"><h2>Atencion prioritaria</h2><?php foreach ($alertas as $alerta): ?><p><a href="<?= ui_e($alerta['url']) ?>"><strong><?= ui_e($alerta['titulo']) ?></strong></a> — <?= ui_e($alerta['detalle']) ?></p><?php endforeach; ?><?php if (!$alertas): ?><p>No hay alertas detectadas por estas comprobaciones. No sustituye la monitorizacion externa.</p><?php endif; ?></section>
<section class="admin-panel"><h2>Entregas y tareas pendientes</h2><p>Ultimas 50 tareas no completadas. Los correos usan reintentos; un resultado SMTP ambiguo puede producir duplicados. Los cuerpos y destinatarios no se muestran aqui.</p><div class="table-scroll"><table class="logs-table"><thead><tr><th>Tarea</th><th>Estado</th><th>Intentos</th><th>Diagnostico</th><th>Accion</th></tr></thead><tbody><?php foreach ($trabajos as $trabajo): ?><tr><td>#<?= (int)$trabajo['id'] ?> <?= ui_e($trabajo['tipo']) ?></td><td><?= ui_e($trabajo['estado']) ?></td><td><?= (int)$trabajo['intentos'] ?>/<?= (int)$trabajo['max_intentos'] ?></td><td><?= ui_e($trabajo['ultimo_error'] ?? 'Pendiente de ejecucion') ?></td><td><?php if ($trabajo['estado']==='fallido'): ?><form method="POST"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$trabajo['id'] ?>"><button name="accion" value="reintentar" class="card-button secondary-button">Reintentar</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="admin-panel"><h2>Conservacion y volumen</h2><p>Base de datos: aproximadamente <?= ui_e((string)$volumen) ?> MB, incluidos indices; no incluye los ficheros adjuntos. <a href="admin_flujos.php">Las incidencias se archivan, no se borran.</a> Los adjuntos se conservan con su incidencia. No hay eliminacion automatica de contenido del cliente.</p><form method="POST" class="page-tools"><?= csrf_campo() ?><label>Trabajos completados (dias)<input name="trabajos" type="number" min="7" max="3650" value="<?= $politica['trabajos'] ?>"></label><label>Auditoria (0: conservar)<input name="auditoria" type="number" min="0" max="3650" value="<?= $politica['auditoria'] ?>"></label><button name="accion" value="retencion" class="card-button">Guardar politica</button></form><p>Candidatos: <?= $estimacion['trabajos'] ?> trabajos completados y <?= $estimacion['auditoria'] ?> registros de auditoria. Los logs de IA tienen su <a href="admin_ajustes.php#configMantenimiento">propia retencion</a>.</p><form method="POST" class="form-stack"><?= csrf_campo() ?><button class="card-button secondary-button" name="accion" value="previsualizar">Previsualizar limpieza</button><label><input type="checkbox" name="confirmar"> He revisado la estimacion y dispongo de una copia si necesito recuperar estos registros.</label><button name="accion" value="purgar" class="card-button secondary-button">Eliminar lote confirmado</button></form></section>
<section class="admin-panel"><h2>Preparacion operativa</h2><p>SMTP: <?= correo_activo() ? 'configurado (no demuestra entrega)' : 'sin configurar' ?>. Procesador: <?= ui_e(trabajos_worker_salud($pdo)['etiqueta']) ?>.</p><p>Ultima restauracion declarada por un administrador: <?= ui_e($restauracion ?: 'ninguna') ?>.</p><p>Incluye base de datos, adjuntos y configuracion en una copia cifrada fuera del servidor. Prueba su restauracion en un entorno aislado, con correo e IA externos desactivados.</p><form method="POST"><?= csrf_campo() ?><label><input type="checkbox" name="confirmar" required> He comprobado la restauracion de una copia en un entorno aislado.</label><button class="card-button secondary-button" name="accion" value="restauracion">Registrar comprobacion manual</button></form></section>
<section class="admin-panel"><h2>Conocimiento pendiente de revision</h2><p>Articulos publicados sin actualizar en 180 dias; revisa si las instrucciones siguen siendo validas.</p><?php foreach ($articulos as $articulo): ?><p><a href="admin_conocimiento.php?editar=<?= (int)$articulo['id'] ?>"><?= ui_e($articulo['titulo']) ?></a> · <?= ui_e($articulo['actualizado_en']) ?></p><?php endforeach; ?></section>
<section class="admin-panel"><h2>Guias que necesitan mejorar</h2><?php foreach ($pocoUtiles as $articulo): ?><p><a href="admin_conocimiento.php?editar=<?= (int)$articulo['id'] ?>"><?= ui_e($articulo['titulo']) ?></a> · <?= (int)$articulo['negativas'] ?> valoraciones negativas de <?= (int)$articulo['total'] ?>.</p><?php endforeach; ?><?php if (!$pocoUtiles): ?><p>Sin valoraciones negativas registradas. La ausencia de opiniones no demuestra eficacia.</p><?php endif; ?></section>
<?php ui_admin_pie(); ?>
