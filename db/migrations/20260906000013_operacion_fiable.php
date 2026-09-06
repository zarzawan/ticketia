<?php

use Phinx\Migration\AbstractMigration;

final class OperacionFiable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE trabajos_ia ADD reserva_token CHAR(32) NULL,
            ADD reservado_hasta DATETIME NULL, ADD INDEX idx_reserva (estado, reservado_hasta)");
        $this->execute("ALTER TABLE mensajes ADD solicitud_id CHAR(32) NULL,
            ADD UNIQUE KEY uq_mensaje_solicitud (usuario_id, solicitud_id)");
        $this->execute("ALTER TABLE copiloto_ia ADD fuentes_json TEXT NULL");
        $this->execute("CREATE TABLE soporte_borradores (
            usuario_id INT NOT NULL, incidencia_id INT NOT NULL,
            mensaje TEXT NOT NULL, accion VARCHAR(16) NOT NULL DEFAULT 'responder',
            version INT NOT NULL DEFAULT 1,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (usuario_id, incidencia_id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (incidencia_id) REFERENCES incidencias(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE soporte_borradores');
        $this->execute('ALTER TABLE copiloto_ia DROP COLUMN fuentes_json');
        $this->execute('ALTER TABLE mensajes DROP INDEX uq_mensaje_solicitud, DROP COLUMN solicitud_id');
        $this->execute('ALTER TABLE trabajos_ia DROP INDEX idx_reserva, DROP COLUMN reserva_token, DROP COLUMN reservado_hasta');
    }
}
