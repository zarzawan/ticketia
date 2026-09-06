# TicketIA — referencia amplia para agentes y nuevos desarrolladores

Este documento conserva el traspaso de conocimiento detallado del proyecto.
Consulta solo la sección necesaria para la tarea actual; la guía breve y de
carga automática está en `../AGENTS.md`.

## Alcance seguro y contexto mínimo para agentes

TicketIA es una aplicación propia y autorizada. El mantenimiento de sesiones,
roles, CSRF, credenciales de desarrollo y aislamiento entre clientes tiene un
fin exclusivamente defensivo: corregir y verificar la aplicación.

- Empieza por `git status`, el diff y los archivos directamente relacionados
  con la petición. No hagas una auditoría general si no se ha solicitado.
- No leas ni muestres por defecto `.env`, `AGENTS.local.md`, volcados de base de
  datos, adjuntos o archivos de credenciales. Si son imprescindibles, consulta
  solo el dato mínimo y redacta valores sensibles en la salida.
- Evita volcar archivos grandes, árboles recursivos o registros completos en el
  contexto del modelo. Usa búsquedas acotadas, fragmentos y colas de log breves.
- No arranques, detengas ni repares Apache o MariaDB para una tarea de código
  salvo que la verificación lo necesite. Conserva datos y procesos existentes.
- Divide los trabajos amplios en cambios verificables. Tras un turno muy largo,
  resume el estado y usa una conversación nueva para una tarea distinta, en vez
  de reenviar todo el historial y las salidas de herramientas.

## Qué es

Helpdesk open source con IA (AGPL-3.0), self-hosted, PHP 8.2 **procedural sin
framework** + MariaDB/MySQL + Composer. Publicado en
https://github.com/zarzawan/ticketia (release v1.0.0). La IA clasifica tickets,
traduce conversaciones, sugiere respuestas y analiza el backlog; funciona con
IA 100% local (LM Studio/Ollama/API compatible OpenAI) y opcionalmente
OpenAI/xAI.

Historia: nació como POC monousuario y se industrializó en 8 fases (repo nuevo
sin el historial de la POC). Todo el detalle de decisiones está en el
CHANGELOG y en los mensajes de commit, que son deliberadamente descriptivos.

## Principios del proyecto (respétalos)

1. **Sin framework y sin build**: PHP plano, vanilla JS, un solo CSS
   (`public/estilos.css`, sistema de diseño estilo Apple con variables CSS y
   tema oscuro vía `data-theme`). No introduzcas frameworks, bundlers ni
   dependencias pesadas sin necesidad clara.
2. **Español como idioma del código**: identificadores, comentarios, commits y
   literales de interfaz en español **sin acentos ni eñes** en el código fuente.
3. **Degradación elegante**: sin IA configurada la app es un helpdesk normal;
   sin SMTP no se envía nada. Los fallos de IA/correo/auditoría nunca rompen la
   petición del usuario.
4. **Seguridad por defecto**: toda página de `public/` pasa por el guard
   global; todo POST exige CSRF; los adjuntos viven fuera del docroot; nunca
   se confía en datos del cliente para autorización.
5. **Verificación empírica**: cada cambio se prueba contra la aplicación
   corriendo (curl/capturas), no solo con lint. Hay receta completa en
   `.claude/skills/verify/SKILL.md` (login por curl, SMTP de captura,
   capturas headless). Úsala.

## Arquitectura

