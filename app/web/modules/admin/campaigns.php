<?php
declare(strict_types=1);
// Interfaz del marcador v1. Toda lectura/escritura de campañas pasa por
// bot_ivr/campaign_config_service.php; esta página no ejecuta SQL propio.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Carriers.php';
require_once __DIR__ . '/../../bot_ivr/campaign_config_service.php';
use Includes\Auth;
use Includes\Carriers;

Auth::checkAccess(9);
if (empty($_SESSION['campaigns_csrf'])) $_SESSION['campaigns_csrf'] = bin2hex(random_bytes(32));

if (!function_exists('h')) { function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); } }

$error = '';
$message = (string)($_SESSION['campaigns_message'] ?? '');
unset($_SESSION['campaigns_message']);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: null;
$isNew = ($_GET['view'] ?? '') === 'new';
$form = null;
$db = null;
$carriers = [];
$origins = [];
$campaigns = [];

try {
    $db = bot_ivr_repository();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            if (!hash_equals($_SESSION['campaigns_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sesión inválida. Recarga la página.');
            $action = (string)($_POST['action'] ?? '');
            $postId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: null;
            $actor = (string)($_SESSION['user'] ?? 'admin');
            if ($action === 'save') {
                $savedId = bot_cfg_save($db, $postId, $_POST);
                $_SESSION['campaigns_message'] = $postId === null ? 'Campaña creada.' : 'Cambios guardados.';
            } elseif ($action === 'start' && $postId !== null) {
                bot_cfg_start($db, $postId, $actor);
                $savedId = $postId;
                $_SESSION['campaigns_message'] = 'Marcador iniciado.';
            } elseif ($action === 'stop' && $postId !== null) {
                bot_cfg_stop($db, $postId, $actor);
                $savedId = $postId;
                $_SESSION['campaigns_message'] = 'Marcador detenido; las llamadas en curso terminan solas.';
            } else {
                throw new RuntimeException('Acción inválida.');
            }
            header('Location: campaigns.php?id=' . $savedId);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $id = $postId ?? null;
            $isNew = $id === null;
            $form = [ // se vuelve a pintar lo que el usuario escribió
                'campaign' => array_merge(['campaign_id'=>$id, 'dialer_state'=>'stopped'], $_POST),
                'headers' => array_map(fn($r) => ['header_name'=>$r['name'] ?? '', 'lead_field'=>$r['field'] ?? ''], (array)($_POST['headers'] ?? [])),
                'retry_rules' => (array)($_POST['retry_rules'] ?? []),
                'schedules' => (array)($_POST['schedules'] ?? []),
                'lead_fields' => ['phone', 'customer_name'], 'active_calls' => 0,
            ];
        }
    }
    if ($id !== null || $isNew) {
        try { $carriers = Carriers::getAll(); $origins = Carriers::dialOrigins(); }
        catch (Throwable $e) { $error = $error ?: 'No se pudieron leer troncales/orígenes: ' . $e->getMessage(); }
    }
    if ($form === null && $id !== null) $form = bot_cfg_get($db, $id);
    if ($id === null && !$isNew) $campaigns = bot_cfg_list($db);
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
    if ($id !== null && $form === null) { $id = null; $isNew = false; }
}

if ($isNew && $form === null) {
    $form = ['campaign'=>['campaign_id'=>null, 'name'=>'', 'active'=>0, 'call_origin'=>'', 'max_channels'=>10, 'amd_enabled'=>0,
        'transfer_carrier_id'=>'', 'transfer_destination'=>'', 'timezone'=>'America/Lima', 'lead_order'=>'DOWN', 'dial_timeout'=>60, 'campaign_cid'=>'', 'dialer_state'=>'stopped'],
        'headers'=>[], 'retry_rules'=>[], 'schedules'=>[], 'lead_fields'=>['phone', 'customer_name'], 'active_calls'=>0];
}
$c = $form['campaign'] ?? [];
$running = ($c['dialer_state'] ?? '') === 'running';
$days = [1=>'Lunes', 2=>'Martes', 3=>'Miércoles', 4=>'Jueves', 5=>'Viernes', 6=>'Sábado', 7=>'Domingo'];

