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
        $this->assertSame('Nueva', ui_estado_label('abierta'));
        $this->assertSame('En trabajo', ui_estado_label('en_curso'));
        $this->assertSame('Solucion propuesta', ui_estado_label('resuelta'));
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

    public function testTarjetaKanbanPuedeParticiparEnAccionesMasivas(): void
    {
        $html = ui_render_kanban_card([
            'id' => 7,
            'titulo' => 'Prueba',
            'resumen' => 'Resumen',
            'urgencia' => 'leve',
            'estado' => 'abierta',
            'fecha_creacion' => '2026-07-12 10:00:00',
        ], [], true);

        $this->assertStringContainsString("class='ticket-check'", $html);
        $this->assertStringContainsString("form='formAccionesMasivas'", $html);
        $this->assertStringContainsString("name='ids[]'", $html);
        $this->assertStringContainsString("class='kanban-controls-disclosure'", $html);
        $this->assertStringContainsString('<summary>Ajustar</summary>', $html);
    }

    public function testActividadPuedeDesplegarElContenidoSinSaltarDeSeccion(): void
    {
        $html = ui_render_timeline_item([
            'tipo' => 'mensaje-cliente',
            'titulo' => 'Mensaje de Cliente',
            'descripcion' => '',
            'fecha' => '2026-07-12 10:00:00',
            'desplegable' => 'Contenido del mensaje',
            'desplegable_texto' => 'mensaje',
        ]);

        $this->assertStringContainsString("<details class='timeline-disclosure'>", $html);
        $this->assertStringContainsString('Ver mensaje', $html);
        $this->assertStringContainsString('Ocultar mensaje', $html);
        $this->assertStringContainsString('Contenido del mensaje', $html);
        $this->assertStringNotContainsString('href=', $html);
    }
}
