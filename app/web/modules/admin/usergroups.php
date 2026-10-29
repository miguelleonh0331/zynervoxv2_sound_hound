<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/UserGroups.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\UserGroups;
use Includes\Campaigns;
use Includes\Audit;

Auth::checkAccess(9);

$msg = '';
$editData = null;
$template = UserGroups::getTemplateValues();
$allCampaigns = Campaigns::getAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_group'])) {
        $data = $_POST;
        
        // Formateo de campañas permitidas
        if (isset($_POST['sel_campaigns']) && is_array($_POST['sel_campaigns'])) {
            $data['allowed_campaigns'] = " " . implode(" ", $_POST['sel_campaigns']) . " -";
        } else {
            $data['allowed_campaigns'] = " -";
        }

        unset($data['save_group'], $data['is_edit'], $data['old_group_id'], $data['sel_campaigns']);
        
        if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
            if (UserGroups::update($_POST['old_group_id'], $data)) {
                $msg = "<p style='color: #10b981;'>✅ Grupo '".$data['user_group']."' actualizado.</p>";
            }
        } else {
            if (UserGroups::create($data)) {
                $msg = "<p style='color: #10b981;'>✅ Grupo '".$data['user_group']."' creado.</p>";
            } else {
                $msg = "<p style='color: #fca5a5;'>❌ Error al crear grupo.</p>";
            }
        }
    }

    if (isset($_POST['delete_group'])) {
        if (UserGroups::delete($_POST['user_group'])) {
            $msg = "<p style='color: #ef4444;'>🗑️ Grupo eliminado.</p>";
        }
    }
}

if (isset($_GET['edit_id'])) {
    $editData = UserGroups::getById($_GET['edit_id']);
}

$allGroups = UserGroups::getAll();

