<?php

use Phinx\Migration\AbstractMigration;

/**
 * Esquema inicial de TicketIA: el modelo de datos heredado de la POC,
 * ya con asignacion, cache de traducciones, logs de IA y ajustes.
 */
final class EsquemaInicial extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE incidencias (
                id INT(11) NOT NULL AUTO_INCREMENT,
                titulo VARCHAR(255) NOT NULL,
                descripcion TEXT NOT NULL,
                estado ENUM('abierta','en_curso','cerrada') NOT NULL DEFAULT 'abierta',
                fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_cierre DATETIME NULL DEFAULT NULL,
                urgencia VARCHAR(20) NULL DEFAULT 'leve',
                recomendacion TEXT NULL,
                tipo VARCHAR(50) NULL DEFAULT NULL,
                asignado_a VARCHAR(100) NULL DEFAULT NULL,
                resumen VARCHAR(255) NULL DEFAULT NULL,
                idioma VARCHAR(8) NULL DEFAULT 'es',
                PRIMARY KEY (id),
                INDEX idx_estado (estado),
                INDEX idx_urgencia (urgencia),
                INDEX idx_tipo (tipo),
                INDEX idx_asignado (asignado_a),
                INDEX idx_fecha_creacion (fecha_creacion),
                FULLTEXT INDEX ft_busqueda (titulo, descripcion, resumen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE mensajes (
                id INT(11) NOT NULL AUTO_INCREMENT,
                id_incidencia INT(11) NOT NULL,
                autor VARCHAR(50) NOT NULL,
                mensaje TEXT NOT NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_incidencia (id_incidencia),
                CONSTRAINT fk_mensajes_incidencia FOREIGN KEY (id_incidencia)
                    REFERENCES incidencias (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE reaperturas (
                id INT(11) NOT NULL AUTO_INCREMENT,
                id_incidencia INT(11) NOT NULL,
                motivo TEXT NOT NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_incidencia (id_incidencia),
                CONSTRAINT fk_reaperturas_incidencia FOREIGN KEY (id_incidencia)
                    REFERENCES incidencias (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE catalogo_productos (
                id INT(11) NOT NULL AUTO_INCREMENT,
                nombre VARCHAR(150) NOT NULL,
                descripcion TEXT NULL,
                categoria VARCHAR(80) NULL,
                precio DECIMAL(10,2) NULL,
                caracteristicas TEXT NULL,
                esfuerzo VARCHAR(20) NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE recomendaciones_venta (
                id INT(11) NOT NULL AUTO_INCREMENT,
                id_incidencia INT(11) NOT NULL,
                producto VARCHAR(150) NOT NULL,
                guion TEXT NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_incidencia (id_incidencia),
                CONSTRAINT fk_recomendaciones_incidencia FOREIGN KEY (id_incidencia)
                    REFERENCES incidencias (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE traducciones (
                id INT(11) NOT NULL AUTO_INCREMENT,
                id_incidencia INT(11) NOT NULL,
                idioma_destino VARCHAR(8) NOT NULL DEFAULT 'es',
                contenido_hash CHAR(40) NOT NULL,
                payload LONGTEXT NOT NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_traduccion (id_incidencia, idioma_destino),
                CONSTRAINT fk_traducciones_incidencia FOREIGN KEY (id_incidencia)
                    REFERENCES incidencias (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE llm_logs (
                id INT(11) NOT NULL AUTO_INCREMENT,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                proveedor VARCHAR(20) NOT NULL,
                modo VARCHAR(20) NOT NULL,
                modelo VARCHAR(100) NOT NULL,
                origen VARCHAR(100) NOT NULL,
                duracion_ms INT(11) NOT NULL,
                http_code INT(11) NULL,
                exito TINYINT(1) NOT NULL DEFAULT 0,
                tokens_entrada INT(11) NULL,
                tokens_salida INT(11) NULL,
                error VARCHAR(255) NULL,
                PRIMARY KEY (id),
                INDEX idx_fecha (fecha),
                INDEX idx_proveedor (proveedor)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE ajustes (
                clave VARCHAR(50) NOT NULL,
                valor TEXT NOT NULL,
                PRIMARY KEY (clave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        foreach (['ajustes', 'llm_logs', 'traducciones', 'recomendaciones_venta', 'catalogo_productos', 'reaperturas', 'mensajes', 'incidencias'] as $tabla) {
            $this->execute("DROP TABLE IF EXISTS $tabla");
        }
    }
}
