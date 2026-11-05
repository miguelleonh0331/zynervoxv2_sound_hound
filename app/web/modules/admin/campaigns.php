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
$errorLink = '';
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
            $fromList = ($_POST['from'] ?? '') === 'list';
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
            } elseif ($action === 'delete' && $postId !== null) {
                bot_cfg_delete($db, $postId, $actor);
                $_SESSION['campaigns_message'] = 'Campaña #' . $postId . ' eliminada.';
                header('Location: campaigns.php');
                exit;
            } else {
                throw new RuntimeException('Acción inválida.');
            }
            header('Location: campaigns.php' . ($fromList ? '' : '?id=' . $savedId));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if (($action ?? '') === 'delete' && strpos($error, 'Elimina primero las listas') === 0) {
                $n = 0;
                foreach (bot_cfg_list($db) as $row) if ((int)$row['campaign_id'] === (int)$postId) $n = (int)$row['lists_count'];
                $error = 'Tiene ' . $n . ' ' . ($n === 1 ? 'lista' : 'listas') . ($n === 1 ? ': elimínala primero en Listas.' : ': elimínalas primero en Listas.');
                $errorLink = 'lists.php?campaign_id=' . (int)$postId;
            }
            $id = $postId ?? null;
            $isNew = $id === null;
            if (($_POST['from'] ?? '') === 'list') { $id = null; $isNew = false; } // vuelve al listado con el error
            else $form = [ // se vuelve a pintar lo que el usuario escribió
                'campaign' => array_merge(['campaign_id'=>$id, 'dialer_state'=>'stopped'], $_POST),
                'headers' => array_map(fn($r) => ['header_name'=>$r['name'] ?? '', 'lead_field'=>$r['field'] ?? ''], (array)($_POST['headers'] ?? [])),
                'rules' => (array)($_POST['rules'] ?? []),
                'amd_actions' => array_values((array)($_POST['amd_actions'] ?? [])),
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
    $form = ['campaign'=>['campaign_id'=>null, 'name'=>'', 'active'=>0, 'call_origin'=>'', 'max_channels'=>10,
        'transfer_carrier_id'=>'', 'transfer_destination'=>'', 'timezone'=>'America/Lima', 'lead_order'=>'DOWN', 'dial_timeout'=>60, 'campaign_cid'=>'', 'dialer_state'=>'stopped'],
        'headers'=>[], 'rules'=>bot_cfg_default_rules(), 'amd_actions'=>[], 'schedules'=>[], 'lead_fields'=>['phone', 'customer_name'], 'active_calls'=>0];
}
$c = $form['campaign'] ?? [];
$running = ($c['dialer_state'] ?? '') === 'running';
$sipCodes = bot_cfg_sip_codes();
$amdResults = bot_cfg_amd_results();
$amdDefaults = bot_cfg_amd_defaults();
$amdSaved = [];
foreach (($form['amd_actions'] ?? []) as $row) if (isset($row['result'])) $amdSaved[$row['result']] = $row;
$days = [1=>'Lunes', 2=>'Martes', 3=>'Miércoles', 4=>'Jueves', 5=>'Viernes', 6=>'Sábado', 7=>'Domingo'];

