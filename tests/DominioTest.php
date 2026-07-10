<?php

use PHPUnit\Framework\TestCase;

final class DominioTest extends TestCase
{
    public function testCatalogosBasicos(): void
    {
        $this->assertSame(['critico', 'urgente', 'leve'], dominio_urgencias());
        $this->assertSame(['abierta', 'en_curso', 'cerrada'], dominio_estados());
        $this->assertContains('id_desc', dominio_ordenes());
        $this->assertNotEmpty(dominio_tipos());
    }

    public function testOrderByConocidosYPorDefecto(): void
    {
        $this->assertSame('fecha_creacion DESC', dominio_order_by('recientes'));
        $this->assertSame('fecha_creacion ASC', dominio_order_by('antiguas'));
        $this->assertStringContainsString('FIELD(urgencia', dominio_order_by('urgencia'));
        // Cualquier valor desconocido cae al orden por defecto (nunca SQL inyectable).
        $this->assertSame('id DESC', dominio_order_by('1; DROP TABLE incidencias'));
    }

    public function testAppendFiltrosSinFiltrosNoTocaNada(): void
    {
        $sql = 'SELECT * FROM incidencias WHERE 1=1';
        $params = [];
        dominio_append_filtros($sql, $params, []);
        $this->assertSame('SELECT * FROM incidencias WHERE 1=1', $sql);
        $this->assertSame([], $params);
    }

    public function testAppendFiltrosCombinados(): void
    {
        $sql = 'WHERE 1=1';
        $params = [];
        dominio_append_filtros($sql, $params, [
            'tipo' => 'Redes',
            'urgencia' => 'critico',
            'estado' => 'abierta',
        ]);
        $this->assertStringContainsString('tipo = :tipo', $sql);
        $this->assertStringContainsString('urgencia = :urgencia', $sql);
        $this->assertStringContainsString('estado = :estado_filtro', $sql);
        $this->assertSame('Redes', $params[':tipo']);
        $this->assertSame('critico', $params[':urgencia']);
        $this->assertSame('abierta', $params[':estado_filtro']);
    }

    public function testAppendFiltroAsignado(): void
    {
        $sql = 'WHERE 1=1';
        $params = [];
        dominio_append_filtros($sql, $params, ['asignado' => 'sin_asignar']);
        $this->assertStringContainsString('asignado_id IS NULL', $sql);

        $sql2 = 'WHERE 1=1';
        $params2 = [];
        dominio_append_filtros($sql2, $params2, ['asignado' => '7']);
        $this->assertStringContainsString('asignado_id = :asignado', $sql2);
        $this->assertSame(7, $params2[':asignado']);
    }

    public function testAppendFiltroBusquedaYFechas(): void
    {
        $sql = 'WHERE 1=1';
        $params = [];
        dominio_append_filtros($sql, $params, [
            'busqueda' => 'vpn',
            'desde' => '2026-01-01',
            'hasta' => '2026-01-31',
        ]);
        $this->assertStringContainsString(':busqueda', $sql);
        $this->assertStringContainsString(':desde', $sql);
        $this->assertStringContainsString(':hasta', $sql);
        $this->assertSame('%vpn%', $params[':busqueda']);
    }
}
