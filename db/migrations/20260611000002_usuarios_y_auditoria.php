<?php

use Phinx\Migration\AbstractMigration;

/**
 * Fase 1: autenticacion y trazabilidad.
 * - clientes: empresas a las que pertenecen los usuarios con rol cliente.
 * - usuarios: cuentas con rol (admin, operador, comercial, cliente).
 * - auditoria: registro de acciones relevantes.
 * - incidencias.asignado_a (texto libre) pasa a asignado_id (FK a usuarios).
 */
final class UsuariosYAuditoria extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE clientes (
                id INT(11) NOT NULL AUTO_INCREMENT,
                nombre VARCHAR(150) NOT NULL,
                email_contacto VARCHAR(190) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE usuarios (
                id INT(11) NOT NULL AUTO_INCREMENT,
                nombre VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL,
                hash_password VARCHAR(255) NOT NULL,
                rol ENUM('admin','operador','comercial','cliente') NOT NULL DEFAULT 'operador',
                cliente_id INT(11) NULL DEFAULT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                intentos_fallidos INT(11) NOT NULL DEFAULT 0,
                bloqueado_hasta DATETIME NULL DEFAULT NULL,
                ultimo_acceso DATETIME NULL DEFAULT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_email (email),
                INDEX idx_rol (rol),
                CONSTRAINT fk_usuarios_cliente FOREIGN KEY (cliente_id)
                    REFERENCES clientes (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE auditoria (
                id INT(11) NOT NULL AUTO_INCREMENT,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                usuario_id INT(11) NULL,
                usuario_email VARCHAR(190) NULL,
                accion VARCHAR(80) NOT NULL,
                detalle VARCHAR(500) NULL,
                ip VARCHAR(45) NULL,
                PRIMARY KEY (id),
                INDEX idx_fecha (fecha),
                INDEX idx_usuario (usuario_id),
                INDEX idx_accion (accion)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Asignacion sobre usuarios reales (la columna de texto libre era de la POC).
        $this->execute("ALTER TABLE incidencias DROP INDEX idx_asignado, DROP COLUMN asignado_a");
        $this->execute("
            ALTER TABLE incidencias
                ADD COLUMN asignado_id INT(11) NULL DEFAULT NULL AFTER tipo,
                ADD INDEX idx_asignado (asignado_id),
                ADD CONSTRAINT fk_incidencias_asignado FOREIGN KEY (asignado_id)
                    REFERENCES usuarios (id) ON DELETE SET NULL
        ");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE incidencias DROP FOREIGN KEY fk_incidencias_asignado, DROP INDEX idx_asignado, DROP COLUMN asignado_id");
        $this->execute("ALTER TABLE incidencias ADD COLUMN asignado_a VARCHAR(100) NULL DEFAULT NULL AFTER tipo, ADD INDEX idx_asignado (asignado_a)");
        $this->execute("DROP TABLE IF EXISTS auditoria");
        $this->execute("DROP TABLE IF EXISTS usuarios");
        $this->execute("DROP TABLE IF EXISTS clientes");
    }
}
