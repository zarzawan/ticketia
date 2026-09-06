<?php

use Phinx\Migration\AbstractMigration;

/**
 * Recuperacion de acceso, segundo factor TOTP y revocacion de sesiones.
 */
final class SeguridadCuentas extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE usuarios
                ADD COLUMN sesion_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER bloqueado_hasta,
                ADD COLUMN totp_secreto_cifrado VARCHAR(255) NULL AFTER sesion_version,
                ADD COLUMN totp_activado_en DATETIME NULL AFTER totp_secreto_cifrado,
                ADD COLUMN totp_ultimo_periodo BIGINT UNSIGNED NULL AFTER totp_activado_en
        ");

        $this->execute("
            CREATE TABLE recuperaciones_password (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id INT(11) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expira_en DATETIME NOT NULL,
                usado_en DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_recuperacion_token (token_hash),
                INDEX idx_recuperacion_usuario_fecha (usuario_id, creado_en),
                INDEX idx_recuperacion_expira (expira_en),
                CONSTRAINT fk_recuperacion_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE codigos_recuperacion_2fa (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id INT(11) NOT NULL,
                codigo_hash CHAR(64) NOT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_codigo_2fa (usuario_id, codigo_hash),
                CONSTRAINT fk_codigo_2fa_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS codigos_recuperacion_2fa");
        $this->execute("DROP TABLE IF EXISTS recuperaciones_password");
        $this->execute("
            ALTER TABLE usuarios
                DROP COLUMN totp_ultimo_periodo,
                DROP COLUMN totp_activado_en,
                DROP COLUMN totp_secreto_cifrado,
                DROP COLUMN sesion_version
        ");
    }
}