```
public/            docroot (Apache/nginx apuntan AQUÍ)
  index.php        panel del equipo: dashboard KPI + filtros + kanban + alta
  ver_incidencia.php  detalle: conversación, notas internas, adjuntos, timeline, IA
  portal.php / portal_ver.php   portal del rol cliente (sin datos internos)
  login.php / logout.php
  admin_*.php      panel de administración (usuarios, clientes/empresas, auditoría, ajustes)
  guardar_*.php, mover_*, cerrar_*, reabrir_*, asignar_*, subir/descargar_adjunto.php  endpoints POST
  analisis*.php, consultar_llm.php, sugerir_respuesta.php, recomendar_venta.php  funciones IA
  estilos.css      ÚNICO css
src/
  arranque.php     bootstrap único: TODAS las páginas hacen require de este fichero.
                   Carga .env → config → auth → dominio → ui → llm →
                   clasificacion → adjuntos → correo → trabajos, y aplica el
                   GUARD GLOBAL (sesión, CSRF en POST, páginas admin solo rol
                   admin, rol cliente confinado al portal y viceversa).
  config.php       PDO ($pdo global) + $llm_config/$llm_provider desde .env;
                   override de proveedor desde la tabla ajustes; LLM_SOLO_LOCAL.
  auth.php         login/lockout (5 intentos/15 min), Argon2id, sesión endurecida,
                   csrf_token/csrf_campo/csrf_verificar (falla con HTTP 400),
                   auditar(), seguridad_cabeceras() (CSP incluida), usuarios_asignables().
  dominio.php      catálogos (tipos/urgencias/estados), dominio_append_filtros
                   (SQL parametrizado), dominio_order_by (whitelist),
                   incidencia_cambiar_estado (historial + email),
                   incidencia_visible_para_cliente (autorización del portal).
  ui.php           helpers de render: ui_e (escape), tarjeta kanban, chip usuario,
                   markdown seguro (marked+DOMPurify por CDN), layout admin.
  llm.php          capa LLM completa. Ver sección IA.
  clasificacion.php  prompt de clasificación + clasificacion_aplicar.
  structured_output.php  esquema JSON para la clasificación.
  adjuntos.php     whitelist de extensiones + validación finfo; almacén en
                   almacen/adjuntos (fuera del docroot, nombres aleatorios).
  correo.php       PHPMailer/SMTP opcional; notificaciones de ticket nuevo,
                   respuesta y cambio de estado (best effort, nunca rompe).
  trabajos.php     cola trabajos_ia: reclamo atómico FOR UPDATE, backoff 2/10/30 min.
bin/
  instalar.php     instalador CLI (BD, migraciones, seeds, admin inicial).
  worker.php       procesa la cola IA (--lote=N | --bucle | --reintentar-fallidos).
  smtp_captura_dev.py  servidor SMTP de pruebas (guarda .eml).
db/
  migrations/      Phinx, SQL crudo. NUNCA edites una migración ya publicada:
                   crea una nueva. Config en phinx.php (lee .env).
  seeds/           InicialSeeder + catalogo_demo.json (115 productos),
                   datos_demo.json (16 tickets en 4 idiomas), conversaciones_demo.json.
tests/             PHPUnit (unitarios puros, sin BD). bootstrap.php carga solo
                   llm+dominio+ui+adjuntos.
docker/            Dockerfile multi-etapa + nginx.conf (fastcgi_buffering off
                   para SSE). docker-compose.yml en la raíz. Validado solo en CI.
.github/workflows/ci.yml   lint + phpunit (PHP 8.2/8.3) + gitleaks + build Docker.
```

### Tablas principales

`incidencias` (cliente_id, creado_por, asignado_id, tipo, urgencia, estado,
idioma, resumen, recomendacion) · `mensajes` (usuario_id, autor
cliente|tecnico, interno 0/1) · `usuarios` (rol admin|operador|comercial|cliente,
cliente_id, hash_password, intentos_fallidos, bloqueado_hasta) · `clientes`
(empresas) · `adjuntos` · `cambios_estado` · `reaperturas` · `traducciones`
(caché por hash de contenido) · `llm_logs` (observabilidad IA) · `auditoria` ·
`ajustes` (clave/valor, ej. proveedor IA activo) · `trabajos_ia` (cola) ·
`catalogo_productos` (para recomendación comercial).

## Subsistemas que debes entender antes de tocarlos

### IA (src/llm.php)

- `LLMHttpClient` habla con cualquier API compatible OpenAI. Fachadas:
  `LLMClient::getResponse()`, `LLMClient::streamResponse()` (SSE),
  `LLMClientTraductor::getResponse()`. El proveedor activo sale de
  `$llm_provider` (env + override en tabla ajustes).
- **Filtro de razonamiento**: los modelos reasoner (DeepSeek-R1, Qwen3,
  gpt-oss) anteponen su "pensamiento". `llm_strip_razonamiento()` lo elimina
  de respuestas completas y `LLMFiltroRazonamiento` lo hace en streaming
  (máquina de estados con retención de etiquetas partidas y red de seguridad).
  Cubre `<think>...</think>` y canales harmony `<|channel|>analysis...`,
  incluidas las variantes degradadas `<|channel>thought...<channel|>` que
  emite LM Studio. Hay tests exhaustivos en
  `tests/LlmFiltroRazonamientoTest.php`. Si tocas el filtro, corre los tests
  y prueba en streaming real.
- **Alta en dos fases** (`guardar_incidencia.php`): el ticket se inserta al
  instante con valores por defecto, se responde al usuario cerrando la
  conexión (`Connection: close` + `fastcgi_finish_request`), y la
  clasificación IA corre después en el mismo proceso. Si falla, se encola en
  `trabajos_ia` y `bin/worker.php` la reintenta.
- **Control de coste**: `LLM_MAX_LLAMADAS_DIA` corta proveedores de pago
  (cuenta sobre llm_logs, la IA local nunca se limita); `LLM_SOLO_LOCAL=1`
  ignora las claves de pago.
- **Traducción de contenido**: cacheada en la tabla `traducciones` con hash
  sha1 del contenido; una traducción por contenido. Las respuestas del equipo
  a tickets en otro idioma se traducen al enviarse (nunca las notas internas).

### Traducción de contenido

- La interfaz permanece en español. No hay i18n de interfaz ni selector de
  idioma.
- Los **datos** (títulos de tickets, mensajes, departamentos) no se traducen en
  la capa de interfaz: eso lo gestiona la traducción IA de contenido cuando
  corresponde.

### Roles y autorización

