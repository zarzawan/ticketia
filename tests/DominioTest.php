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

    public function testSlaCriticoDetectaRiesgoYVencimiento(): void
    {
        $ticket = [
            'fecha_creacion' => '2026-07-12 10:00:00',
            'urgencia' => 'critico',
            'estado' => 'abierta',
            'primera_respuesta' => '2026-07-12 10:30:00',
        ];
        $riesgo = dominio_sla_calcular($ticket, new DateTimeImmutable('2026-07-12 13:00:00'));
        $vencido = dominio_sla_calcular($ticket, new DateTimeImmutable('2026-07-12 15:00:00'));

        $this->assertSame('riesgo', $riesgo['estado']);
        $this->assertSame('vencido', $vencido['estado']);
        $this->assertSame(1, $riesgo['objetivo_respuesta_horas']);
        $this->assertSame(4, $riesgo['objetivo_resolucion_horas']);
    }

    public function testSlaDetectaPrimeraRespuestaVencida(): void
    {
        $sla = dominio_sla_calcular([
            'fecha_creacion' => '2026-07-12 10:00:00',
            'urgencia' => 'critico',
            'estado' => 'abierta',
        ], new DateTimeImmutable('2026-07-12 11:30:00'));

        $this->assertSame('vencido', $sla['estado']);
        $this->assertSame('vencido', $sla['estado_respuesta']);
        $this->assertSame('primera_respuesta', $sla['objetivo_actual']);
    }

    public function testTurnoYPrioridadOperativaSonExplicables(): void
    {
        $this->assertSame('equipo', dominio_turno_atencion('cliente', 'en_curso')['clave']);
        $this->assertSame('cliente', dominio_turno_atencion('tecnico', 'en_curso')['clave']);
        $this->assertSame('resuelto', dominio_turno_atencion('cliente', 'cerrada')['clave']);
        $puntos = dominio_prioridad_operativa([
            'fecha_creacion' => '2026-07-12 10:00:00',
            'urgencia' => 'critico',
            'estado' => 'abierta',
            'asignado_id' => null,
            'ultimo_autor' => 'cliente',
        ], new DateTimeImmutable('2026-07-12 15:00:00'));
        $this->assertSame(100, $puntos);
    }

    public function testSlaPermiteNivelClienteYExcepcionPorTipo(): void
    {
        dominio_sla_establecer_politicas([
            ['nivel_cliente' => 'premium', 'tipo_incidencia' => '*', 'urgencia' => 'urgente', 'primera_respuesta_horas' => 2, 'resolucion_horas' => 8],
            ['nivel_cliente' => 'premium', 'tipo_incidencia' => 'Seguridad', 'urgencia' => 'urgente', 'primera_respuesta_horas' => 1, 'resolucion_horas' => 4],
        ]);

        $general = dominio_sla_objetivos('urgente', 'Correo', 'premium');
        $seguridad = dominio_sla_objetivos('urgente', 'Seguridad', 'premium');

        $this->assertSame(8, $general['resolucion']);
        $this->assertSame('*', $general['tipo_politica']);
        $this->assertSame(4, $seguridad['resolucion']);
        $this->assertSame('Seguridad', $seguridad['tipo_politica']);
        dominio_sla_establecer_politicas([]);
    }
}
