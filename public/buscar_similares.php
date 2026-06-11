<?php
// Devuelve en JSON hasta 5 incidencias similares al texto recibido,
// usando el indice FULLTEXT (titulo, descripcion, resumen).
// Lo consume el formulario de alta de index.php para avisar de posibles duplicados.
require_once __DIR__ . '/../src/arranque.php';

header('Content-Type: application/json');

$q = trim((string)($_GET['q'] ?? ''));
$excluir = filter_input(INPUT_GET, 'excluir', FILTER_VALIDATE_INT) ?: 0;

if (mb_strlen($q) < 4) {
    echo json_encode(['ok' => true, 'resultados' => []]);
    exit;
}

$sql = "SELECT id, titulo, resumen, estado, urgencia,
               MATCH(titulo, descripcion, resumen) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
        FROM incidencias
        WHERE MATCH(titulo, descripcion, resumen) AGAINST (? IN NATURAL LANGUAGE MODE)";
$params = [$q, $q];

if ($excluir > 0) {
    $sql .= " AND id <> ?";
    $params[] = $excluir;
}

$sql .= " ORDER BY score DESC LIMIT 5";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error en busqueda de similares: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'resultados' => []]);
    exit;
}

$salida = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'titulo' => (string)$r['titulo'],
        'resumen' => (string)($r['resumen'] ?? ''),
        'estado' => (string)$r['estado'],
        'urgencia' => (string)($r['urgencia'] ?? 'leve')
    ];
}, $resultados);

echo json_encode(['ok' => true, 'resultados' => $salida], JSON_UNESCAPED_UNICODE);
exit;
