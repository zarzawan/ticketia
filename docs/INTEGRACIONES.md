# Conexiones y claves

Administracion > Conexiones y claves permite configurar Gmail, OpenAI, xAI y la
clave/modelo de IA local. No incorpora proveedores nuevos ni cambia el motor SSE.

## Preparacion del servidor

- Conserva `APP_KEY` en el entorno privado, con al menos 32 caracteres aleatorios.
  Es la misma clave que protege 2FA: no la reemplaces si ya existe. Requiere OpenSSL.
  Si aun no existe, genera una clave con `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
  y guardala en el entorno del servidor, nunca en Git ni en conversaciones.
- Guarda una copia segura de APP_KEY separada de la copia de la base de datos.
  Perderla impide descifrar las conexiones y los secretos 2FA. Una copia de la
  base sola no basta; no se implementa rotacion automatica de esta clave.
- Sirve la aplicacion por HTTPS. Solo se permite HTTP desde loopback para
  desarrollo local. Detras de un proxy, configura HTTPS en el servidor de forma
  fiable; no se confia en cabeceras reenviadas enviadas por el navegador.
- La raiz publica del servidor debe ser `public/`. Protege `.env` y las copias
  frente al acceso web. Este formulario no modifica archivos ni permisos.

## Guardar, probar y restablecer

1. Escoge Gmail, OpenAI, xAI o IA local. Introduce los datos y tu contrasena de
   administrador. El formulario exige sesion admin y CSRF.
2. Guardar cifra todo el apartado con AES-256-GCM en `ajustes`. No necesita una
   migracion nueva ni almacena tokens en claro. No prueba ni activa proveedores.
3. Los campos secretos nunca se rellenan en HTML: vacio conserva el valor actual.
   Para reemplazarlo escribe el nuevo. Los errores tampoco devuelven secretos.
4. Comprobar conexion prueba los datos escritos y los secretos conservados, sin
   guardarlos. Si introduces secretos nuevos para probarlos, tendras que volver
   a escribirlos para guardarlos; no se conservan en la sesion del navegador.
5. Restablecer exige confirmacion y elimina solo ese apartado del panel. Vuelve a
   usarse la configuracion del entorno si existe; no revoca tokens del proveedor.

Los valores del panel tienen prioridad sobre `.env` y las variables del proceso
solo para los campos de conexion admitidos. `APP_KEY`, `LLM_SOLO_LOCAL`, limites,
timeouts y el endpoint local permanecen bajo control del servidor. Si falla el
descifrado, se bloquean los valores del apartado: no se reutilizan secretos
antiguos del entorno silenciosamente. Un formulario antiguo no sobrescribe una
edicion posterior de otro administrador.

La configuracion se carga en cada arranque, tanto web como CLI. Reinicia los
workers persistentes para que adopten una clave nueva. La IA activa se elige en
Configuracion; guardar otra clave no la cambia. El modo `LLM_SOLO_LOCAL=1` bloquea
las pruebas de IA externa y su utilizacion aunque se guarden sus credenciales.

## Que comprueba cada prueba

- Gmail: renueva acceso OAuth, comprueba que el perfil corresponde al buzon y
  verifica la etiqueta. No lee cuerpos, lista mensajes, importa ni modifica el
  correo. OAuth y consentimiento previo siguen siendo necesarios: [GMAIL.md](GMAIL.md).
- OpenAI / xAI: consulta metadatos de los modelos configurados con la clave. No
  genera texto, no utiliza incidencias y no certifica saldo ni compatibilidad
  completa del modelo con todos los parametros o streaming del proyecto.
- IA local: la prueba funcional existente esta en Configuracion y usa el
  proveedor activo. Su endpoint no se edita desde este nuevo formulario.

Pruebas limitadas en tiempo y tamano de respuesta, sin redirecciones y con
destinos externos fijos. La auditoria solo registra proveedor y exito/fallo;
no guarda tokens, contrasenas ni respuestas completas del servicio.

Referencias: [perfil Gmail](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/getProfile),
[etiqueta Gmail](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.labels/get),
[modelo OpenAI](https://developers.openai.com/api/reference/resources/models/methods/retrieve),
[modelos xAI](https://docs.x.ai/developers/rest-api-reference/inference/models).
