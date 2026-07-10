# Changelog

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
