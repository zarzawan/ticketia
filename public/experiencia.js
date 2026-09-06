// Interacciones compartidas: navegacion, formularios y ayuda contextual.
document.addEventListener('DOMContentLoaded', () => {
    try { document.documentElement.dataset.theme = localStorage.getItem('incidencias_theme') || 'light'; } catch (_) { /* Preferencia opcional. */ }
    document.querySelectorAll('[data-cambiar-tema]').forEach(boton => boton.addEventListener('click', () => {
        const tema = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = tema;
        try { localStorage.setItem('incidencias_theme', tema); } catch (_) { /* Preferencia opcional. */ }
    }));
    const buscador = document.getElementById('adminNavSearch');
    buscador?.addEventListener('input', () => {
        const consulta = buscador.value.trim().toLocaleLowerCase();
        let visibles = 0;
        document.querySelectorAll('.admin-nav-link').forEach(enlace => {
            enlace.hidden = !enlace.textContent.toLocaleLowerCase().includes(consulta);
            if (!enlace.hidden) visibles++;
        });
        document.querySelectorAll('.admin-nav-group').forEach(grupo => { grupo.hidden = consulta !== ''; });
        document.getElementById('adminNavVacio').hidden = visibles > 0;
    });
    document.addEventListener('keydown', evento => {
        if (evento.key !== 'Escape') return;
        document.body.classList.remove('admin-menu-open');
        document.getElementById('adminMenuToggle')?.setAttribute('aria-expanded', 'false');
    });
    const titulo = document.querySelector('[data-sugerir-articulos]');
    const resultados = document.getElementById('ayudaSugerida');
    let temporizador, peticion;
    titulo?.addEventListener('input', () => {
        clearTimeout(temporizador);
        peticion?.abort();
        resultados.replaceChildren();
        resultados.hidden = true;
        if (titulo.value.trim().length < 4) return;
        temporizador = setTimeout(async () => {
            peticion = new AbortController();
            try {
                const respuesta = await fetch('buscar_ayuda.php?q=' + encodeURIComponent(titulo.value), { signal: peticion.signal, headers: { Accept: 'application/json' } });
                if (!respuesta.ok) return;
                const datos = await respuesta.json();
                const encabezado = document.createElement('strong');
                encabezado.textContent = 'Estas guias pueden ayudarte';
                resultados.append(encabezado);
                datos.articulos.forEach(articulo => {
                    const enlace = document.createElement('a');
                    enlace.href = 'ayuda.php?id=' + Number(articulo.id);
                    enlace.target = '_blank';
                    enlace.rel = 'noopener';
                    enlace.textContent = articulo.titulo + ' ↗';
                    resultados.append(enlace);
                });
                resultados.hidden = datos.articulos.length === 0;
            } catch (_) { /* El formulario sigue disponible si la ayuda falla. */ }
        }, 350);
    });
    document.querySelectorAll('[data-plantilla-solicitud]').forEach(boton => boton.addEventListener('click', () => {
        const formulario = document.getElementById('nuevaSolicitud');
        const descripcion = document.getElementById('descripcion');
        formulario.open = true;
        if (!descripcion.value.trim()) descripcion.value = boton.dataset.plantillaSolicitud;
        document.getElementById('titulo').focus();
    }));
});
