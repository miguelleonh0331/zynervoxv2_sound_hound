<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/Audit.php';
require_once __DIR__ . '/../../includes/Motor.php';
use Includes\Auth;
use Includes\Campaigns;
use Includes\Audit;
use Includes\Motor;

Auth::checkAccess([7, 8, 9]);

$msg = '';

// Manejar Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clone'])) {
        try {
            if (Campaigns::clone($_POST['template_id'], $_POST['new_id'], $_POST['new_name'], $_POST['new_desc'])) {
                $msg = "<p style='color: #10b981; margin-bottom: 1rem;'>✅ Campaña '".$_POST['new_id']."' creada exitosamente.</p>";
            }
        } catch (Exception $e) { $msg = "<p style='color: #fca5a5; margin-bottom: 1rem;'>❌ Error: " . $e->getMessage() . "</p>"; }
    }
    
    if (isset($_POST['toggle'])) {
        if (Campaigns::toggleStatus($_POST['campaign_id'], $_POST['current_status'])) {
            $msg = "<p style='color: #10b981; margin-bottom: 1rem;'>✅ Estado actualizado para ".$_POST['campaign_id'].".</p>";
        }
    }

    if (isset($_POST['delete'])) {
        if (Campaigns::delete($_POST['campaign_id'])) {
            $msg = "<p style='color: #ef4444; margin-bottom: 1rem;'>🗑️ Campaña ".$_POST['campaign_id']." eliminada.</p>";
        }
    }

    if (isset($_POST['apply_preset'])) {
        if (Motor::applyToCampaign($_POST['preset_id'], $_POST['campaign_id'])) {
            $msg = "<p style='color: #10b981; margin-bottom: 1rem;'>🚀 Motor aplicado a ".$_POST['campaign_id'].".</p>";
        }
    }
}

$templates = Campaigns::getTemplates();
$allCampaigns = Campaigns::getAll();
$presets = Motor::getPresets();
$logs = Audit::getRecent('vicidial_campaigns', 15);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Campañas - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .log-badge { font-size: 10px; padding: 2px 6px; border-radius: 2px; background: var(--glass); text-transform: uppercase; color: var(--text-muted); }
        .action-CLONE { color: #b45309; }
        .action-ACTIVATE { color: #10b981; }
        .action-DEACTIVATE { color: #ef4444; }
        .action-DELETE { color: #ef4444; }
    </style>
</head>
<body>

    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('campaigns');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('campaigns');
    }
    ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Gestión de Campañas</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Configuración y Auditoría</p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: grid; grid-template-columns: 1fr 320px; gap: 1.25rem; align-items: start;">
            <div class="card">
                <h2>Listado de Campañas</h2>
                <table class="table-compact">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Status</th>
                            <th>Motor</th>
                            <th>Ratio</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCampaigns as $c): ?>
                        <tr>
                            <td><strong><?php echo $c['campaign_id']; ?></strong></td>
                            <td><?php echo $c['campaign_name']; ?></td>
                            <td>
                                <span style="color: <?php echo $c['active'] == 'Y' ? '#10b981' : '#ef4444'; ?>; font-weight: 600;">
                                    <?php echo $c['active'] == 'Y' ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td><?php echo Motor::identifyPreset($c, $presets); ?></td>
                            <td><?php echo $c['auto_dial_level']; ?></td>
                            <td style="white-space: nowrap;">
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="campaign_id" value="<?php echo $c['campaign_id']; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $c['active']; ?>">
                                    <button type="submit" name="toggle" class="link-action">
                                        <?php echo $c['active'] == 'Y' ? 'Desactivar' : 'Activar'; ?>
                                    </button>
                                </form>
                                <form method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar campaña?');">
                                    <input type="hidden" name="campaign_id" value="<?php echo $c['campaign_id']; ?>">
                                    <button type="submit" name="delete" class="link-action danger">Eliminar</button>
                                </form>
                                <form method="POST" class="inline-form" style="margin-top: 2px;">
                                    <input type="hidden" name="campaign_id" value="<?php echo $c['campaign_id']; ?>">
                                    <select name="preset_id" style="font-size: 0.7rem; padding: 1px; height: 20px; width: 110px; display: inline-block;" required>
                                        <option value="">Motor...</option>
                                        <?php foreach ($presets as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"><?php echo $p['preset_name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="apply_preset" class="link-action" style="margin-left:2px;">Aplicar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h2 style="margin-top: 2rem;">Historial de Cambios (Audit Log)</h2>
                <table class="table-compact" style="font-size: 0.7rem;">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Acción</th>
                            <th>Target</th>
                            <th>Detalles</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo date('d/m H:i', strtotime($log['audit_timestamp'])); ?></td>
                            <td><?php echo $log['audit_user']; ?></td>
                            <td><span class="log-badge action-<?php echo $log['audit_action']; ?>"><?php echo $log['audit_action']; ?></span></td>
                            <td><strong><?php echo $log['campaign_id']; ?></strong></td>
                            <td style="color: var(--text-muted); font-size: 0.7rem;">
                                Snapshot: <?php echo $log['campaign_name']; ?> (<?php echo $log['active']; ?>)
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Nueva Campaña (clonar)</h2>
                <form method="POST" style="margin-top: 0.75rem;">
                    <div class="field">
                        <label>Plantilla base</label>
                        <select name="template_id" required>
                            <option value="">Seleccione...</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo $t['campaign_id']; ?>">
                                    <?php echo $t['campaign_id']; ?> - <?php echo $t['campaign_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>ID nueva campaña</label>
                        <input type="text" name="new_id" required maxlength="8">
                    </div>

                    <div class="field">
                        <label>Nombre</label>
                        <input type="text" name="new_name" required>
                    </div>

                    <div class="field">
                        <label>Descripción</label>
                        <textarea name="new_desc" rows="2"></textarea>
                    </div>

                    <button type="submit" name="clone" class="btn-primary" style="width: 100%; padding: 0.6rem;">Clonar y Activar</button>
                </form>
            </div>
        </div>
    </main>

</body>
</html>
