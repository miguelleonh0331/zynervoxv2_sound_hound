<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/Dispositions.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Campaigns;
use Includes\Dispositions;
use Includes\Audit;

Auth::checkAccess(9);

$msg = '';
$editData = null;

// Manejar Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_disposition'])) {
        $data = [
            'status' => strtoupper($_POST['status']),
            'status_name' => $_POST['status_name'],
            'selectable' => $_POST['selectable'],
            'campaign_id' => $_POST['campaign_id'],
            'human_answered' => $_POST['human_answered'],
            'category' => $_POST['category'] ?: 'UNDEFINED',
            'sale' => $_POST['sale'],
            'dnc' => $_POST['dnc'],
            'customer_contact' => $_POST['customer_contact'],
            'not_interested' => $_POST['not_interested'],
            'unworkable' => $_POST['unworkable'],
            'scheduled_callback' => $_POST['scheduled_callback'],
            'completed' => $_POST['completed'],
            'min_sec' => (int)$_POST['min_sec'],
            'max_sec' => (int)$_POST['max_sec'],
            'answering_machine' => $_POST['answering_machine']
        ];

        if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
            if (Dispositions::update($_POST['old_status'], $_POST['old_campaign_id'], $data)) {
                $msg = "<p style='color: #10b981; margin-bottom: 1rem;'>✅ Tipificación '".$data['status']."' actualizada.</p>";
            } else {
                $msg = "<p style='color: #fca5a5; margin-bottom: 1rem;'>❌ Error al actualizar o ya existe.</p>";
            }
        } else {
            if (Dispositions::create($data)) {
                $msg = "<p style='color: #10b981; margin-bottom: 1rem;'>✅ Tipificación '".$data['status']."' creada.</p>";
            } else {
                $msg = "<p style='color: #fca5a5; margin-bottom: 1rem;'>❌ Error al crear: posiblemente el Status ya existe en esa campaña.</p>";
            }
        }
    }

    if (isset($_POST['delete_disposition'])) {
        if (Dispositions::delete($_POST['status'], $_POST['campaign_id'])) {
            $msg = "<p style='color: #ef4444; margin-bottom: 1rem;'>🗑️ Tipificación eliminada.</p>";
        }
    }

    // Clonar tipificaciones
    if (isset($_POST['clone_dispositions'])) {
        if (Dispositions::copyFromCampaign($_POST['source_campaign'], $_POST['target_campaign'])) {
            $msg = "<p style='color: #10b981; margin-bottom: 1rem;'>✅ Tipificaciones clonadas correctamente.</p>";
        } else { $msg = "<p style='color: #fca5a5; margin-bottom: 1rem;'>❌ Error al clonar tipificaciones.</p>"; }
    }
}

// Carga de datos para edición
if (isset($_GET['edit_status']) && isset($_GET['edit_campaign'])) {
    $editData = Dispositions::getById($_GET['edit_status'], $_GET['edit_campaign']);
}

