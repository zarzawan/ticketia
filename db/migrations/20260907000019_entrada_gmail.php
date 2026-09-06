<?php
use Phinx\Migration\AbstractMigration;
final class EntradaGmail extends AbstractMigration {
    public function up(): void {
        $this->execute("CREATE TABLE gmail_entradas (
            id INT AUTO_INCREMENT PRIMARY KEY, buzon VARCHAR(254) NOT NULL, mensaje_id VARCHAR(64) NOT NULL,
            hilo_id VARCHAR(64) NOT NULL, remitente VARCHAR(254) NOT NULL DEFAULT '',
            asunto VARCHAR(250) NOT NULL, cuerpo TEXT NOT NULL, aviso VARCHAR(500) NOT NULL DEFAULT '',
            estado ENUM('pendiente','importado','descartado') NOT NULL DEFAULT 'pendiente',
            incidencia_id INT NULL, recibido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revisado_por INT NULL, UNIQUE KEY uq_gmail_mensaje (buzon,mensaje_id),
            INDEX idx_gmail_revision (estado,id), INDEX idx_gmail_hilo (buzon,hilo_id),
            FOREIGN KEY (incidencia_id) REFERENCES incidencias(id), FOREIGN KEY (revisado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->execute("CREATE TABLE gmail_sincronizacion (
            buzon VARCHAR(254) PRIMARY KEY, etiqueta VARCHAR(100) NOT NULL, cursor_pagina TEXT NOT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(): void { $this->execute('DROP TABLE gmail_sincronizacion'); $this->execute('DROP TABLE gmail_entradas'); }
}
