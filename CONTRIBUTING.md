# Contribuir a TicketIA

¡Gracias por querer mejorar TicketIA! Tu ayuda es vital para que este helpdesk open source crezca. Lee esta guía antes de empezar.

## 🐛 Reportar Bugs y Proponer Mejoras

Utiliza la sección "Issues" en GitHub para reportar problemas o sugerir nuevas funcionalidades. Por favor, sé lo más detallado posible:

*   **Bugs:** Describe el problema, los pasos exactos para reproducirlo y el resultado esperado vs. el obtenido.
*   **Mejoras:** Explica claramente qué quieres cambiar y por qué beneficiaría al proyecto.

## 🚀 Flujo de Contribución

Mantenemos un flujo simple:

1.  Haz un *fork* del repositorio.
2.  Crea una rama descriptiva (ej: `feature/nueva-funcionalidad` o `fix/bug-login`).
3.  Realiza tus cambios y asegúrate de que tu Pull Request (PR) sea pequeño y enfocado en una sola tarea.

## 🎨 Estilo de Código

Buscamos simplicidad y mantenibilidad, sin frameworks complejos:

*   **PHP:** PHP puro, sencillo, sin dependencias innecesarias.
*   **Nomenclatura:** Funciones y variables deben tener nombres descriptivos en español.
*   **Seguridad:** Todas las salidas a la UI deben ser escapadas con `ui_e()`. Las consultas a MySQL deben usar siempre sentencias preparadas.

## ✅ Cómo Probar tu Código

Antes de abrir un PR, asegúrate de que tus cambios funcionan y no rompen nada:

1.  Instala dependencias: `composer install`
2.  Configura la demo: `php bin/instalar.php --con-demo`
3.  Verifica los archivos modificados con `php -l [nombre_archivo]`.

## 📝 Mensajes de Commit

Mantén tus *commits* claros y descriptivos en español. Ejemplo: "feat: Añadir validación de email al registro".

---
*TicketIA está bajo licencia AGPL-3.0.*
