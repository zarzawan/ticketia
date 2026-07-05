<?php

use Phinx\Migration\AbstractMigration;

/**
 * Fase 2: modelo multicliente y adjuntos.
 * - incidencias: empresa propietaria (cliente_id) y usuario creador (creado_por).
 * - mensajes: autoria real (usuario_id) y notas internas no visibles para el cliente.
 * - adjuntos: ficheros asociados a incidencias (almacenados fuera del docroot).
 * - cambios_estado: historial completo de transiciones para el timeline.
 */
final class MulticlienteYAdjuntos extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE incidencias
                ADD COLUMN cliente_id INT(11) NULL DEFAULT NULL AFTER id,
                ADD COLUMN creado_por INT(11) NULL DEFAULT NULL AFTER cliente_id,
                ADD INDEX idx_cliente (cliente_id),
                ADD CONSTRAINT fk_incidencias_cliente FOREIGN KEY (cliente_id)
                    REFERENCES clientes (id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_incidencias_creador FOREIGN KEY (creado_por)
                    REFERENCES usuarios (id) ON DELETE SET NULL
        ");

        $this->execute("
            ALTER TABLE mensajes
                ADD COLUMN usuario_id INT(11) NULL DEFAULT NULL AFTER id_incidencia,
                ADD COLUMN interno TINYINT(1) NOT NULL DEFAULT 0 AFTER mensaje,
                ADD CONSTRAINT fk_mensajes_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE SET NULL
        ");

        $this->execute("
            CREATE TABLE adjuntos (
                id INT(11) NOT NULL AUTO_INCREMENT,
                id_incidencia INT(11) NOT NULL,
                usuario_id INT(11) NULL DEFAULT NULL,
                nombre_original VARCHAR(255) NOT NULL,
                nombre_disco VARCHAR(80) NOT NULL,
                mime VARCHAR(100) NOT NULL,
                tamano INT(11) NOT NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_incidencia (id_incidencia),
                UNIQUE KEY uq_nombre_disco (nombre_disco),
                CONSTRAINT fk_adjuntos_incidencia FOREIGN KEY (id_incidencia)
                    REFERENCES incidencias (id) ON DELETE CASCADE,
                CONSTRAINT fk_adjuntos_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE cambios_estado (
                id INT(11) NOT NULL AUTO_INCREMENT,
                id_incidencia INT(11) NOT NULL,
                usuario_id INT(11) NULL DEFAULT NULL,
                estado_anterior VARCHAR(20) NULL,
                estado_nuevo VARCHAR(20) NOT NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_incidencia (id_incidencia),
                CONSTRAINT fk_cambios_incidencia FOREIGN KEY (id_incidencia)
                    REFERENCES incidencias (id) ON DELETE CASCADE,
                CONSTRAINT fk_cambios_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS cambios_estado");
        $this->execute("DROP TABLE IF EXISTS adjuntos");
        $this->execute("ALTER TABLE mensajes DROP FOREIGN KEY fk_mensajes_usuario, DROP COLUMN usuario_id, DROP COLUMN interno");
        $this->execute("ALTER TABLE incidencias DROP FOREIGN KEY fk_incidencias_cliente, DROP FOREIGN KEY fk_incidencias_creador, DROP INDEX idx_cliente, DROP COLUMN cliente_id, DROP COLUMN creado_por");
    }
}
