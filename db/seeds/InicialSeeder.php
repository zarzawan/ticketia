<?php

use Phinx\Seed\AbstractSeed;

/**
 * Datos de demostracion: catalogo, empresa demo, tickets (datos_demo.json)
 * y conversaciones (conversaciones_demo.json). Solo se insertan sobre tablas
 * vacias para no duplicar en re-ejecuciones.
 */
class InicialSeeder extends AbstractSeed
{
    public function run(): void
    {
        $this->sembrarCatalogo();
        $clienteDemo = $this->sembrarClienteDemo();
        $this->sembrarIncidencias($clienteDemo);
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

    /** Crea la empresa de demostracion y devuelve su id (o el de la existente). */
    private function sembrarClienteDemo(): int
    {
        $existente = $this->fetchRow("SELECT id FROM clientes ORDER BY id LIMIT 1");
        if ($existente) {
            return (int)$existente['id'];
        }

        $conexion = $this->getAdapter()->getConnection();
        $conexion->prepare("INSERT INTO clientes (nombre, email_contacto) VALUES ('Empresa Demo SL', 'contacto@empresademo.example')")
            ->execute();
        return (int)$conexion->lastInsertId();
    }

    private function sembrarIncidencias(int $clienteDemo): void
    {
        $total = (int)$this->fetchRow('SELECT COUNT(*) AS n FROM incidencias')['n'];
        if ($total > 0) {
            return;
        }

        $tickets = json_decode((string)file_get_contents(__DIR__ . '/datos_demo.json'), true);
        if (!is_array($tickets)) {
            throw new RuntimeException('No se pudo leer datos_demo.json');
        }

        $conexion = $this->getAdapter()->getConnection();

        $sql = $conexion->prepare(
            "INSERT INTO incidencias (cliente_id, titulo, descripcion, estado, fecha_creacion, fecha_cierre, urgencia, recomendacion, tipo, resumen, idioma)
             VALUES (:cliente, :titulo, :descripcion, :estado, NOW() - INTERVAL :dias DAY, :fecha_cierre, :urgencia, :recomendacion, :tipo, :resumen, :idioma)"
        );

        $ids = [];
        foreach ($tickets as $i => $t) {
            $dias = (int)($t['dias_antiguedad'] ?? 0);
            $sql->execute([
                // La mitad de los tickets de demo pertenecen a la empresa demo
                ':cliente' => $i % 2 === 0 ? $clienteDemo : null,
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

        // Conversaciones generadas para la demo (autor cliente/tecnico y notas internas).
        $rutaConv = __DIR__ . '/conversaciones_demo.json';
        if (is_file($rutaConv)) {
            $conversaciones = json_decode((string)file_get_contents($rutaConv), true) ?: [];
            $msg = $conexion->prepare(
                "INSERT INTO mensajes (id_incidencia, autor, mensaje, interno, fecha)
                 VALUES (:id, :autor, :mensaje, :interno, NOW() - INTERVAL :minutos MINUTE)"
            );
            foreach ($conversaciones as $conv) {
                $indice = (int)($conv['n'] ?? 0) - 1;
                if (!isset($ids[$indice])) {
                    continue;
                }
                $totalMsgs = count($conv['mensajes']);
                foreach ($conv['mensajes'] as $j => $m) {
                    $msg->execute([
                        ':id' => $ids[$indice],
                        ':autor' => $m['autor'],
                        ':mensaje' => $m['mensaje'],
                        ':interno' => (int)($m['interno'] ?? 0),
                        // separar los mensajes en el tiempo, el ultimo el mas reciente
                        ':minutos' => ($totalMsgs - $j) * 90,
                    ]);
                }
            }
        }

        // Una reapertura de ejemplo en el segundo ticket.
        if (isset($ids[1])) {
            $conexion->prepare("INSERT INTO reaperturas (id_incidencia, motivo, fecha) VALUES (:id, :motivo, NOW())")
                ->execute([':id' => $ids[1], ':motivo' => 'El problema ha vuelto a aparecer esta manana tras el ultimo despliegue.']);
        }
    }
}
