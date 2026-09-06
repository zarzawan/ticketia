<?php

use Phinx\Migration\AbstractMigration;

/** Niveles de servicio de cliente y politicas SLA configurables por tipo. */
final class SlaConfigurable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE clientes ADD COLUMN nivel_servicio VARCHAR(20) NOT NULL DEFAULT 'estandar' AFTER email_contacto");
        $this->execute("ALTER TABLE clientes ADD CONSTRAINT chk_clientes_nivel_servicio CHECK (nivel_servicio IN ('estandar','preferente','premium'))");
        $this->execute("
            CREATE TABLE sla_politicas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nivel_cliente VARCHAR(20) NOT NULL,
                tipo_incidencia VARCHAR(100) NOT NULL DEFAULT '*',
                urgencia VARCHAR(20) NOT NULL,
                primera_respuesta_horas SMALLINT UNSIGNED NOT NULL,
                resolucion_horas SMALLINT UNSIGNED NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sla_politica (nivel_cliente, tipo_incidencia, urgencia),
                CONSTRAINT chk_sla_nivel CHECK (nivel_cliente IN ('estandar','preferente','premium')),
                CONSTRAINT chk_sla_urgencia CHECK (urgencia IN ('critico','urgente','leve')),
                CONSTRAINT chk_sla_horas CHECK (primera_respuesta_horas <= resolucion_horas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            INSERT INTO sla_politicas (nivel_cliente, tipo_incidencia, urgencia, primera_respuesta_horas, resolucion_horas) VALUES
            ('estandar', '*', 'critico', 1, 4), ('estandar', '*', 'urgente', 4, 16), ('estandar', '*', 'leve', 8, 48),
            ('preferente', '*', 'critico', 1, 3), ('preferente', '*', 'urgente', 2, 12), ('preferente', '*', 'leve', 6, 36),
            ('premium', '*', 'critico', 1, 2), ('premium', '*', 'urgente', 2, 8), ('premium', '*', 'leve', 4, 24)
        ");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS sla_politicas');
        $this->execute('ALTER TABLE clientes DROP CONSTRAINT chk_clientes_nivel_servicio');
        $this->execute('ALTER TABLE clientes DROP COLUMN nivel_servicio');
    }
}
