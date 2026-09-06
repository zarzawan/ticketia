<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin','operador');
require_once __DIR__ . '/../src/respuestas.php';
$puede_editar = auth_es('admin');
$disponible = respuestas_esquema_disponible($pdo);
$error = ''; $edicion = null;
$clave = (string)($_GET['editar'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_requerir_rol('admin');
    $clave = (string)($_POST['clave'] ?? '');
    $edicion = ['titulo'=>(string)($_POST['titulo'] ?? ''),'contenido'=>(string)($_POST['contenido'] ?? ''),
        'activo'=>isset($_POST['activo']) ? 1 : 0,'version'=>(int)($_POST['version'] ?? -1)];
    try {
        if (!$disponible) throw new RuntimeException('Aplica la migracion 17 para guardar cambios. Las cinco respuestas base ya se pueden consultar y utilizar.');
        if (!respuestas_guardar($pdo,$clave,$edicion['titulo'],$edicion['contenido'],$edicion['version'],(bool)$edicion['activo'])) {
            http_response_code(409); throw new RuntimeException('Otra persona ha editado esta respuesta. Tu texto se conserva debajo; copia lo que necesites y recarga la version actual antes de guardar.');
        }
        auditar($pdo,'editar_respuesta_reutilizable',$clave);
        header('Location: respuestas.php?guardada=1'); exit;
    } catch (InvalidArgumentException|RuntimeException $e) { $error = $e instanceof PDOException ? 'No se pudo guardar la respuesta. Tu texto se conserva; vuelve a intentarlo.' : $e->getMessage(); }
}
$respuestas = respuestas_listar($pdo, $puede_editar);
if ($puede_editar && isset($respuestas[$clave]) && $edicion === null) $edicion = $respuestas[$clave];
ui_admin_cabecera('Respuestas reutilizables','Consulta el texto completo y adaptalo antes de enviarlo.','respuestas.php');
?>
<?php if (!$puede_editar): ?><p><a href="index.php">Volver a soporte</a>. Puedes consultar las respuestas; un administrador puede editarlas.</p><?php endif; ?>
<?php if (isset($_GET['guardada'])): ?><div class="success-message">Respuesta guardada. Estara disponible al abrir de nuevo el editor de una incidencia.</div><?php endif; ?>
<?php if ($error): ?><div class="login-error"><?= ui_e($error) ?></div><?php endif; ?>
<?php if (!$disponible && $puede_editar): ?><p class="alert alert-warning">La edicion requiere aplicar la migracion 17. Las cinco respuestas base ya se pueden consultar e insertar.</p><?php endif; ?>
<?php if ($puede_editar && $edicion && isset($respuestas[$clave])): ?>
<section class="admin-panel"><h2>Editar respuesta compartida</h2><p>El cambio afecta a todo el equipo. No incluyas datos de clientes, contrasenas ni compromisos que no puedas cumplir.</p>
<form method="POST" class="form-stack" id="editarRespuestaCompartida"><?= csrf_campo() ?><input type="hidden" name="clave" value="<?= ui_e($clave) ?>"><input type="hidden" name="version" value="<?= (int)$edicion['version'] ?>">
<label>Titulo<input name="titulo" maxlength="120" required value="<?= ui_e($edicion['titulo']) ?>"></label>
<label>Texto completo<textarea name="contenido" rows="10" maxlength="10000" required><?= ui_e($edicion['contenido']) ?></textarea></label>
<label><input type="checkbox" name="activo" <?= $edicion['activo'] ? 'checked' : '' ?>> Disponible para el equipo</label>
<div class="page-tools"><button class="card-button" <?= !$disponible ? 'disabled' : '' ?>>Guardar cambios</button><a href="respuestas.php">Volver al listado</a><a href="respuestas.php?editar=<?= ui_e($clave) ?>">Recargar version actual</a></div></form></section>
<section class="admin-panel" data-ia-operativa="respuesta" data-csrf="<?= ui_e(csrf_token()) ?>"><h2>Mejorar con IA</h2><p>Se enviaran el titulo y texto del editor al proveedor IA configurado. Elimina datos personales o secretos antes de consultar. La propuesta no se guarda sin tu revision.</p><button type="button" class="card-button secondary-button" data-generar>Proponer mejor redaccion</button><p class="reusable-preview" role="status" data-salida>La version original se mantiene hasta que aceptes una propuesta.</p><button type="button" class="card-button" data-aceptar hidden>Usar propuesta en el editor</button></section>
<script src="ia_operativa.js?v=<?= filemtime(__DIR__ . '/ia_operativa.js') ?>" defer></script>
<?php endif; ?>
<?php foreach ($respuestas as $id_respuesta=>$respuesta): ?>
<section class="admin-panel"><div class="section-head"><h2><?= ui_e($respuesta['titulo']) ?></h2><?php if ($puede_editar): ?><a href="respuestas.php?editar=<?= ui_e($id_respuesta) ?>" class="card-button secondary-button">Editar</a><?php endif; ?></div>
<?php if (!$respuesta['activo']): ?><p>Desactivada: no aparece en el editor del equipo.</p><?php endif; ?>
<p class="reusable-preview"><?= ui_e($respuesta['contenido']) ?></p></section>
<?php endforeach; ?>
<?php if (!$respuestas): ?><section class="admin-panel"><p>No hay respuestas activas. Solicita a un administrador que active una.</p></section><?php endif; ?>
<?php ui_admin_pie(); ?>
