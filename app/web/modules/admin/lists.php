<?php
declare(strict_types=1);
// Listas del marcador v1: crear listas y cargar la base (TXT/CSV). Lógica en bot_ivr/list_service.php
// y campaigns_service.php (BotIVR); esta página no ejecuta SQL propio.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Carriers.php';
require_once __DIR__ . '/../../bot_ivr/db.php';
require_once __DIR__ . '/../../bot_ivr/campaigns_service.php';
require_once __DIR__ . '/../../bot_ivr/campaign_config_service.php';
require_once __DIR__ . '/../../bot_ivr/list_service.php';
require_once __DIR__ . '/../../bot_ivr/list_call_service.php';
use Includes\Auth;

Auth::checkAccess(9);
if (empty($_SESSION['lists_csrf'])) $_SESSION['lists_csrf'] = bin2hex(random_bytes(32));
if (!function_exists('h')) { function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); } }

$error = '';
$message = (string)($_SESSION['lists_message'] ?? '');
unset($_SESSION['lists_message']);
$campaigns = [];
$lists = [];
$origins = [];
$maxTotal = null;
$limits = null;
try {
    $db = bot_ivr_repository();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            if (empty($_POST) && empty($_FILES)) throw new RuntimeException('La carga supera el límite de esta instalación. Usa un archivo más pequeño.');
            if (!hash_equals($_SESSION['lists_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sesión inválida. Recarga la página.');
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'set_server_max') {
                bot_cfg_set_max_total_channels($db, $_POST['max_total'] ?? null);
                $_SESSION['lists_message'] = 'Máximo de canales del servidor actualizado.';
                header('Location: lists.php');
                exit;
            }
            $campaignId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($campaignId === false) throw new RuntimeException('Elige una campaña.');
            if ($action === 'create_list') {
                $listId = bot_campaign_list_create($db, $campaignId, (string)($_POST['list_name'] ?? ''), isset($_POST['active']));
                $_SESSION['lists_message'] = 'Lista #' . $listId . ' creada en la campaña #' . $campaignId . '.';
            } elseif ($action === 'set_channels') {
                $listId = filter_var($_POST['list_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($listId === false) throw new RuntimeException('Elige una lista.');
                bot_cfg_set_list_channels($db, $campaignId, $listId, $_POST['channels'] ?? null);
                $_SESSION['lists_message'] = 'Lista #' . $listId . ': canales actualizados (surten efecto en el siguiente ciclo, sin parar la campaña).';
            } elseif ($action === 'upload_list') {
                $listId = filter_var($_POST['list_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($listId === false) throw new RuntimeException('Elige una lista.');
                bot_list_get($db, $listId, $campaignId);
                $running = bot_list_call_status($listId, $campaignId);
                if ($running && in_array($running['state'], ['starting','running','stopping'], true)) throw new RuntimeException('Deten la marcacion antes de modificar la lista.');
                $upload = $_FILES['clients_txt'] ?? [];
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona un archivo TXT/CSV dentro del límite de subida de esta instalación.');
                if (!in_array(strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)), ['txt','csv'], true)) throw new RuntimeException('El archivo debe tener extensión .txt o .csv.');
                if (PHP_SAPI !== 'cli' && !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) throw new RuntimeException('Archivo de carga inválido.');
                if ((int)$upload['size'] > BOT_LIST_UPLOAD_BYTES) throw new RuntimeException('El archivo supera 10 MB.');
                $contents = file_get_contents((string)$upload['tmp_name']);
                if ($contents === false) throw new RuntimeException('No se pudo leer el archivo.');
                $result = bot_list_import($db, $listId, $campaignId, bot_list_parse_txt($contents));
                $summary = 'Lista #' . $listId . ' · base reemplazada. Importados: ' . $result['saved'] . '. Duplicados: ' . $result['duplicates'] . '. Rechazados: ' . $result['rejected'] . '.';
                if ($result['errors']) $summary .= ' Motivos: ' . implode(' · ', $result['errors']);
                $_SESSION['lists_message'] = $summary;
            } else {
                throw new RuntimeException('Acción inválida.');
            }
            header('Location: lists.php');
            exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    foreach ($db->campaigns() as $campaign) {
        $campaigns[(int)$campaign['campaign_id']] = $campaign;
    }
    $lists = bot_cfg_lists_channels($db);
    try { $limits = bot_cfg_server_limits($db); $maxTotal = $limits['max_channels_server']; } catch (Throwable $e) { $error = $error ?: 'No se pudo leer el máximo de canales del servidor: ' . $e->getMessage(); }
    try { $origins = array_column(\Includes\Carriers::dialOrigins(), 'name', 'dial_prefix'); } catch (Throwable $e) { $origins = []; }
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listas - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .cfg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem; align-items: end; }
        .cfg-grid label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .cfg-grid input, .cfg-grid select { width: 100%; box-sizing: border-box; }
        .msg-ok { color: #10b981; margin-bottom: 1rem; }
        .msg-err { color: #fca5a5; margin-bottom: 1rem; }
        .card + .card { margin-top: 1rem; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('lists'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Listas</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Crear listas y cargar la base de contactos</p>
            </div>
        </header>

        <?php if ($message !== ''): ?><p class="msg-ok"><?php echo h($message); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="msg-err"><?php echo h($error); ?></p><?php endif; ?>

        <form method="POST" action="lists.php" class="card">
            <h2>Límite del servidor</h2>
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['lists_csrf']); ?>">
            <input type="hidden" name="action" value="set_server_max">
            <div class="cfg-grid">
                <label>Máximo de canales (todo el servidor, 1 a 500) <input type="number" name="max_total" value="<?php echo h($maxTotal ?? ''); ?>" min="1" max="500" required></label>
                <button type="submit" class="btn-primary">Guardar máximo</button>
            </div>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.5rem;">Los canales de cada lista no pueden superar este valor.
                <?php if ($limits !== null): ?>Llamadas abiertas ahora: <strong><?php echo (int)$limits['open_calls']; ?></strong>. Suma de canales de las listas activas: <strong><?php echo (int)$limits['lists_sum']; ?></strong>.<?php endif; ?></p>
            <?php if ($limits !== null && (int)$limits['lists_sum'] > (int)$limits['max_channels_server']): ?>
                <p class="msg-err" style="font-size: 0.8rem;">La suma de canales de las listas activas supera el máximo del servidor: el motor nunca abre más llamadas que el máximo y las reparte por orden de llegada.</p>
            <?php endif; ?>
        </form>

        <div class="card" style="margin-top: 1rem;">
            <h2>Listado de listas</h2>
            <table class="table-compact">
                <thead><tr><th>ID</th><th>Campaña</th><th>Nombre</th><th>Activa</th><th>Leads</th><th>Origen</th><th>Canales</th><th>Abiertas</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lists as $l): $cid = (int)$l['campaign_id']; ?>
                    <tr>
                        <td><strong><?php echo (int)$l['list_id']; ?></strong></td>
                        <td><a href="campaigns.php?id=<?php echo $cid; ?>">#<?php echo $cid; ?> <?php echo h($campaigns[$cid]['name'] ?? ''); ?></a></td>
                        <td><?php echo h($l['name']); ?></td>
                        <td><?php echo (int)$l['active'] ? 'Sí' : 'No'; ?></td>
                        <td><?php echo (int)$l['leads_count']; ?></td>
                        <td><?php echo ($l['dial_prefix'] ?? '') === '' ? '—' : h(($origins[$l['dial_prefix']] ?? '') . ' (' . $l['dial_prefix'] . ')'); ?></td>
                        <td>
                            <form method="POST" action="lists.php" class="inline-form" style="display: inline;">
                                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['lists_csrf']); ?>">
                                <input type="hidden" name="action" value="set_channels">
                                <input type="hidden" name="campaign_id" value="<?php echo $cid; ?>">
                                <input type="hidden" name="list_id" value="<?php echo (int)$l['list_id']; ?>">
                                <input type="number" name="channels" value="<?php echo (int)($l['max_channels'] ?? 1); ?>" min="1"<?php echo $maxTotal !== null ? ' max="' . (int)$maxTotal . '"' : ''; ?> style="width: 5rem;" required>
                                <button type="submit" class="btn-action">Aplicar</button>
                            </form>
                        </td>
                        <td><?php echo (int)($l['open_calls'] ?? 0); ?></td>
                        <td><a class="btn-action" href="../../bot_ivr/list_edit.php?id=<?php echo (int)$l['list_id']; ?>&amp;campaign_id=<?php echo $cid; ?>">Ficha</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lists): ?><tr><td colspan="9" style="color: var(--text-muted);">Sin listas.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <form method="POST" action="lists.php" class="card">
            <h2>Crear lista</h2>
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['lists_csrf']); ?>">
            <input type="hidden" name="action" value="create_list">
            <div class="cfg-grid">
                <label>Campaña
                    <select name="campaign_id" required><option value="">— elegir —</option>
                    <?php foreach ($campaigns as $cid => $campaign): ?><option value="<?php echo $cid; ?>">#<?php echo $cid; ?> <?php echo h($campaign['name']); ?></option><?php endforeach; ?>
                    </select></label>
                <label>Nombre <input name="list_name" maxlength="120" required></label>
                <label>Activa <select name="active"><option value="1">Sí</option><option value="">No</option></select></label>
                <button type="submit" class="btn-primary">Crear lista</button>
            </div>
        </form>

        <form method="POST" action="lists.php" enctype="multipart/form-data" class="card">
            <h2>Cargar base de contactos</h2>
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['lists_csrf']); ?>">
            <input type="hidden" name="action" value="upload_list">
            <input type="hidden" name="campaign_id" id="up_campaign" value="">
            <div class="cfg-grid">
                <label>Lista
                    <select name="list_id" id="up_list" required><option value="">— elegir —</option>
                    <?php foreach ($lists as $l): ?><option value="<?php echo (int)$l['list_id']; ?>" data-campaign="<?php echo (int)$l['campaign_id']; ?>">#<?php echo (int)$l['list_id']; ?> · <?php echo h($l['name']); ?> (campaña #<?php echo (int)$l['campaign_id']; ?>)</option><?php endforeach; ?>
                    </select></label>
                <label>Archivo (TXT/CSV) <input type="file" name="clients_txt" accept=".txt,.csv,text/plain,text/csv" required></label>
                <button type="submit" class="btn-primary">Reemplazar base</button>
            </div>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.75rem;">
                Primera línea: cabecera de 2 a 10 columnas separadas por comas; <code>numero</code> es obligatoria y el resto se conserva como variables del contacto
                (ej. <code>numero,nombre,monto</code>). UTF-8, hasta 50000 registros y 10 MB (límite de esta instalación: <?php echo h(ini_get('upload_max_filesize')); ?>).
                <strong>La carga reemplaza todos los contactos de la lista.</strong> Se informan importados, duplicados y rechazados con sus motivos.
            </p>
        </form>
        <script>
        document.getElementById('up_list').addEventListener('change', function () {
            var o = this.options[this.selectedIndex];
            document.getElementById('up_campaign').value = o ? (o.getAttribute('data-campaign') || '') : '';
        });
        </script>
    </main>
</body>
</html>
