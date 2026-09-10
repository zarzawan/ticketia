<?php
require_once __DIR__ . '/../src/arranque.php';auth_requerir_rol('admin');
require_once __DIR__ . '/../src/gmail.php';
$error='';$aviso='';
$diagnostico=gmail_diagnostico(static fn($clave)=>entorno_valor($clave,''),extension_loaded('curl'));
if (!gmail_disponible($pdo)) { ui_admin_cabecera('Correo entrante','Gmail con revision antes de crear incidencias.','admin_correo_entrante.php');echo '<section class="admin-panel">Aplica la migracion 19 para habilitar la bandeja.</section>';ui_admin_pie();exit; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $id=(int)($_POST['id'] ?? 0);
        if (($_POST['accion'] ?? '')==='importar') {
            if (!isset($_POST['confirmar'])) throw new RuntimeException('Confirma que has verificado remitente, organizacion y contenido.');
            $destino=max(0,(int)($_POST['destino'] ?? 0));
            $ticket=gmail_importar($pdo,$id,(int)auth_usuario()['id'],$destino);$aviso=$destino ? 'Respuesta incorporada a la incidencia #' . $ticket . '.' : 'Incidencia #' . $ticket . ' creada y encolada para clasificacion IA.';
        } elseif (($_POST['accion'] ?? '')==='descartar') {
            $stmt=$pdo->prepare("UPDATE gmail_entradas SET estado='descartado',revisado_por=? WHERE id=? AND estado='pendiente'");$stmt->execute([auth_usuario()['id'],$id]);$aviso='Entrada descartada de la revision. El correo original se conserva en Gmail.';
            auditar($pdo,'descartar_gmail','entrada #' . $id);
        }
    } catch(Throwable $e) { $error=$e instanceof PDOException ? 'No se pudo guardar; no se ha importado la entrada.' : $e->getMessage(); }
}
$estado=in_array($_GET['estado'] ?? '',['importado','descartado'],true) ? $_GET['estado'] : 'pendiente';
$antes=max(0,(int)($_GET['antes'] ?? 0));
$stmt=$pdo->prepare('SELECT id,remitente,asunto,estado,incidencia_id,aviso FROM gmail_entradas WHERE estado=? AND (?=0 OR id<?) ORDER BY id DESC LIMIT 26');$stmt->execute([$estado,$antes,$antes]);$entradas=$stmt->fetchAll(PDO::FETCH_ASSOC);
$siguiente=0;if(count($entradas)>25){array_pop($entradas);$siguiente=(int)end($entradas)['id'];}
$detalle=null;$id=max(0,(int)($_GET['ver'] ?? 0));
if($id){$stmt=$pdo->prepare('SELECT * FROM gmail_entradas WHERE id=?');$stmt->execute([$id]);$detalle=$stmt->fetch(PDO::FETCH_ASSOC);}
$destino=0;$bloqueado=false;
if($detalle && $detalle['estado']==='pendiente') {
    try {$destino=gmail_destino($pdo,$detalle);} catch(RuntimeException $e) {$error=$e->getMessage();$bloqueado=true;}
}
ui_admin_cabecera('Correo entrante','Google Workspace / Gmail: revisar, validar y crear con ayuda de IA.','admin_correo_entrante.php');
?>
<?php if($aviso):?><p class="success-message"><?= ui_e($aviso) ?></p><?php endif; ?>
<?php if($error):?><p class="login-error"><?= ui_e($error) ?></p><?php endif; ?>
<section class="admin-panel"><h2><?= $diagnostico['completo'] ? 'Configuracion local completa' : 'Prepara la conexion con Gmail' ?></h2>
<p><a class="card-button" href="admin_integraciones.php?grupo=gmail">Configurar Gmail y comprobar conexion</a></p>
<p>Esta comprobacion no contacta con Google y no confirma que la autorizacion siga vigente. Nunca se muestran las credenciales.</p>
<?php if($diagnostico['faltan']):?><p>Configura en el archivo privado del servidor: <code><?= ui_e(implode(', ',$diagnostico['faltan'])) ?></code>.</p><?php endif;?>
<?php if($diagnostico['invalidos']):?><p>Revisa el formato de <code><?= ui_e(implode(', ',$diagnostico['invalidos'])) ?></code>. Usa una direccion de correo y el ID de etiqueta, no su nombre.</p><?php endif;?>
<?php if(!$diagnostico['curl']):?><p>Habilita la extension cURL en PHP antes de sincronizar.</p><?php endif;?>
<details><summary>Pasos para el administrador del servidor</summary><ol><li>Prepara OAuth de solo lectura y una etiqueta siguiendo <code>docs/GMAIL.md</code>. No uses la contrasena de Gmail.</li><li>Comprueba la configuracion con <code>php bin/sincronizar_gmail.php --comprobar</code>. No importa correos.</li><li>Con el consentimiento del propietario, ejecuta <code>php bin/sincronizar_gmail.php</code> y revisa el primer lote antes de programar ejecuciones.</li></ol><p>El PHP de consola y el del servidor web pueden tener configuraciones diferentes: comprueba ambos.</p></details></section>
<section class="admin-panel"><h2>Conexion de solo lectura</h2><p>Configura OAuth y una etiqueta exclusiva para soporte siguiendo <code>docs/GMAIL.md</code>. Ejecuta <code>php bin/sincronizar_gmail.php</code> para traer un lote de hasta 20 correos a revision. No se envian mensajes ni se marcan como leidos.</p><p>El remitente de un correo puede ser suplantado. Verifica identidad y organizacion antes de importar. La IA clasifica solo despues de tu aprobacion; no crea usuarios ni concede acceso.</p><nav class="page-tools"><a href="?estado=pendiente">Por revisar</a><a href="?estado=importado">Importados</a><a href="?estado=descartado">Descartados</a></nav></section>
<?php if($detalle): ?><section class="admin-panel"><h2><?= ui_e($detalle['asunto']) ?></h2><p>Remitente declarado: <?= ui_e($detalle['remitente'] ?: 'No identificado') ?></p><p><?= ui_e($detalle['aviso']) ?></p><div class="reusable-preview"><?= ui_e($detalle['cuerpo']) ?></div>
<?php if($detalle['estado']==='pendiente'):?>
<?php if($destino):?><p>Respuesta para <a href="ver_incidencia.php?id=<?= $destino ?>">incidencia #<?= $destino ?></a>. Sera visible para el cliente. Si estaba esperando al cliente, pasara a En trabajo. No se reabren incidencias resueltas o cerradas ni se modifica su clasificacion.</p><?php endif;?>
<form method="POST" class="form-stack"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>"><input type="hidden" name="destino" value="<?= $destino ?>"><label><input type="checkbox" name="confirmar"> He verificado la identidad del remitente, su organizacion y el contenido. <?= $destino ? 'Autorizo incorporar esta respuesta a la incidencia indicada.' : 'Autorizo crear la incidencia y clasificarla con el proveedor IA configurado.' ?></label><div class="page-tools"><button name="accion" value="importar" class="card-button" <?= $bloqueado ? 'disabled' : '' ?>><?= $destino ? 'Incorporar respuesta' : 'Crear incidencia con IA' ?></button><button name="accion" value="descartar" class="card-button secondary-button">Descartar de la revision</button></div></form><?php endif; ?></section><?php endif; ?>
<section class="admin-panel"><h2>Entradas <?= ui_e($estado) ?></h2><div class="table-scroll"><table class="logs-table"><thead><tr><th>Asunto</th><th>Remitente declarado</th><th>Incidencia</th></tr></thead><tbody><?php foreach($entradas as $e):?><tr><td><a href="?ver=<?= (int)$e['id'] ?>&amp;estado=<?= ui_e($estado) ?>"><?= ui_e($e['asunto']) ?></a></td><td><?= ui_e($e['remitente']) ?></td><td><?php if($e['incidencia_id']):?><a href="ver_incidencia.php?id=<?= (int)$e['incidencia_id'] ?>">#<?= (int)$e['incidencia_id'] ?></a><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if(!$entradas):?><p>No hay entradas. Si aun no has conectado Gmail, completa la autorizacion y ejecuta la sincronizacion.</p><?php endif;?><?php if($siguiente):?><a href="?estado=<?= ui_e($estado) ?>&amp;antes=<?= $siguiente ?>">Ver anteriores</a><?php endif;?></section>
<?php ui_admin_pie(); ?>
