<?php

use Phinx\Migration\AbstractMigration;

/** Indices compuestos para colas, historico y calculo del siguiente paso. */
final class IndicesOperativos extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE incidencias
                ADD INDEX idx_estado_id_archivo (estado, id, fecha_archivo),
                ADD INDEX idx_estado_asignado_id (estado, asignado_id, id),
                ADD INDEX idx_estado_urgencia_id (estado, urgencia, id)
        ");
        $this->execute("
            ALTER TABLE mensajes
                ADD INDEX idx_incidencia_publica_fecha (id_incidencia, interno, fecha, id)
        ");
    }

    public function down(): void
    {
        $this->execute("
            ALTER TABLE mensajes DROP INDEX idx_incidencia_publica_fecha
        ");
        $this->execute("
            ALTER TABLE incidencias
                DROP INDEX idx_estado_id_archivo,
                DROP INDEX idx_estado_asignado_id,
                DROP INDEX idx_estado_urgencia_id
        ");
    }
}