function renderField($label, $name, $value, $type = 'text', $options = []) {
    echo "<div class='field'>";
    echo "<label>$label</label>";
    if ($type === 'select') {
        echo "<select name='$name'>";
        foreach ($options as $k => $v) {
            $sel = ($value == $k) ? 'selected' : '';
            echo "<option value='$k' $sel>$v</option>";
        }
        echo "</select>";
    } else {
        echo "<input type='$type' name='$name' value='".htmlspecialchars($value)."'>";
    }
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Grupos de Usuario - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .form-section { background: transparent; border-radius: 0; padding: 0.6rem 0; margin-bottom: 0; border: none; border-bottom: 1px solid var(--border); }
        .form-section h3 { margin-top: 0; margin-bottom: 0.4rem; color: var(--text); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; }
        .group-row { padding: 5px 8px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; }
        .camp-check { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.75rem; padding: 4px 6px; border-radius: 2px; }
        .camp-check:hover { background: var(--glass); }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('usergroups'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Grupos de Usuario</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Configuración de Permisos por Nivel</p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: grid; grid-template-columns: 300px 1fr; gap: 1.25rem; align-items: start;">

            <!-- Listado Lateral -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <h2>Grupos</h2>
                    <a href="usergroups.php" class="link-action">+ Nuevo</a>
                </div>
                <?php foreach ($allGroups as $g): ?>
                    <div class="group-row">
                        <div>
                            <strong><?php echo $g['user_group']; ?></strong><br>
                            <span style="font-size: 0.7rem; color: var(--text-muted);"><?php echo $g['group_name']; ?></span>
                        </div>
                        <div>
                            <a href="?edit_id=<?php echo urlencode($g['user_group']); ?>" class="link-action">Editar</a>
                            <form method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar grupo <?php echo $g['user_group']; ?>?');">
                                <input type="hidden" name="user_group" value="<?php echo $g['user_group']; ?>">
                                <button type="submit" name="delete_group" class="link-action danger">Eliminar</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Formulario Principal -->
            <div class="card">
                <form method="POST">
                    <?php if ($editData): ?>
                        <input type="hidden" name="is_edit" value="1">
                        <input type="hidden" name="old_group_id" value="<?php echo $editData['user_group']; ?>">
                    <?php endif; ?>

                    <?php $d = $editData ?: $template; ?>
                    <div class="form-section">
                        <h3>Configuración del Grupo</h3>
                        <div class="grid-2">
                            <?php 
                            renderField('ID Grupo', 'user_group', $d['user_group']);
                            renderField('Nombre Descriptivo', 'group_name', $d['group_name']);
                            ?>
                        </div>

                        <div style="margin-top: 1rem;">
                            <label>Campañas Permitidas</label>
                            <div style="background: var(--glass); border-radius: 2px; padding: 0.6rem; max-height: 220px; overflow-y: auto; border: 1px solid var(--border); margin-top: 0.3rem;">
                                <?php
                                $currentCamps = explode(" ", trim($d['allowed_campaigns']));
                                ?>
                                <?php foreach ($allCampaigns as $c): ?>
                                    <label class="camp-check">
                                        <input type="checkbox" name="sel_campaigns[]" value="<?php echo $c['campaign_id']; ?>"
                                            <?php echo in_array($c['campaign_id'], $currentCamps) ? 'checked' : ''; ?>>
                                        <span style="color: var(--primary); font-weight: 700; font-family: monospace; min-width: 70px;"><?php echo $c['campaign_id']; ?></span>
                                        <span style="color: var(--text-muted);"><?php echo $c['campaign_name']; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="margin-top: 1rem; text-align: center;">
                            <button type="button" onclick="document.getElementById('advanced-grp').style.display='block'; this.style.display='none'" class="link-action">Mostrar opciones avanzadas</button>
                        </div>
                    </div>

                    <div id="advanced-grp" style="display:none;">
                        <div class="form-section">
                            <h3>Permisos de Reportes y Admin</h3>
                            <div class="grid-3">
                                <?php 
                                renderField('Reportes Permitidos', 'allowed_reports', $d['allowed_reports']);
                                renderField('Grupos Visibles (Admin)', 'admin_viewable_groups', $d['admin_viewable_groups']);
                                renderField('Reportes Custom', 'allowed_custom_reports', $d['allowed_custom_reports']);
                                ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Transferencias y Marcación</h3>
                            <div class="grid-3">
                                <?php 
                                $yn = ['Y' => 'Si (Y)', 'N' => 'No (N)'];
                                renderField('Transferencia Ciega', 'agent_xfer_blind_transfer', $d['agent_xfer_blind_transfer'], 'select', $yn);
                                renderField('Transferencia VM', 'agent_xfer_vm_transfer', $d['agent_xfer_vm_transfer'], 'select', $yn);
                                renderField('Park & Dial', 'agent_xfer_park_customer_dial', $d['agent_xfer_park_customer_dial'], 'select', $yn);
                                ?>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="save_group" class="btn-primary" style="width: 100%; padding: 0.6rem;">
                        <?php echo $editData ? 'Guardar Cambios del Grupo' : 'Crear Nuevo Grupo'; ?>
                    </button>
                </form>

                <!-- Auditoría -->
                <div style="margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1.25rem;">
                    <h2 style="margin-bottom: 0.5rem;">Auditoría de Grupos</h2>
                    <div style="overflow-x: auto;">
                        <table class="table-compact">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Admin</th>
                                    <th>Acción</th>
                                    <th>Grupo</th>
                                    <th>Nombre</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $grpLogs = Audit::getRecent('vicidial_user_groups', 15);
                                foreach ($grpLogs as $l): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($l['audit_timestamp'])); ?></td>
                                        <td><strong><?php echo $l['audit_user']; ?></strong></td>
                                        <td><span style="color: <?php echo $l['audit_action'] == 'DELETE' ? '#ef4444' : ($l['audit_action'] == 'CREATE' ? '#10b981' : '#facc15'); ?>"><?php echo $l['audit_action']; ?></span></td>
                                        <td><?php echo $l['user_group']; ?></td>
                                        <td><?php echo $l['group_name']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

</body>
</html>
