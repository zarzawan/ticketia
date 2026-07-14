<?php

use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testNormalizaLosDatosGuardadosEnSesion(): void
    {
        $datos = auth_datos_sesion([
            'id' => '7',
            'nombre' => 'Ana',
            'email' => 'ana@example.test',
            'rol' => 'operador',
            'cliente_id' => null,
            'sesion_version' => '3',
        ]);

        $this->assertSame(7, $datos['id']);
        $this->assertNull($datos['cliente_id']);
        $this->assertSame(3, $datos['sesion_version']);
    }

    public function testDescartaUnSegundoFactorCaducado(): void
    {
        $_SESSION['auth_2fa_pendiente'] = [
            'usuario_id' => 7,
            'creado_en' => time() - 301,
            'intentos' => 0,
        ];

        $this->assertNull(auth_2fa_pendiente());
        $this->assertArrayNotHasKey('auth_2fa_pendiente', $_SESSION);
    }

    public function testConservaUnSegundoFactorReciente(): void
    {
        $_SESSION['auth_2fa_pendiente'] = [
            'usuario_id' => 7,
            'creado_en' => time(),
            'intentos' => 1,
        ];

        $this->assertSame(7, auth_2fa_pendiente()['usuario_id']);
    }
}
