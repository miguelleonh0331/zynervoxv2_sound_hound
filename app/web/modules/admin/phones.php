<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Phones.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Phones;
use Includes\Audit;

Auth::checkAccess(9);
$_SESSION['phones_csrf'] = $_SESSION['phones_csrf'] ?? bin2hex(random_bytes(32));

$msg = '';
$editData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['phones_csrf'], $_POST['csrf_token'])) { http_response_code(403); exit('CSRF invalido'); }
    try {
    if (isset($_POST['save_phone'])) {
        $data = [
            'extension' => trim($_POST['extension']),
            'login' => trim($_POST['login']),
            'pass' => $_POST['pass'],
            'fullname' => $_POST['fullname'],
            'outbound_cid' => $_POST['outbound_cid'],
            'active' => $_POST['active'],
            'protocol' => $_POST['protocol'],
        ];

        if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
            if (Phones::update($_POST['old_extension'], $data)) {
                $msg = "<p style='color: #047857;'>Anexo '{$data['extension']}' actualizado; archivos de anexos generados.</p>";
            } else {
                $msg = "<p style='color: #b91c1c;'>Error al actualizar anexo.</p>";
            }
        } else {
            if (Phones::create($data)) {
                $msg = "<p style='color: #047857;'>Anexo '{$data['extension']}' creado; archivos de anexos generados.</p>";
            } else {
                $msg = "<p style='color: #b91c1c;'>Error al crear anexo (¿ya existe ese número?).</p>";
            }
        }
    }

    if (isset($_POST['delete_phone'])) {
        if (Phones::delete($_POST['extension'])) {
            $msg = "<p style='color: #b91c1c;'>Anexo eliminado; archivos de anexos actualizados.</p>";
        }
    }
    } catch (\Throwable $e) { $msg = "<p style='color:#b91c1c;'>No se pudo guardar; revise extension, protocolo y campos.</p>"; }
}

if (isset($_GET['edit_id'])) {
    $editData = Phones::getById($_GET['edit_id']);
}

$search = $_GET['search'] ?? null;
$allPhones = Phones::getAll($search);

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
        echo "<input type='$type' name='$name' value='" . htmlspecialchars($value ?? '') . "'>";
    }
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Anexos / Teléfonos - Zynervox</title>
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
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; }
        .toolbar { display: flex; gap: 6px; margin-bottom: 0.6rem; align-items: center; }
        .toolbar input { flex: 1; font-size: 0.8125rem; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('phones'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Anexos / Teléfonos</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Cuentas de anexos administradas por Zynervox</p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: flex; flex-direction: column; gap: 0.3rem;">

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <h2>Anexos</h2>
                    <a href="phones.php" class="link-action">+ Nuevo</a>
                </div>

                <form method="GET" class="toolbar">
                    <input type="text" name="search" placeholder="Buscar por extensión, login o nombre..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                    <button type="submit" class="btn-action">Buscar</button>
                    <?php if ($search): ?>
                        <a href="phones.php" class="btn-action" title="Limpiar">&times;</a>
                    <?php endif; ?>
                </form>

                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Extensión</th>
                            <th>Login</th>
                            <th>Nombre</th>
                            <th>Protocolo</th>
                            <th>CID Saliente</th>
                            <th>Activo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allPhones as $p): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['extension']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['login']); ?></td>
                            <td><?php echo htmlspecialchars($p['fullname']); ?></td>
                            <td><?php echo htmlspecialchars($p['protocol']); ?></td>
                            <td><?php echo htmlspecialchars($p['outbound_cid']); ?></td>
                            <td><?php echo $p['active'] == 'Y' ? 'Si' : 'No'; ?></td>
                            <td style="white-space: nowrap;">
                                <a href="?edit_id=<?php echo urlencode($p['extension']); ?>">Editar</a>
                                <form method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar anexo <?php echo htmlspecialchars($p['extension']); ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['phones_csrf']); ?>">
                                    <input type="hidden" name="extension" value="<?php echo htmlspecialchars($p['extension']); ?>">
                                    <button type="submit" name="delete_phone" class="link-inline danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($allPhones)): ?>
                            <tr><td colspan="7" style="text-align:center; color:var(--text-muted);">Sin resultados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2><?php echo $editData ? 'Editar Anexo' : 'Nuevo Anexo'; ?></h2>
                <form method="POST" style="margin-top: 0.5rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['phones_csrf']); ?>">
                    <?php if ($editData): ?>
                        <input type="hidden" name="is_edit" value="1">
                        <input type="hidden" name="old_extension" value="<?php echo $editData['extension']; ?>">
                    <?php endif; ?>
                    <?php $d = $editData ?: ['extension' => '', 'login' => '', 'pass' => '', 'fullname' => '', 'outbound_cid' => '', 'active' => 'Y', 'protocol' => 'SIP']; ?>

                    <div class="form-section" style="border-bottom: none;">
                        <div class="grid-3">
                            <?php
                            renderField('Extensión (número de anexo)', 'extension', $d['extension']);
                            if ($editData) { echo '<input type="hidden" name="extension" value="' . htmlspecialchars($d['extension']) . '">'; }
                            renderField('Login', 'login', $d['login']);
                            renderField('Password (vacio conserva el actual)', 'pass', $editData ? '' : $d['pass'], 'password');
                            renderField('Nombre', 'fullname', $d['fullname']);
                            renderField('CID Saliente', 'outbound_cid', $d['outbound_cid']);
                            renderField('Protocolo', 'protocol', $d['protocol'], 'select', ['SIP' => 'SIP', 'PJSIP' => 'PJSIP']);
                            renderField('Activo', 'active', $d['active'], 'select', ['Y' => 'Si (Y)', 'N' => 'No (N)']);
                            ?>
                        </div>
                    </div>

                    <div style="margin-top: 0.6rem;">
                        <button type="submit" name="save_phone" class="btn-primary" style="padding: 0.4rem 1rem;">
                            <?php echo $editData ? 'Guardar Cambios' : 'Crear Anexo'; ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2>Historial de Cambios (Auditoría)</h2>
                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario Admin</th>
                            <th>Acción</th>
                            <th>Extensión</th>
                            <th>Nombre</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $logs = Phones::recentChanges();
                        foreach ($logs as $l): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($l['audit_timestamp'])); ?></td>
                                <td><strong><?php echo $l['audit_user']; ?></strong></td>
                                <td><?php echo $l['audit_action']; ?></td>
                                <td><?php echo $l['extension']; ?></td>
                                <td><?php echo $l['fullname']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">Sin actividad.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>
