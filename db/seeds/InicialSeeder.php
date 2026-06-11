<?php

use Phinx\Seed\AbstractSeed;

/**
 * Datos de demostracion: catalogo de ejemplo y los tickets de db/seeds/datos_demo.json.
 * Solo se insertan si las tablas estan vacias, para no duplicar en re-ejecuciones.
 */
class InicialSeeder extends AbstractSeed
{
    public function run(): void
    {
        $this->sembrarCatalogo();
        $this->sembrarIncidencias();
    }

    private function sembrarCatalogo(): void
    {
        $total = (int)$this->fetchRow('SELECT COUNT(*) AS n FROM catalogo_productos')['n'];
        if ($total > 0) {
            return;
        }

        $this->table('catalogo_productos')->insert([
            ['nombre' => 'Servidor Cloud Basico', 'descripcion' => 'Instancia cloud de 2 vCPU y 4 GB de RAM con disco SSD, ideal para webs y aplicaciones pequenas.', 'categoria' => 'computacion', 'precio' => 19.90, 'caracteristicas' => '2 vCPU, 4 GB RAM, 80 GB SSD, trafico ilimitado', 'esfuerzo' => 'bajo'],
            ['nombre' => 'Servidor Cloud Avanzado', 'descripcion' => 'Instancia cloud de 8 vCPU y 32 GB de RAM para cargas de produccion exigentes.', 'categoria' => 'computacion', 'precio' => 89.90, 'caracteristicas' => '8 vCPU, 32 GB RAM, 320 GB NVMe, snapshots diarios', 'esfuerzo' => 'medio'],
            ['nombre' => 'Backup Gestionado', 'descripcion' => 'Copias de seguridad automatizadas con retencion de 30 dias y restauracion asistida.', 'categoria' => 'backups', 'precio' => 14.90, 'caracteristicas' => 'Retencion 30 dias, cifrado en reposo, restauracion en 4h', 'esfuerzo' => 'bajo'],
            ['nombre' => 'Archivado a Largo Plazo', 'descripcion' => 'Almacenamiento frio para retencion normativa de documentos y registros.', 'categoria' => 'archivado', 'precio' => 9.90, 'caracteristicas' => 'Retencion 7 anos, WORM, acceso bajo demanda', 'esfuerzo' => 'bajo'],
            ['nombre' => 'Firewall Gestionado', 'descripcion' => 'Proteccion perimetral administrada con reglas personalizadas y monitorizacion 24x7.', 'categoria' => 'seguridad', 'precio' => 49.90, 'caracteristicas' => 'WAF, IDS/IPS, informes mensuales', 'esfuerzo' => 'medio'],
            ['nombre' => 'Consultoria de Migracion', 'descripcion' => 'Acompanamiento tecnico para migraciones a cloud, cumplimiento normativo y arquitecturas complejas.', 'categoria' => 'consultoria', 'precio' => 350.00, 'caracteristicas' => 'Analisis previo, plan de migracion, ejecucion asistida', 'esfuerzo' => 'alto'],
        ])->saveData();
    }

    private function sembrarIncidencias(): void
    {
        $total = (int)$this->fetchRow('SELECT COUNT(*) AS n FROM incidencias')['n'];
        if ($total > 0) {
            return;
        }

        $ruta = __DIR__ . '/datos_demo.json';
        $tickets = json_decode((string)file_get_contents($ruta), true);
        if (!is_array($tickets)) {
            throw new RuntimeException("No se pudo leer $ruta");
        }

        $conexion = $this->getAdapter()->getConnection();

        $sql = $conexion->prepare(
            "INSERT INTO incidencias (titulo, descripcion, estado, fecha_creacion, fecha_cierre, urgencia, recomendacion, tipo, resumen, idioma)
             VALUES (:titulo, :descripcion, :estado, NOW() - INTERVAL :dias DAY, :fecha_cierre, :urgencia, :recomendacion, :tipo, :resumen, :idioma)"
        );

        $ids = [];
        foreach ($tickets as $t) {
            $dias = (int)($t['dias_antiguedad'] ?? 0);
            $sql->execute([
                ':titulo' => $t['titulo'],
                ':descripcion' => $t['descripcion'],
                ':estado' => $t['estado'],
                ':dias' => $dias,
                ':fecha_cierre' => $t['estado'] === 'cerrada' ? date('Y-m-d H:i:s', strtotime("-" . max(0, $dias - 1) . " days")) : null,
                ':urgencia' => $t['urgencia'],
                ':recomendacion' => $t['recomendacion'] ?? null,
                ':tipo' => $t['tipo'],
                ':resumen' => mb_substr($t['resumen'] ?? $t['titulo'], 0, 250),
                ':idioma' => $t['idioma'],
            ]);
            $ids[] = (int)$conexion->lastInsertId();
        }

        // Conversacion de ejemplo en los dos primeros tickets y una reapertura.
        if (count($ids) >= 2) {
            $msg = $conexion->prepare(
                "INSERT INTO mensajes (id_incidencia, autor, mensaje, fecha) VALUES (:id, :autor, :mensaje, NOW() - INTERVAL :dias DAY)"
            );
            $msg->execute([':id' => $ids[0], ':autor' => 'tecnico', ':mensaje' => 'Hemos recibido su incidencia y estamos revisando los registros del sistema. Le informaremos en cuanto tengamos un diagnostico.', ':dias' => 2]);
            $msg->execute([':id' => $ids[0], ':autor' => 'cliente', ':mensaje' => 'Gracias por la rapida respuesta. Quedamos a la espera de novedades.', ':dias' => 1]);
            $msg->execute([':id' => $ids[1], ':autor' => 'tecnico', ':mensaje' => 'El problema quedo resuelto tras aplicar la correccion. Quedamos atentos por si reaparece.', ':dias' => 1]);

            $conexion->prepare(
                "INSERT INTO reaperturas (id_incidencia, motivo, fecha) VALUES (:id, :motivo, NOW())"
            )->execute([':id' => $ids[1], ':motivo' => 'El problema ha vuelto a aparecer esta manana tras el ultimo despliegue.']);
        }
    }
}
