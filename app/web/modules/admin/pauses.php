<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/Pauses.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Campaigns;
use Includes\Pauses;
use Includes\Audit;

Auth::checkAccess(9);

$msg = '';
$editData = null;

// Manejar Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Guardar / Editar
    if (isset($_POST['save_pause'])) {
        $data = [
            'pause_code' => strtoupper($_POST['pause_code']),
            'pause_code_name' => $_POST['pause_code_name'],
            'billable' => $_POST['billable'],
            'campaign_id' => $_POST['campaign_id'],
            'time_limit' => (int)$_POST['time_limit'],
            'require_mgr_approval' => $_POST['require_mgr_approval']
        ];

        if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
            if (Pauses::update($_POST['old_code'], $_POST['old_campaign_id'], $data)) {
                $msg = "<div class='alert success'>✅ Pausa '".$data['pause_code']."' actualizada.</div>";
            } else { $msg = "<div class='alert error'>❌ Error al actualizar.</div>"; }
        } else {
            if (Pauses::create($data)) {
                $msg = "<div class='alert success'>✅ Pausa '".$data['pause_code']."' creada.</div>";
            } else { $msg = "<div class='alert error'>❌ Error al crear.</div>"; }
        }
    }

    // Clonar
    if (isset($_POST['clone_pauses'])) {
        if (Pauses::copyFromCampaign($_POST['source_campaign'], $_POST['target_campaign'])) {
            $msg = "<div class='alert success'>📋 Pausas clonadas de {$_POST['source_campaign']} a {$_POST['target_campaign']}.</div>";
        } else { $msg = "<div class='alert error'>❌ Error al clonar pausas.</div>"; }
    }

    // Eliminar
    if (isset($_POST['delete_pause'])) {
        if (Pauses::delete($_POST['pause_code'], $_POST['campaign_id'])) {
            $msg = "<div class='alert success'>🗑️ Pausa eliminada correctamente.</div>";
        }
    }
}

// Carga de datos para edición
if (isset($_GET['edit_code']) && isset($_GET['edit_campaign'])) {
    $editData = Pauses::getById($_GET['edit_code'], $_GET['edit_campaign']);
}

