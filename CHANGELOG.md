# Changelog

## Proximamente

### Mejorado

- Soporte simplificado: Kanban por defecto, tecnico asignable directamente desde
  cada tarjeta, busqueda visible y filtros secundarios sin decenas de botones.
- Un unico editor para respuesta, solucion o nota interna, con borrador IA revisable.
- Al proponer una solucion se guarda un mensaje publico de forma atomica con el
  cambio de estado; el comentario se conserva al reabrir y no se duplica al reenviar.
- Bandeja con filtros SLA y ordenacion global antes de paginar; lista y columnas
  Kanban permiten recorrer todos los resultados. CSV respeta la cola seleccionada.
- Estado del procesador automatico basado en actividad real del CLI, con aviso
  configurable por ausencia de senal y distincion del procesado manual.
- Pruebas aisladas de volumen (1002 activas, 2500 cerradas), equivalencia SLA y worker.
- Rediseño común de acceso, administración y portal: navegación agrupada con
  búsqueda, iconos, formularios plegables, foco visible y adaptación móvil.
- Directorios paginados de usuarios y organizaciones; conservación de las acciones
  de acceso y contraseñas dentro de un menú de seguridad.
- Centro de conocimiento con borradores asistidos por IA, revisión editorial,
  audiencias, archivo y protección contra ediciones concurrentes.
- Portal con ayudas al alta, sugerencias de guías, siguiente paso por solicitud,
  historial separado y valoración del servicio.
- Pruebas HTTP aisladas de permisos, publicación, feedback, contraseñas y streaming.
- Corrección del control IA vacío y límites de paginación.

- Despliegue Docker autocontenido con extensiones PHP completas, configuracion de
  produccion, Nginx inmutable, volumen de adjuntos, healthchecks y worker persistente.
- Configuracion compatible con variables reales del proceso y con `.env`, sin copiar
  secretos ni configuracion local dentro de las imagenes.
- Prueba de humo Docker en CI con migraciones, extensiones, worker e inicio de sesion
  real, ademas de los tests unitarios existentes.
- Segundo factor TOTP por usuario con secretos cifrados, proteccion contra reutilizacion
  y ocho codigos de recuperacion de un solo uso.
- Recuperacion de contrasena por email con respuesta anti-enumeracion, limites de envio,
  tokens de 30 minutos almacenados como hash y revocacion de sesiones anteriores.
- Administracion de cuentas ampliada con estado 2FA, retirada segura del segundo factor,
  envio de enlaces de acceso y revocacion al cambiar roles, estado o contrasena.
- Edicion de contrasenas con formulario visible, validacion comun y errores controlados;
  una migracion pendiente se informa en pantalla en lugar de provocar un error fatal.
- Centro de control IA con fiabilidad, latencia, tokens, errores frecuentes, contexto de
  incidencia, adopcion de borradores y feedback humano por periodo y proveedor.
- Feedback util/no util en el copiloto y trazabilidad de borradores usados y enviados,
  sin bloquear el trabajo si falla la telemetria.
- Retencion configurable de actividad IA con limpieza automatica por lotes desde el worker.
- Nuevo espacio profesional de soporte con navegacion lateral, cabecera adaptable y una jerarquia comun para bandeja y detalle.
- Bandeja simplificada sin accesos duplicados: las colas y el alta de incidencias viven en el menu lateral, mientras el resumen y la analitica permanecen visibles sobre los filtros.
- Colas inteligentes para incidencias que requieren respuesta, SLA en riesgo, espera del cliente y tickets sin asignar.
- Lista operativa predeterminada y Kanban opcional, con prioridad explicable, siguiente paso en lenguaje claro, ordenacion por columna, seleccion multiple y acciones masivas seguras.
- SLA configurables por nivel de cliente, tipo de incidencia y urgencia, con objetivos de primera respuesta y resolucion visibles en tarjetas y detalle.
- Copiloto IA por incidencia con traduccion, borrador reutilizable y asistencia comercial integrada con el catalogo.
- Actividad compacta sin repetir descripciones ni mensajes, con enlaces al contenido relacionado.
- Primera respuesta publica del equipo mueve automaticamente la incidencia abierta a trabajo en curso.
- Taxonomia de urgencias normalizada y protegida en base de datos para evitar filtros y metricas fragmentados.
- Nueva portada de administracion con prioridades, carga del equipo, salud de servicios, actividad reciente y accesos rapidos.
- Navegacion administrativa lateral y adaptable a movil para mantener contexto entre usuarios, empresas, auditoria, ajustes y actividad IA.
- Portal de cliente renovado con buscador, tarjetas de seguimiento, prioridad, mensajes y fecha de ultima actividad.
- Mejoras de legibilidad, estados vacios, jerarquia visual y comportamiento responsive en las superficies administrativas y de cliente.
- Ciclo de vida ITSM con solucion propuesta, confirmacion o rechazo del cliente, cierre automatico configurable y archivo logico paginado.
- Las incidencias cerradas desaparecen de la bandeja activa y siguen disponibles en un historial separado, indexado y consultable.
- Administracion ampliada con reglas de ciclo de vida, mantenimiento manual, volumen por fase y gestion del catalogo que usa la IA comercial.
- Analisis IA limitado al trabajo activo y a un contexto acotado de 60 incidencias para mantener tiempos y consumo previsibles al crecer el historico.

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
