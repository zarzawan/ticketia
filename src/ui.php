<?php


function ui_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ui_estado_label(string $estado): string {
    $map = [
        'abierta' => 'Nueva',
        'en_curso' => 'En trabajo',
        'resuelta' => 'Solucion propuesta',
        'cerrada' => 'Cerrada'
    ];

    return $map[$estado] ?? ucfirst(str_replace('_', ' ', $estado));
}

function ui_estado_class(string $estado): string {
    return str_replace('_', '-', $estado);
}

function ui_urgencia_label(string $urgencia): string {
    $map = [
        'critico' => 'Critica',
        'urgente' => 'Urgente',
        'leve' => 'Leve'
    ];

    return $map[$urgencia] ?? ucfirst($urgencia);
}

function ui_render_kpi_card(string $label, $value): string {
    $safeLabel = ui_e($label);
    $safeValue = ui_e((string)$value);

    return "<article class='kpi-card'><span>{$safeLabel}</span><strong>{$safeValue}</strong></article>";
}

/** Iniciales para el monograma de asignado (ej. "Jose Luis" -> "JL"). */
function ui_iniciales(string $nombre): string {
    $partes = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
    if (!$partes) {
        return '';
    }
    $iniciales = mb_substr($partes[0], 0, 1);
    if (count($partes) > 1) {
        $iniciales .= mb_substr($partes[count($partes) - 1], 0, 1);
    }
    return mb_strtoupper($iniciales);
}

function ui_render_kanban_card(array $incidencia, array $asignables = [], bool $seleccionable = false): string {
    $id = (int)($incidencia['id'] ?? 0);
    $titulo = ui_e($incidencia['titulo'] ?? 'Sin titulo');
    $resumen = ui_e($incidencia['resumen'] ?? 'Sin resumen');
    $urgencia = (string)($incidencia['urgencia'] ?? 'leve');
    $urgenciaLabel = ui_urgencia_label($urgencia);
    $estado = (string)($incidencia['estado'] ?? 'abierta');
    $tipoRaw = (string)($incidencia['tipo'] ?? '');
    $tipo = ui_e($tipoRaw !== '' ? $tipoRaw : 'Sin tipo');
    $asignadoId = (int)($incidencia['asignado_id'] ?? 0);
    $asignadoNombre = (string)($incidencia['asignado_nombre'] ?? '');
    $fechaCreacionRaw = (string)($incidencia['fecha_creacion'] ?? '');
    $edadTexto = '';
    $edadDias = 0;
    $tiposDisponibles = dominio_tipos();

    if ($fechaCreacionRaw !== '') {
        try {
            $fechaCreacion = new DateTime($fechaCreacionRaw);
            $edadDias = $fechaCreacion->diff(new DateTime())->days;
            $edadTexto = $edadDias . ' dias';
        } catch (Exception $e) {
            $edadTexto = '';
        }
    }

    $options = '';
    foreach ($tiposDisponibles as $tipoDisponible) {
        $selected = $tipoRaw === $tipoDisponible ? ' selected' : '';
        $safeTipo = ui_e($tipoDisponible);
        $options .= "<option value='{$safeTipo}'{$selected}>{$safeTipo}</option>";
    }

    $opcionesAsignado = "<option value=''" . ($asignadoId === 0 ? ' selected' : '') . ">Sin asignar</option>";
    foreach ($asignables as $asignable) {
        $idAsignable = (int)$asignable['id'];
        $selected = $asignadoId === $idAsignable ? ' selected' : '';
        $safeNombre = ui_e((string)$asignable['nombre']);
        $opcionesAsignado .= "<option value='{$idAsignable}'{$selected}>{$safeNombre}</option>";
    }

    $avatarVacio = $asignadoId === 0 || $asignadoNombre === '';
    $avatarClase = $avatarVacio ? 'kanban-avatar is-empty' : 'kanban-avatar';
    $avatarTexto = $avatarVacio ? '–' : ui_e(ui_iniciales($asignadoNombre));
    $avatarTitle = $avatarVacio ? 'Sin asignar' : ui_e($asignadoNombre);
    $sla = dominio_sla_calcular($incidencia);
    $slaClase = ui_e((string)$sla['estado']);
    $slaTexto = ui_e(dominio_duracion_humana((int)$sla['restante_segundos']));
    $turno = dominio_siguiente_paso($incidencia['ultimo_autor'] ?? null, $estado);
    $turnoClase = ui_e($turno['clave']);
    $turnoTexto = ui_e($turno['label']);
    $prioridadInfo = dominio_prioridad_operativa_desglose($incidencia);
    $prioridad = $prioridadInfo['total'];
    $prioridadTitulo = ui_e(implode(' + ', array_map(
        static fn(array $factor): string => $factor['label'] . ' (' . $factor['puntos'] . ')',
        $prioridadInfo['factores']
    )));
    $selector = $seleccionable
        ? "<label class='kanban-select-check' title='Seleccionar para accion masiva'><input class='ticket-check' type='checkbox' name='ids[]' value='{$id}' form='formAccionesMasivas' aria-label='Seleccionar incidencia {$id}'></label>"
        : '';

    return "
        <article class='kanban-card {$urgencia}' draggable='true' data-id='{$id}'>
            <header>
                {$selector}
                <span class='kanban-id'>#{$id}</span>
                <span class='kanban-urgencia urg-{$urgencia}'>{$urgenciaLabel}</span>
                <span class='priority-score' title='{$prioridadTitulo}'>{$prioridad}</span>
            </header>
            <h4><a class='kanban-title' href='ver_incidencia.php?id={$id}' draggable='false'>{$titulo}</a></h4>
            <p class='kanban-resumen'>{$resumen}</p>
            <div class='ticket-signals'>
                <span class='sla-badge {$slaClase}' title='Objetivo de resolucion'>{$slaTexto}</span>
                <span class='turn-badge {$turnoClase}'>{$turnoTexto}</span>
            </div>
            <div class='kanban-foot'>
                <span class='kanban-meta'><span class='kanban-meta-tipo'>{$tipo}</span>" . ($edadTexto !== '' ? "<span class='kanban-meta-sep'> · </span><span class='kanban-meta-edad'>{$edadTexto}</span>" : "") . "</span>
                <span class='kanban-foot-actions'>
                    <span class='{$avatarClase}' title='{$avatarTitle}'>{$avatarTexto}</span>
                    <a class='kanban-open' href='ver_incidencia.php?id={$id}' draggable='false'>Abrir ›</a>
                </span>
            </div>
            <div class='kanban-assignment'>
                <label for='tecnico-{$id}'>Tecnico</label>
                <form class='kanban-asignado-form' data-id='{$id}'>
                    <select id='tecnico-{$id}' name='asignado' class='kanban-select-asignado' aria-label='Tecnico de incidencia {$id}' title='Se guarda al elegir'>
                        {$opcionesAsignado}
                    </select>
                </form>
                <span class='assignment-status' aria-live='polite'></span>
                <button type='button' class='asignarme-link' data-asignarme='" . (int)(auth_usuario()['id'] ?? 0) . "'>Asignarme</button>
            </div>
            <details class='kanban-controls-disclosure'>
                <summary>Departamento</summary>
            <div class='kanban-controls'>
                <form class='kanban-tipo-form' data-id='{$id}'>
                    <select name='tipo' class='kanban-select-tipo' aria-label='Departamento' title='Departamento'>
                        {$options}
                    </select>
                </form>
            </div>
            </details>
        </article>
    ";
}

