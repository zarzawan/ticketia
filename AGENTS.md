# TicketIA — guía breve para agentes

Helpdesk self-hosted en PHP 8.2 procedural, MariaDB/MySQL, Composer, JavaScript
vanilla y un único CSS. Mantén esta guía pequeña: Codex la recibe antes de cada
tarea.

## Reglas de trabajo

- Conserva la arquitectura sin framework ni build y evita dependencias pesadas.
- Usa español en nombres, comentarios y mensajes; en código, sin acentos ni ñ.
- Revisa primero `git status`, el diff y solo los archivos relacionados con la
  petición. Preserva cambios ajenos y el comportamiento existente.
- No leas ni muestres archivos locales ignorados, datos del usuario, adjuntos o
  configuración privada salvo que una comprobación concreta lo requiera.
- Usa búsquedas acotadas, fragmentos y colas breves; no vuelques árboles,
  archivos grandes ni registros completos al contexto del modelo.
- No ejecutes instaladores o seeds sobre la base de desarrollo. No reinicies
  XAMPP ni alteres datos existentes salvo que la tarea y su verificación lo
  requieran expresamente.
- Para cambios de esquema, crea una migración nueva; no edites las publicadas.

## Verificación

- PHP: `C:\xampp\php\php.exe`.
- Lint de cada PHP modificado: `php -l archivo.php`.
- Tests: `vendor/bin/phpunit`.
- Cuando el cambio afecte un flujo visible, compruébalo también por HTTP en
  `http://localhost/ticketia/public`.
- La receta E2E está en `.claude/skills/verify/SKILL.md`; consulta únicamente la
  sección necesaria y no modifiques la base de desarrollo para preparar datos.

## Mapa mínimo

- `public/`: páginas y endpoints web.
- `src/arranque.php`: bootstrap común.
- `src/config.php`: configuración.
- `src/llm.php`: integración de IA.
- `db/migrations/`: evolución del esquema.
- `tests/`: pruebas unitarias sin base de datos.

La arquitectura detallada, historia y notas operativas están en
`docs/AGENTS_REFERENCE.md`. Es material de consulta: abre solo la sección que
necesites, nunca el documento completo por defecto.
