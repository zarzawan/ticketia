# Operacion profesional: actualizacion y puesta en servicio

Esta entrega conserva PHP procedural, JavaScript sin build y el CSS existente.
No instala servicios, no modifica configuracion privada y no aplica migraciones
automaticamente sobre una instalacion existente.

## Actualizar una instalacion

1. Realizar una copia de base de datos, adjuntos y configuracion. Conservarla
   cifrada fuera del servidor y comprobar una restauracion aislada.
2. Programar una ventana de mantenimiento y detener ordenadamente el worker.
3. Actualizar el codigo y aplicar exclusivamente migraciones (no ejecutar seeds):

   ```powershell
   & C:\xampp\php\php.exe vendor/bin/phinx migrate -c phinx.php
   ```

4. Reiniciar el worker mediante su servicio o tarea programada habitual. Comprobar
   una notificacion de prueba y el panel **Necesita atencion**.
5. Simular las reglas y el calendario antes de activarlos. Su activacion no forma
   parte de la instalacion: requiere decisiones del administrador.

Migraciones nuevas: 13 (reservas, idempotencia y borradores), 14 (equipos y reglas),
15 (calendario), 16 (memoria de IA y utilidad del conocimiento).
Las tablas del calendario se rellenan al guardar su configuracion, no al migrar.
No se han cambiado las migraciones anteriores.

## Que cambia para el equipo

- Kanban conserva el selector visible y permite **Asignarme** en un clic.
- El titulo abre una vista previa lateral; **Abrir** conserva la navegacion normal.
  El borrador permanece al cerrar la vista previa. Tras enviar, un aviso permite
  actualizar la bandeja; no se recarga automaticamente perdiendo trabajo.
- Borradores privados por usuario e incidencia, con control de version entre
  pestanas. No se almacenan mensajes en localStorage. Un fallo de red deja el
  texto en pantalla y advierte antes de salir.
- Respuesta, nota y solucion siguen compartiendo un editor. Se incluyen tres
  respuestas reutilizables y avisos de cambios concurrentes.
- Respuestas ordinarias: mensaje y transicion en una transaccion, comprobacion
  del estado actual, huella de version y clave de envio unica. Las soluciones
  conservan la misma garantia transaccional. Reapertura y motivo son atomicos.
- Conversaciones de 30 mensajes por pagina, con cursor; los SLA y la siguiente
  accion se calculan sobre la conversacion completa, no sobre la pagina visible.

## IA con informacion verificable

El copiloto recupera hasta tres articulos publicados y visibles para clientes,
y dos soluciones del mismo departamento y de la misma empresa. Sin empresa,
se restringe al mismo creador. No recupera borradores ni articulos internos para
redactar respuestas publicas. Las fuentes son material consultado, no una garantia
de que cada frase generada sea correcta. Se muestran sus enlaces al tecnico.

El porcentaje de confianza autodeclarado deja de presentarse. El tecnico revisa
siempre la respuesta antes de enviarla. Se conservan las valoraciones y trazas.

El contexto reciente contiene un maximo de 15 mensajes truncados a 1000 caracteres.
La memoria avanza por lotes de hasta 30 mensajes publicos antiguos en las nuevas
generaciones; se etiqueta como resumen parcial y derivado, no como historial
completo. Puede requerir varias generaciones para recorrer conversaciones largas
y puede contener errores. Una generacion puede realizar una llamada adicional.
Los informes generales recuperan solo los dos eventos recientes por incidencia
que su JSON ya utilizaba, sin cargar primero todo el historial.

El transporte de streaming en `src/llm.php` no se ha modificado. Se elimina en los
informes una comprobacion redundante de `json_last_error()` que podia confundir un
error anterior con el resultado de `json_encode(..., JSON_THROW_ON_ERROR)`.

## Procesos, correos y recuperacion

- Las reservas de trabajo caducan a los 15 minutos. El worker recupera las
  interrumpidas y respeta el numero maximo de intentos. Una reserva antigua no
  puede marcar como terminada una ejecucion posterior.
