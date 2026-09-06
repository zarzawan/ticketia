# Evolucion 2.1 (sin publicar)

## Entrega actual

- Bandeja: los accesos rapidos son alternativas, no filtros acumulativos de
  responsable y estado. Los contadores conservan busqueda, departamento,
  urgencia y fechas. Kanban sigue siendo la vista inicial y la asignacion
  sigue visible. Los filtros viven en la URL, no se restauran desde un
  almacenamiento compartido entre cuentas. El historial del navegador y los
  enlaces guardados siguen conservando la consulta.
- Administracion > Predicciones: listado de organizaciones paginado y ficha
  enlazada desde Organizaciones. Ventanas de 30, 90 o 180 dias, graficos de
  altas mensuales y estados actuales, historico completo paginado por cursor.
  Las cerradas y archivadas se conservan. No se cargan conversaciones ni
  adjuntos para construir los indicadores.
- Calidad: media por incidencia valorada, cobertura sobre incidencias del
  periodo, reaperturas y primera respuesta media en horas naturales. Mostrar
  la muestra evita confundir falta de valoraciones con satisfaccion. El SLA
  usa las politicas y calendario existentes; sus alertas abarcan todos los
  pendientes, incluso antiguos.
- Predicciones: reglas version 1 explicadas en la pantalla. Sin bajas,
  renovaciones y compras confirmadas no se entrenan probabilidades ni se
  afirma causalidad. No se ejecutan acciones comerciales automaticas. Las
  clasificaciones de IA pueden necesitar correccion humana. La actividad
  reducida o el estado inactivo de una organizacion no se interpretan como baja.
- Respuestas reutilizables: cinco bases (recepcion, diagnostico, seguimiento,
  comprobacion y nuevas necesidades). Todo el equipo puede consultar; solo
  administracion modifica/desactiva las compartidas. Se anaden al borrador,
  nunca se envian automaticamente. Revisar la adecuacion antes de enviar.

## IA incorporada y procesos revisados

- Predicciones: analista bajo demanda por organizacion; recibe agregados y las
  reglas calculadas, sin nombre del cliente, mensajes ni adjuntos. Propone una
  interpretacion y una accion, explicando limitaciones; no altera los indicadores.
- Plantillas: mejora de redaccion bajo demanda, vista previa y aceptacion
  explicita en el editor. No se guarda ni publica automaticamente.
- Equipos: propuesta de organizacion basada en volumen por departamento y
  numero de tecnicos/equipos/reglas. No inventa especialidades ni asigna personas.
- Se reutiliza el proveedor y los limites de coste existentes, con cache privada
  de sesion de diez minutos y validacion de campos de salida. Los nuevos procesos
  registran auditoria y usan las trazas habituales de LLM. El streaming no cambia.
- Clasificacion al entrar, copiloto, traduccion, analisis de incidencias y
  creacion de conocimiento desde soluciones ya disponen de IA. Se conservan sus
  flujos y se incluyen en las regresiones; no se duplican llamadas por cada visita.
- Autenticacion, permisos, contrasenas, transacciones, SLA y borrado no se
  delegan en IA: requieren reglas deterministas y comprobables.

## Aplicar cambios de esquema

La migracion nueva `20260907000017_respuestas_reutilizables.php` habilita las
ediciones persistentes. Aplicar las migraciones pendientes con el procedimiento
habitual y copia previa; no ejecutar seeds sobre instalaciones existentes.
Las cinco bases estan en el codigo y funcionan tambien sin la migracion 17.
La actualizacion no modifica los mensajes enviados ni los borradores existentes.

`bin/copiar_bd.php` permite generar una copia SQL con mysqldump en un directorio
privado fuera de la carpeta web. No incluye adjuntos ni configuracion, no cifra
el archivo y no sustituye una prueba de restauracion. Nunca subir la copia a Git.

## Pendiente de la fase propuesta

- Estado explicito Esperando al cliente, con politica de pausa de SLA y
  reanudacion probada; no deducirlo solo del ultimo autor del mensaje.
- Correo entrante con remitentes autorizados, aislamiento de empresas,
  identificacion segura del hilo y proteccion contra duplicados/bucles.
- Registrar resultados comerciales confirmados para evaluar las alertas con
  el tiempo y, solo con suficiente muestra, validar modelos predictivos.

Estos puntos no se consideran implementados por esta entrega.
