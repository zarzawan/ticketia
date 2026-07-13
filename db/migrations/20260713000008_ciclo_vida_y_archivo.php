<?php

use Phinx\Migration\AbstractMigration;

/**
 * Ciclo de vida escalable inspirado en ITSM:
 * - el equipo propone una solucion antes del cierre definitivo;
 * - el cliente puede confirmarla o rechazarla;
 * - los cierres antiguos pasan a un archivo logico, sin perder datos;
 * - el catalogo comercial puede administrarse sin borrar productos historicos.
 */
final class CicloVidaYArchivo extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE incidencias
                MODIFY COLUMN estado ENUM('abierta','en_curso','resuelta','cerrada') NOT NULL DEFAULT 'abierta',
                ADD COLUMN fecha_resolucion DATETIME NULL DEFAULT NULL AFTER fecha_cierre,
                ADD COLUMN resolucion_codigo VARCHAR(40) NULL DEFAULT NULL AFTER fecha_resolucion,
                ADD COLUMN resolucion_notas TEXT NULL AFTER resolucion_codigo,
                ADD COLUMN resuelto_por INT(11) NULL DEFAULT NULL AFTER resolucion_notas,
                ADD COLUMN fecha_archivo DATETIME NULL DEFAULT NULL AFTER resuelto_por,
                ADD INDEX idx_estado_cierre (estado, fecha_cierre, id),
                ADD INDEX idx_archivo (fecha_archivo, id),
                ADD INDEX idx_cliente_estado (cliente_id, estado, id),
                ADD CONSTRAINT fk_incidencias_resuelto_por FOREIGN KEY (resuelto_por)
                    REFERENCES usuarios (id) ON DELETE SET NULL
        ");

        $this->execute("
            ALTER TABLE catalogo_productos
                ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER esfuerzo,
                ADD INDEX idx_catalogo_activo_categoria (activo, categoria)
        ");

        $this->execute("
            INSERT INTO ajustes (clave, valor) VALUES
                ('dias_cierre_automatico', '7'),
                ('dias_archivo_automatico', '30')
            ON DUPLICATE KEY UPDATE clave = VALUES(clave)
        ");
    }

    public function down(): void
    {
        $this->execute("UPDATE incidencias SET estado = 'en_curso' WHERE estado = 'resuelta'");
        $this->execute("
            ALTER TABLE incidencias
                DROP FOREIGN KEY fk_incidencias_resuelto_por,
                DROP INDEX idx_estado_cierre,
                DROP INDEX idx_archivo,
                DROP INDEX idx_cliente_estado,
                DROP COLUMN fecha_archivo,
                DROP COLUMN resuelto_por,
                DROP COLUMN resolucion_notas,
                DROP COLUMN resolucion_codigo,
                DROP COLUMN fecha_resolucion,
                MODIFY COLUMN estado ENUM('abierta','en_curso','cerrada') NOT NULL DEFAULT 'abierta'
        ");
        $this->execute("ALTER TABLE catalogo_productos DROP INDEX idx_catalogo_activo_categoria, DROP COLUMN activo");
        $this->execute("DELETE FROM ajustes WHERE clave IN ('dias_cierre_automatico', 'dias_archivo_automatico')");
    }
}