function campaigns_headers_row(array $r): string {
    return '<tr><td><input name="headers[][name]" value="' . h($r['header_name'] ?? '') . '" placeholder="X-Cliente" maxlength="64" required></td>'
        . '<td><input name="headers[][field]" value="' . h($r['lead_field'] ?? '') . '" list="lead_fields" placeholder="campo del lead" maxlength="60" required></td>'
        . '<td><button type="button" class="btn-action" data-del>Quitar</button></td></tr>';
}
function campaigns_rule_row(array $r): string {
    global $sipCodes;
    $select = '<select name="rules[][sip_code]">';
    $groups = [];
    foreach ($sipCodes as $c) $groups[$c['group_label']][] = $c;
    foreach ($groups as $label => $codes) {
        $select .= '<optgroup label="' . h($label) . '">';
        foreach ($codes as $c) $select .= '<option value="' . (int)$c['sip_code'] . '"' . (isset($r['sip_code']) && (string)$r['sip_code'] === (string)$c['sip_code'] ? ' selected' : '') . '>' . ((int)$c['sip_code'] > 0 ? (int)$c['sip_code'] . ' · ' : '') . h($c['label']) . '</option>';
        $select .= '</optgroup>';
    }
    $select .= '</select>';
    return '<tr><td>' . $select . '</td>'
        . '<td><input type="number" name="rules[][max_attempts]" value="' . h($r['max_attempts'] ?? 3) . '" min="1" max="99" required></td>'
        . '<td><input type="number" name="rules[][retry_delay_min]" value="' . h($r['retry_delay_min'] ?? 30) . '" min="0" max="10080" required></td>'
        . '<td><select name="rules[][active]"><option value="1"' . ((string)($r['active'] ?? '1') === '0' ? '' : ' selected') . '>Sí</option><option value="0"' . ((string)($r['active'] ?? '1') === '0' ? ' selected' : '') . '>No</option></select></td>'
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
        <?php if ($error !== ''): ?><p class="msg-err"><?php echo h($error); ?><?php if ($errorLink !== ''): ?> <a href="<?php echo h($errorLink); ?>">Ir a Listas</a><?php endif; ?></p><?php endif; ?>

<?php if ($form === null): ?>
        <div class="card">
            <h2>Listado de campañas</h2>
            <table class="table-compact">
                <thead><tr><th>ID</th><th>Nombre</th><th>Activa</th><th>Marcador</th><th>Listas</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $row): ?>
                    <tr>
                        <td><strong><?php echo (int)$row['campaign_id']; ?></strong></td>
                        <td><?php echo h($row['name']); ?></td>
                        <td><?php echo (int)$row['active'] ? 'Sí' : 'No'; ?></td>
                        <td style="color: <?php echo ($row['dialer_state'] ?? '') === 'running' ? '#10b981' : '#9ca3af'; ?>;"><?php echo ($row['dialer_state'] ?? '') === 'running' ? 'En marcha' : 'Detenido'; ?></td>
                        <td><?php echo (int)$row['lists_count']; ?></td>
                        <td style="white-space: nowrap;">
                            <a class="btn-action" href="campaigns.php?id=<?php echo (int)$row['campaign_id']; ?>">Editar</a>
                            <form method="POST" action="campaigns.php" class="inline-form" style="display: inline;">
                                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['campaigns_csrf']); ?>">
                                <input type="hidden" name="campaign_id" value="<?php echo (int)$row['campaign_id']; ?>">
                                <input type="hidden" name="from" value="list">
                                <?php if (($row['dialer_state'] ?? '') === 'running'): ?>
                                    <button type="submit" name="action" value="stop" class="btn-action">Detener</button>
                                <?php else: ?>
                                    <button type="submit" name="action" value="start" class="btn-action">Iniciar</button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" action="campaigns.php" class="inline-form" style="display: inline;" onsubmit="return confirm(this.getAttribute('data-confirm'));" data-confirm="<?php echo h('Se eliminará la campaña «' . $row['name'] . '» (#' . (int)$row['campaign_id'] . ') y su configuración. El historial de llamadas se conserva. No se puede deshacer.'); ?>">
                                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['campaigns_csrf']); ?>">
                                <input type="hidden" name="campaign_id" value="<?php echo (int)$row['campaign_id']; ?>">
                                <input type="hidden" name="from" value="list">
                                <button type="submit" name="action" value="delete" class="btn-action">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$campaigns): ?><tr><td colspan="7" style="color: var(--text-muted);">Sin campañas.</td></tr><?php endif; ?>
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
                    <label>Tipo de marcación
                        <select name="call_origin"><option value="">— elegir —</option>
                        <?php $dialTypes = ['7308' => 'Solo AMD', '7314' => 'Zypad en sombra + AMD']; foreach ($dialTypes as $prefix => $label): ?>
                            <option value="<?php echo $prefix; ?>"<?php echo (string)($c['call_origin'] ?? '') === (string)$prefix ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                        <?php if (($c['call_origin'] ?? '') !== '' && !isset($dialTypes[(string)$c['call_origin']])): ?><option value="<?php echo h($c['call_origin']); ?>" selected><?php echo h($c['call_origin']); ?> (valor existente)</option><?php endif; ?>
                        </select></label>
                    
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
                <h2>Acción según el resultado del AMD</h2>
                <p style="color: var(--text-muted); font-size: 0.8rem;">Se aplica al contestar la llamada. «Reintentar» vuelve a llamar según los intentos y la espera indicados.</p>
                <table class="table-compact cfg-table"><thead><tr><th>Resultado</th><th>Acción</th><th>Intentos máx.</th><th>Espera (min)</th></tr></thead><tbody>
                <?php foreach ($amdResults as $i => $ar): $cur = $amdSaved[$ar['result']] ?? $amdDefaults[$ar['result']] ?? ['action'=>'hangup', 'max_attempts'=>0, 'retry_delay_min'=>0]; ?>
                    <tr>
                        <td><input type="hidden" name="amd_actions[<?php echo $i; ?>][result]" value="<?php echo h($ar['result']); ?>"><?php echo h($ar['label']); ?> (<?php echo h($ar['result']); ?>)</td>
                        <td><select name="amd_actions[<?php echo $i; ?>][action]"><?php foreach (['transfer'=>'Transferir', 'hangup'=>'Colgar y registrar', 'retry'=>'Reintentar'] as $k => $label): ?><option value="<?php echo $k; ?>"<?php echo ($cur['action'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></td>
                        <td><input type="number" name="amd_actions[<?php echo $i; ?>][max_attempts]" value="<?php echo (int)($cur['max_attempts'] ?? 0); ?>" min="0" max="99"></td>
                        <td><input type="number" name="amd_actions[<?php echo $i; ?>][retry_delay_min]" value="<?php echo (int)($cur['retry_delay_min'] ?? 0); ?>" min="0" max="10080"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
                <p style="color: var(--text-muted); font-size: 0.75rem;">Intentos y espera solo cuentan con «Reintentar» (mínimo 1 intento).</p>
            </div>

            <div class="card">
                <h2>Reintentos por código SIP final</h2>
                <p style="color: var(--text-muted); font-size: 0.8rem;">Un código sin regla no se reintenta. «Sin respuesta del proveedor» cubre las llamadas sin código. El 200 (contestada) lo decide el AMD.</p>
                <table class="table-compact cfg-table" data-rows="retry"><thead><tr><th>Código SIP</th><th>Intentos máx.</th><th>Espera (min)</th><th>Activa</th><th></th></tr></thead>
                    <tbody><?php foreach (($form['rules'] ?? []) as $r) echo campaigns_rule_row($r); ?></tbody></table>
                <button type="button" class="btn-action" data-add="retry">+ Código</button>
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
                    <a class="btn-action" href="lists.php?campaign_id=<?php echo (int)$c['campaign_id']; ?>">Listas</a>
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
            var prefixes = {headers: 'headers', retry: 'rules', schedule: 'schedules'};
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
