<?php
use Phinx\Migration\AbstractMigration;

final class RespuestasReutilizables extends AbstractMigration {
    public function up(): void {
        $this->execute("CREATE TABLE respuestas_reutilizables (
            clave VARCHAR(40) NOT NULL PRIMARY KEY, titulo VARCHAR(120) NOT NULL,
            contenido TEXT NOT NULL, activo TINYINT NOT NULL DEFAULT 1,
            version INT NOT NULL DEFAULT 1,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(): void { $this->execute('DROP TABLE respuestas_reutilizables'); }
}
