<?php
require_once __DIR__ . '/../src/arranque.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Obtener la incidencia
$sql = "SELECT * FROM incidencias WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id]);
$incidencia = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$incidencia) {
    http_response_code(404);
    echo json_encode(["error" => "Incidencia no encontrada."]);
    exit;
}

// Obtener mensajes
$sql_mensajes = "SELECT autor, mensaje, fecha FROM mensajes WHERE id_incidencia = :id ORDER BY fecha ASC";
$stmt_mensajes = $pdo->prepare($sql_mensajes);
$stmt_mensajes->execute([':id' => $id]);
$mensajes = $stmt_mensajes->fetchAll(PDO::FETCH_ASSOC);

// Obtener reaperturas
$sql_reap = "SELECT motivo, fecha FROM reaperturas WHERE id_incidencia = :id ORDER BY fecha ASC";
$stmt_reap = $pdo->prepare($sql_reap);
$stmt_reap->execute([':id' => $id]);
$reaperturas = $stmt_reap->fetchAll(PDO::FETCH_ASSOC);

// Construir el array JSON
$resultado = [
    "incidencia" => [
        "id" => $incidencia['id'],
        "titulo" => $incidencia['titulo'],
        "descripcion" => $incidencia['descripcion'],
        "estado" => $incidencia['estado'],
        "fecha_creacion" => $incidencia['fecha_creacion'],
        "fecha_cierre" => $incidencia['fecha_cierre'],
        "mensajes" => $mensajes,
        "reaperturas" => $reaperturas
    ]
];

// Enviar cabecera JSON
header('Content-Type: application/json');

// Mostrar
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
