<?php
use Phinx\Migration\AbstractMigration;

final class MemoriaYCalidad extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE memoria_ia (
            incidencia_id INT PRIMARY KEY, hasta_id INT NOT NULL DEFAULT 0, resumen TEXT NOT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (incidencia_id) REFERENCES incidencias(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE conocimiento_valoraciones (
            articulo_id INT NOT NULL, usuario_id INT NOT NULL, util TINYINT NOT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (articulo_id,usuario_id),
            FOREIGN KEY (articulo_id) REFERENCES conocimiento(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            CHECK (util IN (0,1))) ENGINE=InnoDB");
    }
    public function down(): void { $this->execute('DROP TABLE conocimiento_valoraciones'); $this->execute('DROP TABLE memoria_ia'); }
}