/** Chip con el usuario autenticado y enlace de salida, para las cabeceras. */
function ui_menu_usuario(): string {
    $usuario = function_exists('auth_usuario') ? auth_usuario() : null;
    if ($usuario === null) {
        return '';
    }

    $iniciales = ui_e(ui_iniciales((string)$usuario['nombre']));
    $nombre = ui_e((string)$usuario['nombre']);
    $rol = ui_e(ucfirst((string)$usuario['rol']));
    $admin = auth_es('admin') ? "<a href='admin_inicio.php' title='Panel de administracion'>Admin</a>" : '';

    return "
        <span class='usuario-chip' title='{$nombre}'>
            <span class='kanban-avatar'>{$iniciales}</span>
            <span>{$nombre} <span class='usuario-rol'>· {$rol}</span></span>
            {$admin}
            <a href='mi_cuenta.php' title='Seguridad de mi cuenta'>Mi cuenta</a>
            <a href='logout.php' title='Cerrar sesion'>Salir</a>
        </span>
    ";
}

function ui_render_markdown_block(string $text, string $extraClasses = '', string $emptyText = ''): string {
    $classes = trim('markdown-compact js-markdown ' . $extraClasses);
    $safeClasses = ui_e($classes);
    $safeText = ui_e($text);
    $safeEmpty = ui_e($emptyText);
    $fallback = $text !== '' ? nl2br($safeText) : $safeEmpty;

    return "<div class=\"{$safeClasses}\" data-md=\"{$safeText}\" data-md-empty=\"{$safeEmpty}\">{$fallback}</div>";
}

