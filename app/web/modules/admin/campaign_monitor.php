<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/CampaignMonitor.php';

use Includes\Auth;
use Includes\Campaigns;
use Includes\CampaignMonitor;

Auth::checkAccess([7, 8, 9]);
if (\Config\Config::deployment('isolated',false)) {
    $monitorTitle='Monitor de campanas';
    require __DIR__.'/../../bot_ivr/campaign_monitor_view.php';
    exit;
}

$campaigns = Campaigns::getAll();
// La lista de campañas monitoreadas vive en localStorage del navegador
// (pedido explícito del usuario: "así no saturo mi BD con consultas a
// campañas que no me interesan"). El servidor NO conoce esa lista en la
// carga inicial -- todo el fetch de snapshots ocurre por JS, ver abajo.
// Lo único que SÍ se resuelve server-side siempre es la lista de leads
// huérfanos (diagnóstico global, no depende de qué campañas se monitoreen).
$orphanLists = CampaignMonitor::getOrphanLists();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Monitor de Campañas - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        /* Campañas lado a lado, 2 columnas (hasta 16 campañas visibles sin
           scroll horizontal, "2 x 8" pedido por el usuario) en vez de
           apiladas en una sola columna. Con pantallas angostas cae a 1
           columna sola. Borde naranja de marca para resaltar cada tarjeta. */
        #campaign-groups {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            align-items: start;
        }
        #campaign-groups .card {
            margin-bottom: 0; /* el gap del grid ya separa las tarjetas */
            border: 2px solid #F5821F;
        }
        @media (max-width: 900px) {
            #campaign-groups { grid-template-columns: 1fr; }
        }

        #watchlist-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 1rem; }
        .watch-chip {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
            border-radius: 12px; background: var(--glass); font-size: .75rem; color: var(--text);
        }
        .watch-chip button {
            border: none; background: none; color: var(--text-muted); cursor: pointer;
            font-size: .85rem; line-height: 1; padding: 0;
        }
        .watch-chip button:hover { color: #ef4444; }
        #btn-add-campaign {
            width: 28px; height: 28px; border-radius: 50%; border: 1px dashed var(--border);
            background: var(--bg-card); color: var(--primary); font-size: 1rem; font-weight: 700;
            cursor: pointer; line-height: 1;
        }
        #btn-add-campaign:hover { background: var(--glass); }
        #add-campaign-select { display: none; }
    </style>
</head>
<body>

    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('campaign_monitor');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('campaign_monitor');
    }
    ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Monitor de Campañas</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">
                    Progreso de listas y KPIs de las campañas que agregues &mdash; actualiza cada 8s
                </p>
            </div>
        </header>

        <div id="watchlist-bar">
            <span id="watch-chips"></span>
            <button id="btn-add-campaign" type="button" title="Agregar campaña a monitorear">+</button>
            <select id="add-campaign-select">
                <option value="">Elegir campaña…</option>
                <?php foreach ($campaigns as $c): ?>
                    <option value="<?php echo htmlspecialchars($c['campaign_id']); ?>">
                        <?php echo htmlspecialchars($c['campaign_name']); ?> [<?php echo htmlspecialchars($c['campaign_id']); ?>]
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="orphan-lists"><?php echo CampaignMonitor::renderOrphanHtml($orphanLists); ?></div>

        <div id="campaign-groups"></div>
    </main>

<script>
const ALL_CAMPAIGNS = <?php echo json_encode($campaigns); ?>;
const WATCHLIST_KEY = 'zynervox_campaign_watchlist';

function getWatchlist() {
    try {
        const raw = JSON.parse(localStorage.getItem(WATCHLIST_KEY) || '[]');
        return Array.isArray(raw) ? raw : [];
    } catch (e) { return []; }
}

function saveWatchlist(list) {
    localStorage.setItem(WATCHLIST_KEY, JSON.stringify(list));
}

function campaignName(id) {
    const found = ALL_CAMPAIGNS.find(c => c.campaign_id === id);
    return found ? found.campaign_name : id;
}

function renderChips() {
    const list = getWatchlist();
    const chipsEl = document.getElementById('watch-chips');
    if (list.length === 0) {
        chipsEl.innerHTML = '<span style="color:var(--text-muted); font-size:.8rem;">Sin campañas agregadas todavía</span>';
    } else {
        chipsEl.innerHTML = list.map(id =>
            `<span class="watch-chip">${campaignName(id)} [${id}] <button type="button" data-remove="${id}" title="Quitar">&times;</button></span>`
        ).join(' ');
    }

    // Refresca el <select> de "agregar" para que no ofrezca lo ya agregado.
    const select = document.getElementById('add-campaign-select');
    select.innerHTML = '<option value="">Elegir campaña…</option>' +
        ALL_CAMPAIGNS.filter(c => !list.includes(c.campaign_id))
            .map(c => `<option value="${c.campaign_id}">${c.campaign_name} [${c.campaign_id}]</option>`)
            .join('');
}

function addCampaign(id) {
    if (!id) return;
    const list = getWatchlist();
    if (!list.includes(id)) {
        list.push(id);
        saveWatchlist(list);
        renderChips();
        refreshCampaignMonitor();
    }
}

function removeCampaign(id) {
    const list = getWatchlist().filter(x => x !== id);
    saveWatchlist(list);
    renderChips();
    refreshCampaignMonitor();
}

document.getElementById('watch-chips').addEventListener('click', (event) => {
    const id = event.target.getAttribute('data-remove');
    if (id) removeCampaign(id);
});

document.getElementById('btn-add-campaign').addEventListener('click', () => {
    const select = document.getElementById('add-campaign-select');
    select.style.display = select.style.display === 'none' ? 'inline-block' : 'none';
    if (select.style.display !== 'none') select.focus();
});

document.getElementById('add-campaign-select').addEventListener('change', (event) => {
    addCampaign(event.target.value);
    event.target.style.display = 'none';
});

function refreshCampaignMonitor() {
    const list = getWatchlist();
    const container = document.getElementById('campaign-groups');
    if (list.length === 0) {
        container.innerHTML = "<div class='card'><p style='color:var(--text-muted); text-align:center; padding:2rem; font-size:0.85rem;'>Agrega una campaña con el botón \"+\" para empezar a monitorearla.</p></div>";
        return;
    }
    fetch('api_campaign_monitor.php?ids=' + encodeURIComponent(list.join(',')))
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(e => console.error('Campaign monitor refresh error:', e));
}

renderChips();
refreshCampaignMonitor();
setInterval(refreshCampaignMonitor, 8000);
</script>

</body>
</html>
