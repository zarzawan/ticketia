document.querySelectorAll('[data-ia-operativa]').forEach(panel => {
    const boton = panel.querySelector('[data-generar]');
    const salida = panel.querySelector('[data-salida]');
    const aceptar = panel.querySelector('[data-aceptar]');
    let propuesta, original;
    boton.addEventListener('click', async () => {
        const datos = new FormData();
        datos.set('csrf', panel.dataset.csrf);
        datos.set('modo', panel.dataset.iaOperativa);
        if (panel.dataset.cliente) datos.set('cliente', panel.dataset.cliente);
        if (panel.dataset.dias) datos.set('dias', panel.dataset.dias);
        const form = document.getElementById('editarRespuestaCompartida');
        if (panel.dataset.iaOperativa === 'respuesta') {
            original = {titulo:form.elements.titulo.value, contenido:form.elements.contenido.value};
            datos.set('titulo', original.titulo); datos.set('contenido', original.contenido);
        }
        boton.disabled = true; propuesta = null;
        if (aceptar) aceptar.hidden = true;
        salida.textContent = 'La IA esta preparando una propuesta. Puedes seguir consultando la pagina...';
        try {
            const r = await fetch('admin_ia_operativa.php',{method:'POST',body:datos});
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || 'No se pudo consultar la IA.');
            propuesta = j.datos;
            salida.textContent = panel.dataset.iaOperativa === 'respuesta'
                ? propuesta.titulo + '\n\n' + propuesta.contenido
                : 'Interpretacion IA\n' + propuesta.resumen + '\n\nAccion propuesta\n' + propuesta.siguiente_accion + '\n\nLimites\n' + propuesta.limitaciones;
            if (aceptar) aceptar.hidden = false;
        } catch (e) { salida.textContent = e.message || 'La IA no esta disponible. Puedes continuar manualmente.'; }
        finally { boton.disabled = false; }
    });
    aceptar?.addEventListener('click', () => {
        if (!propuesta) return;
        const form = document.getElementById('editarRespuestaCompartida');
        if (form.elements.titulo.value !== original.titulo || form.elements.contenido.value !== original.contenido) {
            salida.textContent = 'Has editado el original durante la consulta. La propuesta no lo sustituira; copia el texto que necesites o genera otra.';
            aceptar.hidden = true; return;
        }
        form.elements.titulo.value = propuesta.titulo;
        form.elements.contenido.value = propuesta.contenido;
        aceptar.hidden = true;
        salida.textContent = 'Propuesta copiada al editor, todavia sin guardar. Revisala y pulsa Guardar cambios si la apruebas.';
    });
});
