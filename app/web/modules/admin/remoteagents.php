<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Users.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Users;
use Includes\Audit;

Auth::checkAccess(9);

$msg = '';
$editData = null;
$template = Users::getTemplateValues();
$template['user_level'] = 1; // Los agentes remotos operan como Agente por defecto

// Manejar Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_user'])) {
        $data = $_POST;
        unset($data['save_user'], $data['is_edit'], $data['user_id']);

        if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
            if (Users::update($_POST['user_id'], $data)) {
                $msg = "<p style='color: #047857;'>Agente remoto '".$data['user']."' actualizado.</p>";
            }
        } else {
            // true = no crear anexo/extension en "phones": este es justamente
            // el punto que distingue a un Agente Remoto (GSM) de un Usuario normal.
            if (Users::create($data, true)) {
                $msg = "<p style='color: #047857;'>Agente remoto '".$data['user']."' creado (sin anexo SIP).</p>";
            } else {
                $msg = "<p style='color: #b91c1c;'>Error al crear agente remoto.</p>";
            }
        }
    }

    if (isset($_POST['delete_user'])) {
        if (Users::delete($_POST['user_id'])) {
            $msg = "<p style='color: #b91c1c;'>Agente remoto eliminado.</p>";
        }
    }
}

if (isset($_GET['edit_id'])) {
    $editData = Users::getById($_GET['edit_id']);
}

$filterGroup = $_GET['group_filter'] ?? null;
$search = $_GET['search'] ?? null;
$allUsers = Users::getAllRemote($filterGroup, $search);
$userGroups = Users::getUserGroups();