$selectedCampaign = $_GET['filter_campaign'] ?? null;
$campaigns = Campaigns::getAll();
$dispositions = Dispositions::getAll($selectedCampaign);
$logs = Audit::getRecent('vicidial_campaign_statuses', 15);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tipificaciones - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.6rem; }
        .badge { font-size: 10px; padding: 2px 6px; border-radius: 2px; background: var(--glass); color: #10b981; font-weight: 700; }
        .badge.no { color: #ef4444; }
        code.status-code { background: var(--glass); padding: 2px 5px; border-radius: 2px; color: var(--text); }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('dispositions'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Tipificaciones (Dispositions)</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Configuración de Estados por Campaña</p>
            </div>
            <div>
                <button class="btn-action" onclick="document.getElementById('clone-modal').style.display='flex'">Clonar Tipificaciones</button>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: grid; grid-template-columns: 1fr 400px; gap: 1.25rem; align-items: start;">

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
                        <a href="dispositions.php" class="btn-action">Limpiar</a>
                    </form>
                </div>

                <div class="card">
                    <h2>Listado de Dispositions</h2>
                    <?php if (empty($dispositions)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 1.5rem; font-size: 0.85rem;">No hay tipificaciones para mostrar en este filtro.</p>
                    <?php else: ?>
                        <table class="table-compact">
                            <thead>
                                <tr>
                                    <th>Campaña</th>
                                    <th>Status</th>
                                    <th>Nombre</th>
                                    <th>Venta</th>
                                    <th>DNC</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dispositions as $d): ?>
                                <tr>
                                    <td><strong><?php echo $d['campaign_id']; ?></strong></td>
                                    <td><code class="status-code"><?php echo $d['status']; ?></code></td>
                                    <td><?php echo $d['status_name']; ?></td>
                                    <td><span class="badge <?php echo $d['sale'] == 'Y' ? '' : 'no'; ?>"><?php echo $d['sale']; ?></span></td>
                                    <td><span class="badge <?php echo $d['dnc'] == 'Y' ? '' : 'no'; ?>"><?php echo $d['dnc']; ?></span></td>
                                    <td style="white-space: nowrap;">
                                        <a href="?edit_status=<?php echo urlencode($d['status']); ?>&edit_campaign=<?php echo urlencode($d['campaign_id']); ?>&filter_campaign=<?php echo $selectedCampaign; ?>" class="link-action">Editar</a>
                                        <form method="POST" onsubmit="return confirm('¿Eliminar tipificación?');" class="inline-form">
                                            <input type="hidden" name="status" value="<?php echo $d['status']; ?>">
                                            <input type="hidden" name="campaign_id" value="<?php echo $d['campaign_id']; ?>">
                                            <button type="submit" name="delete_disposition" class="link-action danger">Eliminar</button>
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
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Acción</th>
                                <th>Campaña</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('d/m H:i', strtotime($log['audit_timestamp'])); ?></td>
                                <td><?php echo $log['audit_user']; ?></td>
                                <td><span style="color: var(--primary);"><?php echo $log['audit_action']; ?></span></td>
                                <td><?php echo $log['campaign_id']; ?></td>
                                <td><strong><?php echo $log['status']; ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Formulario de Creación/Edición -->
            <div class="card" style="position: sticky; top: 1rem;">
                <h2><?php echo $editData ? 'Editar Tipificación' : 'Nueva Tipificación'; ?></h2>
                <form method="POST" action="dispositions.php?filter_campaign=<?php echo $selectedCampaign; ?>" style="margin-top: 0.75rem;">
                    <?php if ($editData): ?>
                        <input type="hidden" name="is_edit" value="1">
                        <input type="hidden" name="old_status" value="<?php echo $editData['status']; ?>">
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

                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem;">
                        <div class="field">
                            <label>Status (ID)</label>
                            <input type="text" name="status" value="<?php echo $editData ? $editData['status'] : ''; ?>" placeholder="Ej: VENTA" maxlength="6" required <?php echo $editData ? 'readonly' : ''; ?>>
                        </div>
                        <div class="field">
                            <label>Nombre del Estado</label>
                            <input type="text" name="status_name" value="<?php echo $editData ? $editData['status_name'] : ''; ?>" placeholder="Ej: Venta Exitosa" maxlength="30" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label>Seleccionable</label>
                            <select name="selectable">
                                <option value="Y" <?php echo ($editData && $editData['selectable'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                <option value="N" <?php echo ($editData && $editData['selectable'] == 'N') ? 'selected' : ''; ?>>No (N)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Human Answered</label>
                            <select name="human_answered">
                                <option value="Y" <?php echo ($editData && $editData['human_answered'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                <option value="N" <?php echo (!$editData || $editData['human_answered'] == 'N') ? 'selected' : ''; ?>>No (N)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Es Venta</label>
                            <select name="sale">
                                <option value="Y" <?php echo ($editData && $editData['sale'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                <option value="N" <?php echo (!$editData || $editData['sale'] == 'N') ? 'selected' : ''; ?>>No (N)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>DNC (No llamar)</label>
                            <select name="dnc">
                                <option value="Y" <?php echo ($editData && $editData['dnc'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                <option value="N" <?php echo (!$editData || $editData['dnc'] == 'N') ? 'selected' : ''; ?>>No (N)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Categoría</label>
                            <input type="text" name="category" value="<?php echo $editData ? $editData['category'] : 'UNDEFINED'; ?>">
                        </div>
                        <div class="field">
                            <label>Cont. Cliente</label>
                            <select name="customer_contact">
                                <option value="Y" <?php echo ($editData && $editData['customer_contact'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                <option value="N" <?php echo (!$editData || $editData['customer_contact'] == 'N') ? 'selected' : ''; ?>>No (N)</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border);">
                        <strong style="font-size: 0.8125rem;">Opciones Avanzadas</strong>
                        <div class="form-grid" style="margin-top: 0.6rem;">
                            <div class="field">
                                <label>No Interesado</label>
                                <select name="not_interested">
                                    <option value="N" selected>No (N)</option>
                                    <option value="Y" <?php echo ($editData && $editData['not_interested'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Unworkable</label>
                                <select name="unworkable">
                                    <option value="N" selected>No (N)</option>
                                    <option value="Y" <?php echo ($editData && $editData['unworkable'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Agendar Llamada</label>
                                <select name="scheduled_callback">
                                    <option value="N" selected>No (N)</option>
                                    <option value="Y" <?php echo ($editData && $editData['scheduled_callback'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Finalizada</label>
                                <select name="completed">
                                    <option value="N" selected>No (N)</option>
                                    <option value="Y" <?php echo ($editData && $editData['completed'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="field">
                                <label>Tiempo Min (sec)</label>
                                <input type="number" name="min_sec" value="<?php echo $editData ? $editData['min_sec'] : '0'; ?>">
                            </div>
                            <div class="field">
                                <label>Tiempo Max (sec)</label>
                                <input type="number" name="max_sec" value="<?php echo $editData ? $editData['max_sec'] : '0'; ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label>Answering Machine</label>
                            <select name="answering_machine">
                                <option value="N" selected>No (N)</option>
                                <option value="Y" <?php echo ($editData && $editData['answering_machine'] == 'Y') ? 'selected' : ''; ?>>Si (Y)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" name="save_disposition" class="btn-primary" style="width: 100%; margin-top: 1rem; padding: 0.6rem;">
                        <?php echo $editData ? 'Actualizar Tipificación' : 'Crear Tipificación'; ?>
                    </button>

                    <?php if ($editData): ?>
                        <a href="dispositions.php?filter_campaign=<?php echo $selectedCampaign; ?>" style="display: block; text-align: center; margin-top: 8px; color: var(--text-muted); font-size: 0.75rem;">Cancelar Edición</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </main>

    <!-- Modal de Clonación -->
    <div id="clone-modal" style="display:none; position:fixed; inset:0; background:rgba(45,47,59,0.5); z-index:100; align-items:center; justify-content:center;">
        <div class="card" style="width:100%; max-width:380px; padding: 1.5rem;">
            <h2 style="margin-bottom:1rem;">Clonar Tipificaciones</h2>
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
                    <button type="submit" name="clone_dispositions" class="btn-primary" style="flex:1; padding: 0.5rem;">Clonar Todo</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
