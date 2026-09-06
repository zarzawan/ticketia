<?php
use PHPUnit\Framework\TestCase;

final class BandejaTest extends TestCase {
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
