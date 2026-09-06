<?php
// Acciones de cola sobre una seleccion limitada de incidencias.
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $id): bool => $id > 0)));
$ids = array_slice($ids, 0, 100);
$accion = trim((string)($_POST['accion_masiva'] ?? ''));

if (empty($ids) || $accion === '') {
    header('Location: index.php?masivo=sin_seleccion');
    exit;
}

$pdo->beginTransaction();
try {
    if (str_starts_with($accion, 'estado:')) {
        $estado = substr($accion, 7);
        if (!in_array($estado, ['abierta','en_curso'], true)) {
            throw new InvalidArgumentException('Estado no valido');
        }
        foreach ($ids as $id) {
            incidencia_cambiar_estado($pdo, $id, $estado);
        }
        $detalle = "estado $estado";
    } elseif (str_starts_with($accion, 'asignar:')) {
        $asignadoRaw = substr($accion, 8);
        $asignado = $asignadoRaw === '' ? null : (int)$asignadoRaw;
        $validos = array_map(static fn(array $u): int => (int)$u['id'], usuarios_asignables($pdo));
        if ($asignado !== null && !in_array($asignado, $validos, true)) {
            throw new InvalidArgumentException('Asignacion no valida');
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE incidencias SET asignado_id = ? WHERE id IN ($marcas)");
        $stmt->execute(array_merge([$asignado], $ids));
        $detalle = $asignado === null ? 'sin asignar' : "asignadas a usuario #$asignado";
    } else {
        throw new InvalidArgumentException('Accion no valida');
    }

    auditar($pdo, 'accion_masiva', count($ids) . " incidencias: $detalle");
    $pdo->commit();
    header('Location: index.php?masivo=' . count($ids));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TicketIA accion masiva: ' . $e->getMessage());
    header('Location: index.php?masivo=error');
}
exit;