function ui_render_timeline_item(array $event): string {
    $tipo = ui_e($event['tipo'] ?? 'evento');
    $titulo = ui_e($event['titulo'] ?? 'Evento');
    $descripcion_raw = trim((string)($event['descripcion'] ?? ''));
    $descripcion = $descripcion_raw !== '' ? ui_render_markdown_block($descripcion_raw, 'timeline-md') : '';
    $fecha = ui_e($event['fecha'] ?? '');
    $enlace_raw = (string)($event['enlace'] ?? '');
    $enlace = str_starts_with($enlace_raw, '#') ? ui_e($enlace_raw) : '';
    $enlace_texto = ui_e((string)($event['enlace_texto'] ?? 'Ver contenido'));
    $referencia = $enlace !== '' ? "<a class='timeline-reference' href='{$enlace}'>{$enlace_texto} &rsaquo;</a>" : '';
    $desplegable_raw = trim((string)($event['desplegable'] ?? ''));
    if ($desplegable_raw !== '') {
        $desplegable_tipo = ui_e((string)($event['desplegable_texto'] ?? 'contenido'));
        $desplegable_contenido = ui_render_markdown_block($desplegable_raw, 'timeline-md timeline-disclosure-content');
        $referencia = "<details class='timeline-disclosure'><summary><span class='timeline-disclosure-open'>Ver {$desplegable_tipo}</span><span class='timeline-disclosure-close'>Ocultar {$desplegable_tipo}</span></summary>{$desplegable_contenido}</details>";
    }

    return "
        <article class='timeline-item {$tipo}'>
            <div class='timeline-dot'></div>
            <div class='timeline-content'>
                <header>
                    <strong>{$titulo}</strong>
                    <span>{$fecha}</span>
                </header>
                {$descripcion}
                {$referencia}
            </div>
        </article>
    ";
}

// ---------------------------------------------------------------------------
// Layout compartido del panel de administracion
// ---------------------------------------------------------------------------

function ui_admin_nav(string $activa): string {
    $tabs = [
        'admin_inicio.php' => ['Vista general', 'Prioridades y salud del servicio', 'panel', 'Gestion'],
        'admin_operacion.php' => ['Necesita atencion', 'Entregas, salud y conservacion', 'flujo', 'Gestion'],
        'admin_usuarios.php' => ['Personas y acceso', 'Usuarios, roles y contrasenas', 'personas', 'Gestion'],
        'admin_clientes.php' => ['Organizaciones', 'Empresas y niveles de servicio', 'empresa', 'Gestion'],
        'admin_flujos.php' => ['Ciclo de vida', 'Resolucion, cierre y archivo', 'flujo', 'Servicio'],
        'admin_reglas.php' => ['Equipos y reparto', 'Simular antes de automatizar', 'personas', 'Servicio'],
        'admin_calendario.php' => ['Horario de servicio', 'Jornada, festivos y simulacion', 'historial', 'Servicio'],
        'admin_conocimiento.php' => ['Conocimiento', 'Articulos y autoservicio', 'libro', 'Servicio'],
        'admin_catalogo.php' => ['Catalogo comercial', 'Productos para el asistente', 'catalogo', 'Servicio'],
        'ver_logs_llm.php' => ['Control de IA', 'Calidad, consumo y errores', 'ia', 'Plataforma'],
        'admin_ajustes.php' => ['Configuracion', 'IA, SLA y mantenimiento', 'ajustes', 'Plataforma'],
        'admin_auditoria.php' => ['Auditoria', 'Registro de acciones', 'historial', 'Plataforma'],
    ];

    $html = "<aside class='admin-sidebar' id='adminSidebar'>";
    $html .= "<a class='admin-brand' href='admin_inicio.php'><span class='admin-brand-mark'>T</span><span><strong>TicketIA</strong><small>Administracion</small></span></a>";
    $html .= "<label class='admin-nav-search'><span class='sr-only'>Buscar en administracion</span><input type='search' id='adminNavSearch' placeholder='Buscar una funcion...' autocomplete='off'></label>";
    $html .= "<nav class='admin-nav' aria-label='Administracion'>";
    $grupoActual = '';
    foreach ($tabs as $url => [$etiqueta, $descripcion, $icono, $grupo]) {
        if ($grupo !== $grupoActual) {
            $html .= "<span class='admin-nav-group'>{$grupo}</span>";
            $grupoActual = $grupo;
        }
        $clase = $url === $activa ? 'admin-nav-link active' : 'admin-nav-link';
        $actual = $url === $activa ? " aria-current='page'" : '';
        $html .= "<a class='{$clase}' href='{$url}'{$actual}><span class='admin-nav-icon'>" . ui_icono($icono) . "</span><span><strong>{$etiqueta}</strong><small>{$descripcion}</small></span></a>";
    }
    $html .= "<p id='adminNavVacio' hidden>Sin coincidencias</p></nav><div class='admin-sidebar-foot'><a href='index.php'>&larr; Espacio de soporte</a><a href='ayuda.php'>Ver centro de ayuda</a></div>";
    return $html . "</aside>";
}

