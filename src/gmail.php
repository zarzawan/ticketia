<?php
// Entrada en cuarentena: From no demuestra identidad y nunca autoriza acceso por si solo.
function gmail_disponible(PDO $pdo): bool {
    try { $pdo->query('SELECT mensaje_id FROM gmail_entradas LIMIT 0'); $pdo->query('SELECT cursor_pagina FROM gmail_sincronizacion LIMIT 0'); return true; }
    catch (PDOException $e) { return false; }
}

/** Solo devuelve estados y nombres de variables, nunca valores privados. */
function gmail_diagnostico(callable $leer, bool $curlDisponible): array {
    $faltan=[];
    foreach (['GMAIL_CLIENT_ID','GMAIL_CLIENT_SECRET','GMAIL_REFRESH_TOKEN','GMAIL_BUZON','GMAIL_LABEL_ID'] as $clave) {
        if (trim((string)$leer($clave))==='') $faltan[]=$clave;
    }
    $invalidos=[];
    $buzon=trim((string)$leer('GMAIL_BUZON'));
    $etiqueta=trim((string)$leer('GMAIL_LABEL_ID'));
    if ($buzon!=='' && !filter_var($buzon,FILTER_VALIDATE_EMAIL)) $invalidos[]='GMAIL_BUZON';
    if ($etiqueta!=='' && !preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$etiqueta)) $invalidos[]='GMAIL_LABEL_ID';
    return ['completo'=>!$faltan && !$invalidos && $curlDisponible,'faltan'=>$faltan,'invalidos'=>$invalidos,'curl'=>$curlDisponible];
}

function gmail_http(string $url, ?array $formulario = null, string $token = ''): array {
    $ch = curl_init($url); $texto = '';
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>$token !== '' ? ['Authorization: Bearer ' . $token] : [],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $parte) use (&$texto): int {
            if (strlen($texto)+strlen($parte)>2000000) return 0;
            $texto .= $parte; return strlen($parte);
        }]);
    if ($formulario !== null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($formulario)]);
    $ok = curl_exec($ch); $codigo = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($ok === false || $codigo !== 200) throw new RuntimeException('Gmail no disponible o sin permiso (HTTP ' . $codigo . '). No se ha avanzado el lote.');
    $datos = json_decode($texto,true);
    if (!is_array($datos)) throw new RuntimeException('Gmail devolvio un formato no valido.');
    return $datos;
}

function gmail_extraer(array $mensaje): array {
    $cabeceras = [];
    foreach ($mensaje['payload']['headers'] ?? [] as $h) $cabeceras[strtolower($h['name'] ?? '')][] = (string)($h['value'] ?? '');
    $desde = count($cabeceras['from'] ?? []) === 1 ? $cabeceras['from'][0] : '';
    if (preg_match('/^[^<>]*<([^<>]+)>$/D',$desde,$m)) $desde = $m[1];
    $desde = strtolower(trim($desde));
    if (!filter_var($desde,FILTER_VALIDATE_EMAIL)) $desde = '';
    $texto = ''; $html = ''; $adjuntos = false; $recortado = false; $nodos = 0;
    $leer = function(array $parte,int $profundidad = 0) use (&$leer,&$texto,&$html,&$adjuntos,&$recortado,&$nodos): void {
        if (++$nodos>100 || $profundidad>10) { $recortado=true; return; }
        if (!empty($parte['filename']) || !empty($parte['body']['attachmentId'])) { $adjuntos=true; return; }
        $tipo = strtolower((string)($parte['mimeType'] ?? ''));
        if (in_array($tipo,['text/plain','text/html'],true)) {
            $dato = base64_decode(strtr((string)($parte['body']['data'] ?? ''),'-_','+/'),true);
            if ($dato === false) { $recortado=true; return; }
            if (!mb_check_encoding($dato,'UTF-8')) { $dato=mb_convert_encoding($dato,'UTF-8','Windows-1252'); $recortado=true; }
            if ($tipo === 'text/plain') $texto .= "\n" . $dato;
            else $html .= "\n" . $dato;
        }
        foreach ($parte['parts'] ?? [] as $hija) if (is_array($hija)) $leer($hija,$profundidad+1);
    };
    $leer($mensaje['payload'] ?? []);
    $aviso = [];
    if (trim($texto)==='' && $html!=='') {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is','',$html);
        $texto = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div)\b[^>]*>#i',"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $aviso[]='HTML convertido a texto: revisa el contenido.';
    }
    if ($adjuntos) $aviso[]='Adjuntos no importados; consultalos en Gmail.';
    if ($recortado || mb_strlen($texto)>30000) $aviso[]='Contenido recortado o codificacion adaptada; revisar original.';
    if ($desde==='') $aviso[]='Remitente ambiguo: no se puede convertir.';
    if (($cabeceras['auto-submitted'][0] ?? 'no') !== 'no' || isset($cabeceras['list-id'])) $aviso[]='Posible respuesta automatica o lista de correo: revisar antes de importar.';
    return ['remitente'=>$desde,'asunto'=>mb_substr(trim($cabeceras['subject'][0] ?? 'Sin asunto'),0,250) ?: 'Sin asunto',
        'cuerpo'=>mb_substr(trim($texto),0,30000),'aviso'=>mb_substr(implode(' ',$aviso),0,500)];
}

