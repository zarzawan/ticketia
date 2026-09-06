<?php

use PHPUnit\Framework\TestCase;

final class GobiernoIaTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('LLM_LOG_RETENTION_DIAS');
        unset($_ENV['LLM_LOG_RETENTION_DIAS']);
        gobierno_ia_contexto_establecer(null);
    }

    public function testValidaHashesSha1(): void
    {
        $this->assertTrue(gobierno_ia_hash_valido(str_repeat('a', 40)));
        $this->assertFalse(gobierno_ia_hash_valido('no-valido'));
    }

    public function testAcotaLaRetencion(): void
    {
        putenv('LLM_LOG_RETENTION_DIAS=2');
        $this->assertSame(7, gobierno_ia_retencion_dias());

        putenv('LLM_LOG_RETENTION_DIAS=900');
        $this->assertSame(730, gobierno_ia_retencion_dias());
    }

    public function testMantieneElContextoDeIncidencia(): void
    {
        gobierno_ia_contexto_establecer(42);
        $this->assertSame(42, gobierno_ia_contexto_incidencia());

        gobierno_ia_contexto_establecer(0);
        $this->assertNull(gobierno_ia_contexto_incidencia());
    }
}
