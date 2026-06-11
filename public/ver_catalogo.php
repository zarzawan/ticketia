<?php
require_once __DIR__ . '/../src/arranque.php';

// Obtener parámetros de búsqueda y filtros
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_categoria = isset($_GET['filtro_categoria']) ? trim($_GET['filtro_categoria']) : '';
$filtro_esfuerzo = isset($_GET['filtro_esfuerzo']) ? trim($_GET['filtro_esfuerzo']) : '';

// Construir la consulta SQL con filtros dinámicos
$sql = "SELECT nombre, descripcion, categoria, precio, caracteristicas, esfuerzo 
        FROM catalogo_productos 
        WHERE 1=1";
$params = [];

if ($busqueda) {
    $sql .= " AND (nombre LIKE :busqueda OR descripcion LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

if ($filtro_categoria) {
    $sql .= " AND categoria = :categoria";
    $params[':categoria'] = $filtro_categoria;
}

if ($filtro_esfuerzo) {
    $sql .= " AND esfuerzo = :esfuerzo";
    $params[':esfuerzo'] = $filtro_esfuerzo;
}

$sql .= " ORDER BY categoria, precio ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar productos por categoría
$productos_por_categoria = [];
foreach ($productos as $producto) {
    $categoria = $producto['categoria'];
    if (!isset($productos_por_categoria[$categoria])) {
        $productos_por_categoria[$categoria] = [];
    }
    $productos_por_categoria[$categoria][] = $producto;
}

// Obtener las categorías y niveles de esfuerzo disponibles para los filtros
$categorias = array_unique(array_column($productos, 'categoria'));
$esfuerzos = array_unique(array_column($productos, 'esfuerzo'));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 Catálogo de Productos</title>
    <link rel="stylesheet" href="estilos.css">
    <style>
        .catalogo-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .filter-form {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .filter-field {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .filter-field label {
            font-weight: bold;
            color: #2c3e50;
        }
        .filter-field input, .filter-field select {
            padding: 8px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1em;
        }
        .filter-field input[type="submit"] {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 8px 15px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .filter-field input[type="submit"]:hover {
            background-color: #0056b3;
        }
        .catalogo-categoria {
            margin-bottom: 40px;
        }
        .catalogo-categoria h2 {
            font-size: 1.8em;
            color: #2c3e50;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .producto-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .producto-card {
            background-color: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        .producto-card:hover {
            transform: translateY(-5px);
        }
        .producto-card h3 {
            font-size: 1.3em;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .producto-card p {
            margin: 5px 0;
            color: #7f8c8d;
        }
        .producto-card .precio {
            font-weight: bold;
            color: #007bff;
        }
        .producto-card .esfuerzo {
            font-style: italic;
            color: #666;
        }
    </style>
</head>
<body>
<div class="container catalogo-container">
    <h1>📋 Catálogo de Productos</h1>

    <form method="GET" action="ver_catalogo.php" class="filter-form">
        <div class="filter-field">
            <label for="busqueda">Buscar:</label>
            <input type="text" id="busqueda" name="busqueda" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Nombre o descripción...">
        </div>
        <div class="filter-field">
            <label for="filtro_categoria">Categoría:</label>
            <select id="filtro_categoria" name="filtro_categoria">
                <option value="">Todas las categorías</option>
                <?php foreach ($categorias as $categoria): ?>
                    <option value="<?= htmlspecialchars($categoria) ?>" <?= $filtro_categoria === $categoria ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst($categoria)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="filtro_esfuerzo">Nivel de esfuerzo:</label>
            <select id="filtro_esfuerzo" name="filtro_esfuerzo">
                <option value="">Todos los niveles</option>
                <?php foreach ($esfuerzos as $esfuerzo): ?>
                    <option value="<?= htmlspecialchars($esfuerzo) ?>" <?= $filtro_esfuerzo === $esfuerzo ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst($esfuerzo)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <input type="submit" value="Filtrar">
        </div>
    </form>

    <?php if (empty($productos)): ?>
        <p>No se encontraron productos que coincidan con los criterios de búsqueda.</p>
    <?php else: ?>
        <?php foreach ($productos_por_categoria as $categoria => $productos): ?>
            <div class="catalogo-categoria">
                <h2><?= htmlspecialchars(ucfirst($categoria)) ?></h2>
                <div class="producto-grid">
                    <?php foreach ($productos as $producto): ?>
                        <div class="producto-card">
                            <h3><?= htmlspecialchars($producto['nombre']) ?></h3>
                            <p><strong>Descripción:</strong> <?= htmlspecialchars($producto['descripcion']) ?></p>
                            <p><strong>Precio:</strong> <span class="precio"><?= htmlspecialchars($producto['precio']) ?> €</span></p>
                            <p><strong>Características:</strong> <?= htmlspecialchars($producto['caracteristicas']) ?></p>
                            <p><strong>Esfuerzo:</strong> <span class="esfuerzo"><?= htmlspecialchars(ucfirst($producto['esfuerzo'])) ?></span></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>