// Helper para inputs
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
        echo "<input type='$type' name='$name' value='$value'>";
    }
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Agentes Remotos (GSM) - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        body, .main-content { font-family: Arial, Helvetica, sans-serif; }

        .sidebar { box-shadow: none; }
        .sidebar-link, .nav-item { border-radius: 0; padding: 0.5rem 1rem; }
        .sidebar-link:hover, .nav-item:hover, .sidebar-link.active, .nav-item.active { transform: none; }

        .card { box-shadow: none; border-radius: 2px; padding: 0.6rem; }
        .card h2 { margin-bottom: 0.4rem; font-size: 0.85rem; }

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
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; }
        .badge-level { background: var(--primary); color: white; padding: 1px 5px; font-size: 0.7rem; }
        .badge-gsm { background: var(--glass); color: var(--text-muted); padding: 1px 5px; font-size: 0.65rem; text-transform: uppercase; }

        .toolbar { display: flex; gap: 6px; margin-bottom: 0.6rem; align-items: center; }
        .toolbar input, .toolbar select { margin-top: 0; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('remote'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Agentes Remotos (GSM)</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Agentes que se loguean marcando a un número externo (celular), sin extensión SIP local</p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: flex; flex-direction: column; gap: 0.3rem;">

            <!-- Listado de Agentes Remotos: tabla clasica -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; gap: 10px;">
                    <h2>Agentes Remotos <span class="badge-gsm">sin anexo</span></h2>
                    <a href="remoteagents.php" class="link-action">+ Nuevo</a>
                </div>

                <!-- Buscador y Filtro -->
                <form method="GET" class="toolbar">
                    <input type="text" name="search" placeholder="Buscar por nombre, ID o login..." value="<?php echo htmlspecialchars($search); ?>" style="flex:1; font-size:0.75rem;">
                    <select name="group_filter" style="font-size: 0.75rem; padding: 0.4rem; width:auto;">
                        <option value="">Todos los grupos</option>
                        <?php foreach ($userGroups as $g): ?>
                            <option value="<?php echo $g['user_group']; ?>" <?php echo $filterGroup == $g['user_group'] ? 'selected' : ''; ?>>
                                <?php echo $g['user_group']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-action">Buscar</button>
                    <?php if ($search || $filterGroup): ?>
                        <a href="remoteagents.php" class="btn-action" title="Limpiar filtros">&times;</a>
                    <?php endif; ?>
                </form>

                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Nombre Completo</th>
                            <th>Nivel</th>
                            <th>Grupo</th>
                            <th>Teléfono (GSM)</th>
                            <th>Activo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allUsers as $u): ?>
                        <tr>
                            <td><strong><?php echo $u['user']; ?></strong></td>
                            <td><?php echo $u['full_name']; ?></td>
                            <td><span class="badge-level"><?php echo $u['user_level']; ?></span></td>
                            <td><?php echo $u['user_group']; ?></td>
                            <td><?php echo $u['phone_login']; ?></td>
                            <td><?php echo $u['active'] == 'Y' ? 'Si' : 'No'; ?></td>
                            <td style="white-space: nowrap;">
                                <a href="?edit_id=<?php echo $u['user_id']; ?>">Editar</a>
                                <form method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar agente remoto <?php echo $u['user']; ?>?');">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                    <button type="submit" name="delete_user" class="link-inline danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($allUsers)): ?>
                            <tr><td colspan="7" style="text-align:center; color:var(--text-muted);">Sin resultados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Formulario de creacion / edicion -->
            <div class="card">
                <h2><?php echo $editData ? 'Editar Agente Remoto' : 'Creación de Agente Remoto (GSM)'; ?></h2>
                <form method="POST" style="margin-top: 0.5rem;">
                    <?php if ($editData): ?>
                        <input type="hidden" name="is_edit" value="1">
                        <input type="hidden" name="user_id" value="<?php echo $editData['user_id']; ?>">
                    <?php endif; ?>

                    <div class="form-section">
                        <div class="grid-3">
                            <?php
                            $d = $editData ?: $template;
                            renderField('ID Usuario / Login', 'user', $d['user']);
                            renderField('Password', 'pass', $d['pass'], 'password');
                            renderField('Nombre Completo', 'full_name', $d['full_name']);

                            $levels = [1 => 'Agente', 7 => 'GTR', 8 => 'Supervisor', 9 => 'Administrador'];
                            renderField('Nivel', 'user_level', $d['user_level'], 'select', $levels);

                            $groups = [];
                            foreach ($userGroups as $g) $groups[$g['user_group']] = $g['user_group'] . " - " . $g['group_name'];
                            renderField('Grupo de Usuario', 'user_group', $d['user_group'], 'select', $groups);

                            renderField('Activo', 'active', $d['active'], 'select', ['Y' => 'Si (Y)', 'N' => 'No (N)']);
                            ?>
                        </div>
                    </div>

                    <div class="form-section" style="border-bottom: none;">
                        <h3>Número Externo (GSM) — a donde se hace el dial-out</h3>
                        <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem;">
                            <?php
                            renderField('Teléfono / Phone Login', 'phone_login', $d['phone_login']);
                            renderField('Phone Pass', 'phone_pass', $d['phone_pass']);
                            ?>
                        </div>
                        <p style="color: var(--text-muted); font-size: 0.7rem; margin-top: 0.4rem;">
                            Este proceso NO crea una extensión/anexo en la tabla de teléfonos (phones): el agente se conecta vía dial-out a este número.
                        </p>
                    </div>

                    <div style="margin-top: 0.6rem;">
                        <button type="submit" name="save_user" class="btn-primary" style="padding: 0.4rem 1rem;">
                            <?php echo $editData ? 'Guardar Cambios' : 'Crear Agente Remoto'; ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Auditoria -->
            <div class="card">
                <h2>Historial de Cambios (Auditoría)</h2>
                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario Admin</th>
                            <th>Acción</th>
                            <th>User Afectado</th>
                            <th>Nombre</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $userLogs = Audit::getRecent('vicidial_users', 20);
                        foreach ($userLogs as $l): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($l['audit_timestamp'])); ?></td>
                                <td><strong><?php echo $l['audit_user']; ?></strong></td>
                                <td><?php echo $l['audit_action']; ?></td>
                                <td><?php echo $l['user']; ?></td>
                                <td><?php echo $l['full_name']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($userLogs)): ?>
                            <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">Sin actividad.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>
