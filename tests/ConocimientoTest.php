<?php
use PHPUnit\Framework\TestCase;

final class ConocimientoTest extends TestCase
{
    public function testUnClienteSoloVeArticulosPublicadosParaClientes(): void
    {
        foreach (['borrador', 'publicado', 'archivado'] as $estado) {
            foreach (['interno', 'clientes'] as $visibilidad) {
                $this->assertSame($estado === 'publicado' && $visibilidad === 'clientes',
                    conocimiento_visible(compact('estado', 'visibilidad'), true));
                $this->assertSame($estado === 'publicado',
                    conocimiento_visible(compact('estado', 'visibilidad'), false));
            }
        }
        $this->assertFalse(conocimiento_visible([], true));
    }

    public function testValidaContenidoYValoresManipulados(): void
    {
        $datos = ['titulo'=>'Correo', 'resumen'=>'Como configurar el correo', 'contenido'=>'Abre los ajustes.', 'categoria'=>'Acceso', 'estado'=>'borrador', 'visibilidad'=>'interno'];
        $this->assertNull(conocimiento_validar($datos));
        $this->assertNotNull(conocimiento_validar(array_replace($datos, ['estado'=>'publicar_sin_revision'])));
        $this->assertNotNull(conocimiento_validar(array_replace($datos, ['contenido'=>' '])));
        $this->assertNotNull(conocimiento_validar(array_replace($datos, ['titulo'=>str_repeat('a',181)])));
    }

    public function testElClienteEntiendeElSiguientePaso(): void
    {
        $this->assertSame('Tienes una respuesta', portal_siguiente_accion('en_curso', 'tecnico')['titulo']);
        $this->assertSame('El equipo esta trabajando', portal_siguiente_accion('abierta', null)['titulo']);
        $this->assertSame('Confirma la solucion', portal_siguiente_accion('resuelta', 'tecnico')['titulo']);
        $this->assertSame('Solicitud finalizada', portal_siguiente_accion('cerrada', 'tecnico')['titulo']);
    }
}
