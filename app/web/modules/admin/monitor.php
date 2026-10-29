<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/AgentMonitor.php';

use Includes\Auth;
use Includes\Campaigns;
use Includes\AgentMonitor;

Auth::checkAccess([7, 8, 9]);

$campaigns = Campaigns::getAll();
$selectedCampaign = $_GET['campaign_id'] ?? '';
$groups = AgentMonitor::getLiveByCampaign($selectedCampaign ?: null);
$totalAgents = 0;
foreach ($groups as $g) { $totalAgents += count($g['agents']); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Monitor - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        /* Cada campana es su propia tarjeta; en grilla para verlas lado a
           lado (antes quedaban apiladas en una sola columna). Los agentes
           adentro son ahora una lista angosta de una columna (no tabla
           ancha), asi que la tarjeta tambien puede ser mas angosta -> caben
           mas campanas por fila (pensado para monitorear ~20 a la vez). */
        #monitor-groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; align-items: start; }
        .agent-list .agent-row:last-child { border-bottom: none; }
    </style>
</head>
<body>

    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('monitor');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('monitor');
    }
    ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Monitor de Agentes</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">
                    <span id="total-agents"><?php echo $totalAgents; ?></span> agentes conectados &mdash; actualiza cada 5s
                </p>
            </div>
            <div>
                <form method="GET" id="filter-form">
                    <select name="campaign_id" onchange="this.form.submit()">
                        <option value="">Todas las campañas</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?php echo $c['campaign_id']; ?>" <?php echo $selectedCampaign == $c['campaign_id'] ? 'selected' : ''; ?>>
                                <?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </header>

        <div id="monitor-groups">
            <?php echo AgentMonitor::renderHtml($groups); ?>
        </div>
    </main>

<script>
const campaignFilter = <?php echo json_encode($selectedCampaign); ?>;

function refreshMonitor() {
    let url = 'api_monitor.php';
    if (campaignFilter) { url += '?campaign_id=' + encodeURIComponent(campaignFilter); }

    fetch(url)
        .then(r => r.text())
        .then(html => {
            document.getElementById('monitor-groups').innerHTML = html;
            const rows = document.querySelectorAll('#monitor-groups tbody tr').length;
            document.getElementById('total-agents').textContent = rows;
        })
        .catch(e => console.error('Monitor refresh error:', e));
}

setInterval(refreshMonitor, 5000);
</script>

</body>
</html>
