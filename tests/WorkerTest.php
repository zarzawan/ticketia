<?php
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase {
    public function testSinRegistroNuncaSePresentaComoOperativo(): void {
        foreach ([null, [], ['fecha' => 1010, 'modo' => 'bucle'], ['fecha' => 990, 'modo' => 'manual']] as $latido) {
            $salud = trabajos_worker_evaluar($latido, 1000, 60);
            $this->assertSame('sin_datos', $salud['estado']);
            $this->assertNotSame('ok', $salud['tono']);
        }
    }

    public function testUmbralYDiferenciaEntreServicioYCron(): void {
        $reciente = trabajos_worker_evaluar(['fecha' => 940, 'modo' => 'puntual'], 1000, 60);
        $this->assertSame('reciente', $reciente['estado']);
        $this->assertStringContainsString('ejecucion puntual', $reciente['detalle']);
        $vencido = trabajos_worker_evaluar(['fecha' => 939, 'modo' => 'bucle'], 1000, 60);
        $this->assertSame('sin_senal', $vencido['estado']);
        $this->assertSame('warn', $vencido['tono']);
        $this->assertStringContainsString('servicio', $vencido['detalle']);
    }
}
