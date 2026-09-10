<?php
use PHPUnit\Framework\TestCase;

final class BandejaTest extends TestCase {
    public function testCambiarColaEliminaFiltrosIncompatibles(): void {
        $url = bandeja_url_cola('accion', ['filtro_asignado'=>'sin_asignar','filtro_estado'=>'resuelta',
            'cola'=>'sla','pagina'=>8,'pagina_abierta'=>4,'busqueda'=>'correo','vista'=>'lista','filtro_tipo'=>'Correo']);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['busqueda'=>'correo','vista'=>'lista','filtro_tipo'=>'Correo','cola'=>'accion'], $query);
        $this->assertSame('index.php', bandeja_url_cola('todas', ['cola'=>'sla','filtro_asignado'=>1]));
        $this->assertSame('index.php?filtro_estado=resuelta', bandeja_url_cola('confirmar', ['vista'=>['invalida']]));
    }

    public function testOrdenacionSoloAdmiteExpresionesConocidas(): void {
        $this->assertSame('sla_restante ASC, id ASC', bandeja_orden_sql('sla', 'asc'));
        $this->assertSame('prioridad_operativa DESC, id DESC', bandeja_orden_sql('prioridad', 'DROP TABLE'));
        $this->assertSame('id DESC, id DESC', bandeja_orden_sql('id; DROP TABLE incidencias', 'asc', 'invalido'));
    }

    public function testColaSlaNoIncluyeCerradas(): void {
        $sql = 'SELECT id FROM fuente WHERE 1=1';
        bandeja_append_cola($sql, 'sla');
        $this->assertStringContainsString("estado <> 'cerrada'", $sql);
        $this->assertStringContainsString("sla_estado IN ('riesgo','vencido')", $sql);
        $original = $sql;
        bandeja_append_cola($sql, "' OR 1=1");
        $this->assertSame($original, $sql);
    }
}