- Configurar timeouts de IA por debajo de ese plazo. La aplicacion de una
  clasificacion verifica el propietario bajo bloqueo y se confirma junto con
  la finalizacion del trabajo.
- Las notificaciones de soporte se encolan por destinatario. Es obligatorio
  disponer de worker para entregarlas. SMTP no configurado significa notificaciones
  desactivadas, no entrega realizada. El panel distingue fallos y pendientes.
- Los correos de recuperacion de contrasena siguen enviandose directamente para
  no retrasar enlaces temporales ni conservarlos en la cola.
- SMTP ofrece entrega **al menos una vez**, no exactamente una vez. Una caida
  despues de la aceptacion remota puede causar duplicados. El Message-ID estable
  identifica los reintentos, pero no obliga al servidor remoto a deduplicarlos.
- Al completar un correo se elimina su cuerpo y destinatarios del payload. Los
  fallidos conservan el contenido necesario para reintentar: proteger las copias
  y el acceso a la base de datos.

## Equipos, calendario y datos historicos

Las reglas nacen desactivadas. La simulacion muestra coincidencias de la regla
aislada y el candidato de menor carga; no asigna tickets. La activacion exige
simulacion reciente y miembros activos. Las reglas activas se evalúan por prioridad
y no sustituyen asignaciones manuales. Se aplican al guardar una clasificacion.

El calendario es global: jornada diaria continua, dias de semana y festivos. No
incluye turnos nocturnos, calendarios por contrato ni pausa por espera del cliente.
Permanece desactivado por defecto. Activarlo recalcula indicadores existentes,
incluidos los historicos; la pantalla exige confirmacion explicita.

SQL y PHP comparten aritmetica de **hora civil**, eliminando la discrepancia por
cambios de horario. No se ha supuesto una zona original ni convertido DATETIME
antiguos a UTC: esa conversion requeriria conocer la procedencia de las fechas.
PHP y MySQL deben utilizar la misma hora civil; el panel avisa de diferencias.
El calendario materializado abarca 1970-2199, con al menos cuatro horas semanales.

## Conservacion y conocimiento

Las incidencias y sus adjuntos se conservan; el archivo sigue siendo logico y
consultable. No se implementa borrado automatico de contenido de clientes.
Trabajos completados y auditoria tienen politicas distintas, con estimacion,
previsualizacion y confirmacion antes de eliminar lotes de hasta 1000 registros.
La auditoria se conserva por defecto. Los logs de IA mantienen su politica previa.

El panel muestra articulos publicados sin actualizar en 180 dias y guias con
valoraciones negativas. Cada usuario puede actualizar una valoracion por articulo.
La falta de valoraciones no se interpreta como eficacia demostrada.

La fecha de restauracion registrada es una declaracion del administrador, no una
copia ejecutada ni verificada por TicketIA. Las comprobaciones de salud tampoco
sustituyen un monitor externo: si toda la aplicacion cae, no puede avisar desde su
propio panel.

## Verificacion

- PHPUnit y lint PHP; comprobacion sintactica de JavaScript.
- Banco HTTP en base efimera: permisos, privacidad, conflictos, doble envio,
  reservas vencidas, fuentes y memoria, utilidad de articulos, reglas simuladas,
  limpieza confirmada y concordancia SQL/PHP del calendario.
- 1002 incidencias activas, 2500 cerradas y conversacion de 10000 mensajes.
- Streaming general y de seguridad contra proveedor ficticio.
- Entrega SMTP contra receptor local efimero, sin correo externo.
- Revision de navegador: asignacion, vista previa, borrador recuperado y mensaje
  visible al enviar; vista movil sin desbordamiento horizontal del documento.

Estas pruebas no equivalen a un ensayo de concurrencia masiva ni validan la
calidad factual de un proveedor real. Antes de uso intensivo, medir carga real,
revisar muestras de respuestas y realizar una prueba de restauracion.