/** Una pagina por ejecucion. Repetir una pagina tras un fallo no duplica entradas. */
function gmail_sincronizar(PDO $pdo, string $buzon, string $etiqueta, callable $get): int {
    if (!filter_var($buzon,FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$etiqueta)) throw new RuntimeException('Buzon o ID de etiqueta no valido.');
    $candado = 'gmail:' . substr(hash('sha256',$buzon),0,48);
    $stmt=$pdo->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute([$candado]);
    if ((int)$stmt->fetchColumn()!==1) throw new RuntimeException('Ya hay una sincronizacion en curso.');
    try {
        $stmt=$pdo->prepare('SELECT etiqueta,cursor_pagina FROM gmail_sincronizacion WHERE buzon=?');$stmt->execute([$buzon]);$anterior=$stmt->fetch(PDO::FETCH_ASSOC);
        $query=['labelIds'=>$etiqueta,'maxResults'=>20,'includeSpamTrash'=>'false'];
        if ($anterior && $anterior['etiqueta']===$etiqueta && $anterior['cursor_pagina']!=='') $query['pageToken']=$anterior['cursor_pagina'];
        $pagina=$get('messages?' . http_build_query($query)); $n=0;
        foreach ($pagina['messages'] ?? [] as $referencia) {
            $id=(string)($referencia['id'] ?? ''); if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$id)) throw new RuntimeException('Identificador Gmail no valido.');
            $stmt=$pdo->prepare('SELECT id FROM gmail_entradas WHERE buzon=? AND mensaje_id=?');$stmt->execute([$buzon,$id]);if($stmt->fetchColumn())continue;
            $mensaje=$get('messages/' . rawurlencode($id) . '?format=full');$datos=gmail_extraer($mensaje);
            $hilo=(string)($mensaje['threadId'] ?? ''); if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$hilo)) throw new RuntimeException('Hilo Gmail no valido.');
            $stmt=$pdo->prepare('INSERT INTO gmail_entradas (buzon,mensaje_id,hilo_id,remitente,asunto,cuerpo,aviso) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$buzon,$id,$hilo,$datos['remitente'],$datos['asunto'],$datos['cuerpo'],$datos['aviso']]);$n++;
        }
        $stmt=$pdo->prepare('INSERT INTO gmail_sincronizacion (buzon,etiqueta,cursor_pagina) VALUES (?,?,?) ON DUPLICATE KEY UPDATE etiqueta=VALUES(etiqueta),cursor_pagina=VALUES(cursor_pagina)');
        $stmt->execute([$buzon,$etiqueta,(string)($pagina['nextPageToken'] ?? '')]);return $n;
    } finally { $stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$candado]); }
}

