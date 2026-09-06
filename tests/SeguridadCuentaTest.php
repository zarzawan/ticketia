<?php

use PHPUnit\Framework\TestCase;

final class SeguridadCuentaTest extends TestCase
{
    private const CLAVE = 'APP_KEY';

    protected function tearDown(): void
    {
        putenv(self::CLAVE);
        unset($_ENV[self::CLAVE]);
    }

    public function testTotpCumpleElVectorRfc6238(): void
    {
        $secreto = cuenta_base32_codificar('12345678901234567890');

        $this->assertSame('287082', cuenta_totp_codigo($secreto, intdiv(59, 30)));
        $this->assertSame(intdiv(59, 30), cuenta_totp_verificar($secreto, '287082', 59));
        $this->assertNull(cuenta_totp_verificar($secreto, '000000', 59));
    }

    public function testBase32MantieneElSecreto(): void
    {
        $original = random_bytes(20);
        $this->assertSame($original, cuenta_base32_decodificar(cuenta_base32_codificar($original)));
    }

    public function testCifraYDescifraConLaClaveDeAplicacion(): void
    {
        putenv(self::CLAVE . '=' . str_repeat('a', 32));

        $cifrado = cuenta_secreto_cifrar('SECRETO');

        $this->assertNotNull($cifrado);
        $this->assertNotSame('SECRETO', $cifrado);
        $this->assertSame('SECRETO', cuenta_secreto_descifrar((string)$cifrado));
    }

    public function testRechazaClaveDeAplicacionInsegura(): void
    {
        putenv(self::CLAVE . '=corta');

        $this->assertFalse(cuenta_app_key_disponible());
        $this->assertNull(cuenta_secreto_cifrar('SECRETO'));
    }

    public function testNormalizaCodigoDeRecuperacion(): void
    {
        $this->assertSame(
            cuenta_codigo_recuperacion_hash('ABCDE-23456'),
            cuenta_codigo_recuperacion_hash('abcde 23456')
        );
    }
}
