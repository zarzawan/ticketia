<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin');
$config = $GLOBALS['ticketia_calendario'] ?? ['activo'=>false,'inicio'=>'09:00','fin'=>'17:00','dias'=>[1,2,3,4,5],'festivos'=>[]];
$error = ''; $aviso = ''; $simulado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = ['activo'=>isset($_POST['activo']), 'inicio'=>(string)($_POST['inicio'] ?? ''), 'fin'=>(string)($_POST['fin'] ?? ''),
        'dias'=>array_map('intval',(array)($_POST['dias'] ?? [])), 'festivos'=>array_values(array_filter(array_map('trim',explode("\n",(string)($_POST['festivos'] ?? '')))))];
    try {
        if (!calendario_validar($config)) throw new RuntimeException('Revisa el horario, los dias y los festivos (AAAA-MM-DD).');
        $huella = hash('sha256', json_encode($config));
        if (($_POST['accion'] ?? '') === 'simular') {
            $fecha = (string)($_POST['fecha'] ?? '');
            if (!preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$fecha)) throw new RuntimeException('Selecciona una fecha de prueba entre 2000 y 2099.');
            $horas = max(1,min(8760,(int)($_POST['horas'] ?? 4)));
            $simulado = calendario_limite(calendario_civil($fecha), $horas, $config['activo'] ? $config : [])->format('d/m/Y H:i');
            $_SESSION['calendario_simulado'] = ['huella'=>$huella,'hasta'=>time()+600];
        } elseif (($_POST['accion'] ?? '') === 'guardar') {
            $previo = $_SESSION['calendario_simulado'] ?? [];
            if (!isset($_POST['confirmar']) || ($previo['huella'] ?? '') !== $huella || ($previo['hasta'] ?? 0) < time()) throw new RuntimeException('Simula primero esta configuracion y confirma su efecto sobre las incidencias existentes.');
            calendario_guardar($pdo,$config);
            unset($_SESSION['calendario_simulado']);
            auditar($pdo,'calendario_servicio','Horario de servicio actualizado');
            $aviso = 'Calendario guardado. Los plazos se calculan con la misma jornada en la bandeja y en el detalle.';
        }
    } catch (Throwable $e) { $error = $e instanceof PDOException ? 'Aplica la migracion del calendario antes de guardarlo.' : $e->getMessage(); }
}
ui_admin_cabecera('Horario de servicio','Un calendario comun para primera respuesta y resolucion.','admin_calendario.php');
?>
<?php if ($error): ?><p class="login-error"><?= ui_e($error) ?></p><?php endif; ?>
<?php if ($aviso): ?><p class="success-message"><?= ui_e($aviso) ?></p><?php endif; ?>
<?php if ($simulado): ?><p class="success-message">Vencimiento simulado: <?= ui_e($simulado) ?>. No se han cambiado incidencias.</p><?php endif; ?>
<section class="admin-panel"><h2>Jornada y festivos</h2><p>Las fechas historicas se interpretan como hora civil del servicio, sin convertirlas silenciosamente de zona. Configura PHP y la base de datos con la misma hora local. El modo laboral excluye horas fuera de jornada y festivos; no pausa por esperar una respuesta del cliente.</p>
<form method="POST" class="form-stack"><?= csrf_campo() ?><label><input type="checkbox" name="activo" <?= $config['activo'] ? 'checked' : '' ?>> Medir SLA en horas laborales (desmarcado: horas naturales)</label>
<div class="page-tools"><label>Apertura<input type="time" name="inicio" value="<?= ui_e($config['inicio']) ?>" required></label><label>Cierre<input type="time" name="fin" value="<?= ui_e($config['fin']) ?>" required></label></div>
<fieldset><legend>Dias de servicio</legend><?php foreach ([1=>'Lunes',2=>'Martes',3=>'Miercoles',4=>'Jueves',5=>'Viernes',6=>'Sabado',7=>'Domingo'] as $n=>$nombre): ?><label><input type="checkbox" name="dias[]" value="<?= $n ?>" <?= in_array($n,$config['dias'],true) ? 'checked' : '' ?>> <?= $nombre ?></label><?php endforeach; ?></fieldset>
<label>Festivos, uno por linea (AAAA-MM-DD)<textarea name="festivos" rows="5" maxlength="6000"><?= ui_e(implode("\n",$config['festivos'])) ?></textarea></label>
<h3>Simular antes de guardar</h3><div class="page-tools"><label>Inicio de prueba<input type="datetime-local" name="fecha" value="<?= ui_e((string)($_POST['fecha'] ?? date('Y-m-d\TH:i'))) ?>"></label><label>Objetivo (horas)<input type="number" name="horas" min="1" max="8760" value="<?= max(1,min(8760,(int)($_POST['horas'] ?? 4))) ?>"></label></div>
<button name="accion" value="simular" class="card-button secondary-button">Simular sin guardar</button>
<label><input type="checkbox" name="confirmar"> Entiendo que este calendario recalcula los indicadores de las incidencias existentes, incluidas las historicas.</label><button name="accion" value="guardar" class="card-button">Guardar calendario simulado</button></form></section>
<?php ui_admin_pie(); ?>
