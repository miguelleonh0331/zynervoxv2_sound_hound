<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Leads.php';

use Includes\Auth;
use Includes\Leads;

Auth::checkAccess([7, 8, 9]);

$query = trim($_GET['q'] ?? '');
$results = $query !== '' ? Leads::search($query) : [];

$selectedLeadId = $_GET['lead_id'] ?? null;
$selectedLead = null;
$history = [];
$recordings = [];
if ($selectedLeadId) {
    $selectedLead = Leads::getById($selectedLeadId);
    if ($selectedLead) {
        $history = Leads::getCallHistory($selectedLeadId);
        $recordings = Leads::getRecordings($selectedLeadId);
    }
}

function fmtDuration($sec) {
    $sec = (int)$sec;
    return sprintf('%02d:%02d', floor($sec / 60), $sec % 60);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Filters - Zynervox</title>
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
        .classic-table tr.is-selected { outline: 2px solid var(--primary); outline-offset: -2px; }

        .toolbar { display: flex; gap: 6px; margin-bottom: 0.6rem; align-items: center; }
        .toolbar input { flex: 1; font-size: 0.8125rem; }

        .lead-summary { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 0.5rem; font-size: 0.8rem; }
        .lead-summary span { color: var(--text-muted); }
        .lead-summary strong { color: var(--text); }
    </style>
</head>
<body>

    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('filters');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('filters');
    }
    ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Filters</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Buscar contactos y revisar su historial de llamadas</p>
            </div>
        </header>

        <div style="display: flex; flex-direction: column; gap: 0.3rem;">

            <!-- Buscador de contactos -->
            <div class="card">
                <h2>Buscar Contacto</h2>
                <form method="GET" class="toolbar">
                    <input type="text" name="q" placeholder="Teléfono, nombre o Lead ID..." value="<?php echo htmlspecialchars($query); ?>">
                    <button type="submit" class="btn-action">Buscar</button>
                    <?php if ($query): ?>
                        <a href="filters.php" class="btn-action" title="Limpiar">&times;</a>
                    <?php endif; ?>
                </form>

                <?php if ($query): ?>
                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Lead ID</th>
                            <th>Teléfono</th>
                            <th>Nombre</th>
                            <th>Lista</th>
                            <th>Status</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                        <tr class="<?php echo ($selectedLeadId == $r['lead_id']) ? 'is-selected' : ''; ?>">
                            <td><strong><?php echo $r['lead_id']; ?></strong></td>
                            <td><?php echo $r['phone_number']; ?></td>
                            <td><?php echo trim($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td><?php echo $r['list_id']; ?></td>
                            <td><?php echo $r['status']; ?></td>
                            <td>
                                <a href="?q=<?php echo urlencode($query); ?>&lead_id=<?php echo $r['lead_id']; ?>">Ver Historial</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($results)): ?>
                            <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">Sin resultados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Historial de llamadas del contacto seleccionado -->
            <?php if ($selectedLead): ?>
            <div class="card">
                <h2>Historial de Llamadas</h2>
                <div class="lead-summary">
                    <span>Lead ID: <strong><?php echo $selectedLead['lead_id']; ?></strong></span>
                    <span>Teléfono: <strong><?php echo $selectedLead['phone_number']; ?></strong></span>
                    <span>Nombre: <strong><?php echo trim($selectedLead['first_name'] . ' ' . $selectedLead['last_name']); ?></strong></span>
                    <span>Lista: <strong><?php echo $selectedLead['list_id']; ?></strong></span>
                    <span>Status Actual: <strong><?php echo $selectedLead['status']; ?></strong></span>
                </div>

                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Campaña</th>
                            <th>Agente</th>
                            <th>Status</th>
                            <th>Duración</th>
                            <th>Término</th>
                            <th>Teléfono</th>
                            <th>Comentarios</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo date('d/m/Y H:i', strtotime($h['call_date'])); ?></td>
                            <td><?php echo $h['campaign_id']; ?></td>
                            <td><?php echo $h['user']; ?></td>
                            <td><?php echo $h['status']; ?></td>
                            <td><?php echo fmtDuration($h['length_in_sec']); ?></td>
                            <td><?php echo $h['term_reason']; ?></td>
                            <td><?php echo $h['phone_number']; ?></td>
                            <td><?php echo htmlspecialchars($h['comments']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="8" style="text-align:center; color:var(--text-muted);">Sin llamadas registradas para este contacto.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Grabaciones (Audios) del contacto seleccionado -->
            <div class="card">
                <h2>Grabaciones (Audios)</h2>
                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Duración</th>
                            <th>Agente</th>
                            <th>Archivo</th>
                            <th>Reproducir</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recordings as $rec): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo $rec['start_time'] ? date('d/m/Y H:i', strtotime($rec['start_time'])) : '--'; ?></td>
                            <td><?php echo fmtDuration($rec['length_in_sec']); ?></td>
                            <td><?php echo $rec['user']; ?></td>
                            <td><?php echo htmlspecialchars($rec['filename']); ?></td>
                            <td style="min-width: 220px;">
                                <audio controls preload="none" style="height: 28px; width: 220px;">
                                    <source src="play_recording.php?id=<?php echo $rec['recording_id']; ?>">
                                </audio>
                            </td>
                            <td>
                                <a href="play_recording.php?id=<?php echo $rec['recording_id']; ?>" target="_blank">Descargar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recordings)): ?>
                            <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">Sin grabaciones para este contacto.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </main>

</body>
</html>
