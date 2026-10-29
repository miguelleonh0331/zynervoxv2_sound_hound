<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Reports.php';
require_once __DIR__ . '/../../includes/Campaigns.php';

use Includes\Auth;
use Includes\Reports;
use Includes\Campaigns;

Auth::checkAccess([7, 8, 9]);

$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$campaignId = $_GET['campaign_id'] ?? '';

$rows = Reports::getCampaignReport($startDate, $endDate, $campaignId ?: null);
$campaigns = Campaigns::getAll();

function fmtDuration($sec) {
    $sec = (int)$sec;
    return sprintf('%02d:%02d:%02d', floor($sec / 3600), floor(($sec % 3600) / 60), $sec % 60);
}

$grandCalls = array_sum(array_column($rows, 'total_calls'));
$grandContacts = array_sum(array_column($rows, 'contacts'));
$grandSales = array_sum(array_column($rows, 'sales'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        body, .main-content { font-family: Arial, Helvetica, sans-serif; }

        .classic-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        .classic-table th {
            background: var(--dark, #2D2F3B); color: #fff; text-align: left;
            padding: 5px 8px; border: 1px solid var(--dark, #2D2F3B);
            text-transform: uppercase; font-size: 0.7rem;
        }
        .classic-table td { padding: 4px 8px; border: 1px solid var(--border); }
        .classic-table tbody tr:nth-child(even) { background: var(--glass); }
        .classic-table tfoot td { font-weight: 700; background: var(--glass); border-top: 2px solid var(--dark); }

        .toolbar { display: flex; gap: 8px; margin-bottom: 0.6rem; align-items: flex-end; flex-wrap: wrap; }
        .toolbar .field { margin-bottom: 0; }
        .toolbar label { display: block; font-size: 0.65rem; margin-bottom: 2px; }
        .toolbar input, .toolbar select { font-size: 0.75rem; padding: 0.35rem 0.5rem; margin-top: 0; }

        .stat-mini { display: flex; gap: 1.25rem; margin-bottom: 0.5rem; font-size: 0.8rem; }
        .stat-mini strong { display: block; font-size: 1.1rem; color: var(--text); }
        .stat-mini span { color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; }
    </style>
</head>
<body>

    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('reports');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('reports');
    }
    ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Reportes</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Reporte por Campaña — llamadas, contactos, ventas y tiempos</p>
            </div>
        </header>

        <div style="display: flex; flex-direction: column; gap: 0.3rem;">

            <div class="card">
                <form method="GET" class="toolbar">
                    <div class="field">
                        <label>Desde</label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                    </div>
                    <div class="field">
                        <label>Hasta</label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                    </div>
                    <div class="field">
                        <label>Campaña</label>
                        <select name="campaign_id" style="width: auto;">
                            <option value="">Todas las campañas</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo $c['campaign_id']; ?>" <?php echo $campaignId == $c['campaign_id'] ? 'selected' : ''; ?>>
                                    <?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-action">Filtrar</button>
                </form>

                <div class="stat-mini">
                    <div><strong><?php echo number_format($grandCalls); ?></strong><span>Llamadas Totales</span></div>
                    <div><strong><?php echo number_format($grandContacts); ?></strong><span>Contactos</span></div>
                    <div><strong><?php echo number_format($grandSales); ?></strong><span>Ventas</span></div>
                    <div><strong><?php echo $grandCalls > 0 ? round(($grandContacts / $grandCalls) * 100, 1) : 0; ?>%</strong><span>Tasa Contacto</span></div>
                </div>

                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Campaña</th>
                            <th>Llamadas</th>
                            <th>Contactos</th>
                            <th>Ventas</th>
                            <th>Tasa Contacto</th>
                            <th>Tasa Venta</th>
                            <th>Tiempo Hablado</th>
                            <th>Duración Prom.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?php echo $r['campaign_id']; ?></strong> — <?php echo $r['campaign_name']; ?></td>
                            <td><?php echo number_format($r['total_calls']); ?></td>
                            <td><?php echo number_format($r['contacts']); ?></td>
                            <td><?php echo number_format($r['sales']); ?></td>
                            <td><?php echo $r['contact_rate']; ?>%</td>
                            <td><?php echo $r['sale_rate']; ?>%</td>
                            <td><?php echo fmtDuration($r['talk_seconds']); ?></td>
                            <td><?php echo fmtDuration($r['avg_duration']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" style="text-align:center; color:var(--text-muted);">Sin datos para este rango/filtro.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>
