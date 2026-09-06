<?php
function probar_respuestas(PDO $pdo, string $admin, string $cliente): void {
    require_once __DIR__ . '/../../src/respuestas.php';
    $agente = iniciar('agente5@pruebas.test');
    comprobar(count(respuestas_listar($pdo))===5,'Cinco respuestas base sin instalar seeds');
    [, $html] = peticion('respuestas.php',$agente);
    comprobar(str_contains($html,'Pedir informacion para diagnosticar') && str_contains($html,'No envies contrasenas'),'Operador puede consultar contenido completo');
    $token = csrf($html);
    // El listado no tiene formularios: obtener el CSRF de una incidencia.
    [, $detalle] = peticion('ver_incidencia.php?id=1',$agente); $token = csrf($detalle);
    comprobar(str_contains($detalle,'respuestas.js?') && str_contains($detalle,'vistaRespuestaRapida'),'Biblioteca y vista previa independientes del autosave');
    $datos = ['clave'=>'recepcion','titulo'=>'Titulo de prueba','contenido'=>'Texto editado <script>no ejecutar</script>','version'=>0,'activo'=>1,'csrf'=>$token];
    [$codigo] = peticion('respuestas.php',$agente,$datos); comprobar($codigo===403,'Operador no puede editar respuestas compartidas');
    [$codigo] = peticion('respuestas.php',$cliente); comprobar($codigo===302,'Cliente no accede a biblioteca interna');
    [, $html] = peticion('respuestas.php?editar=recepcion',$admin); $datos['csrf'] = csrf($html);
    [$codigo] = peticion('respuestas.php',$admin,$datos); comprobar($codigo===302,'Administrador guarda respuesta');
    [, $html] = peticion('respuestas.php',$admin);
    comprobar(str_contains($html,'Texto editado &lt;script&gt;') && !str_contains($html,'<script>no ejecutar'),'Contenido editable escapado sin XSS');
    [$codigo,$html] = peticion('respuestas.php',$admin,$datos); comprobar($codigo===409 && str_contains($html,'Tu texto se conserva'),'Edicion concurrente no pisa la version guardada');
    $datos['version']=1; unset($datos['activo']);
    [$codigo]=peticion('respuestas.php',$admin,$datos); comprobar($codigo===302 && count(respuestas_listar($pdo))===4,'Desactivar respuesta la retira del editor');
    $datos['version']=2; $datos['activo']=1; $datos['contenido']='';
    peticion('respuestas.php',$admin,$datos); comprobar(count(respuestas_listar($pdo))===4,'Validacion impide guardar respuesta vacia');
    $datos['contenido']=respuestas_base()['recepcion']['contenido']; $datos['titulo']=respuestas_base()['recepcion']['titulo'];
    $datos['csrf']='invalido'; [$codigo]=peticion('respuestas.php',$admin,$datos); comprobar($codigo===400,'Edicion exige CSRF');
    [, $html]=peticion('respuestas.php?editar=recepcion',$admin); $datos['csrf']=csrf($html);
    peticion('respuestas.php',$admin,$datos); comprobar(count(respuestas_listar($pdo))===5,'Reactivar conserva cinco respuestas disponibles');
    $pdo->exec('RENAME TABLE respuestas_reutilizables TO respuestas_prueba_ausente');
    try {
        comprobar(count(respuestas_listar($pdo))===5,'Sin migracion siguen disponibles las cinco bases');
        [, $html]=peticion('respuestas.php',$admin); comprobar(str_contains($html,'La edicion requiere aplicar la migracion 17'),'Instalacion antigua informa del requisito sin error fatal');
    } finally { $pdo->exec('RENAME TABLE respuestas_prueba_ausente TO respuestas_reutilizables'); }
}
