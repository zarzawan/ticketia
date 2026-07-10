# TicketIA

**Helpdesk open source con IA — self-hosted, multilingüe y con soporte de IA 100% local.**

*Open source AI-powered helpdesk — self-hosted, multilingual, works with fully local AI.*

> ⚠️ **Estado: en desarrollo activo (pre-v1.0).** Incluye autenticación con roles, CSRF y
> auditoría, pero aún no ha pasado una revisión de seguridad externa.

---

## ¿Qué es?

TicketIA es un sistema de gestión de incidencias que usa modelos de lenguaje (LLM) para
automatizar el trabajo repetitivo del soporte:

- 🤖 **Triage automático**: cada ticket nace clasificado (urgencia, departamento, idioma,
  resumen y recomendación de actuación) sin bloquear al usuario — la IA trabaja en segundo plano.
- 🌍 **Multilingüe real**: el cliente escribe en su idioma; el equipo trabaja en español.
  Traducción bidireccional con caché (cada contenido se traduce una sola vez).
- 📊 **Análisis ejecutivo del backlog**: informes generados por IA en streaming, con vista
  especializada en incidencias de seguridad.
- 🔍 **Detección de duplicados** al crear un ticket (búsqueda FULLTEXT).
- ✍️ **Borradores de respuesta** sugeridos por IA en la conversación.
- 💼 **Recomendación comercial**: cruza tickets comerciales con tu catálogo de productos.
- 📈 **Observabilidad de IA**: latencia, tokens y errores por proveedor.
- 🔒 **Privacidad**: funciona con IA local (LM Studio, Ollama o cualquier API compatible
  OpenAI). Los proveedores cloud (OpenAI, xAI) son opcionales. Los modelos razonadores
  (DeepSeek-R1, Qwen3, gpt-oss…) están soportados: el razonamiento se filtra automáticamente.
- 🏢 **Multicliente con portal**: cada empresa ve solo sus tickets; los clientes crean y
  siguen los suyos desde un portal propio, sin acceso a los datos internos del equipo.
- 📎 **Adjuntos seguros**: validación por contenido real, almacenados fuera del docroot y
  descarga siempre autenticada.
- 🗒️ **Notas internas** en la conversación, visibles solo para el equipo (nunca se traducen
  ni llegan al cliente).
- ✉️ **Notificaciones por email** (opcionales, SMTP): ticket nuevo, respuestas y cambios de
  estado, con enlaces al portal o al panel según el destinatario.
- ⚙️ **Cola de trabajos IA**: si el proveedor está caído, la clasificación se encola y un
  worker la reintenta con backoff; incluye límite diario de llamadas de pago y modo
  solo-local para coste cero garantizado.
- 🛡️ **Panel de administración**: usuarios y roles, empresas, auditoría completa y ajustes
  del sistema con prueba de conexión IA en vivo.

Interfaz con panel Kanban (arrastrar y soltar), filtros persistentes, estadísticas
clicables, cola personal del operador, asignación de técnicos y modo oscuro.

## Capturas

| Panel del equipo | Detalle de incidencia |
|---|---|
| ![Panel Kanban](docs/capturas/panel.png) | ![Detalle](docs/capturas/detalle.png) |

| Portal de cliente | Administración |
|---|---|
| ![Portal](docs/capturas/portal.png) | ![Admin](docs/capturas/admin.png) |

## Requisitos

- PHP >= 8.2 con `pdo_mysql`, `curl`, `mbstring`
- MySQL 8 / MariaDB 10.6+
- Composer
- Opcional: un servidor de IA local (LM Studio u Ollama) o claves de OpenAI/xAI

## Instalación con Docker (recomendada)

```bash
git clone https://github.com/TU_USUARIO/ticketia.git
cd ticketia
cp .env.example .env          # ajusta la IA si quieres usarla
docker compose up -d
docker compose exec php composer install
docker compose exec php php bin/instalar.php --con-demo
```

Abre **http://localhost:8080** — el instalador habrá creado el usuario administrador
(`admin@ticketia.local` con contraseña generada, mostrada por consola; o pasa
`--admin-email=... --admin-pass=...` para elegirla) y tendrás el panel con datos de
demostración. Desde **Usuarios** puedes crear el resto de cuentas (admin, operador,
comercial, cliente).

> Para usar tu IA local desde Docker, en `.env` usa
> `LLM_LOCAL_ENDPOINT=http://host.docker.internal:1234/v1/chat/completions`.

## Instalación manual (XAMPP, hosting compartido, VPS…)

```bash
git clone https://github.com/TU_USUARIO/ticketia.git
cd ticketia
composer install
cp .env.example .env          # configura tu base de datos y la IA
php bin/instalar.php --con-demo
```

Apunta el *document root* de tu servidor web al directorio **`public/`** y abre `index.php`.

## Configurar la IA

Todo se configura en `.env` (ver `.env.example`):

| Variable | Descripción |
|---|---|
| `LLM_PROVIDER` | Proveedor activo: `local`, `openai` o `xai` (también cambiable desde el panel) |
| `LLM_LOCAL_ENDPOINT` | URL de tu servidor local compatible OpenAI (LM Studio: puerto 1234, Ollama: 11434) |
| `LLM_LOCAL_MODEL` | Identificador del modelo cargado |
| `OPENAI_API_KEY` / `XAI_API_KEY` | Solo si quieres proveedores cloud (de pago, clave propia) |

Sin IA configurada, TicketIA funciona como un helpdesk convencional: los tickets se crean
con valores por defecto y puedes clasificarlos a mano o re-clasificarlos con IA más tarde.

Control de coste: `LLM_SOLO_LOCAL=1` ignora los proveedores de pago aunque haya claves, y
`LLM_MAX_LLAMADAS_DIA` corta las llamadas de pago al alcanzar el cupo diario.

## Worker de trabajos IA

Las tareas de IA que fallan (proveedor caído, timeout) se encolan y se reintentan con
backoff. Programa el worker con cron o el Programador de tareas de Windows:

```bash
php bin/worker.php            # procesa hasta 10 trabajos y termina
php bin/worker.php --bucle    # en bucle continuo (servicio)
```

## Notificaciones por email (opcional)

Configura `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS` y `APP_URL` en `.env` (ver
`.env.example`). Sin SMTP configurado no se envía nada y todo funciona igual.

## Tests

```bash
vendor/bin/phpunit
```

La CI de GitHub Actions ejecuta lint, tests (PHP 8.2–8.3), escaneo de secretos y el build
de la imagen Docker en cada push y pull request.

## Hoja de ruta hacia v1.0

- [x] Núcleo de tickets + IA (POC endurecida)
- [x] Instalador, migraciones, Docker, datos de demo
- [x] Autenticación, roles, CSRF y auditoría (2FA y recuperación por email, pendientes)
- [x] Multicliente y adjuntos
- [x] Portal de cliente con notificaciones email
- [x] Panel de administración
- [x] Cola de trabajos IA y control de coste
- [x] Tests + CI
- [ ] Release v1.0 y repositorio público

Post-v1: email-to-ticket, interfaz en inglés (i18n), búsqueda semántica con embeddings,
SLA con alertas, API REST, 2FA TOTP y recuperación de contraseña por email.

## Contribuir

Issues y pull requests son bienvenidos. Lee [CONTRIBUTING.md](CONTRIBUTING.md), el
[código de conducta](CODE_OF_CONDUCT.md) y la [política de seguridad](SECURITY.md).

## Licencia

[AGPL-3.0](LICENSE) — TicketIA es libre y lo seguirá siendo: cualquier servicio basado en
este código debe publicar sus modificaciones.
