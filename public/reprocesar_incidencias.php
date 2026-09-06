<?php
require_once __DIR__ . '/../src/arranque.php';

// Por defecto solo reprocesa incidencias pendientes de clasificar (tipo NULL),
// que es el caso normal cuando la clasificacion en segundo plano del alta fallo.
// Con ?todas=1 reprocesa el historico completo.
$todas = isset($_GET['todas']) && $_GET['todas'] === '1';

$sql = "SELECT id, titulo, descripcion FROM incidencias" . ($todas ? '' : ' WHERE tipo IS NULL');
$stmt = $pdo->query($sql);
$incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$procesadas = 0;
$errores = 0;

foreach ($incidencias as $incidencia) {
    $id = (int)$incidencia['id'];
    gobierno_ia_contexto_establecer($id);

    $clasificacion = clasificar_incidencia((string)$incidencia['titulo'], (string)$incidencia['descripcion']);
    if ($clasificacion === null) {
        $errores++;
        error_log("Reproceso: clasificacion fallida para incidencia #$id");
        continue;
    }

    try {
        clasificacion_aplicar($pdo, $id, $clasificacion);
        $procesadas++;
    } catch (PDOException $e) {
        $errores++;
        error_log("Error al actualizar incidencia #$id: " . $e->getMessage());
    }
}

echo "Reprocesamiento completado (" . ($todas ? 'todas las incidencias' : 'solo pendientes de clasificar') . "):<br>";
echo "Incidencias procesadas: $procesadas<br>";
echo "Errores: $errores<br>";
echo "<a href='index.php'>Volver al panel</a>";
