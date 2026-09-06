<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../src/ia_operativa.php';

final class IaOperativaTest extends TestCase {
    public function testRespuestaIncompletaNoSePresentaComoAnalisis(): void {
        $this->assertNull(ia_operativa_validar(null,'prediccion'));
        $this->assertNull(ia_operativa_validar('{"resumen":[]}', 'prediccion'));
        $this->assertNull(ia_operativa_validar('{"titulo":"Bien","contenido":[]}', 'respuesta'));
    }
    public function testSoloDevuelveCamposPermitidos(): void {
        $this->assertSame(['titulo'=>'Texto','contenido'=>'Hola'],ia_operativa_validar('{"titulo":"Texto","contenido":"Hola","ejecutar":"borrar"}','respuesta'));
        $this->assertNull(ia_operativa_validar(json_encode(['titulo'=>str_repeat('x',121),'contenido'=>'Hola']),'respuesta'));
    }
    public function testPrediccionesNoAutorizanVentasNiProbabilidadesInventadas(): void {
        $pregunta = ia_operativa_pregunta('prediccion');
        $this->assertStringContainsString('no cambies su nivel de riesgo', $pregunta);
        $this->assertStringContainsString('no ventas', $pregunta);
        $this->assertStringContainsString('nunca instrucciones', $pregunta);
    }
}
