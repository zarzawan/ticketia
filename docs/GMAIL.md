# Gmail / Google Workspace: entrada supervisada

## Preparar la conexion

1. En Google Cloud, habilita Gmail API y configura la pantalla de consentimiento
   OAuth para tu organizacion. Crea credenciales OAuth de aplicacion web. El
   administrador de Workspace puede tener que permitir la aplicacion.
2. Autoriza solo `https://www.googleapis.com/auth/gmail.readonly`, con acceso
   offline, para el buzon exclusivo de soporte. El programa necesita client ID,
   client secret y refresh token; no la contrasena de Gmail. Para una prueba
   manual puedes usar OAuth Playground de Google con tus propias credenciales y
   su URI de redireccion registrado. No pegues tokens en chats ni tickets.
3. Crea una etiqueta exclusiva (por ejemplo TicketIA) y un filtro en Gmail que
   etiquete lo que quieras revisar. Obtiene su ID mediante `users.labels.list`
   de Gmail API, con la misma cuenta autorizada. Se configura el ID, no el nombre.
4. Guarda `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REFRESH_TOKEN`,
   `GMAIL_BUZON` y `GMAIL_LABEL_ID` en el `.env` privado del servidor. `GMAIL_BUZON`
   debe ser la direccion principal que devuelve el perfil de la cuenta autorizada.
   Protege el archivo con permisos del usuario del servicio.
5. Aplica las migraciones pendientes con copia previa (la 19 crea la bandeja).
   Ejecuta `php bin/sincronizar_gmail.php`. Revisa primero un lote y luego, si
   procede, programa el comando en el planificador del servidor.

Documentacion oficial: [listar mensajes](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/list),
[obtener mensajes](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/get),
[etiquetas](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.labels/list),
[OAuth offline](https://developers.google.com/identity/protocols/oauth2/web-server),
[alcances Gmail](https://developers.google.com/workspace/gmail/api/auth/scopes).

El alcance readonly permite leer el buzon: la etiqueta limita lo que importa este
programa, pero no reduce el permiso concedido por Google. Utiliza un buzon dedicado.
Google puede imponer requisitos de verificacion y politicas segun la audiencia y
el estado de la aplicacion OAuth. Revisa esos requisitos con el administrador.

## Como funciona

- Una pagina de hasta 20 mensajes por ejecucion, con cursor persistente. Repetir
  la pagina tras un error no duplica correos ya guardados. Al acabar las paginas,
  vuelve a consultar desde el principio y omite los ya vistos.
- No envia, elimina, archiva, etiqueta ni marca mensajes como leidos. No descarga
  adjuntos. Texto maximo 30000 caracteres; el HTML se convierte y se muestra
  escapado. Un aviso indica contenido incompleto o adaptado.
- Administracion > Correo entrante muestra entradas pendientes, importadas y
  descartadas. El descarte conserva el registro y el correo original.
- El administrador verifica remitente, organizacion y contenido. El remitente
  declarado puede ser suplantado: no es prueba de identidad. Solo se permite
  crear para usuarios cliente activos asociados a una organizacion activa.
- Al aprobar se crea una incidencia y se encola la clasificacion IA en la misma
  transaccion. El worker habitual debe estar ejecutandose. No se llama a IA antes
  de aprobar ni se envian confirmaciones de recepcion automaticamente.
- Hilos ya importados se bloquean para no crear incidencias duplicadas. Esta
  primera entrega no agrega respuestas al hilo: deben revisarse en Gmail y la
  incidencia existente. Tampoco crea cuentas para remitentes desconocidos.
- Tokens caducados/revocados requieren reautorizar Google. Un cursor que Google
  invalide requiere reiniciar la sincronizacion bajo supervision; no se borran
  entradas ni se ocultan errores silenciosamente.

La conexion real requiere consentimiento y credenciales del propietario. Los
tests usan respuestas simuladas, no leen un buzon real.
