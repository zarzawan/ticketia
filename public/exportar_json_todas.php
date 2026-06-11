<?php
require_once __DIR__ . '/../src/arranque.php';

// Obtener todas las incidencias ordenadas por estado y fecha
$sql = "SELECT * FROM incidencias ORDER BY FIELD(estado, 'abierta', 'en_curso', 'cerrada'), fecha_creacion ASC";
$stmt = $pdo->query($sql);
$incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resultado = [];
$resumen = [
    'total' => count($incidencias),
    'abierta' => 0,
    'en_curso' => 0,
    'cerrada' => 0,
    'criticas' => 0,
    'sin_respuesta' => 0
];

foreach ($incidencias as $incidencia) {
    $id = $incidencia['id'];
    $dias = (new DateTime($incidencia['fecha_creacion']))->diff(new DateTime())->days;

    // Cargar mensajes
    $sql_mensajes = "SELECT autor, mensaje, fecha FROM mensajes WHERE id_incidencia = :id ORDER BY fecha ASC";
    $stmt_mensajes = $pdo->prepare($sql_mensajes);
    $stmt_mensajes->execute([':id' => $id]);
    $mensajes = $stmt_mensajes->fetchAll(PDO::FETCH_ASSOC);

    // Cargar reaperturas
    $sql_reap = "SELECT motivo, fecha FROM reaperturas WHERE id_incidencia = :id ORDER BY fecha ASC";
    $stmt_reap = $pdo->prepare($sql_reap);
    $stmt_reap->execute([':id' => $id]);
    $reaperturas = $stmt_reap->fetchAll(PDO::FETCH_ASSOC);

    // Detección de riesgo
    $riesgo = '';
    if (preg_match('/urgente|bloquea|no funciona|crítico|cliente enfadado|inaccesible|pistola|ransomware|ataque|empresa parada|servidor.*caído/i', $incidencia['descripcion'])) {
        $riesgo = '🔥 POSIBLE INCIDENTE CRÍTICO';
        $resumen['criticas']++;
    }

    // Días sin respuesta
    $dias_sin_respuesta = null;
    if ($mensajes) {
        $fecha_ultimo = end($mensajes)['fecha'];
        $dias_sin_respuesta = (new DateTime($fecha_ultimo))->diff(new DateTime())->days;
        if ($dias_sin_respuesta > 5) {
            $resumen['sin_respuesta']++;
        }
    }

    // Etiquetas automáticas
    $tags = [];
    if ($dias > 10) $tags[] = '⏳ antigua';
    if (count($reaperturas) > 0) $tags[] = '🔁 reabierta';
    if ($dias_sin_respuesta !== null && $dias_sin_respuesta > 5) $tags[] = '📬 sin respuesta';
    if ($riesgo) $tags[] = '🚨 crítica';
    if ($incidencia['estado'] === 'cerrada') $tags[] = '✅ cerrada';

    // Contador por estado
    $resumen[$incidencia['estado']]++;

    $resultado[] = [
        "id" => $id,
        "titulo" => $incidencia['titulo'],
        "descripcion" => $incidencia['descripcion'],
        "estado" => $incidencia['estado'],
        "fecha_creacion" => $incidencia['fecha_creacion'],
        "fecha_cierre" => $incidencia['fecha_cierre'],
        "dias_desde_creacion" => $dias,
        "dias_sin_respuesta" => $dias_sin_respuesta,
        "riesgo" => $riesgo,
        "tags" => $tags,
        "mensajes" => $mensajes,
        "reaperturas" => $reaperturas
    ];
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    "resumen" => $resumen,
    "incidencias" => $resultado
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
