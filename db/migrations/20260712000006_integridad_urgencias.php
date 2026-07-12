<?php

use Phinx\Migration\AbstractMigration;

/** Normaliza valores heredados y protege la taxonomia de urgencias. */
final class IntegridadUrgencias extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE incidencias SET urgencia = 'urgente' WHERE urgencia = 'urgent'");
        $this->execute("UPDATE incidencias SET urgencia = 'leve' WHERE urgencia IS NULL OR urgencia NOT IN ('critico','urgente','leve')");
        $this->execute("ALTER TABLE incidencias ADD CONSTRAINT chk_incidencias_urgencia CHECK (urgencia IN ('critico','urgente','leve'))");
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE incidencias DROP CONSTRAINT chk_incidencias_urgencia');
    }
}
