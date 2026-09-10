// La biblioteca no depende del guardado automatico ni de las migraciones de borradores.
function iniciarRespuestasReutilizables() {
    const selector = document.getElementById('respuestaRapida');
    const vista = document.getElementById('vistaRespuestaRapida');
    const insertar = document.getElementById('insertarRespuestaRapida');
    const texto = document.querySelector('#formRespuesta textarea[name="mensaje"]');
    if (!selector || !vista || !insertar || !texto) return;
    selector.addEventListener('change', () => {
        vista.textContent = selector.value || 'Selecciona una respuesta para leerla antes de insertarla.';
        insertar.disabled = !selector.value;
    });
    insertar.addEventListener('click', () => {
        if (!selector.value) return;
        // Anadir conserva todo el borrador existente y no envia ni cambia el estado.
        const combinado = texto.value + (texto.value.trim() ? '\n\n' : '') + selector.value;
        if (texto.maxLength > 0 && combinado.length > texto.maxLength) {
            vista.textContent = 'El texto combinado supera el limite del mensaje. Acorta el borrador antes de anadir la respuesta.';
            return;
        }
        texto.value = combinado;
        texto.dispatchEvent(new Event('input', {bubbles:true}));
        selector.value = '';
        insertar.disabled = true;
        vista.textContent = 'Respuesta anadida al borrador. Revisala y adaptala antes de enviarla.';
        texto.focus();
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciarRespuestasReutilizables, {once:true});
else iniciarRespuestasReutilizables();
