<?php


function ui_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ui_estado_label(string $estado): string {
    $map = [
        'abierta' => 'Abierta',
        'en_curso' => 'En curso',
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

function ui_render_kanban_card(array $incidencia, array $asignables = []): string {
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

    return "
        <article class='kanban-card {$urgencia}' draggable='true' data-id='{$id}'>
            <header>
                <span class='kanban-id'>#{$id}</span>
                <span class='kanban-urgencia urg-{$urgencia}'>{$urgenciaLabel}</span>
            </header>
            <h4><a class='kanban-title' href='ver_incidencia.php?id={$id}' draggable='false'>{$titulo}</a></h4>
            <p class='kanban-resumen'>{$resumen}</p>
            <div class='kanban-foot'>
                <span class='kanban-meta'><span class='kanban-meta-tipo'>{$tipo}</span>" . ($edadTexto !== '' ? "<span class='kanban-meta-sep'> · </span><span class='kanban-meta-edad'>{$edadTexto}</span>" : "") . "</span>
                <span class='kanban-foot-actions'>
                    <span class='{$avatarClase}' title='{$avatarTitle}'>{$avatarTexto}</span>
                    <a class='kanban-open' href='ver_incidencia.php?id={$id}' draggable='false'>Abrir ›</a>
                </span>
            </div>
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
    $admin = auth_es('admin') ? "<a href='usuarios.php' title='Gestion de usuarios'>Usuarios</a>" : '';

    return "
        <span class='usuario-chip' title='{$nombre}'>
            <span class='kanban-avatar'>{$iniciales}</span>
            <span>{$nombre} <span class='usuario-rol'>· {$rol}</span></span>
            {$admin}
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
    $descripcion = ui_render_markdown_block((string)($event['descripcion'] ?? ''), 'timeline-md');
    $fecha = ui_e($event['fecha'] ?? '');

    return "
        <article class='timeline-item {$tipo}'>
            <div class='timeline-dot'></div>
            <div class='timeline-content'>
                <header>
                    <strong>{$titulo}</strong>
                    <span>{$fecha}</span>
                </header>
                {$descripcion}
            </div>
        </article>
    ";
}
?>
