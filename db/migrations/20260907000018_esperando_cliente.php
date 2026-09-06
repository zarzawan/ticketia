<?php
use Phinx\Migration\AbstractMigration;

final class EsperandoCliente extends AbstractMigration {
    public function up(): void {
        $this->execute("ALTER TABLE incidencias MODIFY estado ENUM('abierta','en_curso','esperando_cliente','resuelta','cerrada') NOT NULL DEFAULT 'abierta'");
    }
    public function down(): void {
        // No convertir silenciosamente estados que tienen significado operativo.
        if ((int)$this->fetchRow("SELECT COUNT(*) AS total FROM incidencias WHERE estado='esperando_cliente'")['total'] > 0) {
            throw new RuntimeException('Retoma las incidencias en espera antes de revertir esta migracion.');
        }
        $this->execute("ALTER TABLE incidencias MODIFY estado ENUM('abierta','en_curso','resuelta','cerrada') NOT NULL DEFAULT 'abierta'");
    }
}
