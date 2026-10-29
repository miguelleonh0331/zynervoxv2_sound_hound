<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Users.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Users;
use Includes\Audit;

Auth::checkAccess([7, 8, 9]);

$msg = '';
$editData = null;
$template = Users::getTemplateValues();

// Manejar Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_user'])) {
        // Recoger solo los campos enviados (el resto usará el template en el backend)
        $data = $_POST;
        unset($data['save_user'], $data['is_edit'], $data['user_id']);

        if (isset($_POST['is_edit']) && $_POST['is_edit'] == '1') {
            if (Users::update($_POST['user_id'], $data)) {
                $msg = "<p style='color: #047857;'>Usuario '".$data['user']."' actualizado.</p>";
            }
        } else {
            if (Users::create($data)) {
                $msg = "<p style='color: #047857;'>Usuario '".$data['user']."' creado.</p>";
            } else {
                $msg = "<p style='color: #b91c1c;'>Error al crear usuario.</p>";
            }
        }
    }

    if (isset($_POST['delete_user'])) {
        if (Users::delete($_POST['user_id'])) {
            $msg = "<p style='color: #b91c1c;'>Usuario eliminado.</p>";
        }
    }

    if (isset($_POST['bulk_upload'])) {
        $rows = explode("\n", $_POST['bulk_data']);
        $success = 0;
        foreach ($rows as $row) {
            $fields = explode(';', trim($row));
            if (count($fields) >= 3) {
                $uData = [
                    'user' => trim($fields[0]),
                    'full_name' => trim($fields[1]),
                    'user_group' => trim($fields[2]),
                    'pass' => trim($fields[0]),
                    'phone_login' => trim($fields[0]),
                    'phone_pass' => trim($fields[0]),
                    'active' => 'Y'
                ];
                if (Users::create($uData)) $success++;
            }
        }
        $msg = "<p style='color: #047857;'>Carga masiva completada: $success usuarios creados.</p>";
    }
}

if (isset($_GET['edit_id'])) {
    $editData = Users::getById($_GET['edit_id']);
}

$filterGroup = $_GET['group_filter'] ?? null;
$search = $_GET['search'] ?? null;
$allUsers = Users::getAll($filterGroup, $search);
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
    <title>Usuarios - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        /* Version liviana/densa solo para esta pagina: sin Google Fonts,
           sin sombras/gradientes, tabla clasica en vez de tarjetas de fila. */
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

        .toolbar { display: flex; gap: 6px; margin-bottom: 0.6rem; align-items: center; }
        .toolbar input, .toolbar select { margin-top: 0; }
    </style>
