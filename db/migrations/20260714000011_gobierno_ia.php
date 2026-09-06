<?php

use Phinx\Migration\AbstractMigration;

/** Trazabilidad por usuario/incidencia y feedback humano del copiloto. */
final class GobiernoIa extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE llm_logs
                ADD COLUMN usuario_id INT(11) NULL AFTER origen,
                ADD COLUMN incidencia_id INT(11) NULL AFTER usuario_id,
                ADD INDEX idx_llm_fecha_exito (fecha, exito),
                ADD INDEX idx_llm_proveedor_fecha (proveedor, fecha),
                ADD INDEX idx_llm_incidencia (incidencia_id),
                ADD CONSTRAINT fk_llm_log_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_llm_log_incidencia FOREIGN KEY (incidencia_id)
                    REFERENCES incidencias (id) ON DELETE SET NULL
        ");

        $this->execute("
            CREATE TABLE feedback_ia (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id INT(11) NULL,
                incidencia_id INT(11) NOT NULL,
                tipo VARCHAR(30) NOT NULL DEFAULT 'copiloto',
                contenido_hash CHAR(40) NOT NULL,
                valoracion TINYINT NULL,
                borrador_usado TINYINT(1) NOT NULL DEFAULT 0,
                borrador_enviado TINYINT(1) NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_feedback_ia (usuario_id, incidencia_id, tipo, contenido_hash),
                INDEX idx_feedback_fecha (actualizado_en),
                INDEX idx_feedback_incidencia (incidencia_id),
                CONSTRAINT fk_feedback_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE SET NULL,
                CONSTRAINT fk_feedback_incidencia FOREIGN KEY (incidencia_id)
                    REFERENCES incidencias (id) ON DELETE CASCADE,
                CONSTRAINT chk_feedback_valoracion CHECK (valoracion IS NULL OR valoracion IN (-1, 1))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS feedback_ia");
        $this->execute("
            ALTER TABLE llm_logs
                DROP FOREIGN KEY fk_llm_log_incidencia,
                DROP FOREIGN KEY fk_llm_log_usuario,
                DROP INDEX idx_llm_incidencia,
                DROP INDEX idx_llm_proveedor_fecha,
                DROP INDEX idx_llm_fecha_exito,
                DROP COLUMN incidencia_id,
                DROP COLUMN usuario_id
        ");
    }
}
