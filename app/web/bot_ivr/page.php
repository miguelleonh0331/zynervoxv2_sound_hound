<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// Homologado al layout de Zynervox: reemplaza el envoltorio propio de CARSA
// (topbar con logo SynerVox, olas SVG, particulas, CSS en /synervox/assets/...)
// por nuestro sidebar.php + layout.css. El contenido/logica de cada pagina
// (index.php, etc.) no cambia, solo el marco visual.
function initial_survey_page_start(string $title, string $subtitle = ''): void {
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?php echo $title !== '' ? h($title) . ' - ' : ''; ?>Bot IVR - Zynervox</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../modules/admin/layout.css">
<style>
    /* Puente de compatibilidad: las paginas migradas de CARSA usan estas
       clases (.carsa-*, .timeline-item, .muted, etc.) por todo el HTML ya
       existente. En vez de reescribir cada echo, se homologan aqui al mismo
       estilo clasico/liviano del resto de Zynervox (tablas oscuras, sin
       sombras/gradientes, colores planos) para no arriesgar la logica PHP. */
    body, .main-content { font-family: Arial, Helvetica, sans-serif; }

    .carsa-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem; margin-bottom: 0.75rem; box-shadow: none; }
    .carsa-card h2 { font-size: 0.85rem; font-weight: 700; margin: 0 0 0.5rem; color: var(--text); }

    .carsa-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .carsa-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }

    .carsa-form { display: flex; flex-direction: column; gap: 0.5rem; }
    .carsa-field { display: flex; flex-direction: column; gap: 0.2rem; }
    .carsa-field label { font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px; }
    .carsa-field input, .carsa-field select, .carsa-field textarea {
        background: #fff; border: 1px solid var(--border); border-radius: 2px;
        padding: 0.4rem 0.5rem; font-size: 0.8125rem; color: var(--text);
    }

    .carsa-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .carsa-btn {
        background: var(--primary); color: #fff; border: none; border-radius: 2px;
        padding: 0.4rem 1rem; font-size: 0.8125rem; font-weight: 600; cursor: pointer;
        text-decoration: none; display: inline-block;
    }
    .carsa-btn:hover { background: var(--primary-hover); }
    .carsa-btn.secondary { background: var(--bg-card); color: var(--text); border: 1px solid var(--border); }
    .carsa-btn.secondary:hover { border-color: var(--primary); color: var(--primary); }

    .timeline, .timeline-item { font-size: 0.75rem; }
    .timeline-item { padding: 0.4rem 0; border-bottom: 1px solid var(--border); }
    .timeline-item strong { display: block; color: var(--text); font-size: 0.78rem; }
    .timeline-item span, .timeline-item code { color: var(--text-muted); font-size: 0.72rem; }

    .muted { color: var(--text-muted); font-size: 0.8rem; }
    .carsa-msg { background: #ECFDF5; border: 1px solid #10b981; color: #047857; padding: 0.5rem 0.75rem; border-radius: 2px; margin-bottom: 0.6rem; font-size: 0.8rem; }
    .carsa-err { background: #FEF2F2; border: 1px solid #ef4444; color: #b91c1c; padding: 0.5rem 0.75rem; border-radius: 2px; margin-bottom: 0.6rem; font-size: 0.8rem; }

    .carsa-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; margin-top: 0.5rem; }
    .carsa-table th {
        background: var(--dark); color: #fff; text-align: left; padding: 5px 8px;
        border: 1px solid var(--dark); text-transform: uppercase; font-size: 0.7rem;
    }
    .carsa-table td { padding: 4px 8px; border: 1px solid var(--border); color: var(--text); }
    .carsa-table tbody tr:nth-child(even) { background: var(--glass); }

    .page-header h1 { font-size: 1.1rem; font-weight: 700; margin: 0; }
    .page-header .subtitle { color: var(--text-muted); font-size: 0.75rem; }

    #manual-call-fields[hidden] { display: none !important; }

    /* Modales nativos <dialog> (ej. "Crear y asignar lista" en
       campaign_edit.php): sin esto el navegador los pinta con su caja por
       defecto, sin padding ni ancho definido -- se ven descuadrados. */
    /* layout.css resetea "* { margin:0 }", que anula el margin:auto por
       defecto con el que el navegador centra un <dialog>: sin reponerlo
       aqui queda pegado en la esquina superior izquierda. */
    dialog { background: var(--bg-card); color: var(--text); border: 1px solid var(--border); border-radius: 4px; padding: 1rem; width: 100%; max-width: 420px; margin: auto; box-shadow: 0 8px 24px rgba(0,0,0,0.18); }
    dialog::backdrop { background: rgba(0,0,0,0.45); }
    dialog h2 { font-size: 0.95rem; font-weight: 700; margin: 0 0 0.75rem; color: var(--text); }
</style>
</head>
<body>

    <?php require_once __DIR__ . '/../modules/admin/sidebar.php'; renderSidebar('bot_ivr', '../'); ?>

    <main class="main-content">
        <?php if ($title !== '' || $subtitle !== ''): ?>
        <header class="top-bar page-header" style="margin-bottom: 0.75rem;">
            <div>
                <?php if ($title !== ''): ?><h1><?php echo h($title); ?></h1><?php endif; ?>
                <?php if ($subtitle !== ''): ?><div class="subtitle"><?php echo h($subtitle); ?></div><?php endif; ?>
            </div>
        </header>
        <?php endif; ?>
<?php
}

function initial_survey_page_end(): void {
?>
    </main>
</body>
</html>
<?php
}
