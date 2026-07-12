# Changelog

## Proximamente

### Mejorado

- Nuevo espacio profesional de soporte con navegacion lateral, cabecera adaptable y una jerarquia comun para bandeja y detalle.
- Bandeja simplificada sin accesos duplicados: las colas y el alta de incidencias viven en el menu lateral, mientras el resumen y la analitica permanecen visibles sobre los filtros.
- Colas inteligentes para incidencias que requieren respuesta, SLA en riesgo, espera del cliente y tickets sin asignar.
- Vista Kanban predeterminada y lista operativa con prioridad explicable, senales de turno, ordenacion ascendente o descendente por columna, seleccion multiple y acciones masivas de estado o asignacion.
- SLA configurables por nivel de cliente, tipo de incidencia y urgencia, con objetivos de primera respuesta y resolucion visibles en tarjetas y detalle.
- Copiloto IA por incidencia con traduccion, borrador reutilizable y asistencia comercial integrada con el catalogo.
- Actividad compacta sin repetir descripciones ni mensajes, con enlaces al contenido relacionado.
- Primera respuesta publica del equipo mueve automaticamente la incidencia abierta a trabajo en curso.
- Taxonomia de urgencias normalizada y protegida en base de datos para evitar filtros y metricas fragmentados.
- Nueva portada de administracion con prioridades, carga del equipo, salud de servicios, actividad reciente y accesos rapidos.
- Navegacion administrativa lateral y adaptable a movil para mantener contexto entre usuarios, empresas, auditoria, ajustes y actividad IA.
- Portal de cliente renovado con buscador, tarjetas de seguimiento, prioridad, mensajes y fecha de ultima actividad.
- Mejoras de legibilidad, estados vacios, jerarquia visual y comportamiento responsive en las superficies administrativas y de cliente.

Los cambios relevantes de cada versión. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es/) y el versionado,
[SemVer](https://semver.org/lang/es/).

## [1.0.0] — 2026-07-10

Primera versión estable y pública.

### Núcleo de tickets
- Panel Kanban con arrastrar y soltar, filtros persistentes, estadísticas clicables,
  cola personal del operador y modo oscuro.
- Alta de tickets en dos fases: el ticket se crea al instante y la IA lo clasifica en
  segundo plano (urgencia, departamento, idioma, resumen y recomendación).
- Historial de cambios de estado, reaperturas con motivo, detección de duplicados
  (FULLTEXT), exportación CSV filtrada.

### IA
- Proveedores: IA local (cualquier API compatible OpenAI: LM Studio, Ollama…), OpenAI y
  xAI, conmutables desde el panel. Sin IA configurada funciona como helpdesk convencional.
- Soporte de modelos razonadores: el razonamiento (think/harmony) se filtra siempre,
  también en streaming.
- Traducción bidireccional con caché, análisis ejecutivo del backlog en streaming,
  borradores de respuesta, recomendación comercial sobre catálogo propio.
- Cola de trabajos con worker (`bin/worker.php`), reintentos con backoff, límite diario
  de llamadas de pago (`LLM_MAX_LLAMADAS_DIA`) y modo solo-local (`LLM_SOLO_LOCAL`).
- Observabilidad: latencia, tokens y errores por proveedor.

### Seguridad y multiusuario
- Autenticación con Argon2id, bloqueo por intentos, sesiones endurecidas, CSRF global,
  cabeceras de seguridad y auditoría completa.
- Roles admin / operador / comercial / cliente, multicliente por empresa.
- Portal de cliente sin datos internos; notas internas del equipo nunca visibles ni
  traducidas.
- Adjuntos validados por contenido, almacenados fuera del docroot, descarga autenticada.
- Notificaciones por email opcionales (PHPMailer/SMTP).

### Infraestructura
- Instalador CLI, migraciones Phinx, datos de demo, Docker (nginx + PHP-FPM + MariaDB),
  tests PHPUnit y CI en GitHub Actions (lint, tests, gitleaks, build Docker).
