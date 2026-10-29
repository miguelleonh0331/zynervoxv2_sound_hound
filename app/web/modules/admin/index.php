<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/DashboardStats.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;
use Includes\DashboardStats;

Auth::checkAccess(9);
if (\Config\Config::deployment('isolated', false)) {
    $monitorTitle='Dashboard';
    require __DIR__.'/../../bot_ivr/campaign_monitor_view.php';
    exit;
}

$summary = DashboardStats::getTenantSummary();
$live = DashboardStats::getLiveCounts();
// "asterisk -V" solo imprime la version del binario compilado, no requiere
// conectarse al socket de control (evita darle a www-data acceso al AMI/CLI
// completo de Asterisk solo para mostrar un numero de version).
$asteriskVersionRaw = trim(shell_exec("asterisk -V 2>/dev/null") ?? '');
if (preg_match('/Asterisk\s+([0-9.]+)/', $asteriskVersionRaw, $m)) {
    $asteriskVersion = $m[1];
} else {
    $asteriskVersion = '--';
}

$rowLabels = [
    'users'     => 'Usuarios (Users)',
    'campaigns' => 'Campañas (Campaigns)',
    'lists'     => 'Listas / Leads (Lists)',
    'ingroups'  => 'Colas Entrantes (In-Groups)',
    'dids'      => 'Números Telefónicos (DIDs)',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .strip-top {
            background: var(--bg-sidebar); color: var(--sidebar-text-muted);
            font-size: 0.7rem; padding: 4px 1.5rem; display: flex;
            justify-content: flex-end; gap: 1.5rem; margin: -1.25rem -1.5rem 0 -1.5rem;
        }
        .strip-top strong { color: #fff; }

        .dash-topbar {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 2px; padding: 0.6rem 1.5rem;
            margin: 0 -1.5rem 1rem -1.5rem;
            display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
        }
        .dash-brand { display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--dark, var(--text)); }
        .dash-brand .dot { width: 26px; height: 26px; border-radius: 2px; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; }
        .dash-brand small { display: block; font-size: 0.6rem; color: var(--text-muted); font-weight: 700; letter-spacing: 0.5px; }
        .dash-status { display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: #10b981; font-weight: 700; }
        .dash-status .dot-live { width: 8px; height: 8px; border-radius: 50%; background: #10b981; display: inline-block; }
        .dash-nav { display: flex; gap: 1.25rem; font-size: 0.8rem; color: var(--text-muted); margin-left: auto; align-items: center; }
        .dash-nav a { color: var(--text-muted); text-decoration: none; }
        .dash-nav a:hover { color: var(--primary); }
        .dash-nav .badge-count { background: #ef4444; color: #fff; border-radius: 2px; font-size: 0.6rem; padding: 0 5px; margin-left: 3px; }

        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 1rem; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem 0.9rem; }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .stat-card .stat-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
        .stat-card .stat-value { font-size: 1.7rem; font-weight: 700; margin-top: 2px; }
        .stat-card .stat-sub { font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; }
        .stat-card .stat-icon { width: 30px; height: 30px; border-radius: 2px; background: var(--glass); display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .stat-card .mini-badge { font-size: 0.65rem; font-weight: 700; padding: 1px 7px; border-radius: 2px; }

        .summary-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .refresh-badge { font-size: 0.65rem; color: var(--text-muted); border: 1px solid var(--border); padding: 2px 8px; border-radius: 2px; }

        .dash-footer {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
            margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border);
            font-size: 0.75rem; color: var(--text-muted);
        }
    </style>
</head>
<body>

    <?php renderSidebar('home'); ?>

    <main class="main-content">
        <div class="strip-top">
            <span><strong>ASTERISK V<?php echo htmlspecialchars($asteriskVersion); ?></strong> / WEBRTC</span>
            <span id="dash-clock">--</span>
        </div>

        <div class="dash-topbar">
            <div class="dash-brand">
                <span class="dot">Z</span>
                <span>Zynervox<br><small>DIALER PRO</small></span>
            </div>

            <div class="dash-nav">
                <a href="../../logout.php">Cerrar Sesión (<?php echo htmlspecialchars($_SESSION['user']); ?>)</a>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <div class="stat-label">Agentes Conectados</div>
                        <div class="stat-value"><?php echo $live['connected']; ?></div>
                    </div>
                    <div class="stat-icon">&#128101;</div>
                </div>
                <div class="stat-sub"><?php echo $live['ready']; ?> disponibles &middot; <?php echo $live['paused']; ?> en pausa</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <div class="stat-label">Agentes en Llamada</div>
                        <div class="stat-value"><?php echo $live['incall']; ?></div>
                    </div>
                    <div class="stat-icon">&#127908;</div>
                </div>
                <div class="stat-sub"><?php echo $live['queue']; ?> en cola</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <div class="stat-label">Llamadas Activas</div>
                        <div class="stat-value"><?php echo $live['incall']; ?></div>
                    </div>
                    <div class="stat-icon">&#128222;</div>
                </div>
                <div class="stat-sub">Troncal SIP: <span style="color:var(--text-muted);">--</span></div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <div class="stat-label">Llamadas Timbrando</div>
                        <div class="stat-value"><?php echo $live['ringing'] ?? '--'; ?></div>
                    </div>
                    <div class="stat-icon">&#128276;</div>
                </div>
                <div class="stat-sub">Hopper: <span style="color:var(--text-muted);">-- leads listos</span></div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.25rem;">
            <div class="summary-head">
                <h2>Resumen del Sistema: Registros del Tenant</h2>
                <span class="refresh-badge">Refresco automático: 5s</span>
            </div>
            <table class="table-compact">
                <thead>
                    <tr><th>Registros (Records)</th><th>Activos (Active)</th><th>Inactivos (Inactive)</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rowLabels as $key => $label): $s = $summary[$key]; ?>
                    <tr>
                        <td><?php echo $label; ?></td>
                        <td style="color:#10b981; font-weight:700;"><?php echo $s['active']; ?></td>
                        <td style="color:var(--text-muted);"><?php echo $s['inactive']; ?></td>
                        <td><strong><?php echo $s['total']; ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card" style="margin-bottom: 1.25rem;">
            <div class="summary-head">
                <h2>Estadísticas Totales de Hoy (Total Stats for Today)</h2>
                <a href="#" class="link-action">ver estadísticas detalladas / max stats</a>
            </div>
            <table class="table-compact">
                <thead><tr><th>Total Llamadas</th><th>Total Entrantes (Inbound)</th><th>Total Salientes Predictivas</th><th>Máximo Agentes</th><th>Contactabilidad (RPC)</th></tr></thead>
                <tbody><tr><td colspan="5" style="text-align:center; color:var(--text-muted);">Pendiente de conectar (ver ROADMAP.md)</td></tr></tbody>
            </table>
        </div>

        <div class="card">
            <div class="summary-head">
                <h2>Estadísticas Totales de Ayer (Total Stats for Yesterday)</h2>
                <a href="#" class="link-action">ver histórico consolidado</a>
            </div>
            <table class="table-compact">
                <thead><tr><th>Total Llamadas</th><th>Entrantes</th><th>Salientes</th><th>Máximo Agentes</th><th>Ventas Concretadas (Sale)</th></tr></thead>
                <tbody><tr><td colspan="5" style="text-align:center; color:var(--text-muted);">Pendiente de conectar (ver ROADMAP.md)</td></tr></tbody>
            </table>
        </div>

        <div class="dash-footer">
            <div>Carga CPU: <span style="color:var(--text-muted);">--</span> &nbsp;|&nbsp; Memoria RAM: <span style="color:var(--text-muted);">--</span> &nbsp;|&nbsp; MySQL Latencia: <span style="color:var(--text-muted);">--</span></div>
            <div style="display:flex; gap:0.6rem;">
                <button class="btn-action">Forzar Hopper Reload</button>
                <a href="campaigns.php" class="btn-primary" style="padding: 0.5rem 1rem;">+ Nueva Campaña</a>
            </div>
        </div>
    </main>

<script>
function tickClock() {
    const now = new Date();
    document.getElementById('dash-clock').textContent = now.toLocaleDateString('es-PE', { weekday:'long', year:'numeric', month:'long', day:'numeric' }) + ' - ' + now.toLocaleTimeString('es-PE');
}
tickClock();
setInterval(tickClock, 1000);
</script>

</body>
</html>