/** La revision confirma identidad; no se crean cuentas desde cabeceras de correo. */
function gmail_destino(PDO $pdo, array $correo): int {
    $stmt=$pdo->prepare("SELECT DISTINCT incidencia_id FROM gmail_entradas WHERE buzon=? AND hilo_id=? AND estado='importado' LIMIT 2");
    $stmt->execute([$correo['buzon'],$correo['hilo_id']]);$ids=$stmt->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)>1) throw new RuntimeException('Hilo ambiguo: revisa las incidencias asociadas antes de continuar.');
    return (int)($ids[0] ?? 0);
}

function gmail_importar(PDO $pdo, int $entrada, int $revisor, int $destinoEsperado = 0): int {
    require_once __DIR__ . '/soporte.php';
    $candado = null;
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM gmail_entradas WHERE id=? FOR UPDATE');$stmt->execute([$entrada]);$correo=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$correo || $correo['estado']!=='pendiente' || $correo['cuerpo']==='') throw new RuntimeException('Entrada no disponible o sin texto.');
        $candado = 'gmailhilo:' . substr(hash('sha256',$correo['buzon'] . ':' . $correo['hilo_id']),0,48);
        $stmt=$pdo->prepare('SELECT GET_LOCK(?,5)');$stmt->execute([$candado]);
        if((int)$stmt->fetchColumn()!==1){$candado=null;throw new RuntimeException('Otra revision de este hilo esta en curso. Intentalo de nuevo.');}
        $stmt=$pdo->prepare("SELECT u.id,u.cliente_id,u.rol FROM usuarios u JOIN clientes c ON c.id=u.cliente_id
            WHERE u.email=? AND u.rol='cliente' AND u.activo=1 AND c.activo=1 FOR UPDATE");$stmt->execute([$correo['remitente']]);$usuario=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) throw new RuntimeException('El remitente debe corresponder a un cliente activo con organizacion activa. Verifica su identidad antes de registrarlo.');
        $id=gmail_destino($pdo,$correo);
        if ($id!==$destinoEsperado) throw new RuntimeException('El destino del hilo ha cambiado. Abre de nuevo la entrada y confirma la incidencia.');
        if ($id) {
            $stmt=$pdo->prepare('SELECT cliente_id,estado FROM incidencias WHERE id=? FOR UPDATE');$stmt->execute([$id]);$incidencia=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$incidencia || (int)$incidencia['cliente_id']!==(int)$usuario['cliente_id']) throw new RuntimeException('El remitente no pertenece al cliente de esta incidencia.');
            if (!in_array($incidencia['estado'],dominio_estados_activos(),true)) throw new RuntimeException('La incidencia esta resuelta o cerrada. Revisa si corresponde reabrirla antes de incorporar el correo.');
            $solicitud=substr(hash('sha256','gmail:' . $correo['buzon'] . ':' . $correo['mensaje_id']),0,32);
            if (soporte_responder($pdo,$id,$usuario,$correo['cuerpo'],false,$solicitud)!=='ok') throw new RuntimeException('No se pudo incorporar la respuesta; revisa la conversacion.');
        } else {
        $stmt=$pdo->prepare("INSERT INTO incidencias (titulo,descripcion,cliente_id,creado_por,estado,urgencia,idioma) VALUES (?,?,?,?,'abierta','leve','es')");
        $stmt->execute([$correo['asunto'],$correo['cuerpo'],$usuario['cliente_id'],$usuario['id']]);$id=(int)$pdo->lastInsertId();
        trabajos_encolar($pdo,'clasificar',['id_incidencia'=>$id]);
        }
        $stmt=$pdo->prepare("UPDATE gmail_entradas SET estado='importado',incidencia_id=?,revisado_por=? WHERE id=?");$stmt->execute([$id,$revisor,$entrada]);
        auditar($pdo,'importar_gmail','entrada #' . $entrada . ' -> incidencia #' . $id);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
    finally { if($candado!==null){$stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$candado]);} }
}
