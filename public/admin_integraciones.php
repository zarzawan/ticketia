<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin');
header('Cache-Control: no-store');
$grupos=['gmail'=>'Gmail / Workspace','openai'=>'OpenAI','xai'=>'xAI','local'=>'IA local'];
$grupo=(string)($_GET['grupo'] ?? 'gmail');if(!isset($grupos[$grupo]))$grupo='gmail';
$error='';$aviso=(string)($_SESSION['integraciones_aviso'] ?? '');unset($_SESSION['integraciones_aviso']);
$seguro=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true);
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!$seguro)throw new RuntimeException('Usa HTTPS antes de introducir credenciales. HTTP solo se permite desde el propio servidor.');
        if(time()<(int)($_SESSION['integraciones_bloqueo'] ?? 0))throw new RuntimeException('Espera unos segundos antes de volver a intentarlo.');
        $stmt=$pdo->prepare('SELECT hash_password FROM usuarios WHERE id=? AND activo=1 AND rol=\'admin\'');$stmt->execute([auth_usuario()['id']]);
        $password=$_POST['password_actual'] ?? '';
        if(!is_string($password) || !password_verify($password,(string)$stmt->fetchColumn())) {
            $_SESSION['integraciones_bloqueo']=time()+10;
            throw new RuntimeException('Confirma tu contrasena de administrador.');
        }
        $accion=(string)($_POST['accion'] ?? '');$version=(string)($_POST['version'] ?? '');
        if(!hash_equals(integraciones_version($pdo,$grupo),$version))throw new RuntimeException('Otro administrador ha cambiado este apartado. Recarga antes de continuar.');
        if($accion==='restablecer') {
            if(!isset($_POST['confirmar_restablecer']))throw new RuntimeException('Confirma que quieres eliminar los datos guardados en este apartado y volver a la configuracion del servidor.');
            integraciones_guardar($pdo,$grupo,null,$version);
            $_SESSION['integraciones_aviso']='Datos del panel eliminados. Vuelve a utilizarse la configuracion del servidor, si existe. Esto no revoca credenciales en el proveedor.';
        } elseif(in_array($accion,['guardar','probar'],true)) {
            $valores=integraciones_validar($grupo,$_POST,static fn($clave)=>entorno_valor($clave,''));
            if($accion==='guardar') {
                integraciones_guardar($pdo,$grupo,$valores,$version);
                $_SESSION['integraciones_aviso']='Configuracion guardada cifrada. Se aplicara en las siguientes peticiones; reinicia los workers persistentes si los utilizas. Guardar no comprueba la conexion ni cambia la IA activa.';
            } else {
                if(time()<(int)($_SESSION['integraciones_prueba_hasta'] ?? 0))throw new RuntimeException('Espera 15 segundos entre pruebas de conexion.');
                $_SESSION['integraciones_prueba_hasta']=time()+15;
                try {$aviso=integraciones_probar($grupo,$valores,'integraciones_http');auditar($pdo,'probar_conexion',$grupo . ': ok');}
                catch(Throwable $e){auditar($pdo,'probar_conexion',$grupo . ': fallo');throw $e;}
                $aviso.=' Se han probado los datos del formulario, pero no se han guardado. Si has introducido secretos nuevos, vuelve a escribirlos para guardar.';
            }
        } else throw new RuntimeException('Accion no valida.');
        if($accion!=='probar'){header('Location: admin_integraciones.php?grupo=' . $grupo);exit;}
    } catch(Throwable $e) {$error=$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'No se pudo completar la operacion. No se muestran detalles para proteger las credenciales.';}
}
$defectos=['OPENAI_MODEL'=>'gpt-5','OPENAI_MODEL_STREAM'=>'gpt-4.1-mini','XAI_MODEL'=>'grok-3','XAI_MODEL_STREAM'=>'grok-3-mini-beta'];
ui_admin_cabecera('Conexiones y claves','Configura el correo y los proveedores de IA sin editar sus secretos en archivos.','admin_integraciones.php');
?>
<?php if($error):?><p class="login-error" role="alert"><?= ui_e($error) ?></p><?php endif;?>
<?php if($aviso):?><p class="success-message" role="status"><?= ui_e($aviso) ?></p><?php endif;?>
<section class="admin-panel conexion-panel"><nav class="service-subnav" aria-label="Proveedor de conexion"><?php foreach($grupos as $id=>$nombre):?><a href="?grupo=<?= $id ?>" <?= $id===$grupo ? 'aria-current="page"' : '' ?>><?= ui_e($nombre) ?></a><?php endforeach;?></nav>
<h2><?= ui_e($grupos[$grupo]) ?></h2>
<?php if(!$seguro):?><p class="login-error">Abre esta pagina con HTTPS antes de introducir credenciales.</p><?php endif;?>
<?php if(!cuenta_app_key_disponible()):?><p class="login-error">El servidor necesita APP_KEY de al menos 32 caracteres aleatorios para guardar credenciales cifradas. Configurala una sola vez en el entorno privado; no cambies una clave existente. Consulta <code>docs/INTEGRACIONES.md</code>.</p><?php endif;?>
<?php if(isset($GLOBALS['integraciones_errores'][$grupo])):?><p class="login-error">No se pueden descifrar los datos guardados. Restaura APP_KEY o usa Restablecer y vuelve a introducir todos los datos. No se utilizan credenciales antiguas automaticamente.</p><?php endif;?>
<?php if($grupo==='gmail'):?><p>Introduce las credenciales OAuth del buzon de soporte, no la contrasena de Gmail. Necesitas autorizacion de solo lectura, un refresh token y el ID de la etiqueta. <a href="admin_correo_entrante.php">Abrir bandeja de revision</a>.</p><p>Comprobar conexion consulta el perfil y la etiqueta; no importa ni modifica correos. Preparacion OAuth: <code>docs/GMAIL.md</code>.</p>
<?php elseif($grupo==='local'):?><p>El endpoint local se mantiene en la configuracion del servidor. <a href="admin_ajustes.php">Prueba la IA activa desde Configuracion</a>.</p>
<?php else:?><p>Comprobar conexion valida acceso a los modelos sin generar texto. No comprueba saldo, calidad de respuesta ni streaming. <a href="admin_ajustes.php">Seleccionar y probar la IA activa</a>.</p><?php endif;?>
<?php if($grupo!=='gmail' && entorno_valor('LLM_SOLO_LOCAL','')==='1'):?><p>Modo solo local activo en el servidor: las claves externas pueden guardarse, pero no se utilizan ni se prueban.</p><?php endif;?>
<p>Los campos secretos siempre aparecen vacios. Dejalos vacios para conservarlos; nunca se envian al navegador. Los datos guardados aqui tienen prioridad sobre el entorno del servidor.</p>
<form method="POST" class="form-stack" autocomplete="off"><?= csrf_campo() ?><input type="hidden" name="version" value="<?= ui_e(integraciones_version($pdo,$grupo)) ?>">
<fieldset class="conexion-campos" <?= !$seguro ? 'disabled' : '' ?>><legend>Datos de conexion</legend>
<?php foreach(integraciones_campos()[$grupo] as $clave=>$etiqueta): $secreto=integraciones_es_secreto($clave);$valor=(string)entorno_valor($clave,$defectos[$clave] ?? ''); if(!$secreto && isset($_POST[$clave]) && is_string($_POST[$clave]))$valor=mb_substr($_POST[$clave],0,254); ?>
<label for="<?= $clave ?>"><?= ui_e($etiqueta) ?><?php if($secreto):?> — <?= $valor!=='' ? 'Configurado; vacio conserva el valor' : 'Sin configurar' ?><?php endif;?></label>
<input id="<?= $clave ?>" name="<?= $clave ?>" type="<?= $secreto ? 'password' : ($clave==='GMAIL_BUZON' ? 'email' : 'text') ?>" value="<?= $secreto ? '' : ui_e($valor) ?>" maxlength="<?= $secreto ? 4096 : 254 ?>" autocomplete="<?= $secreto ? 'new-password' : 'off' ?>" spellcheck="false">
<?php endforeach;?>
<label for="password_actual">Tu contrasena de administrador para confirmar</label><input type="password" id="password_actual" name="password_actual" autocomplete="current-password" required>
<div class="page-tools"><button class="card-button" name="accion" value="guardar">Guardar datos</button><?php if($grupo!=='local'):?><button class="card-button secondary-button" name="accion" value="probar">Comprobar conexion sin guardar</button><?php endif;?></div>
<details><summary>Restablecer este apartado</summary><p>Elimina solo la configuracion guardada en el panel. Se volvera a usar el entorno del servidor; no revoca tokens en Google ni en los proveedores IA.</p><label><input type="checkbox" name="confirmar_restablecer"> Confirmo que quiero restablecer este apartado.</label><button class="card-button secondary-button" name="accion" value="restablecer">Restablecer</button></details>
</fieldset></form></section>
<?php ui_admin_pie(); ?>
