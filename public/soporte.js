// Borradores privados en servidor; nunca se guardan conversaciones en localStorage.
function iniciarBorradoresSoporte() {
    if (window.self !== window.top && /(?:comentario|resolucion)=ok/.test(location.search)) {
        window.parent.postMessage({tipo:'incidencia_actualizada'},location.origin);
    }
    const form = document.getElementById('formRespuesta');
    if (!form) return;
    const texto = form.elements.mensaje;
    const accion = form.elements.accion_respuesta;
    const aviso = document.getElementById('estadoBorrador');
    const boton = document.getElementById('enviarRespuesta');
    let pendiente = false, temporizador, cola = Promise.resolve();
    const guardar = () => {
        cola = cola.catch(() => {}).then(async () => {
            if (!pendiente) return;
            const contenido = texto.value, modo = accion.value;
            const datos = new FormData(form);
            const respuesta = await fetch('borrador_soporte.php', {method: 'POST', body: datos});
            const json = await respuesta.json();
            if (!respuesta.ok || !json.ok) throw new Error(json.error || 'No se pudo guardar el borrador.');
            form.elements.borrador_version.value = json.version;
            pendiente = texto.value !== contenido || accion.value !== modo;
            aviso.textContent = pendiente ? 'Cambios pendientes de guardar...' : 'Borrador privado guardado';
        });
        return cola;
    };
    const cambiar = () => {
        pendiente = true;
        aviso.textContent = 'Guardando borrador privado...';
        clearTimeout(temporizador);
        temporizador = setTimeout(() => guardar().catch(e => { aviso.textContent = e.message; }), 600);
    };
    texto.addEventListener('input', cambiar);
    accion.addEventListener('change', cambiar);
    window.addEventListener('beforeunload', e => { if (pendiente) { e.preventDefault(); e.returnValue = ''; } });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        clearTimeout(temporizador);
        try {
            do { await guardar(); } while (pendiente);
            // Bloquea edicion solo al enviar, despues de conservar el borrador.
            texto.readOnly = true;
            pendiente = false;
            form.submit();
        } catch (error) {
            aviso.textContent = error.message;
            boton.disabled = false;
            boton.textContent = 'Reintentar envio';
        }
    });
    document.getElementById('respuestaRapida')?.addEventListener('change', e => {
        if (!e.target.value) return;
        if (texto.value.trim() && !confirm('Sustituir el texto actual por la respuesta reutilizable?')) { e.target.value = ''; return; }
        texto.value = e.target.value;
        texto.dispatchEvent(new Event('input', {bubbles: true}));
        e.target.value = '';
        texto.focus();
    });
    // Solo informa; no reemplaza texto ni acepta silenciosamente cambios concurrentes.
    setInterval(async () => {
        if (document.hidden) return;
        try {
            const r = await fetch('borrador_soporte.php?id=' + form.elements.id_incidencia.value);
            if (!r.ok) return;
            const j = await r.json();
            if (j.huella !== form.elements.huella.value) {
                document.getElementById('avisoActualizacion').hidden = false;
            }
        } catch (_) { /* La perdida de red no borra el borrador. */ }
    }, 30000);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciarBorradoresSoporte, {once:true});
else iniciarBorradoresSoporte();
