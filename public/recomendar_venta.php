<?php
require_once __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id_incidencia = filter_input(INPUT_POST, 'id_incidencia', FILTER_VALIDATE_INT);
if (!$id_incidencia) {
    header('Location: index.php');
    exit;
}

$sql = "SELECT titulo, descripcion FROM incidencias WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id_incidencia]);
$incidencia = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$incidencia) {
    header('Location: index.php');
    exit;
}

$sql_productos = "SELECT nombre, descripcion, categoria, precio, caracteristicas, esfuerzo FROM catalogo_productos";
$stmt_productos = $pdo->query($sql_productos);
$productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);
$catalogo = json_encode($productos);

$contexto_recomendacion = "Incidencia: Título: \"{$incidencia['titulo']}\"\nDescripción: \"{$incidencia['descripcion']}\"\nCatálogo de productos: $catalogo";
$pregunta_recomendacion = "Basándote en la incidencia y el catálogo de productos, evalúa si hay hasta tres productos relevantes para vender al cliente. Solo recomienda productos si hay una coincidencia clara y directa entre la incidencia
y las descripciones/características de los productos; no fuerces recomendaciones si no encajan perfectamente, para evitar ventas inapropiadas.
Si ninguno de los productos es aplicable (por ejemplo, si la incidencia no se relaciona con categorías como almacenamiento, computación, seguridad, etc.),
no recomiendes nada: devuelve un array vacío en 'productos' y un guion que sugiera escalar la consulta al departamento de ingeniería preventa para una evaluación especializada.
Si hay recomendaciones:

Prioriza productos de 'archivado' si se menciona archivado, retención o cumplimiento normativo.
Incluye un producto de 'consultoria' junto con otros relevantes si la incidencia implica migraciones, cumplimiento normativo o configuraciones complejas.
Considera el nivel de esfuerzo (bajo, medio, alto) para que sea adecuado al tamaño y necesidades del cliente (por ejemplo, bajo para startups simples).
Proporciona un guion de venta breve (máximo 300 palabras) que un técnico pueda usar para convencer al cliente, mencionando los productos recomendados, sus beneficios específicos relacionados con la incidencia, y una llamada a la acción explícita (por ejemplo, sugerir una reunión o demo).

Devuelve únicamente un JSON con las claves 'productos' (array de nombres exactos de productos, máximo 4)
y 'guion' (texto del guion de venta). Si no hay productos aplicables, 'productos' debe ser un array vacío [] y 'guion' debe ser un mensaje como
: 'Dado que esta incidencia requiere una solución más especializada, recomiendo escalar la consulta al departamento de ingeniería preventa para una evaluación detallada.
¿Le gustaría programar una llamada para discutir opciones personalizadas?'. No uses Markdown ni envoltorios como ```json, solo devuelve el JSON puro.";

$recomendacion_response = LLMClient::getResponse($contexto_recomendacion, $pregunta_recomendacion);
$recomendacion = llm_extract_json_payload($recomendacion_response);

if (!is_array($recomendacion) || !isset($recomendacion['productos'], $recomendacion['guion']) || !is_array($recomendacion['productos'])) {
    // Fallo del LLM: no persistir nada para no ensuciar el historial comercial.
    error_log("Error al decodificar JSON de recomendación. Respuesta: " . ($recomendacion_response ?? 'null'));
    header("Location: ver_incidencia.php?id=$id_incidencia&recomendacion=error");
    exit;
}

$guion = trim((string)$recomendacion['guion']);
$productos_validos = array_values(array_filter(array_map(
    fn($p) => trim((string)$p),
    $recomendacion['productos']
), fn($p) => $p !== ''));

// Guardar la recomendación en el historial. Si no hay productos aplicables se
// guarda una fila con producto vacío para conservar el guion de escalado.
$sql_historial = "INSERT INTO recomendaciones_venta (id_incidencia, producto, guion, fecha)
                  VALUES (:id_incidencia, :producto, :guion, NOW())";
$stmt_historial = $pdo->prepare($sql_historial);

if (count($productos_validos) === 0) {
    $stmt_historial->execute([
        ':id_incidencia' => $id_incidencia,
        ':producto' => '',
        ':guion' => $guion
    ]);
} else {
    foreach ($productos_validos as $producto) {
        $stmt_historial->execute([
            ':id_incidencia' => $id_incidencia,
            ':producto' => $producto,
            ':guion' => $guion
        ]);
    }
}

// La vista lee la ultima recomendacion desde BD; ya no viaja por la URL.
header("Location: ver_incidencia.php?id=$id_incidencia&recomendacion=1");
exit;
