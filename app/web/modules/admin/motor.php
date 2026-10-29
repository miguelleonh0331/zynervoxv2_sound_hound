<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/Motor.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Campaigns;
use Includes\Motor;
use Includes\Audit;

Auth::checkAccess(9);

$msg = '';

// Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_preset'])) {
        $data = [
            'preset_name' => $_POST['preset_name'],
            'dial_method' => $_POST['dial_method'],
            'auto_dial_level' => $_POST['auto_dial_level'],
            'adaptive_maximum_level' => $_POST['adaptive_maximum_level'],
            'adaptive_dropped_percentage' => $_POST['adaptive_dropped_percentage'],
            'available_only_ratio_tally' => $_POST['available_only_ratio_tally']
        ];
        if (isset($_POST['preset_id']) && !empty($_POST['preset_id'])) {
            if (Motor::updatePreset($_POST['preset_id'], $data)) {
                $msg = "<p style='color: #10b981;'>✅ Preset '".$_POST['preset_name']."' actualizado.</p>";
            }
        } else {
            if (Motor::createPreset($data)) {
                $msg = "<p style='color: #10b981;'>✅ Preset '".$_POST['preset_name']."' creado.</p>";
            }
        }
    }
// ... resto del código post ...
    if (isset($_POST['apply_preset'])) {
        if (Motor::applyToCampaign($_POST['preset_id'], $_POST['campaign_id'])) {
            $msg = "<p style='color: #10b981;'>✅ Motor aplicado a la campaña ".$_POST['campaign_id'].".</p>";
        }
    }

    if (isset($_POST['delete_preset'])) {
        if (Motor::deletePreset($_POST['preset_id'])) {
            $msg = "<p style='color: #ef4444;'>🗑️ Preset eliminado.</p>";
        }
    }
}

$editPreset = null;
if (isset($_GET['edit_id'])) {
    $editPreset = Motor::getPresetById($_GET['edit_id']);
}

