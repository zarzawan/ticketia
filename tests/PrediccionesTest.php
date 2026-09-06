<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../src/predicciones.php';

final class PrediccionesTest extends TestCase {
    public function testSinActividadNoInventaRiesgoNiCompras(): void {
        $p = predicciones_evaluar([]);
        $this->assertSame('Datos insuficientes', $p['riesgo']);
        $this->assertNull($p['puntos']);
        $this->assertSame('Sin evidencia suficiente', $p['oportunidad']);
        $this->assertSame(0, $p['cobertura']);
    }

    public function testPocaMuestraConservaAlertasSinClasificar(): void {
        $p = predicciones_evaluar(['recientes'=>1,'vencidas'=>1,'comerciales'=>9]);
        $this->assertNull($p['puntos']);
        $this->assertCount(1, $p['motivos']);
        $this->assertSame('Priorizar la recuperacion del servicio', $p['oportunidad']);
    }

    public function testRiesgoExplicableImpideRecomendarVentas(): void {
        $p = predicciones_evaluar(['recientes'=>5,'valoradas'=>3,'satisfaccion'=>2,'reabiertas'=>1,'vencidas'=>2,'quejas'=>2,'comerciales'=>3]);
        $this->assertSame('Alto', $p['riesgo']);
        $this->assertSame(100, $p['puntos']);
        $this->assertCount(4, $p['motivos']);
        $this->assertSame('Priorizar la recuperacion del servicio', $p['oportunidad']);
    }

    public function testOportunidadExigeCalidadYMuestraNoSoloVolumen(): void {
        $m = ['recientes'=>100,'comerciales'=>40];
        $this->assertSame('Sin evidencia suficiente', predicciones_evaluar($m)['oportunidad']);
        $m += ['valoradas'=>3,'satisfaccion'=>4];
        $this->assertSame('Revisar necesidades de ampliacion', predicciones_evaluar($m)['oportunidad']);
        $this->assertSame(3.0, predicciones_evaluar($m)['cobertura']);
        $m['vencidas'] = 1;
        $this->assertSame('Priorizar la recuperacion del servicio', predicciones_evaluar($m)['oportunidad']);
    }
}