- Guard global en `arranque.php`: páginas públicas = solo login.php; POST ⇒
  CSRF (campo `csrf` o cabecera `X-CSRF`); lista `$paginas_admin` ⇒ rol admin;
  rol cliente confinado a `$paginas_cliente` (portal + endpoints que usa) y el
  resto de roles no entra al portal.
- El portal filtra SIEMPRE por ámbito con `incidencia_visible_para_cliente()`:
  empresa del usuario o, si no tiene, tickets creados por él. Cualquier
  endpoint nuevo que un cliente pueda tocar debe repetir esa comprobación.
- Los clientes nunca ven: notas internas, asignaciones, recomendaciones IA,
  datos de otros clientes. `usuarios_asignables()` = admin+operador activos;
  al quitarle a alguien ese rol, sus tickets abiertos vuelven a la cola
  (admin_usuarios.php).

## Entorno de desarrollo y verificación

- Desarrollo actual: **XAMPP en Windows** (Apache + mod_php + MariaDB 10.4).
  Binarios: `C:\xampp\php\php.exe`, `C:\xampp\mysql\bin\mysql.exe`. La app se
  sirve en `http://localhost/ticketia/public`. Composer y Python 3 disponibles.
- Instalación desde cero: `composer install && cp .env.example .env` (ajustar
  BD) `&& php bin/instalar.php --con-demo`.
- Tests: `vendor/bin/phpunit` (23 tests, deben quedar en verde SIEMPRE).
- Lint: `php -l` sobre cada fichero tocado.
- **Verificación E2E**: sigue `.claude/skills/verify/SKILL.md`. Resumen: login
  por curl (campo `password`, CSRF con sed), batería de flujos por rol,
  `bin/smtp_captura_dev.py` para probar correos reales, BD temporal para
  probar seeds (NUNCA re-siembres la BD de desarrollo: tiene datos del
  propietario), capturas con Edge headless (`--headless=new --screenshot`,
  rutas Windows con `cygpath -m`, HTML bajado con curl + `<base href>`).
- CI en cada push: lint, phpunit en 8.2/8.3, gitleaks (historial completo) y
  build Docker. No hagas push si phpunit falla en local.

## Gotchas aprendidos (te ahorrarán horas)

- **LM Studio del propietario devuelve formato harmony** aunque el modelo
  cargado diga ser otro: cualquier texto que generes con esa IA local debe
  pasarse por `llm_strip_razonamiento()`.
- **MariaDB de XAMPP se corrompe con apagados bruscos** (tablas Aria del
  esquema mysql: db, global_priv, columns_priv). Síntoma: mysqld arranca y
  muere sin loguear error; se ve con `mysqld --console` ("Incorrect file
  format"). Arreglo: restaurar la tabla desde `C:\xampp\mysql\backup\mysql\`,
  re-otorgar GRANTs, `mysqlcheck --repair` (columns_priv necesita
  `SET SESSION aria_sort_buffer_size` grande o retry keycache).
- `http_response_code(419)` bajo Apache mod_php produce 500: usar 400.
- No usar `FILTER_SANITIZE_STRING` (eliminado en PHP 8): `trim((string)(...))`.
- Cookies/headers: emitir antes de cualquier salida.
- CSP activa: nada de scripts externos fuera de cdn.jsdelivr.net; el JS inline
  está permitido pero prefiere soluciones server-side.
- Los filtros del panel persisten en localStorage (`incidencias_filtros`) y se
  restauran al volver al index: si "faltan" tickets, es un filtro pegado
  (botón Limpiar). Decisión del propietario: sin aviso visible.
- Git en Windows: warnings LF→CRLF constantes e inofensivos. El shell de
  trabajo resetea el cwd entre comandos: usa rutas absolutas o `cd` al inicio.
- gh CLI NO está instalado: para la API de GitHub usa el token de
  `git credential fill` (protocol=https, host=github.com) con curl.
- phpunit de este repo no toca BD; los flujos con BD se verifican por HTTP.

## Estado actual y backlog

Completado: fases 0–7 del plan (núcleo, auth/roles, multicliente/adjuntos/
admin, portal cliente + email, colas operador, cola IA + costes, tests + CI,
release v1.0.0 pública).

Backlog priorizado (post-v1):
1. Recuperación de contraseña por email + 2FA TOTP (el SMTP ya existe).
2. Email-to-ticket (crear tickets desde un buzón IMAP).
3. SLA con alertas (avisos por urgencia/antigüedad; los datos ya están).
4. API REST (para integraciones; pensar en tokens por usuario).
5. Búsqueda semántica con embeddings (hoy hay FULLTEXT).
6. Mejoras de comunidad: responder issues/discussions, README en inglés.

## Reglas para contribuir cambios

- Commits en español, imperativo, cuerpo explicando el porqué (mira
  `git log` como referencia de estilo). CHANGELOG.md para cambios de versión.
- Migraciones: siempre nuevas, nunca editar las publicadas.
- Nada de secretos en el repo (gitleaks corre en CI; .env está gitignorado).
- Verifica contra la app corriendo antes de commitear; si el cambio toca
  seguridad o el portal, prueba explícitamente el acceso indebido (debe fallar).
