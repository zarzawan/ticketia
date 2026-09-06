<?php
use PHPUnit\Framework\TestCase;

final class OperacionProfesionalTest extends TestCase {
    protected function tearDown(): void { $GLOBALS['ticketia_calendario'] = null; }

    public function testJornadaFestivoYFinDeSemana(): void {
        $c = ['inicio'=>'09:00','fin'=>'17:00','dias'=>[1,2,3,4,5],'festivos'=>['2026-09-07']];
        $this->assertTrue(calendario_validar($c));
        $this->assertSame('2026-09-08 10:00', calendario_limite(calendario_civil('2026-09-04 16:00'),2,$c)->format('Y-m-d H:i'));
        $GLOBALS['ticketia_calendario'] = $c;
        $this->assertSame(7200, calendario_segundos(calendario_civil('2026-09-04 16:00'),calendario_civil('2026-09-08 10:00')));
        $this->assertSame(-7200, calendario_segundos(calendario_civil('2026-09-08 10:00'),calendario_civil('2026-09-04 16:00')));
    }

    public function testCalendarioRechazaFechasYJornadasInvalidas(): void {
        $base = ['inicio'=>'09:00','fin'=>'17:00','dias'=>[1],'festivos'=>[]];
        foreach ([['inicio'=>'25:00'],['fin'=>'08:00'],['dias'=>[]],['dias'=>[8]],['festivos'=>['2026-02-30']]] as $cambio) {
            $this->assertFalse((bool)calendario_validar(array_replace($base,$cambio)));
        }
    }

    public function testHoraCivilNoCambiaAlCruzarHorarioDeVerano(): void {
        $zona = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Madrid');
            $desde = calendario_civil('2026-03-29 01:00:00');
            $hasta = calendario_civil('2026-03-29 04:00:00');
            $this->assertSame(10800,calendario_segundos($desde,$hasta));
        } finally { date_default_timezone_set($zona); }
    }

    public function testContextoLimitadoYFuentesSeparadas(): void {
        $ticket = ['titulo'=>str_repeat('a',1000),'descripcion'=>str_repeat('b',30000),'estado'=>'abierta'];
        $mensajes = array_fill(0,100,['autor'=>'cliente','mensaje'=>str_repeat('c',30000)]);
        $texto = asistente_contexto($ticket,$mensajes,[]);
        $this->assertLessThan(24000,mb_strlen($texto));
        $this->assertSame(15,substr_count($texto,'cliente:'));
        $this->assertStringContainsString('historial parcial',$texto);
    }

    public function testReglasRespetanEmpresaYDepartamento(): void {
        $regla = ['cliente_id'=>1,'tipo'=>'Correo'];
        $this->assertTrue(reglas_coincide($regla,['cliente_id'=>1,'tipo'=>'Correo']));
        $this->assertFalse(reglas_coincide($regla,['cliente_id'=>2,'tipo'=>'Correo']));
        $this->assertFalse(reglas_coincide($regla,['cliente_id'=>1,'tipo'=>'Redes']));
    }
}
