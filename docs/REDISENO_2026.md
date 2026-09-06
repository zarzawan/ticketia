# Rediseño de TicketIA — septiembre de 2026

## Dirección de producto

TicketIA debe permitir tres cosas sin aprendizaje previo: saber qué atender,
resolver con contexto y entender el estado de una solicitud. La IA propone y
resume; las personas conservan la decisión de responder y publicar.

Se revisaron las páginas de administración, soporte, portal, acceso, ciclo de
vida e integración IA, además de los guards, migraciones y pruebas relacionados.
No se ha realizado una auditoría independiente de seguridad ni una prueba de
carga de producción.

## Referencias adoptadas

- [ServiceNow, Workspace](https://horizon.servicenow.com/workspace/overview):
  espacio de trabajo común y contexto operativo junto a las acciones.
- [Zendesk, Admin Center](https://support.zendesk.com/hc/en-us/articles/4581766374554-Using-Zendesk-Admin-Center):
  navegación agrupada por responsabilidades y buscador de configuración.
- [Jira Service Management, Service Desk](https://www.atlassian.com/software/jira/service-management/features/service-desk):
  portal de autoservicio, conocimiento reutilizable y valoración del servicio.

Son patrones adaptados a un helpdesk pequeño; no se incorporan sus marcas,
recursos visuales, dependencias ni complejidad de configuración.

## Cambios implementados

- Identidad común: azul profundo, acento verde, superficies claras, iconos SVG,
  tamaños de lectura consistentes, foco de teclado y adaptación móvil.
- Acceso rediseñado; administración con grupos Gestión, Servicio y Plataforma.
- Directorios de personas y organizaciones con búsqueda y páginas de 25 filas.
  Las altas son desplegables; las acciones de seguridad siguen disponibles.
- Resumen administrativo con carga del equipo acotada a ocho agentes, resultados
  de los últimos 30 días, satisfacción y accesos a configuración.
- Centro de control IA: llamadas paginadas, errores, consumo y feedback. Una
  instalación sin llamadas ya no falla al calcular la tendencia.
- Biblioteca con borrador, publicación y archivo; audiencia interna o clientes.
  Un borrador IA utiliza título y notas de resolución de una incidencia resuelta.
  No se guarda ni publica automáticamente. Publicar exige revisión explícita.
  Las ediciones concurrentes se detectan mediante una versión.
- Portal con solicitudes en seguimiento, con respuesta, por confirmar e historial;
  paginación por cursor de 25 filas, búsqueda por texto o número y ayudas al alta.
- Sugerencias de artículos publicados mediante búsqueda de texto completo. No
  se presenta esta búsqueda como un chatbot ni como una respuesta generada por IA.
- Detalle con progreso, siguiente paso, conversación, archivos y solución final
  consultable después del cierre. Valoración opcional de 1 a 5 del solicitante.

## Permisos y conservación

Se mantiene el alcance existente del portal: los miembros de una organización
ven sus solicitudes compartidas; las cuentas sin organización ven las propias.
Las notas internas y los artículos internos o no publicados no salen al cliente.
La valoración de servicio solo puede hacerla el creador de la solicitud.

Las incidencias cerradas quedan fuera del trabajo activo; el historial no se
carga completo. El archivo es lógico: no borra la trazabilidad. Los logs IA
conservan la retención configurable implementada en la fase anterior.

## Verificación

Pruebas unitarias y ensayo HTTP en una base desechable con proveedor IA simulado:
permisos, aislamiento entre organizaciones, artículos privados, revisión editorial,
edición concurrente, paginación, contraseñas, feedback, satisfacción y streaming.
Revisión visual de escritorio y móvil, navegación, tema y sugerencias contextuales.

Las migraciones son aditivas y no introducen ejemplos en la base de desarrollo.
El proveedor simulado comprueba el protocolo; no mide la calidad del modelo real.

## Siguientes prioridades para un despliegue real

1. Ampliar las pruebas de carga a búsquedas históricas, mensajes y análisis IA con
   tráfico concurrente. El ensayo local de miles de tickets no sustituye esa carga.
2. Configurar y supervisar el worker en el despliegue. El panel ya avisa cuando
   deja de recibir señales; no instala el servicio ni envía alertas externas.
3. Completar la operación: SMTP de producción, copias verificadas y restauración.
4. Publicar las primeras guías revisadas con el equipo y observar satisfacción y
   uso de borradores antes de añadir automatismos de asignación.
5. Evaluar equipos, vistas guardadas y reglas de asignación cuando el uso real
   indique qué decisiones se repiten.

## Continuación: operación y volumen

- Se elimina el filtrado SLA posterior a los primeros 500 registros. SQL aplica
  el filtro antes del límite a contadores, lista, Kanban y exportación.
- La ordenación por columnas afecta al conjunto completo. La lista pagina y cada
  columna Kanban tiene su navegación; cambiar filtros u orden vuelve al inicio.
- Se mantienen la precedencia de políticas, la respuesta pública (no notas
  internas), el umbral redondeado de riesgo y las fechas de resolución/cierre.
- El worker registra una única señal en `ajustes`, con reloj de la base de datos.
  Se diferencia ejecución puntual, servicio, ausencia de datos y señal caducada.
  No se deduce «activo» a partir de una cola vacía. Sin migración adicional.
- Ensayo aislado de 1002 incidencias activas y 2500 cerradas, paginación, exportación,
  162 combinaciones SLA frente al dominio y ejecución real del CLI puntual/en bucle.
  Las pruebas no arrancan el worker ni alteran incidencias en la base de desarrollo.

## Simplificación del trabajo de soporte

- Kanban es la vista inicial; la lista sigue disponible. El selector de técnico
  está visible en cada tarjeta y guarda al elegir, con confirmación y recuperación
  del valor anterior si falla. No requiere abrir la incidencia ni «Ajustar».
- Búsqueda directa, cuatro accesos de cola y filtros avanzados plegables. Se retira
  el cuadro de métricas y configuración IA de la bandeja cotidiana. El departamento
  se elige en un selector, sin una botonera de todas las categorías.
- La conversación ocupa el centro del detalle. Hay un único editor para responder,
  proponer solución o guardar nota interna; IA prepara un borrador revisable, nunca
  lo envía automáticamente ni reemplaza texto escrito sin confirmación.
- La solución se registra como mensaje público junto al cambio de estado dentro
  de una transacción con bloqueo de fila. Sobrevive a la reapertura y un segundo
  envío sobre una incidencia ya resuelta no duplica el mensaje.
- Adjuntos, datos ampliados, actividad y señales IA permanecen disponibles bajo
  demanda. Se mantiene intacto el diseño del portal de usuario.
- El ensayo HTTP cubre ambos endpoints de solución, fallo de inserción con rollback,
  reapertura, duplicados, permisos, privacidad de notas y asignación desde tarjeta.

La fase de [operacion profesional](OPERACION_PROFESIONAL.md) unifica SQL y PHP
sobre hora civil y añade calendario laboral opcional. No reinterpreta DATETIME
historicos como UTC: para un despliegue multizona sigue siendo necesario conocer
la procedencia de las fechas y preparar una conversion especifica.
