<?php
use Phinx\Migration\AbstractMigration;

final class CalendarioServicio extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE calendario_servicio (
            fecha DATE PRIMARY KEY, apertura DATETIME NOT NULL, cierre DATETIME NOT NULL,
            acumulado_inicio BIGINT NOT NULL, acumulado_fin BIGINT NOT NULL,
            INDEX idx_calendario_fin (acumulado_fin), INDEX idx_calendario_inicio (acumulado_inicio)
        ) ENGINE=InnoDB");
    }
    public function down(): void { $this->execute('DROP TABLE calendario_servicio'); }
}
