<?php

use PHPUnit\Framework\TestCase;

final class EntornoTest extends TestCase
{
    private const CLAVE = 'TICKETIA_TEST_ENTORNO';

    protected function tearDown(): void
    {
        putenv(self::CLAVE);
        unset($_ENV[self::CLAVE]);
    }

    public function testVariableDelProcesoTienePrioridad(): void
    {
        $_ENV[self::CLAVE] = 'fichero';
        putenv(self::CLAVE . '=sistema');

        $this->assertSame('sistema', entorno_valor(self::CLAVE, 'defecto'));
    }

    public function testUsaElValorCargadoDesdeEnv(): void
    {
        putenv(self::CLAVE);
        $_ENV[self::CLAVE] = 'fichero';

        $this->assertSame('fichero', entorno_valor(self::CLAVE, 'defecto'));
    }

    public function testDevuelveElValorPorDefecto(): void
    {
        putenv(self::CLAVE);
        unset($_ENV[self::CLAVE]);

        $this->assertSame('defecto', entorno_valor(self::CLAVE, 'defecto'));
    }
}
