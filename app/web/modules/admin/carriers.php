<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Carriers.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Carriers;
use Includes\Audit;

Auth::checkAccess(9);
$_SESSION['carriers_csrf'] = $_SESSION['carriers_csrf'] ?? bin2hex(random_bytes(32));
$isolated = \Config\Config::deployment('isolated', false);

$msg = '';
$editData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['carriers_csrf'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('CSRF invalido');
    }
    try {
    if (isset($_POST['save_carrier'])) {
        $data = [
            'carrier_id' => strtoupper(trim($_POST['carrier_id'])),
            'carrier_name' => $_POST['carrier_name'],
            'protocol' => $_POST['protocol'] ?? null,
            'server_ip' => $_POST['server_ip'],
            'account_entry' => $_POST['account_entry'],
            'dialplan_entry' => $isolated ? '' : ($_POST['dialplan_entry'] ?? ''),
            'active' => $_POST['active'],
            'carrier_description' => $_POST['carrier_description'],
        ];

        if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
            $result = Carriers::update($_POST['old_carrier_id'], $data);
        } else {
            $result = Carriers::create($data);
        }

        if ($result['ok']) {
            $editData = Carriers::getById($data['carrier_id']);
            $msg = "<p style='color: #047857;'>Troncal guardada. " . ($isolated ? 'Archivos v2 generados, no activados.' : 'PJSIP recargado.') . "</p>";
            if (!empty($result['reload_output'])) {
                $msg .= "<pre style='background:var(--glass); padding:0.5rem; font-size:0.7rem; white-space:pre-wrap;'>" . htmlspecialchars($result['reload_output']) . "</pre>";
            }
        } else {
            $msg = "<p style='color: #b91c1c;'>Error: " . htmlspecialchars($result['error'] ?? 'desconocido') . "</p>";
        }
    }

    if (isset($_POST['delete_carrier'])) {
        $result = Carriers::delete($_POST['carrier_id']);
        $msg = $result['ok']
            ? "<p style='color: #b91c1c;'>Troncal eliminada. " . ($isolated ? 'Archivos v2 generados, no activados.' : 'PJSIP recargado.') . "</p>"
            : "<p style='color: #b91c1c;'>Error al eliminar: " . htmlspecialchars($result['error'] ?? '') . "</p>";
    }
    } catch (\InvalidArgumentException $error) {
        $msg = "<p style='color:#b91c1c;'>" . htmlspecialchars($error->getMessage()) . "</p>";
    } catch (\Throwable $error) {
        $msg = "<p style='color: #b91c1c;'>No se pudo guardar la troncal; revise ID, campos y permisos.</p>";
    }
}

if (isset($_GET['edit_id'])) {
    $editData = Carriers::getById($_GET['edit_id']);
}
$showCarrierForm = ($_GET['new'] ?? '') === '1' || $editData || isset($_POST['save_carrier']);