/** Navegacion comun del espacio de soporte. */
function ui_support_nav(string $activa = 'tickets'): string {
    if (in_array($activa, ['accion', 'sla', 'espera', 'sin_asignar'], true)) $activa = 'tickets';
    $usuario = auth_usuario();
    $miId = (int)($usuario['id'] ?? 0);
    $items = [
        ['tickets', 'index.php', 'IN', 'Bandeja activa', 'Trabajo del equipo'],
        ['mios', 'index.php?filtro_asignado=' . $miId, 'MI', 'Mis incidencias', 'Cola personal'],
        ['archivo', 'archivo.php', 'HI', 'Historial', 'Cerradas y archivadas'],
        ['analisis', 'analisis.php', 'IA', 'Inteligencia', 'Analisis del backlog'],
        ['ayuda', 'ayuda.php', 'CO', 'Conocimiento', 'Soluciones reutilizables'],
    ];
    $html = "<aside class='support-sidebar' id='supportSidebar'>";
    $html .= "<a class='support-brand' href='index.php'><span class='support-brand-mark'>T</span><span><strong>TicketIA</strong><small>AI Service Desk</small></span></a>";
    $html .= "<nav class='support-nav' aria-label='Espacio de soporte'>";
    foreach ($items as [$clave, $url, $icono, $titulo, $detalle]) {
        $icono = ui_icono(match ($clave) { 'analisis'=>'ia', 'ayuda'=>'libro', 'archivo'=>'historial', 'mios','sin_asignar'=>'personas', 'accion'=>'flujo', default=>'panel' });
        $clase = $clave === $activa ? 'support-nav-link active' : 'support-nav-link';
        $actual = $clave === $activa ? " aria-current='page'" : '';
        $html .= "<a class='{$clase}' href='{$url}'{$actual}><span class='support-nav-icon'>{$icono}</span><span><strong>{$titulo}</strong><small>{$detalle}</small></span></a>";
    }
    $html .= "</nav><div class='support-sidebar-foot'><a class='support-new-link' href='index.php#nuevaIncidencia'>+ Nueva incidencia</a>";
    if (auth_es('admin')) {
        $html .= "<a href='admin_inicio.php'>Centro de administracion</a>";
    }
    $html .= "<span>Automatizacion privada</span></div></aside>";
    return $html;
}

/** Cabecera completa de una pagina de administracion (hasta el nav incluido). */
function ui_admin_cabecera(string $titulo, string $subtitulo, string $activa): void {
    $t = ui_e($titulo);
    $s = ui_e($subtitulo);
    echo "<!DOCTYPE html>\n<html lang=\"es\">\n<head>\n";
    echo "    <meta charset=\"UTF-8\">\n";
    echo "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    echo "    <title>TicketIA — {$t}</title>\n";
    echo "    <script>document.documentElement.setAttribute(\"data-theme\", localStorage.getItem(\"incidencias_theme\") || \"light\");</script>\n";
    echo "    <link rel=\"stylesheet\" href=\"estilos.css?v=" . filemtime(__DIR__ . '/../public/estilos.css') . "\">\n";
    echo "</head>\n<body class=\"admin-body\">\n<div class=\"admin-shell\">\n";
    echo ui_admin_nav($activa);
    echo "<a class=\"skip-link\" href=\"#contenidoPrincipal\">Saltar al contenido</a><main class=\"admin-main\" id=\"contenidoPrincipal\">\n";
    echo "<header class=\"admin-topbar\">\n<button id=\"adminMenuToggle\" class=\"admin-menu-toggle\" type=\"button\" aria-label=\"Abrir menu\" aria-expanded=\"false\">Menu</button><div class=\"admin-heading\">\n<span class=\"admin-eyebrow\">Centro de control</span><h1>{$t}</h1>\n<p class=\"subtitulo\">{$s}</p>\n</div>\n";
    echo "<div class=\"usuario-zona\">" . ui_menu_usuario() . "<button id=\"themeToggle\" class=\"theme-button\" type=\"button\" aria-label=\"Cambiar tema\" title=\"Cambiar tema\">Tema</button></div>\n";
    echo "</header>\n<div class=\"admin-content\">\n";
}

