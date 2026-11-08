<?php
declare(strict_types=1);
// Usuarios del panel y su perfil (Administrador, Supervisor, GTR). Solo Administrador. La lógica es de includes/CoreUsers.php; el perfil es user_level.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/CoreUsers.php';
require_once __DIR__ . '/sidebar.php';
use Includes\Auth;
use Includes\CoreUsers;
use Includes\Permissions;

Auth::checkAccess(9);
if (empty($_SESSION['users_csrf'])) $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$actor = (string)$_SESSION['user'];
$error = '';
$message = (string)($_SESSION['users_message'] ?? '');
unset($_SESSION['users_message']);
$edit = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($_SESSION['users_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sesión inválida. Recarga la página.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            CoreUsers::create((string)($_POST['user'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['full_name'] ?? ''), (int)($_POST['user_level'] ?? 0), $actor);
            $_SESSION['users_message'] = 'Usuario creado.';
        } elseif ($action === 'update') {
            CoreUsers::update((string)($_POST['user'] ?? ''), (string)($_POST['full_name'] ?? ''), (int)($_POST['user_level'] ?? 0), ($_POST['active'] ?? '') === '1', (string)($_POST['password'] ?? ''), $actor);
            $_SESSION['users_message'] = 'Cambios guardados.';
        } else {
            throw new RuntimeException('Acción inválida.');
        }
        header('Location: users.php');
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo completar. Inténtalo de nuevo.';
    }
}
$users = [];
try {
    $users = CoreUsers::all();
    $editId = (string)($_GET['edit'] ?? '');
    if ($editId !== '') $edit = CoreUsers::find($editId);
} catch (Throwable $e) { $error = $error ?: 'No se pudo leer la lista de usuarios.'; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .cfg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem; align-items: end; }
        .cfg-grid label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .cfg-grid input, .cfg-grid select { width: 100%; box-sizing: border-box; }
        .card + .card { margin-top: 1rem; }
        .msg-err { color: #dc2626; margin: 0.5rem 0; } .msg-ok { color: #059669; margin: 0.5rem 0; }
        .muted { color: var(--text-muted); font-size: 0.78rem; }
        .tag { font-size: 0.7rem; padding: 1px 6px; background: var(--glass); }
    </style>
</head>
<body>
    <?php renderSidebar('users'); ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Usuarios</h1>
                <p class="muted">Quién entra al panel y con qué perfil. Administrador: todo. Supervisor: Dashboard, Reportes y Guía, solo para consultar. GTR: lo del Supervisor, más iniciar y detener listas, cambiar canales y cargar bases.</p>
            </div>
        </header>
        <?php if ($message !== ''): ?><p class="msg-ok"><?php echo h($message); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="msg-err"><?php echo h($error); ?></p><?php endif; ?>

        <form class="card" method="POST" action="users.php" autocomplete="off">
            <h2><?php echo $edit ? 'Editar ' . h($edit['user']) : 'Nuevo usuario'; ?></h2>
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['users_csrf']); ?>">
            <input type="hidden" name="action" value="<?php echo $edit ? 'update' : 'create'; ?>">
            <div class="cfg-grid">
                <?php if ($edit): ?><input type="hidden" name="user" value="<?php echo h($edit['user']); ?>">
                <?php else: ?><label>Usuario <input name="user" maxlength="60" required pattern="[A-Za-z0-9_.\-]{3,60}" value="<?php echo h($_POST['user'] ?? ''); ?>"></label><?php endif; ?>
                <label>Nombre <input name="full_name" maxlength="100" required value="<?php echo h($edit['full_name'] ?? ($_POST['full_name'] ?? '')); ?>"></label>
                <label>Perfil
                    <select name="user_level" required>
                        <?php $cur = (int)($edit['user_level'] ?? ($_POST['user_level'] ?? 8)); foreach (Permissions::PROFILES as $lvl => $name): ?>
                            <option value="<?php echo (int)$lvl; ?>"<?php echo $cur === (int)$lvl ? ' selected' : ''; ?>><?php echo h($name); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <?php if ($edit): ?>
                <label>Estado
                    <select name="active"><option value="1"<?php echo $edit['active'] === 'Y' ? ' selected' : ''; ?>>Activo</option><option value="0"<?php echo $edit['active'] === 'Y' ? '' : ' selected'; ?>>Desactivado</option></select></label>
                <?php endif; ?>
                <label><?php echo $edit ? 'Clave nueva (vacío = no cambiar)' : 'Clave (mínimo 8)'; ?> <input type="password" name="password" maxlength="72" autocomplete="new-password"<?php echo $edit ? '' : ' required minlength="8"'; ?>></label>
                <button type="submit" class="btn-primary"><?php echo $edit ? 'Guardar cambios' : 'Crear usuario'; ?></button>
                <?php if ($edit): ?><a class="btn-action" href="users.php">Cancelar</a><?php endif; ?>
            </div>
            <p class="muted">Los usuarios no se borran: se desactivan y dejan de poder entrar.</p>
        </form>

        <div class="card">
            <h2>Usuarios del panel</h2>
            <div style="overflow-x:auto;"><table class="table-compact">
                <thead><tr><th>Usuario</th><th>Nombre</th><th>Perfil</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?php echo h($u['user']); ?></strong></td>
                        <td><?php echo h($u['full_name']); ?></td>
                        <td><span class="tag"><?php echo h(Permissions::profileName((int)$u['user_level'])); ?></span></td>
                        <td><?php echo $u['active'] === 'Y' ? 'Activo' : 'Desactivado'; ?></td>
                        <td><a class="btn-action" href="users.php?edit=<?php echo rawurlencode($u['user']); ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="5" class="muted">Sin usuarios.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </main>
</body>
</html>
