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
            <details class='kanban-controls-disclosure'>
                <summary>Ajustar</summary>
            <div class='kanban-controls'>
                <form class='kanban-tipo-form' data-id='{$id}'>
                    <select name='tipo' class='kanban-select-tipo' aria-label='Departamento' title='Departamento'>
                        {$options}
                    </select>
                </form>
                <form class='kanban-asignado-form' data-id='{$id}'>
                    <select name='asignado' class='kanban-select-asignado' aria-label='Asignado' title='Asignado'>
                        {$opcionesAsignado}
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
        'admin_inicio.php' => ['Inicio', 'Resumen y alertas', 'IN'],
        'admin_usuarios.php' => ['Usuarios', 'Cuentas y permisos', 'US'],
        'admin_clientes.php' => ['Empresas', 'Clientes del portal', 'EM'],
        'admin_flujos.php' => ['Flujos', 'Cierre y archivo', 'FL'],
        'admin_catalogo.php' => ['Catalogo', 'Contexto comercial IA', 'CA'],
        'admin_auditoria.php' => ['Auditoria', 'Registro de actividad', 'AU'],
        'admin_ajustes.php' => ['Ajustes', 'IA y mantenimiento', 'AJ'],
        'ver_logs_llm.php' => ['Actividad IA', 'Consumo y errores', 'IA'],
    ];

    $html = "<aside class='admin-sidebar' id='adminSidebar'>";
    $html .= "<a class='admin-brand' href='admin_inicio.php'><span class='admin-brand-mark'>T</span><span><strong>TicketIA</strong><small>Administracion</small></span></a>";
    $html .= "<nav class='admin-nav' aria-label='Administracion'>";
    foreach ($tabs as $url => [$etiqueta, $descripcion, $icono]) {
        $clase = $url === $activa ? 'admin-nav-link active' : 'admin-nav-link';
        $actual = $url === $activa ? " aria-current='page'" : '';
        $html .= "<a class='{$clase}' href='{$url}'{$actual}><span class='admin-nav-icon'>{$icono}</span><span><strong>{$etiqueta}</strong><small>{$descripcion}</small></span></a>";
    }
    $html .= "</nav><div class='admin-sidebar-foot'><a href='index.php'>&larr; Volver al panel</a><span>Centro de control</span></div>";
    return $html . "</aside>";
}

/** Navegacion comun del espacio de soporte. */
function ui_support_nav(string $activa = 'tickets'): string {
    $usuario = auth_usuario();
    $miId = (int)($usuario['id'] ?? 0);
    $items = [
        ['tickets', 'index.php', 'IN', 'Bandeja activa', 'Trabajo del equipo'],
        ['accion', 'index.php?cola=accion', 'AC', 'Para responder', 'Siguiente accion del equipo'],
        ['mios', 'index.php?filtro_asignado=' . $miId, 'MI', 'Mis incidencias', 'Cola personal'],
        ['sin_asignar', 'index.php?filtro_asignado=sin_asignar', 'SA', 'Sin asignar', 'Pendientes de responsable'],
        ['archivo', 'archivo.php', 'HI', 'Historial', 'Cerradas y archivadas'],
        ['analisis', 'analisis.php', 'IA', 'Inteligencia', 'Analisis del backlog'],
    ];
    $html = "<aside class='support-sidebar' id='supportSidebar'>";
    $html .= "<a class='support-brand' href='index.php'><span class='support-brand-mark'>T</span><span><strong>TicketIA</strong><small>AI Service Desk</small></span></a>";
    $html .= "<nav class='support-nav' aria-label='Espacio de soporte'>";
    foreach ($items as [$clave, $url, $icono, $titulo, $detalle]) {
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
    echo "    <link rel=\"stylesheet\" href=\"estilos.css\">\n";
    echo "</head>\n<body class=\"admin-body\">\n<div class=\"admin-shell\">\n";
    echo ui_admin_nav($activa);
    echo "<main class=\"admin-main\">\n";
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