/** Pie comun de una pagina de administracion (cierra el layout y aplica el tema). */
function ui_admin_pie(): void {
    echo <<<'HTML'
</div>
</main>
</div>
<script src="experiencia.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    const menuToggle = document.getElementById('adminMenuToggle');
    const sidebar = document.getElementById('adminSidebar');
    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('incidencias_theme', theme);
    };
    applyTheme(localStorage.getItem('incidencias_theme') || 'light');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    }
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', () => {
            const abierto = document.body.classList.toggle('admin-menu-open');
            menuToggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        });
        document.addEventListener('click', (event) => {
            if (document.body.classList.contains('admin-menu-open') && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                document.body.classList.remove('admin-menu-open');
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }
});
</script>
</body>
</html>
HTML;
}

/** Iconos propios, sin dependencias ni fuentes externas. */
function ui_icono(string $nombre): string {
    $trazos = [
        'panel' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'personas' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m3 10v-3a6 6 0 0 0-2-4"/>',
        'empresa' => '<path d="M4 21V3h12v18M16 10h4v11M2 21h20M8 7h4M8 11h4M8 15h4M9 21v-3h2v3"/>',
        'flujo' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/><path d="M14 6h4v4M6 14v4h4"/>',
        'libro' => '<path d="M12 5v16M12 5C9 3 5 3 2 4v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-1-10 1Z"/>',
        'catalogo' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5Zm0 10v10M3 7l9 5 9-5M8 4l9 5"/>',
        'ia' => '<path d="m12 3 3 6 6 3-6 3-3 6-3-6-6-3 6-3 3-6ZM20 2v4m-2-2h4"/>',
        'ajustes' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
        'historial' => '<path d="M3 11a9 9 0 1 1 2 7M3 4v7h7m2-5v6l4 2"/>',
    ];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($trazos[$nombre] ?? $trazos['panel']) . '</svg>';
}

function ui_paginacion(int $pagina, int $total, int $porPagina, array $filtros = []): string {
    $paginas = max(1, (int)ceil($total / $porPagina));
    $html = '<nav class="pagination" aria-label="Paginacion"><span>' . $total . ' resultados &middot; Pagina ' . $pagina . ' de ' . $paginas . '</span>';
    foreach ([-1 => 'Anterior', 1 => 'Siguiente'] as $salto => $etiqueta) {
        if ($pagina + $salto < 1 || $pagina + $salto > $paginas) continue;
        $url = '?' . http_build_query(array_merge($filtros, ['pagina' => $pagina + $salto]));
        $html .= '<a class="card-button secondary-button" href="' . ui_e($url) . '">' . $etiqueta . '</a>';
    }
    return $html . '</nav>';
}

function ui_worker_estado(array $salud): string {
    return '<div class="worker-status"><strong><span class="health-dot ' . ui_e($salud['tono']) . '"></span> '
        . ui_e($salud['etiqueta']) . '</strong><p>' . ui_e($salud['detalle'])
        . '</p><small>Una cola vacia no confirma que el procesador este funcionando.</small></div>';
}

function ui_portal_cabecera(string $titulo, string $activa = 'solicitudes'): void {
    $inicio = auth_es('cliente') ? 'portal.php' : 'index.php';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TicketIA - ' . ui_e($titulo) . '</title><link rel="stylesheet" href="estilos.css?v=' . filemtime(__DIR__ . '/../public/estilos.css') . '"><script src="experiencia.js" defer></script></head><body class="portal-body portal-pro">';
    echo '<a class="skip-link" href="#contenidoPrincipal">Saltar al contenido</a><header class="help-topbar"><a class="help-brand" href="' . $inicio . '"><span class="admin-brand-mark">T</span><strong>TicketIA<span>Centro de ayuda</span></strong></a><nav aria-label="Centro de ayuda"><a href="' . $inicio . '"' . ($activa === 'solicitudes' ? ' aria-current="page"' : '') . '>' . (auth_es('cliente') ? 'Mis solicitudes' : 'Espacio de soporte') . '</a><a href="ayuda.php"' . ($activa === 'ayuda' ? ' aria-current="page"' : '') . '>Guias y soluciones</a></nav><div class="usuario-zona">' . ui_menu_usuario() . '<button type="button" class="theme-button" data-cambiar-tema aria-label="Cambiar tema">Tema</button></div></header><main class="help-main" id="contenidoPrincipal">';
}

function ui_portal_pie(): void {
    echo '</main><footer class="help-footer"><span>TicketIA &middot; Tu espacio de soporte</span><a href="mi_cuenta.php">Mi cuenta y seguridad</a></footer></body></html>';
}
