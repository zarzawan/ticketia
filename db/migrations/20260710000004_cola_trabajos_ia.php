<?php

use Phinx\Migration\AbstractMigration;

/**
 * Cola de trabajos IA: permite reintentar en segundo plano las tareas de IA
 * que fallan en linea (proveedor caido, timeout) y procesar lotes con
 * bin/worker.php sin bloquear peticiones web.
 */
final class ColaTrabajosIa extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE IF NOT EXISTS trabajos_ia (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tipo VARCHAR(40) NOT NULL,
                payload TEXT NOT NULL,
                estado ENUM('pendiente','en_curso','completado','fallido') NOT NULL DEFAULT 'pendiente',
                intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_intentos TINYINT UNSIGNED NOT NULL DEFAULT 3,
                ultimo_error VARCHAR(255) DEFAULT NULL,
                programado_para DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_estado_programado (estado, programado_para)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS trabajos_ia");
    }
}
