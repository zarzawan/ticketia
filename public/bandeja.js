(() => {
    document.querySelectorAll('[data-asignarme]').forEach(boton => {
        boton.addEventListener('click', () => {
            const select = boton.closest('.kanban-card').querySelector('.kanban-select-asignado');
            if (select.disabled || select.value === boton.dataset.asignarme) return;
            select.value = boton.dataset.asignarme;
            select.dispatchEvent(new Event('change', {bubbles: true}));
        });
    });
    const panel = document.createElement('dialog');
    panel.className = 'ticket-preview';
    panel.setAttribute('aria-label', 'Vista previa de incidencia');
    const cerrar = document.createElement('button');
    cerrar.type = 'button'; cerrar.className = 'card-button secondary-button'; cerrar.textContent = 'Cerrar vista previa';
    const marco = document.createElement('iframe'); marco.title = 'Detalle y respuesta de incidencia';
    const actualizar = document.createElement('button'); actualizar.type = 'button'; actualizar.className = 'card-button secondary-button';
    actualizar.textContent = 'Cambios guardados: actualizar bandeja'; actualizar.hidden = true;
    actualizar.addEventListener('click', () => location.reload());
    panel.append(cerrar, actualizar, marco); document.body.append(panel);
    window.addEventListener('message', e => {
        if (e.origin === location.origin && e.source === marco.contentWindow && e.data?.tipo === 'incidencia_actualizada') actualizar.hidden = false;
    });
    // Mantiene el documento del marco al cerrar: no pierde texto sin guardar.
    cerrar.addEventListener('click', () => panel.close());
    document.querySelectorAll('.kanban-title').forEach(enlace => {
        enlace.addEventListener('click', e => {
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            if (marco.getAttribute('src') !== enlace.getAttribute('href')) {
                if (marco.hasAttribute('src') && marco.contentDocument?.getElementById('mensaje')?.value.trim()
                    && !confirm('Abrir otra incidencia? Comprueba que el borrador anterior esta guardado.')) return;
                marco.src = enlace.getAttribute('href');
            }
            panel.showModal();
        });
    });
})();
