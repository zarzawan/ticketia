<?php

use PHPUnit\Framework\TestCase;

final class UiYAdjuntosTest extends TestCase
{
    public function testEscapadoHtml(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', ui_e('<script>alert(1)</script>'));
        $this->assertSame('a &quot;b&quot; &#039;c&#039;', ui_e('a "b" \'c\''));
    }

    public function testIniciales(): void
    {
        $this->assertSame('JL', ui_iniciales('Jose Luis'));
        $this->assertSame('JC', ui_iniciales('Jose Luis Caro'));
        $this->assertSame('M', ui_iniciales('Marta'));
        $this->assertSame('', ui_iniciales('   '));
    }

    public function testEtiquetasDeEstadoYUrgencia(): void
    {
        $this->assertSame('En curso', ui_estado_label('en_curso'));
        $this->assertSame('Critica', ui_urgencia_label('critico'));
        // Valores desconocidos degradan a algo legible, nunca rompen.
        $this->assertSame('Otro estado', ui_estado_label('otro_estado'));
    }

    public function testFormatoTamanoAdjuntos(): void
    {
        $this->assertSame('512 B', adjuntos_formato_tamano(512));
        $this->assertSame('1 KB', adjuntos_formato_tamano(1024));
        $this->assertSame('1.5 MB', adjuntos_formato_tamano((int)(1.5 * 1024 * 1024)));
    }

    public function testExtensionesPermitidasSinPeligrosas(): void
    {
        $this->assertArrayHasKey('pdf', ADJUNTOS_PERMITIDOS);
        $this->assertArrayHasKey('png', ADJUNTOS_PERMITIDOS);
        foreach (['php', 'exe', 'js', 'html', 'svg', 'bat'] as $peligrosa) {
            $this->assertArrayNotHasKey($peligrosa, ADJUNTOS_PERMITIDOS, "La extension '$peligrosa' no debe permitirse");
        }
    }
}
