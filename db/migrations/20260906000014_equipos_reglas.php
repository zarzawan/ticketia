<?php
use Phinx\Migration\AbstractMigration;

final class EquiposReglas extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE equipos_soporte (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL UNIQUE)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->execute("CREATE TABLE equipos_miembros (equipo_id INT NOT NULL, usuario_id INT NOT NULL,
            PRIMARY KEY (equipo_id,usuario_id), FOREIGN KEY (equipo_id) REFERENCES equipos_soporte(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE) ENGINE=InnoDB");
        $this->execute("CREATE TABLE reglas_asignacion (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL,
            equipo_id INT NOT NULL, cliente_id INT NULL, tipo VARCHAR(100) NOT NULL DEFAULT '',
            prioridad INT NOT NULL DEFAULT 100, activo TINYINT NOT NULL DEFAULT 0,
            FOREIGN KEY (equipo_id) REFERENCES equipos_soporte(id), FOREIGN KEY (cliente_id) REFERENCES clientes(id),
            INDEX idx_reglas_activas (activo, prioridad, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(): void
    {
        $this->execute('DROP TABLE reglas_asignacion');
        $this->execute('DROP TABLE equipos_miembros');
        $this->execute('DROP TABLE equipos_soporte');
    }
}