$presets = Motor::getPresets();
$campaigns = Campaigns::getAll();
$logs = Audit::getRecent('vox_sphere_motor_presets', 10);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Motor - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
        .preset-card { background: var(--glass); border: 1px solid var(--border); padding: 0.6rem 0.75rem; border-radius: 2px; margin-bottom: 0.5rem; }
        .preset-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.4rem; font-size: 0.75rem; margin: 6px 0 0; color: var(--text); }
        .label { color: var(--text-muted); }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('motor'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Configuración de Motor</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Gestiona presets de marcación y aplícalos a campañas</p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div class="grid">
            <!-- Gestión de Presets -->
            <div>
                <div class="card">
                    <h2><?php echo $editPreset ? 'Editar Preset' : 'Crea un Nuevo Preset'; ?></h2>
                    <form method="POST" action="motor.php" style="margin-top: 0.75rem;">
                        <?php if ($editPreset): ?>
                            <input type="hidden" name="preset_id" value="<?php echo $editPreset['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="field">
                            <label>Nombre del Preset</label>
                            <input type="text" name="preset_name" value="<?php echo $editPreset ? $editPreset['preset_name'] : ''; ?>" placeholder="Ej: Marcación Agresiva" required>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="field">
                                <label>Dial Method</label>
                                <select name="dial_method">
                                    <?php $dm = $editPreset ? $editPreset['dial_method'] : 'MANUAL'; ?>
                                    <option value="MANUAL" <?php echo $dm == 'MANUAL' ? 'selected' : ''; ?>>MANUAL</option>
                                    <option value="RATIO" <?php echo $dm == 'RATIO' ? 'selected' : ''; ?>>RATIO</option>
                                    <option value="ADAPT_HARD_LIMIT" <?php echo $dm == 'ADAPT_HARD_LIMIT' ? 'selected' : ''; ?>>ADAPT_HARD_LIMIT</option>
                                    <option value="ADAPT_TAPERED" <?php echo $dm == 'ADAPT_TAPERED' ? 'selected' : ''; ?>>ADAPT_TAPERED</option>
                                    <option value="ADAPT_AVERAGE" <?php echo $dm == 'ADAPT_AVERAGE' ? 'selected' : ''; ?>>ADAPT_AVERAGE</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Auto Dial Level</label>
                                <input type="number" step="0.1" name="auto_dial_level" value="<?php echo $editPreset ? $editPreset['auto_dial_level'] : '1.0'; ?>">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="field">
                                <label>Adapt Max Level</label>
                                <input type="number" step="0.1" name="adaptive_maximum_level" value="<?php echo $editPreset ? $editPreset['adaptive_maximum_level'] : '3.0'; ?>">
                            </div>
                            <div class="field">
                                <label>Dropped %</label>
                                <input type="number" step="0.5" name="adaptive_dropped_percentage" value="<?php echo $editPreset ? $editPreset['adaptive_dropped_percentage'] : '3.0'; ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label>Available Only Ratio Tally</label>
                            <select name="available_only_ratio_tally">
                                <?php $aort = $editPreset ? $editPreset['available_only_ratio_tally'] : 'N'; ?>
                                <option value="Y" <?php echo $aort == 'Y' ? 'selected' : ''; ?>>Y (Si)</option>
                                <option value="N" <?php echo $aort == 'N' ? 'selected' : ''; ?>>N (No)</option>
                            </select>
                        </div>
                        <button type="submit" name="create_preset" class="btn-primary" style="width: 100%; margin-top: 0.75rem; padding: 0.6rem;">
                            <?php echo $editPreset ? 'Guardar Cambios' : 'Guardar Preset'; ?>
                        </button>
                        <?php if ($editPreset): ?>
                            <a href="motor.php" style="display: block; text-align: center; margin-top: 8px; color: var(--text-muted); font-size: 0.75rem;">Cancelar Edición</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="card" style="margin-top: 1.25rem;">
                    <h2>Presets Guardados</h2>
                    <div style="margin-top: 0.75rem;">
                        <?php foreach ($presets as $p): ?>
                        <div class="preset-card">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong style="font-size: 0.85rem;"><?php echo $p['preset_name']; ?></strong>
                                <div>
                                    <a href="?edit_id=<?php echo $p['id']; ?>" class="link-action">Editar</a>
                                    <form method="POST" onsubmit="return confirm('¿Eliminar preset?');" class="inline-form">
                                        <input type="hidden" name="preset_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" name="delete_preset" class="link-action danger">Eliminar</button>
                                    </form>
                                </div>
                            </div>
                            <div class="preset-grid">
                                <div><span class="label">Method:</span> <?php echo $p['dial_method']; ?></div>
                                <div><span class="label">Level:</span> <?php echo $p['auto_dial_level']; ?></div>
                                <div><span class="label">Max:</span> <?php echo $p['adaptive_maximum_level']; ?></div>
                                <div><span class="label">Drop:</span> <?php echo $p['adaptive_dropped_percentage']; ?>%</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Aplicar a Campañas -->
            <div>
                <div class="card">
                    <h2>Aplicar Motor a Campaña</h2>
                    <form method="POST" style="margin-top: 0.75rem;">
                        <div class="field">
                            <label>Seleccionar Campaña</label>
                            <select name="campaign_id" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo $c['campaign_id']; ?>"><?php echo $c['campaign_id']; ?> - <?php echo $c['campaign_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Seleccionar Preset de Motor</label>
                            <select name="preset_id" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($presets as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo $p['preset_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="apply_preset" class="btn-primary" style="width:100%; margin-top: 0.75rem; padding: 0.6rem;">Aplicar Motor</button>
                    </form>
                </div>

                <div class="card" style="margin-top: 1.25rem;">
                    <h2>Logs de Motor</h2>
                    <table class="table-compact">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>User</th>
                                <th>Acción</th>
                                <th>Preset</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><?php echo date('d/m H:i', strtotime($l['audit_timestamp'])); ?></td>
                                <td><?php echo $l['audit_user']; ?></td>
                                <td><?php echo $l['audit_action']; ?></td>
                                <td><?php echo $l['preset_name']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

</body>
</html>