$allCarriers = Carriers::getAll();
$serverIp = trim(shell_exec("hostname -I 2>/dev/null") ?? '');
$serverIp = explode(' ', $serverIp)[0] ?? '';

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
    } elseif ($type === 'textarea') {
        echo "<textarea name='$name' rows='6' style='font-family:monospace; font-size:0.75rem;'>" . htmlspecialchars($value) . "</textarea>";
    } else {
        echo "<input type='$type' name='$name' value='" . htmlspecialchars($value) . "'>";
    }
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Admin (Troncales SIP) - Zynervox</title>
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
        .classic-table a, .classic-table button.link-inline {
            color: var(--primary); text-decoration: underline; font-size: 0.75rem;
            background: none; border: none; padding: 0; cursor: pointer; margin-right: 6px;
        }
        .classic-table button.link-inline.danger { color: #b91c1c; }

        .form-section { background: transparent; border-radius: 0; padding: 0.6rem 0; margin-bottom: 0; border: none; border-bottom: 1px solid var(--border); }
        .form-section h3 { margin-top: 0; margin-bottom: 0.4rem; color: var(--text); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; }
        .carrier-config-grid { display:grid; grid-template-columns:minmax(0,3fr) minmax(0,7fr); gap:1rem; }
        .carrier-config-grid textarea { width:100%; min-height:240px; box-sizing:border-box; }
        @media(max-width:800px) { .carrier-config-grid { grid-template-columns:1fr; } }
        .badge-proto { background: var(--primary); color: white; padding: 1px 5px; font-size: 0.7rem; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('admin'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Admin (Troncales SIP)</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;"><?php echo $isolated ? 'Troncales v2 en zynervox_core. Genera archivos propios sin activar ni recargar Asterisk.' : 'Troncales PJSIP: genera configuracion y recarga PJSIP/dialplan.'; ?></p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: flex; flex-direction: column; gap: 0.3rem;">

            <!-- Listado -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <h2>Troncales</h2>
                    <a href="carriers.php?new=1" class="link-action">+ Nueva</a>
                </div>
                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Protocolo</th>
                            <th>Server IP</th>
                            <th>Activo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCarriers as $c): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($c['carrier_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($c['carrier_name']); ?></td>
                            <td><span class="badge-proto"><?php echo htmlspecialchars($isolated ? Carriers::protocol($c) : $c['protocol']); ?></span></td>
                            <td><?php echo htmlspecialchars($c['server_ip']); ?></td>
                            <td><?php echo $c['active'] == 'Y' ? 'Si' : 'No'; ?></td>
                            <td style="white-space: nowrap;">
                                <a href="?edit_id=<?php echo urlencode($c['carrier_id']); ?>">Editar</a>
                                <form method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar troncal <?php echo $c['carrier_id']; ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['carriers_csrf']); ?>">
                                    <input type="hidden" name="carrier_id" value="<?php echo $c['carrier_id']; ?>">
                                    <button type="submit" name="delete_carrier" class="link-inline danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($allCarriers)): ?>
                            <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">Sin troncales.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Formulario -->
            <?php if ($showCarrierForm): ?>
            <div class="card">
                <h2><?php echo $editData ? 'Editar Troncal' : 'Nueva Troncal'; ?></h2>
                <form method="POST" style="margin-top: 0.5rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['carriers_csrf']); ?>">
                    <?php if ($editData): ?>
                        <input type="hidden" name="is_edit" value="1">
                        <input type="hidden" name="old_carrier_id" value="<?php echo $editData['carrier_id']; ?>">
                    <?php endif; ?>
                    <?php $d = $editData ?: ['carrier_id' => '', 'carrier_name' => '', 'server_ip' => $serverIp, 'active' => 'Y', 'account_entry' => '', 'dialplan_entry' => '', 'carrier_description' => '']; ?>

                    <div class="form-section">
                        <div class="grid-3">
                            <?php
                            renderField('ID Troncal (sin espacios)', 'carrier_id', $d['carrier_id']);
                            if ($editData) { echo '<input type="hidden" name="carrier_id" value="' . htmlspecialchars($d['carrier_id']) . '">'; }
                            renderField('Nombre', 'carrier_name', $d['carrier_name']);
                            renderField('Protocolo', 'protocol', $d['protocol'] ?? 'PJSIP', 'select', ['SIP' => 'SIP', 'PJSIP' => 'PJSIP']);
                            renderField('Server IP (local)', 'server_ip', $d['server_ip']);
                            renderField('Activo', 'active', $d['active'], 'select', ['Y' => 'Si (Y)', 'N' => 'No (N)']);
                            renderField('Descripción', 'carrier_description', $d['carrier_description']);
                            ?>
                        </div>
                    </div>

                    <div class="carrier-config-grid">
                    <div class="form-section">
                        <h3>Bloque SIP / PJSIP</h3>
                        <?php renderField('', 'account_entry', $d['account_entry'], 'textarea'); ?>

                    </div>

                    <?php if (!$isolated): ?>
                    <div class="form-section" style="border-bottom: none;">
                        <h3>Dialplan (opcional) — se escribe en modules/asterisk/extensions-zynervox.conf</h3>
                        <?php renderField('', 'dialplan_entry', $d['dialplan_entry'], 'textarea'); ?>

                    </div>
                    <?php else: ?><p>Administra los planes de marcación en <a href="dialplan.php">Dialplan</a>.</p><?php endif; ?>

                    </div>

                    <div style="margin-top: 0.6rem;">
                        <button type="submit" name="save_carrier" class="btn-primary" style="padding: 0.4rem 1rem;">
                            <?php echo $isolated ? 'Guardar y generar archivos v2' : ($editData ? 'Guardar y Recargar PJSIP' : 'Crear y Recargar PJSIP'); ?>
                        </button>
                    </div>
                </form>

            </div>

            <?php endif; ?>
            <!-- Auditoria -->
            <div class="card">
                <h2>Historial de Cambios (Auditoría)</h2>
                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario Admin</th>
                            <th>Acción</th>
                            <th>Troncal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $logs = Carriers::recentChanges();
                        foreach ($logs as $l): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($l['audit_timestamp'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($l['audit_user']); ?></strong></td>
                                <td><?php echo htmlspecialchars($l['audit_action']); ?></td>
                                <td><?php echo htmlspecialchars($l['carrier_id']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Sin actividad.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>
