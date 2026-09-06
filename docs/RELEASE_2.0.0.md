# TicketIA v2.0.0 — Soporte profesional con IA

Esta version incorpora el rediseño del portal de cliente y la administracion,
una bandeja de soporte simplificada y una IA conectada al conocimiento del equipo.

## Novedades principales

- Kanban por defecto, asignacion visible, **Asignarme** en un clic y vista previa.
- Un editor para responder, proponer solucion o guardar una nota; borradores
  privados recuperables y proteccion frente a cambios concurrentes.
- Soluciones visibles en la conversacion, conservadas al reabrir una incidencia.
- Copiloto con fuentes, resumen progresivo y revision humana antes de enviar.
- Centro de ayuda con publicacion revisada, busqueda y valoraciones de utilidad.
- Administracion con equipos, reglas simuladas, calendario laboral y panel de
  salud, entregas y conservacion de registros.
- Cola recuperable de trabajos y correo, historial paginado, SLA configurables,
  segundo factor y recuperacion segura de contrasenas.
- Despliegue Docker con worker y comprobaciones automaticas en GitHub Actions.

## Importante al actualizar

Se requiere PHP 8.2 o superior y MySQL/MariaDB. Realiza primero una copia y comprueba
su restauracion en un entorno aislado. Deten el worker durante la actualizacion,
actualiza el codigo y ejecuta desde la raiz del proyecto:

```powershell
composer install --no-dev --prefer-dist --no-interaction
& C:\xampp\php\php.exe vendor/bin/phinx migrate -c phinx.php
```

En otros entornos: `php vendor/bin/phinx migrate -c phinx.php`.
Phinx aplica todas las migraciones pendientes desde tu version. **No ejecutes seeds
sobre una instalacion existente.** Reinicia despues el worker habitual y comprueba
las notificaciones. Los correos ordinarios de soporte se entregan ahora desde la cola.

Las reglas de reparto y el calendario requieren simulacion y activacion explicita.
No se convierten automaticamente las fechas historicas a UTC. Las incidencias y
sus adjuntos se conservan. La entrega SMTP puede repetirse tras una interrupcion.

[Guia completa de actualizacion y limites](https://github.com/zarzawan/ticketia/blob/v2.0.0/docs/OPERACION_PROFESIONAL.md)
· [Changelog](https://github.com/zarzawan/ticketia/blob/v2.0.0/CHANGELOG.md)

## Verificacion

- 56 pruebas unitarias, 170 aserciones y 419 comprobaciones HTTP aisladas.
- PHP 8.2 y 8.3, escaneo de secretos y construccion/prueba de humo Docker.
- Ensayo con 1002 incidencias activas, 2500 cerradas y una conversacion de 10000 mensajes.
- Streaming probado contra un proveedor ficticio y entrega SMTP contra un receptor
  local de pruebas; sin servicios externos ni datos reales.
- Revision visual de asignacion, borradores y conversacion, con comprobacion movil.

Estos controles no sustituyen una auditoria de seguridad externa ni una prueba
de carga de tu despliegue real.

Los archivos ZIP y TAR.GZ de GitHub contienen el codigo fuente. Las dependencias
se instalan con Composer; esta release no incluye una imagen Docker preconstruida.