function campaigns_headers_row(array $r): string {
    return '<tr><td><input name="headers[][name]" value="' . h($r['header_name'] ?? '') . '" placeholder="X-Cliente" maxlength="64" required></td>'
        . '<td><input name="headers[][field]" value="' . h($r['lead_field'] ?? '') . '" list="lead_fields" placeholder="campo del lead" maxlength="60" required></td>'
        . '<td><button type="button" class="btn-action" data-del>Quitar</button></td></tr>';
}
function campaigns_rule_row(array $r): string {
    return '<tr><td><input name="retry_rules[][status]" value="' . h($r['status'] ?? '') . '" placeholder="BUSY" maxlength="80" required></td>'
        . '<td><input type="number" name="retry_rules[][max_attempts]" value="' . h($r['max_attempts'] ?? 3) . '" min="1" max="99" required></td>'
        . '<td><input type="number" name="retry_rules[][retry_delay_min]" value="' . h($r['retry_delay_min'] ?? 30) . '" min="0" max="10080" required></td>'
        . '<td><select name="retry_rules[][active]"><option value="1"' . ((string)($r['active'] ?? '1') === '0' ? '' : ' selected') . '>Sí</option><option value="0"' . ((string)($r['active'] ?? '1') === '0' ? ' selected' : '') . '>No</option></select></td>'
        . '<td><button type="button" class="btn-action" data-del>Quitar</button></td></tr>';
}
function campaigns_schedule_row(array $r, array $days): string {
    $select = '<select name="schedules[][weekday]">';
    foreach ($days as $n => $label) $select .= '<option value="' . $n . '"' . ((int)($r['weekday'] ?? 1) === $n ? ' selected' : '') . '>' . h($label) . '</option>';
    $select .= '</select>';
    return '<tr><td>' . $select . '</td>'
        . '<td><input type="time" name="schedules[][start_time]" value="' . h(substr((string)($r['start_time'] ?? '09:00'), 0, 5)) . '" required></td>'
        . '<td><input type="time" name="schedules[][end_time]" value="' . h(substr((string)($r['end_time'] ?? '18:00'), 0, 5)) . '" required></td>'
        . '<td><button type="button" class="btn-action" data-del>Quitar</button></td></tr>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Campañas - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .cfg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem; }
        .cfg-grid label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .cfg-grid input, .cfg-grid select, .cfg-table input, .cfg-table select { width: 100%; box-sizing: border-box; }
        .cfg-table { width: 100%; }
        .msg-ok { color: #10b981; margin-bottom: 1rem; }
        .msg-err { color: #fca5a5; margin-bottom: 1rem; }
        .card + .card { margin-top: 1rem; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('campaigns'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Campañas</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Marcador v1: configuración de campaña</p>
            </div>
            <div><a class="btn-primary" href="campaigns.php?view=new">Nueva campaña</a></div>
        </header>

        <?php if ($message !== ''): ?><p class="msg-ok"><?php echo h($message); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="msg-err"><?php echo h($error); ?></p><?php endif; ?>

<?php if ($form === null): ?>
        <div class="card">
            <h2>Listado de campañas</h2>
            <table class="table-compact">
                <thead><tr><th>ID</th><th>Nombre</th><th>Activa</th><th>Marcador</th><th>Canales</th><th>AMD</th><th>Listas</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $row): ?>
                    <tr>
                        <td><strong><?php echo (int)$row['campaign_id']; ?></strong></td>
                        <td><?php echo h($row['name']); ?></td>
                        <td><?php echo (int)$row['active'] ? 'Sí' : 'No'; ?></td>
                        <td style="color: <?php echo ($row['dialer_state'] ?? '') === 'running' ? '#10b981' : '#9ca3af'; ?>;"><?php echo ($row['dialer_state'] ?? '') === 'running' ? 'En marcha' : 'Detenido'; ?></td>
                        <td><?php echo (int)$row['max_channels']; ?></td>
                        <td><?php echo (int)($row['amd_enabled'] ?? 0) ? 'Sí' : 'No'; ?></td>
                        <td><?php echo (int)$row['lists_count']; ?></td>
                        <td><a class="btn-action" href="campaigns.php?id=<?php echo (int)$row['campaign_id']; ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$campaigns): ?><tr><td colspan="8" style="color: var(--text-muted);">Sin campañas.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
<?php else: ?>
        <form method="POST" action="campaigns.php<?php echo $c['campaign_id'] ? '?id=' . (int)$c['campaign_id'] : '?view=new'; ?>" id="cfg">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['campaigns_csrf']); ?>">
            <input type="hidden" name="campaign_id" value="<?php echo h($c['campaign_id'] ?? ''); ?>">

            <div class="card">
                <h2><?php echo $c['campaign_id'] ? 'Campaña #' . (int)$c['campaign_id'] : 'Nueva campaña'; ?></h2>
                <div class="cfg-grid">
                    <label>Nombre <input name="name" value="<?php echo h($c['name'] ?? ''); ?>" maxlength="100" required></label>
                    <label>Activa
                        <select name="active"><option value="1"<?php echo (string)($c['active'] ?? '0') === '1' ? ' selected' : ''; ?>>Sí</option><option value="0"<?php echo (string)($c['active'] ?? '0') === '1' ? '' : ' selected'; ?>>No</option></select></label>
                    <label>Origen de llamada
                        <select name="call_origin"><option value="">— elegir —</option>
                        <?php $originKnown = false; foreach ($origins as $o): $sel = (string)($c['call_origin'] ?? '') === (string)$o['dial_prefix']; $originKnown = $originKnown || $sel; ?>
                            <option value="<?php echo h($o['dial_prefix']); ?>"<?php echo $sel ? ' selected' : ''; ?>><?php echo h($o['name'] . ' (' . $o['dial_prefix'] . ')'); ?></option>
                        <?php endforeach; ?>
                        <?php if (($c['call_origin'] ?? '') !== '' && !$originKnown): ?><option value="<?php echo h($c['call_origin']); ?>" selected><?php echo h($c['call_origin']); ?> (no disponible)</option><?php endif; ?>
                        </select></label>
                    <label>Canales (llamadas activas) <input type="number" name="max_channels" value="<?php echo h($c['max_channels'] ?? 10); ?>" min="1" max="500" required></label>
                    <label>AMD
                        <select name="amd_enabled"><option value="1"<?php echo (string)($c['amd_enabled'] ?? '0') === '1' ? ' selected' : ''; ?>>Sí</option><option value="0"<?php echo (string)($c['amd_enabled'] ?? '0') === '1' ? '' : ' selected'; ?>>No</option></select></label>
                    <label>Troncal de transferencia
                        <select name="transfer_carrier_id"><option value="">— elegir —</option>
                        <?php $carrierKnown = false; foreach ($carriers as $t): $sel = (string)($c['transfer_carrier_id'] ?? '') === (string)$t['carrier_id']; $carrierKnown = $carrierKnown || $sel; ?>
                            <option value="<?php echo h($t['carrier_id']); ?>"<?php echo $sel ? ' selected' : ''; ?>><?php echo h(($t['carrier_name'] ?? '') !== '' ? $t['carrier_name'] . ' (' . $t['carrier_id'] . ')' : $t['carrier_id']); ?></option>
                        <?php endforeach; ?>
                        <?php if (($c['transfer_carrier_id'] ?? '') !== '' && !$carrierKnown): ?><option value="<?php echo h($c['transfer_carrier_id']); ?>" selected><?php echo h($c['transfer_carrier_id']); ?> (no disponible)</option><?php endif; ?>
                        </select></label>
                    <label>Destino de transferencia <input name="transfer_destination" value="<?php echo h($c['transfer_destination'] ?? ''); ?>" maxlength="40" placeholder="dígitos, +, * y #"></label>
                    <label>Zona horaria <input name="timezone" value="<?php echo h($c['timezone'] ?? 'America/Lima'); ?>" maxlength="64" required></label>
                    <label>Orden de leads
                        <select name="lead_order"><?php foreach (['DOWN'=>'Descendente', 'UP'=>'Ascendente', 'RANDOM'=>'Aleatorio'] as $k => $label): ?><option value="<?php echo $k; ?>"<?php echo ($c['lead_order'] ?? 'DOWN') === $k ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
                    <label>Tiempo de llamada (s) <input type="number" name="dial_timeout" value="<?php echo h($c['dial_timeout'] ?? 60); ?>" min="1" max="255" required></label>
                    <label>CID de campaña <input name="campaign_cid" value="<?php echo h($c['campaign_cid'] ?? ''); ?>" maxlength="20"></label>
                </div>
            </div>

            <div class="card">
                <h2>Headers de la transferencia</h2>
                <p style="color: var(--text-muted); font-size: 0.8rem;">Cada fila envía un header SIP (X-* o User-to-User) con el valor del campo del lead.</p>
                <datalist id="lead_fields"><?php foreach (($form['lead_fields'] ?? []) as $f): ?><option value="<?php echo h($f); ?>"><?php endforeach; ?></datalist>
                <table class="table-compact cfg-table" data-rows="headers"><thead><tr><th>Header</th><th>Campo del lead</th><th></th></tr></thead>
                    <tbody><?php foreach (($form['headers'] ?? []) as $r) echo campaigns_headers_row($r); ?></tbody></table>
                <button type="button" class="btn-action" data-add="headers">+ Header</button>
            </div>

            <div class="card">
                <h2>Reintentos por estado</h2>
                <p style="color: var(--text-muted); font-size: 0.8rem;">NEW siempre se marca una vez. Un estado sin regla no se reintenta.</p>
                <table class="table-compact cfg-table" data-rows="retry"><thead><tr><th>Estado</th><th>Intentos máx.</th><th>Espera (min)</th><th>Activa</th><th></th></tr></thead>
                    <tbody><?php foreach (($form['retry_rules'] ?? []) as $r) echo campaigns_rule_row($r); ?></tbody></table>
                <button type="button" class="btn-action" data-add="retry">+ Estado</button>
            </div>

            <div class="card">
                <h2>Franjas horarias</h2>
                <p style="color: var(--text-muted); font-size: 0.8rem;">Sin franjas, la campaña marca a cualquier hora (zona horaria de la campaña).</p>
                <table class="table-compact cfg-table" data-rows="schedule"><thead><tr><th>Día</th><th>Inicio</th><th>Fin</th><th></th></tr></thead>
                    <tbody><?php foreach (($form['schedules'] ?? []) as $r) echo campaigns_schedule_row($r, $days); ?></tbody></table>
                <button type="button" class="btn-action" data-add="schedule">+ Franja</button>
            </div>

            <div class="card" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <button type="submit" name="action" value="save" class="btn-primary">Guardar</button>
                <a class="btn-action" href="campaigns.php">Volver</a>
                <?php if ($c['campaign_id']): ?>
                    <a class="btn-action" href="../../bot_ivr/campaign_edit.php?id=<?php echo (int)$c['campaign_id']; ?>">Listas</a>
                    <span style="margin-left: auto; color: <?php echo $running ? '#10b981' : '#9ca3af'; ?>;">
                        Marcador: <?php echo $running ? 'en marcha' : 'detenido'; ?> · llamadas activas: <?php echo (int)($form['active_calls'] ?? 0); ?>
                    </span>
                    <?php if ($running): ?>
                        <button type="submit" name="action" value="stop" class="btn-action" formnovalidate>Detener</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="start" class="btn-action" formnovalidate>Iniciar</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>

        <template id="tpl-headers"><?php echo campaigns_headers_row([]); ?></template>
        <template id="tpl-retry"><?php echo campaigns_rule_row([]); ?></template>
        <template id="tpl-schedule"><?php echo campaigns_schedule_row([], $days); ?></template>
        <script>
        (function () {
            var form = document.getElementById('cfg');
            var prefixes = {headers: 'headers', retry: 'retry_rules', schedule: 'schedules'};
            // Los nombres usan "x[][campo]"; se numeran por fila antes de enviar para que PHP agrupe bien.
            function renumber() {
                Object.keys(prefixes).forEach(function (key) {
                    var rows = form.querySelectorAll('table[data-rows="' + key + '"] tbody tr');
                    rows.forEach(function (tr, i) {
                        tr.querySelectorAll('[name]').forEach(function (el) {
                            el.name = el.name.replace(/^([a-z_]+)\[\d*\]/, '$1[' + i + ']');
                        });
                    });
                });
            }
            form.addEventListener('click', function (e) {
                if (e.target.matches('[data-del]')) { e.target.closest('tr').remove(); }
                if (e.target.matches('[data-add]')) {
                    var key = e.target.getAttribute('data-add');
                    var tpl = document.getElementById('tpl-' + key);
                    form.querySelector('table[data-rows="' + key + '"] tbody').appendChild(tpl.content.cloneNode(true));
                }
            });
            form.addEventListener('submit', renumber);
            renumber();
        })();
        </script>
<?php endif; ?>
    </main>
</body>
</html>