$selectedCampaign = $_GET['filter_campaign'] ?? '';
$campaigns = Campaigns::getAll();
$pauses = Pauses::getAll($selectedCampaign);
$logs = Audit::getRecent('vicidial_pause_codes', 15);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Pausas - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .alert { padding: 0.5rem 0.75rem; border-radius: 2px; margin-bottom: 0.75rem; font-size: 0.8rem; }
        .success { background: #ECFDF5; border: 1px solid #10b981; color: #047857; }
        .error { background: #FEF2F2; border: 1px solid #ef4444; color: #b91c1c; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 0.6rem; }
        .badge { font-size: 10px; padding: 2px 6px; border-radius: 2px; background: var(--glass); color: #10b981; font-weight: 700; }
        .badge.no { color: #ef4444; }
        code.status-code { background: var(--glass); padding: 2px 5px; border-radius: 2px; color: var(--text); }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('pauses'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Administrar Pausas</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Configuración de Códigos de Pausa por Campaña</p>
            </div>
            <div>
                <button class="btn-action" onclick="document.getElementById('clone-modal').style.display='flex'">Clonar Pausas</button>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: grid; grid-template-columns: 1fr 380px; gap: 1.25rem; align-items: start;">

            <!-- Listado y Filtros -->
            <div>
                <div class="card" style="margin-bottom: 1rem; padding: 1rem;">
                    <form method="GET" style="display: flex; gap: 0.75rem; align-items: flex-end;">
                        <div class="field" style="margin-bottom: 0; flex: 1;">
                            <label>Filtrar por Campaña</label>
                            <select name="filter_campaign" onchange="this.form.submit()">
                                <option value="">Todas las campañas</option>
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?php echo $c['campaign_id']; ?>" <?php echo $selectedCampaign == $c['campaign_id'] ? 'selected' : ''; ?>>
                                        <?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <a href="pauses.php" class="btn-action">Limpiar</a>
                    </form>
                </div>

                <div class="card">
                    <h2>Códigos de Pausa</h2>
                    <?php if (empty($pauses)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 1.5rem; font-size: 0.85rem;">No hay pausas configuradas.</p>
                    <?php else: ?>
                        <table class="table-compact">
                            <thead>
                                <tr>
                                    <th>Campaña</th>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Billable</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pauses as $p): ?>
                                <tr>
                                    <td><strong><?php echo $p['campaign_id']; ?></strong></td>
                                    <td><code class="status-code"><?php echo $p['pause_code']; ?></code></td>
                                    <td><?php echo $p['pause_code_name']; ?></td>
                                    <td><span class="badge <?php echo $p['billable'] == 'YES' ? '' : 'no'; ?>"><?php echo $p['billable']; ?></span></td>
                                    <td style="white-space: nowrap;">
                                        <a href="?edit_code=<?php echo urlencode($p['pause_code']); ?>&edit_campaign=<?php echo urlencode($p['campaign_id']); ?>&filter_campaign=<?php echo $selectedCampaign; ?>" class="link-action">Editar</a>
                                        <form method="POST" onsubmit="return confirm('¿Eliminar código de pausa?');" class="inline-form">
                                            <input type="hidden" name="pause_code" value="<?php echo $p['pause_code']; ?>">
                                            <input type="hidden" name="campaign_id" value="<?php echo $p['campaign_id']; ?>">
                                            <button type="submit" name="delete_pause" class="link-action danger">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <h2 style="margin-top: 1.25rem; margin-bottom: 0.5rem; font-size: 0.9rem;">Historial de Auditoría</h2>
                <div class="card">
                    <table class="table-compact">
                        <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Campaña</th><th>Código</th></tr></thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('d/m H:i', strtotime($log['audit_timestamp'])); ?></td>
                                <td><?php echo $log['audit_user']; ?></td>
                                <td><span style="color: var(--primary);"><?php echo $log['audit_action']; ?></span></td>
                                <td><?php echo $log['campaign_id']; ?></td>
                                <td><strong><?php echo $log['pause_code']; ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Formulario -->
            <div class="card" style="position: sticky; top: 1rem;">
                <h2><?php echo $editData ? 'Editar Pausa' : 'Nueva Pausa'; ?></h2>
                <form method="POST" style="margin-top: 0.75rem;">
                    <?php if ($editData): ?>
                        <input type="hidden" name="is_edit" value="1">
                        <input type="hidden" name="old_code" value="<?php echo $editData['pause_code']; ?>">
                        <input type="hidden" name="old_campaign_id" value="<?php echo $editData['campaign_id']; ?>">
                    <?php endif; ?>

                    <div class="field">
                        <label>Campaña</label>
                        <select name="campaign_id" required <?php echo $editData ? 'disabled' : ''; ?>>
                            <option value="">Seleccione...</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo $c['campaign_id']; ?>" <?php echo ($editData && $editData['campaign_id'] == $c['campaign_id']) || ($selectedCampaign == $c['campaign_id']) ? 'selected' : ''; ?>>
                                    <?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editData): ?>
                            <input type="hidden" name="campaign_id" value="<?php echo $editData['campaign_id']; ?>">
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label>Código de Pausa (ID)</label>
                        <input type="text" name="pause_code" value="<?php echo $editData ? $editData['pause_code'] : ''; ?>" placeholder="Ej: SOPORTE" maxlength="6" required <?php echo $editData ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="field">
                        <label>Nombre de la Pausa</label>
                        <input type="text" name="pause_code_name" value="<?php echo $editData ? $editData['pause_code_name'] : ''; ?>" placeholder="Ej: Soporte Técnico" maxlength="30" required>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label>Remunerada (Billable)</label>
                            <select name="billable">
                                <option value="YES" <?php echo ($editData && $editData['billable'] == 'YES') ? 'selected' : ''; ?>>Si (YES)</option>
                                <option value="NO" <?php echo (!$editData || $editData['billable'] == 'NO') ? 'selected' : ''; ?>>No (NO)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Requiere Gerente</label>
                            <select name="require_mgr_approval">
                                <option value="NO" selected>No (NO)</option>
                                <option value="YES" <?php echo ($editData && $editData['require_mgr_approval'] == 'YES') ? 'selected' : ''; ?>>Si (YES)</option>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label>Límite de Tiempo (segundos)</label>
                        <input type="number" name="time_limit" value="<?php echo $editData ? $editData['time_limit'] : '0'; ?>" placeholder="0 = Sin límite">
                    </div>

                    <button type="submit" name="save_pause" class="btn-primary" style="width: 100%; margin-top: 0.75rem; padding: 0.6rem;">
                        <?php echo $editData ? 'Actualizar Pausa' : 'Crear Pausa'; ?>
                    </button>

                    <?php if ($editData): ?>
                        <a href="pauses.php?filter_campaign=<?php echo $selectedCampaign; ?>" style="display: block; text-align: center; margin-top: 8px; color: var(--text-muted); font-size: 0.75rem;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </main>

    <!-- Modal de Clonación -->
    <div id="clone-modal" style="display:none; position:fixed; inset:0; background:rgba(45,47,59,0.5); z-index:100; align-items:center; justify-content:center;">
        <div class="card" style="width:100%; max-width:380px; padding: 1.5rem;">
            <h2 style="margin-bottom:1rem;">Clonar Pausas</h2>
            <form method="POST">
                <div class="field">
                    <label>Desde Campaña (Origen)</label>
                    <select name="source_campaign" required>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?php echo $c['campaign_id']; ?>"><?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>A Campaña (Destino)</label>
                    <select name="target_campaign" required>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?php echo $c['campaign_id']; ?>"><?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:10px; margin-top:1rem;">
                    <button type="button" class="btn-action" style="flex:1; text-align:center;" onclick="document.getElementById('clone-modal').style.display='none'">Cancelar</button>
                    <button type="submit" name="clone_pauses" class="btn-primary" style="flex:1; padding: 0.5rem;">Clonar Todo</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
