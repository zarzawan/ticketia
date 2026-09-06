# Changelog

## Proximamente — v2.1 en desarrollo

- Estado explicito Esperando al cliente: peticion publica y transicion atomicas,
  retorno a En trabajo al responder el cliente, cola propia y presencia en
  informes, portal y predicciones. No pausa ni modifica los objetivos SLA.
- Gmail / Workspace: conector OAuth de solo lectura, lotes con cursor y
  deduplicacion, bandeja de revision administrativa y alta supervisada con IA.
  Respuestas supervisadas sobre el mismo hilo, visibles en la conversacion,
  con aislamiento por cliente y retorno desde Esperando al cliente a En trabajo.
  No reabre incidencias cerradas. Requiere consentimiento del propietario.
  Diagnostico local en administracion y CLI --comprobar, sin contactar con Google
  ni mostrar credenciales; distingue configuracion de autorizacion verificada.

- IA bajo demanda en predicciones, redaccion de respuestas compartidas y
  organizacion del reparto. Validacion estructurada, cache privada y propuestas
  revisables, sin cambios automaticos ni modificaciones del streaming.
- Equipos y reparto con guia de inicio y deteccion de esquema incompleto.

- Predicciones para administracion: historico por organizacion, graficos de
  actividad y estados, calidad percibida, cobertura y senales explicables de
  riesgo y oportunidad. Son reglas orientativas, no probabilidades entrenadas.
- Colas con contadores, cambio de cola sin arrastrar responsable/estado y entrada
  al Kanban sin restaurar filtros compartidos entre cuentas del navegador.
- Biblioteca de cinco respuestas reutilizables con vista previa, insercion sin
  borrar el borrador, edicion por administradores, desactivacion y control de
  concurrencia. La migracion 17 habilita persistir las ediciones; las bases se
  pueden consultar y usar sin ella.

## [2.0.0] - 2026-09-07

### Operacion profesional

- Respuestas idempotentes y transaccionales; control de cambios concurrentes y
  reaperturas atomicas, tambien compatibles con acciones masivas.
- Borradores privados con guardado automatico, vista previa del Kanban, asignacion
  propia en un clic y conversaciones paginadas por cursor.
- Copiloto con fuentes de conocimiento publicado y soluciones del mismo cliente;
  memoria progresiva acotada y retirada del porcentaje de confianza autodeclarado.
- Recuperacion de reservas interrumpidas y cola de correo con reintentos. Los
  mensajes de recuperacion de contrasena mantienen el envio directo.
- Centro de operaciones con alertas, entregas, conservacion tecnica confirmada y
  registro de comprobaciones manuales de restauracion.
- Equipos y reparto automatico con simulacion obligatoria antes de activar reglas.
- Calendario laboral opcional con jornada y festivos, y calculo de hora civil
  consistente entre SQL y PHP sin reinterpretar fechas historicas.
- Valoraciones de utilidad de las guias y deteccion de conocimiento por revisar.
- 56 pruebas unitarias, 419 comprobaciones HTTP, ensayo con 10000 mensajes,
  receptor SMTP ficticio y comprobaciones de navegador en escritorio y movil.

### Actualizacion desde 1.0.0

- Crear y comprobar una copia antes de actualizar. Ejecutar las migraciones
  pendientes con Phinx, nunca los seeds, y reiniciar el worker de forma planificada.
- El worker pasa a ser necesario para entregar notificaciones ordinarias de soporte.
- Las reglas y el calendario quedan desactivados hasta su configuracion explicita.
- Las incidencias y adjuntos se conservan; no hay purga automatica del contenido
  del cliente. SMTP puede repetir una entrega si se pierde su confirmacion.
- Instrucciones y limites: [Operacion profesional](docs/OPERACION_PROFESIONAL.md).

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
- Lista operativa alternativa al Kanban, con prioridad explicable, siguiente paso en lenguaje claro, ordenacion por columna, seleccion multiple y acciones masivas seguras.
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
