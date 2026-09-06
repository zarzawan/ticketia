<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin');
ui_admin_cabecera('Equipos y reparto', 'Reglas explicables. Ninguna se activa sin una simulacion previa.', 'admin_reglas.php');
if (!reglas_disponibles($pdo)) {
    echo '<section class="admin-panel"><h2>Prepara tu equipo de soporte</h2><p>Esta instalacion necesita la migracion 14 de equipos y reparto. No se ha creado ni modificado ningun equipo.</p><ol><li>Realiza una copia de la base de datos.</li><li>Aplica las migraciones pendientes con <code>php vendor/bin/phinx migrate -c phinx.php</code> desde la carpeta del proyecto.</li><li>Vuelve aqui para crear un equipo, simular una regla y activarla cuando la hayas revisado.</li></ol><p><a href="admin_operacion.php">Comprobar estado de la instalacion</a> · <a href="admin_usuarios.php">Revisar tecnicos disponibles</a></p></section>';
    ui_admin_pie(); exit;
}
$aviso = ''; $error = ''; $simulacion = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion = (string)($_POST['accion'] ?? '');
        if ($accion === 'equipo') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $miembros = array_values(array_unique(array_map('intval', (array)($_POST['miembros'] ?? []))));
            $validos = array_column(usuarios_asignables($pdo), 'id');
            if ($nombre === '' || mb_strlen($nombre) > 100 || !$miembros || array_diff($miembros, $validos)) throw new RuntimeException('Revisa el nombre y selecciona tecnicos activos.');
            $pdo->beginTransaction();
            $idEquipo = (int)($_POST['equipo_id'] ?? 0);
            if ($idEquipo) {
                $pdo->prepare('UPDATE equipos_soporte SET nombre = ? WHERE id = ?')->execute([$nombre, $idEquipo]);
                $pdo->prepare('DELETE FROM equipos_miembros WHERE equipo_id = ?')->execute([$idEquipo]);
            } else {
                $pdo->prepare('INSERT INTO equipos_soporte (nombre) VALUES (?)')->execute([$nombre]);
                $idEquipo = (int)$pdo->lastInsertId();
            }
            foreach ($miembros as $miembro) $pdo->prepare('INSERT INTO equipos_miembros VALUES (?,?)')->execute([$idEquipo, $miembro]);
            $pdo->commit(); $aviso = 'Equipo guardado.';
        } elseif ($accion === 'crear') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $tipo = trim((string)($_POST['tipo'] ?? ''));
            if ($nombre === '' || mb_strlen($nombre) > 100 || mb_strlen($tipo) > 100) throw new RuntimeException('Nombre o departamento no valido.');
            if ((int)$pdo->query('SELECT COUNT(*) FROM reglas_asignacion')->fetchColumn() >= 100) throw new RuntimeException('Maximo 100 reglas.');
            $pdo->prepare('INSERT INTO reglas_asignacion (nombre,equipo_id,cliente_id,tipo,prioridad) VALUES (?,?,?,?,?)')
                ->execute([$nombre, (int)$_POST['equipo_id'], (int)($_POST['cliente_id'] ?? 0) ?: null, $tipo, max(1,min(999,(int)($_POST['prioridad'] ?? 100)))]);
            $aviso = 'Regla creada desactivada. Simulala antes de activarla.';
        } elseif (in_array($accion, ['simular','activar','desactivar'], true)) {
            $stmt = $pdo->prepare('SELECT * FROM reglas_asignacion WHERE id = ?'); $stmt->execute([(int)($_POST['id'] ?? 0)]);
            $regla = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$regla) throw new RuntimeException('Regla no encontrada.');
            $huella = hash('sha256', json_encode($regla));
            if ($accion === 'simular') {
                $stmt = $pdo->prepare("SELECT id,titulo FROM incidencias WHERE estado IN ('abierta','en_curso') AND asignado_id IS NULL
                    AND (:todos = 1 OR cliente_id = :cliente) AND (:tipos = 1 OR tipo = :tipo) ORDER BY id DESC LIMIT 21");
                $stmt->execute([':todos' => empty($regla['cliente_id']) ? 1 : 0, ':cliente' => $regla['cliente_id'], ':tipos' => $regla['tipo'] === '' ? 1 : 0, ':tipo' => $regla['tipo']]);
                $simulacion = ['tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'tecnico' => reglas_candidato($pdo, (int)$regla['equipo_id'])];
                $_SESSION['regla_simulada'][$regla['id']] = ['huella' => $huella, 'hasta' => time() + 600];
            } else {
                $previa = $_SESSION['regla_simulada'][$regla['id']] ?? [];
                if ($accion === 'activar' && (($previa['huella'] ?? '') !== $huella || ($previa['hasta'] ?? 0) < time()
                    || reglas_candidato($pdo, (int)$regla['equipo_id']) === null)) throw new RuntimeException('Simula la regla y comprueba que el equipo tiene miembros activos antes de activarla.');
                $pdo->prepare('UPDATE reglas_asignacion SET activo = ? WHERE id = ?')->execute([(int)($accion === 'activar'), $regla['id']]);
                unset($_SESSION['regla_simulada'][$regla['id']]);
                $aviso = 'Regla actualizada. Se aplicara a nuevas clasificaciones, sin reasignar trabajo existente.';
            }
        }
        auditar($pdo, 'configurar_reparto', $accion);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof PDOException ? 'No se pudo guardar. Revisa referencias y nombres duplicados.' : $e->getMessage();
    }
}
$equipos = $pdo->query('SELECT * FROM equipos_soporte ORDER BY nombre LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$reglas = $pdo->query('SELECT r.*, e.nombre AS equipo FROM reglas_asignacion r JOIN equipos_soporte e ON e.id=r.equipo_id ORDER BY r.prioridad,r.id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$editar = (int)($_GET['equipo'] ?? 0);
$equipoEditar = null; foreach ($equipos as $equipo) if ((int)$equipo['id'] === $editar) $equipoEditar = $equipo;
$stmt = $pdo->prepare('SELECT usuario_id FROM equipos_miembros WHERE equipo_id=?'); $stmt->execute([$editar]); $miembros = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<section class="admin-panel"><h2><?= !$equipos ? 'Empieza creando tu primer equipo' : 'Reparto del trabajo' ?></h2><p>1. Agrupa a los tecnicos. 2. Indica que incidencias recibira cada equipo. 3. Simula y activa la regla. La asignacion manual siempre se respeta.</p><p><?= count($equipos) ?> equipos · <?= count($reglas) ?> reglas · <?= count(array_filter($reglas,static fn(array $r):bool=>(bool)$r['activo'])) ?> activas</p><?php if (!$equipos): ?><p>Todavia no hay equipos. Abre «Crear equipo», ponle un nombre y selecciona al menos un tecnico. <a href="admin_usuarios.php">Gestionar tecnicos</a>.</p><?php elseif (!$reglas): ?><p>El equipo esta preparado. Crea una primera regla y comprueba su resultado con «Simular» antes de activarla.</p><?php endif; ?></section>
<section class="admin-panel" data-ia-operativa="reparto" data-csrf="<?= ui_e(csrf_token()) ?>"><h2>Ayuda de IA para organizar el soporte</h2><p>La IA consulta volumen agregado por departamento y cantidad de tecnicos, equipos y reglas; no recibe nombres ni conversaciones. Propone una organizacion para revisar, no crea ni activa reglas.</p><button type="button" class="card-button secondary-button" data-generar>Proponer organizacion con IA</button><p class="reusable-preview" data-salida role="status">La propuesta aparecera aqui. Tu decides como repartir el trabajo.</p></section>
<script src="ia_operativa.js?v=<?= filemtime(__DIR__ . '/ia_operativa.js') ?>" defer></script>
<?php if ($aviso): ?><p class="success-message"><?= ui_e($aviso) ?></p><?php endif; ?>
<?php if ($error): ?><p class="login-error" role="alert"><?= ui_e($error) ?></p><?php endif; ?>
<?php if ($simulacion): ?><section class="admin-panel"><h2>Simulacion sin cambios</h2><p>Esta regla aislada coincide con <?= count($simulacion['tickets']) > 20 ? 'mas de 20' : count($simulacion['tickets']) ?> incidencias sin asignar. Tecnico con menor carga ahora: <?= $simulacion['tecnico'] ? '#' . (int)$simulacion['tecnico'] : 'ninguno disponible' ?>.</p><p>En ejecucion se respeta primero la prioridad de las reglas activas y se recalcula la carga. Esta muestra no reasigna incidencias.</p><?php foreach (array_slice($simulacion['tickets'],0,20) as $ticket): ?><p><a href="ver_incidencia.php?id=<?= (int)$ticket['id'] ?>">#<?= (int)$ticket['id'] ?> <?= ui_e($ticket['titulo']) ?></a></p><?php endforeach; ?></section><?php endif; ?>
<section class="admin-panel"><h2>Equipos</h2><p><?php foreach ($equipos as $equipo): ?><a href="?equipo=<?= (int)$equipo['id'] ?>"><?= ui_e($equipo['nombre']) ?></a> · <?php endforeach; ?></p><details <?= $equipoEditar ? 'open' : '' ?>><summary><?= $equipoEditar ? 'Editar equipo' : 'Crear equipo' ?></summary><form method="POST" class="form-stack"><?= csrf_campo() ?><input type="hidden" name="accion" value="equipo"><input type="hidden" name="equipo_id" value="<?= $equipoEditar ? (int)$equipoEditar['id'] : 0 ?>"><label>Nombre<input name="nombre" maxlength="100" value="<?= ui_e($equipoEditar['nombre'] ?? '') ?>" required></label><fieldset><legend>Tecnicos</legend><?php foreach (usuarios_asignables($pdo) as $u): ?><label><input type="checkbox" name="miembros[]" value="<?= (int)$u['id'] ?>" <?= in_array($u['id'],$miembros) ? 'checked' : '' ?>> <?= ui_e($u['nombre']) ?></label><?php endforeach; ?></fieldset><button class="card-button">Guardar equipo</button></form></details></section>
<section class="admin-panel"><h2>Reglas de reparto</h2><p>La prioridad menor se evalua primero. Se asigna al miembro activo con menos incidencias abiertas.</p><details><summary>Crear regla</summary><form method="POST" class="form-stack"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear"><label>Nombre<input name="nombre" maxlength="100" required></label><label>Equipo<select name="equipo_id" required><?php foreach ($equipos as $equipo): ?><option value="<?= (int)$equipo['id'] ?>"><?= ui_e($equipo['nombre']) ?></option><?php endforeach; ?></select></label><label>Departamento exacto (vacio: todos)<input name="tipo" maxlength="100"></label><label>ID de empresa (vacio: todas)<input name="cliente_id" type="number" min="1"></label><label>Prioridad<input name="prioridad" type="number" min="1" max="999" value="100"></label><button class="card-button">Crear desactivada</button></form></details>
<?php foreach ($reglas as $regla): ?><article class="admin-panel"><h3><?= ui_e($regla['nombre']) ?> · <?= $regla['activo'] ? 'Activa' : 'Desactivada' ?></h3><p><?= ui_e($regla['equipo']) ?> · <?= ui_e($regla['tipo'] ?: 'Todos los departamentos') ?> · prioridad <?= (int)$regla['prioridad'] ?></p><form method="POST" class="page-tools"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$regla['id'] ?>"><button class="card-button secondary-button" name="accion" value="simular">Simular</button><button class="card-button" name="accion" value="<?= $regla['activo'] ? 'desactivar' : 'activar' ?>"><?= $regla['activo'] ? 'Desactivar' : 'Activar tras simulacion' ?></button></form></article><?php endforeach; ?></section>
<?php ui_admin_pie(); ?>
