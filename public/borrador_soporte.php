<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin', 'operador');
header('Content-Type: application/json; charset=UTF-8');
$id = (int)($_POST['id_incidencia'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT estado FROM incidencias WHERE id = :id');
$stmt->execute([':id' => $id]);
$estado = $stmt->fetchColumn();
if ($estado === false) { http_response_code(404); echo json_encode(['ok' => false]); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'huella' => soporte_huella($pdo, $id)]); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!soporte_esquema_disponible($pdo)) { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'Aplica la migracion de operacion para guardar borradores.']); exit; }
$mensaje = (string)($_POST['mensaje'] ?? '');
$accion = (string)($_POST['accion_respuesta'] ?? 'responder');
$version = (int)($_POST['borrador_version'] ?? 0);
$usuario = (int)auth_usuario()['id'];
if (mb_strlen($mensaje) > 30000 || !in_array($accion, ['responder','nota','resolver'], true)) { http_response_code(400); exit; }
try {
    if ($version === 0) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO soporte_borradores (usuario_id, incidencia_id, mensaje, accion) VALUES (:usuario, :id, :mensaje, :accion)');
        $stmt->execute([':usuario' => $usuario, ':id' => $id, ':mensaje' => $mensaje, ':accion' => $accion]);
    } else {
        $stmt = $pdo->prepare('UPDATE soporte_borradores SET mensaje = :mensaje, accion = :accion, version = version + 1
            WHERE usuario_id = :usuario AND incidencia_id = :id AND version = :version');
        $stmt->execute([':mensaje' => $mensaje, ':accion' => $accion, ':usuario' => $usuario, ':id' => $id, ':version' => $version]);
    }
    if ($stmt->rowCount() !== 1) { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'Hay otro borrador guardado en otra pestana. Copia tu texto antes de recargar.']); exit; }
    echo json_encode(['ok' => true, 'version' => $version + 1]);
} catch (PDOException $e) { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el borrador. Conserva esta ventana abierta.']); }
