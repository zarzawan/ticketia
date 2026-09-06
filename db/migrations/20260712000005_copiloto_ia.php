<?php

use Phinx\Migration\AbstractMigration;

/** Cache del copiloto operativo por incidencia y version del contenido. */
final class CopilotoIa extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE copiloto_ia (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_incidencia INT NOT NULL,
                contenido_hash CHAR(40) NOT NULL,
                resumen TEXT NOT NULL,
                riesgo VARCHAR(20) NOT NULL DEFAULT 'medio',
                sentimiento VARCHAR(30) NOT NULL DEFAULT 'neutral',
                siguiente_accion TEXT NOT NULL,
                respuesta_sugerida TEXT NULL,
                confianza TINYINT UNSIGNED NOT NULL DEFAULT 50,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_copiloto_ticket (id_incidencia),
                CONSTRAINT fk_copiloto_incidencia FOREIGN KEY (id_incidencia)
                    REFERENCES incidencias (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS copiloto_ia');
    }
}
