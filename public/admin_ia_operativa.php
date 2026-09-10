<?php
require_once __DIR__ . '/../src/arranque.php';
auth_requerir_rol('admin');
require_once __DIR__ . '/../src/ia_operativa.php';
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Usa POST.']); exit; }
try {
    $modo = (string)($_POST['modo'] ?? '');
    if ($modo === 'prediccion') {
        require_once __DIR__ . '/../src/predicciones.php';
        $id = (int)($_POST['cliente'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id=?'); $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) { http_response_code(404); throw new RuntimeException('Organizacion no encontrada.'); }
        $dias = in_array((int)($_POST['dias'] ?? 90),[30,90,180],true) ? (int)($_POST['dias'] ?? 90) : 90;
        $ahora = new DateTimeImmutable();
        $m = predicciones_metricas($pdo,[$id],$dias,$ahora)[$id] ?? [];
        unset($m['cliente_id']);
        $contexto = ['periodo_dias'=>$dias,'metricas'=>$m,'senales'=>predicciones_evaluar($m),'altas_por_mes'=>predicciones_serie($pdo,$id,$dias,$ahora)];
        $ambito = 'cliente:' . $id . ':' . $dias;
    } elseif ($modo === 'respuesta') {
        $titulo = trim((string)($_POST['titulo'] ?? '')); $contenido = trim((string)($_POST['contenido'] ?? ''));
        if ($titulo === '' || mb_strlen($titulo)>120 || $contenido === '' || mb_strlen($contenido)>10000) throw new RuntimeException('Introduce un titulo y un texto dentro de los limites antes de consultar la IA.');
        $contexto = ['titulo'=>$titulo,'contenido'=>$contenido]; $ambito = 'plantilla';
    } elseif ($modo === 'reparto') {
        $contexto = ['departamentos'=>$pdo->query("SELECT tipo,COUNT(*) AS activas,SUM(asignado_id IS NULL) AS sin_asignar
            FROM incidencias WHERE estado IN ('abierta','en_curso','esperando_cliente') GROUP BY tipo ORDER BY activas DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC),
            'tecnicos_activos'=>count(usuarios_asignables($pdo))];
        if (reglas_disponibles($pdo)) {
            $contexto['equipos'] = (int)$pdo->query('SELECT COUNT(*) FROM equipos_soporte')->fetchColumn();
            $contexto['reglas_activas'] = (int)$pdo->query('SELECT COUNT(*) FROM reglas_asignacion WHERE activo=1')->fetchColumn();
        }
        $ambito = 'reparto';
    } else { http_response_code(400); throw new RuntimeException('Funcion IA no valida.'); }
    $hash = hash('sha256', $modo . $ambito . json_encode($contexto));
    $cache = $_SESSION['ia_operativa_cache'][$hash] ?? null;
    if ($cache && $cache['hasta'] > time()) { echo json_encode(['ok'=>true,'datos'=>$cache['datos'],'cache'=>true]); exit; }
    if (($_SESSION['ia_operativa_ultima'] ?? 0) > time()-10) { http_response_code(429); throw new RuntimeException('Espera unos segundos antes de otra consulta IA.'); }
    $_SESSION['ia_operativa_ultima'] = time();
    gobierno_ia_contexto_establecer(null);
    $datos = ia_operativa_validar(LLMClient::getResponse(json_encode($contexto,JSON_UNESCAPED_UNICODE),ia_operativa_pregunta($modo)),$modo);
    if ($datos === null) { http_response_code(502); throw new RuntimeException('La IA no ha devuelto una propuesta valida. Los datos y el texto original no han cambiado. Puedes continuar sin IA.'); }
    $_SESSION['ia_operativa_cache'] = array_slice($_SESSION['ia_operativa_cache'] ?? [],-9,null,true);
    $_SESSION['ia_operativa_cache'][$hash] = ['hasta'=>time()+600,'datos'=>$datos];
    auditar($pdo,'asistencia_ia_operativa',$modo . ' ' . $ambito);
    echo json_encode(['ok'=>true,'datos'=>$datos,'cache'=>false],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok'=>false,'error'=> $e instanceof PDOException ? 'No se pudo consultar la informacion. Revisa las migraciones pendientes.' : ($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo generar la propuesta. Los datos no han cambiado.')],JSON_UNESCAPED_UNICODE);
}