</head>
<body>

    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('users');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('users');
    }
    ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Gestión de Usuarios</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Administración de Cuentas y Permisos</p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div style="display: flex; flex-direction: column; gap: 0.3rem;">

            <!-- Listado de Usuarios: tabla clasica -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; gap: 10px;">
                    <h2>Usuarios</h2>
                    <div style="display: flex; gap: 10px;">
                        <button onclick="document.getElementById('bulk-box').style.display='block'" class="link-action">Masiva</button>
                        <a href="users.php" class="link-action">+ Nuevo</a>
                    </div>
                </div>

                <!-- Caja de Carga Masiva (Oculta) -->
                <div id="bulk-box" style="display:none; background:var(--glass); padding:0.6rem; margin-bottom:0.75rem; border:1px dashed var(--primary);">
                    <h4 style="margin-bottom:0.4rem; font-size:0.75rem; color: var(--text);">Carga Masiva (usuario;nombre completo;grupo)</h4>
                    <form method="POST">
                        <textarea name="bulk_data" placeholder="agente1;Pepe Perez;AGENTE&#10;agente2;Maria Lopez;AGENTE" style="height:80px; font-family:monospace; font-size:0.75rem; width:100%;"></textarea>
                        <div style="display:flex; gap:10px; margin-top:6px;">
                            <button type="submit" name="bulk_upload" class="btn-primary" style="padding:4px 12px; font-size:0.75rem;">Procesar Carga</button>
                            <button type="button" onclick="document.getElementById('bulk-box').style.display='none'" class="link-action">Cancelar</button>
                        </div>
                    </form>
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
                        <a href="users.php" class="btn-action" title="Limpiar filtros">&times;</a>
                    <?php endif; ?>
                </form>

                <table class="classic-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Nombre Completo</th>
                            <th>Nivel</th>
                            <th>Grupo</th>
                            <th>Activo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allUsers as $u):
                            $lastAction = $u['last_login_date'];
                            if ($lastAction == '2001-01-01 00:00:01') {
                                $lastAction = $u['created_at'] ?: '2001-01-01 00:00:01';
                            }
                            $daysInactive = 0;
                            if ($lastAction != '2001-01-01 00:00:01') {
                                $diff = time() - strtotime($lastAction);
                                $daysInactive = floor($diff / (60 * 60 * 24));
                            }
                            $isNew = ($u['last_login_date'] == '2001-01-01 00:00:01' && $daysInactive <= 10);
                            $isDead = ($daysInactive > 10);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo $u['user']; ?></strong>
                                <?php if ($isNew): ?><span style="color:#047857; font-size:0.65rem;"> NUEVO</span><?php endif; ?>
                                <?php if ($isDead): ?><span style="color:#b91c1c; font-size:0.65rem;"> INACTIVO (<?php echo $daysInactive; ?>d)</span><?php endif; ?>
                            </td>
                            <td><?php echo $u['full_name']; ?></td>
                            <td><span class="badge-level"><?php echo $u['user_level']; ?></span></td>
                            <td><?php echo $u['user_group']; ?></td>
                            <td><?php echo $u['active'] == 'Y' ? 'Si' : 'No'; ?></td>
                            <td style="white-space: nowrap;">
                                <a href="?edit_id=<?php echo $u['user_id']; ?>">Editar</a>
                                <form method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar usuario <?php echo $u['user']; ?>?');">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                    <button type="submit" name="delete_user" class="link-inline danger">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($allUsers)): ?>
                            <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">Sin resultados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Formulario de creacion / edicion -->
            <div class="card">
                <h2><?php echo $editData ? 'Editar Usuario' : 'Creación de Usuario'; ?></h2>
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

                        <div style="margin-top: 0.4rem;">
                            <button type="button" onclick="document.getElementById('advanced-options').style.display='block'; this.style.display='none'" class="link-action">Mostrar opciones avanzadas</button>
                        </div>
                    </div>

                    <div id="advanced-options" style="display:none;">
                        <div class="form-section">
                            <h3>Teléfono de Agente</h3>
                            <div class="grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem;">
                                <?php
                                renderField('Phone Login', 'phone_login', $d['phone_login']);
                                renderField('Phone Pass', 'phone_pass', $d['phone_pass']);
                                ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Permisos de Modificación</h3>
                            <div class="grid-4">
                                <?php
                                $yn = ['1' => 'Si (1)', '0' => 'No (0)'];
                                renderField('Mod. Usuarios', 'modify_users', $d['modify_users'], 'select', $yn);
                                renderField('Mod. Campañas', 'modify_campaigns', $d['modify_campaigns'], 'select', $yn);
                                renderField('Mod. Listas', 'modify_lists', $d['modify_lists'], 'select', $yn);
                                renderField('Mod. Leads', 'modify_leads', $d['modify_leads'], 'select', $yn);
                                renderField('Mod. Ingroups', 'modify_ingroups', $d['modify_ingroups'], 'select', $yn);
                                renderField('Mod. Tipificaciones', 'modify_statuses', $d['modify_statuses'], 'select', $yn);
                                renderField('Ver Reportes', 'view_reports', $d['view_reports'], 'select', $yn);
                                renderField('Exportar Rep.', 'export_reports', $d['export_reports'], 'select', $yn);
                                ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Permisos de Eliminación</h3>
                            <div class="grid-4">
                                <?php
                                renderField('Borrar Usuarios', 'delete_users', $d['delete_users'], 'select', $yn);
                                renderField('Borrar Campañas', 'delete_campaigns', $d['delete_campaigns'], 'select', $yn);
                                renderField('Borrar Listas', 'delete_lists', $d['delete_lists'], 'select', $yn);
                                renderField('Borrar Ingroups', 'delete_ingroups', $d['delete_ingroups'], 'select', $yn);
                                ?>
                            </div>
                        </div>

                        <div class="form-section" style="border-bottom: none;">
                            <h3>Interfaz de Agente</h3>
                            <div class="grid-3">
                                <?php
                                renderField('Grabación', 'vicidial_recording', $d['vicidial_recording'], 'select', ['1' => 'On', '0' => 'Off']);
                                renderField('Transferencias', 'vicidial_transfers', $d['vicidial_transfers'], 'select', $yn);
                                renderField('Marcación Manual', 'agentcall_manual', $d['agentcall_manual'], 'select', $yn);
                                renderField('Callbacks Personales', 'agentonly_callbacks', $d['agentonly_callbacks'], 'select', $yn);
                                renderField('Hotkeys', 'hotkeys_active', $d['hotkeys_active'], 'select', $yn);
                                renderField('API Access', 'vdc_agent_api_access', $d['vdc_agent_api_access'], 'select', $yn);
                                ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 0.6rem;">
                        <button type="submit" name="save_user" class="btn-primary" style="padding: 0.4rem 1rem;">
                            <?php echo $editData ? 'Guardar Cambios' : 'Crear Usuario desde Plantilla'; ?>
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
