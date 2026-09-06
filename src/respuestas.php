<?php
// Cinco bases disponibles incluso antes de migrar; las ediciones se guardan aparte.
function respuestas_base(): array {
    return [
        'recepcion'=>['titulo'=>'Confirmar recepcion','contenido'=>"Hola,\n\nHemos recibido tu solicitud. La revisaremos y compartiremos las novedades en esta misma conversacion.\n\nSi hay alguna fecha limite o el problema impide trabajar, indicanoslo para valorar su impacto. Gracias."],
        'informacion'=>['titulo'=>'Pedir informacion para diagnosticar','contenido'=>"Hola,\n\nPara poder ayudarte, indicanos:\n1. Que intentabas hacer y que ocurrio.\n2. El mensaje de error exacto y los pasos para reproducirlo.\n3. Desde cuando sucede y a cuantas personas afecta.\n4. Que comprobaciones has realizado.\n\nSi adjuntas una captura, oculta los datos personales. No envies contrasenas, codigos de acceso ni claves."],
        'seguimiento'=>['titulo'=>'Solicitar una actualizacion al cliente','contenido'=>"Hola,\n\nRetomamos esta solicitud para saber si el problema sigue ocurriendo. Si es asi, cuentanos si ha cambiado el comportamiento o si tienes nueva informacion que pueda ayudarnos.\n\nPuedes responder en esta misma conversacion. Gracias."],
        'comprobacion'=>['titulo'=>'Pedir comprobacion de la solucion propuesta','contenido'=>"Hola,\n\nPuedes probar los pasos que hemos compartido en esta conversacion y confirmarnos si el problema se ha resuelto?\n\nSi continua, indicanos en que paso falla y el mensaje que aparece, sin incluir datos sensibles. Tu confirmacion nos ayudara a decidir el siguiente paso."],
        'necesidad'=>['titulo'=>'Aclarar una nueva necesidad o ampliacion','contenido'=>"Hola,\n\nPara valorar tu solicitud, cuentanos que necesitas conseguir, cuantas personas o equipos lo utilizarian y si tienes una fecha deseada.\n\nCon esa informacion podremos revisar las opciones y compartir una propuesta, si procede. Esta consulta no supone una compra ni un cambio en tu servicio."],
    ];
}

function respuestas_esquema_disponible(PDO $pdo): bool {
    try { $pdo->query('SELECT clave FROM respuestas_reutilizables LIMIT 0'); return true; }
    catch (PDOException $e) { return false; }
}

function respuestas_listar(PDO $pdo, bool $incluir_inactivas = false): array {
    $respuestas = [];
    foreach (respuestas_base() as $clave=>$base) $respuestas[$clave] = $base + ['version'=>0,'activo'=>1];
    if (respuestas_esquema_disponible($pdo)) {
        foreach ($pdo->query('SELECT clave,titulo,contenido,activo,version FROM respuestas_reutilizables')->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            if (isset($respuestas[$fila['clave']])) $respuestas[$fila['clave']] = $fila;
        }
    }
    return $incluir_inactivas ? $respuestas : array_filter($respuestas, static fn(array $r): bool => (bool)$r['activo']);
}

/** Control optimista: una edicion antigua nunca pisa cambios de otra persona. */
function respuestas_guardar(PDO $pdo, string $clave, string $titulo, string $contenido, int $version, bool $activo): bool {
    if (!isset(respuestas_base()[$clave]) || trim($titulo)==='' || trim($contenido)==='' || mb_strlen($titulo)>120 || mb_strlen($contenido)>10000 || $version<0) {
        throw new InvalidArgumentException('Revisa el titulo (1-120 caracteres) y el contenido (1-10000 caracteres).');
    }
    if ($version === 0) {
        try {
            $stmt = $pdo->prepare('INSERT INTO respuestas_reutilizables (clave,titulo,contenido,activo,version) VALUES (?,?,?,?,1)');
            return $stmt->execute([$clave,trim($titulo),trim($contenido),(int)$activo]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return false;
            throw $e;
        }
    }
    $stmt = $pdo->prepare('UPDATE respuestas_reutilizables SET titulo=?,contenido=?,activo=?,version=version+1 WHERE clave=? AND version=?');
    $stmt->execute([trim($titulo),trim($contenido),(int)$activo,$clave,$version]);
    return $stmt->rowCount() === 1;
}
