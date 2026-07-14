<?php
// Acceso comun a configuracion: prioriza variables reales del proceso y usa
// despues los valores cargados desde .env por phpdotenv.

function entorno_valor(string $clave, mixed $por_defecto = null): mixed {
    $valor_sistema = getenv($clave);
    if ($valor_sistema !== false) {
        return $valor_sistema;
    }

    return array_key_exists($clave, $_ENV) ? $_ENV[$clave] : $por_defecto;
}
