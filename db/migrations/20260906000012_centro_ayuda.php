<?php

use Phinx\Migration\AbstractMigration;

final class CentroAyuda extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE conocimiento (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(180) NOT NULL,
            resumen VARCHAR(400) NOT NULL,
            contenido MEDIUMTEXT NOT NULL,
            categoria VARCHAR(80) NOT NULL DEFAULT 'General',
            visibilidad ENUM('interno','clientes') NOT NULL DEFAULT 'interno',
            estado ENUM('borrador','publicado','archivado') NOT NULL DEFAULT 'borrador',
            creado_por INT NULL,
            actualizado_por INT NULL,
            version INT NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_conocimiento_publicacion (estado, visibilidad, actualizado_en),
            FULLTEXT INDEX ft_conocimiento (titulo, resumen, contenido),
            FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
            FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->execute("CREATE TABLE satisfaccion_servicio (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            incidencia_id INT NOT NULL,
            usuario_id INT NOT NULL,
            puntuacion TINYINT UNSIGNED NOT NULL,
            comentario VARCHAR(1000) NOT NULL DEFAULT '',
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_satisfaccion (incidencia_id, usuario_id),
            INDEX idx_satisfaccion_fecha (actualizado_en),
            FOREIGN KEY (incidencia_id) REFERENCES incidencias(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            CHECK (puntuacion BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE satisfaccion_servicio');
        $this->execute('DROP TABLE conocimiento');
    }
}
