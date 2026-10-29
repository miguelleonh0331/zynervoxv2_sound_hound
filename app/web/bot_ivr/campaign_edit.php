<?php
declare(strict_types=1);
require __DIR__ . '/campaigns_page.php';
$campaignId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$campaign = null;
$lists = [];
try {
    if ($campaignId === false || $campaignId === null) throw new RuntimeException('ID de campaña inválido.');
    $db = bot_ivr_repository();
    $campaign = bot_campaign_get($db, $campaignId);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== 'save_db_config') {
        try {
            bot_campaign_csrf();
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'update_campaign') {
                bot_campaign_update($db, $campaignId, $_POST);
            } elseif ($action === 'create_list') {
                bot_campaign_list_create($db, $campaignId, (string)($_POST['list_name'] ?? ''), isset($_POST['active']), array_key_exists('id_flujo', $_POST) ? $_POST['id_flujo'] : null);
            } elseif ($action === 'update_list') {
                bot_campaign_list_update($db, $campaignId, (int)($_POST['list_id'] ?? 0), (string)($_POST['list_name'] ?? ''), isset($_POST['active']), array_key_exists('id_flujo', $_POST) ? $_POST['id_flujo'] : null);
            } else {
                throw new RuntimeException('Acción inválida.');
            }
            bot_campaign_redirect('campaign_edit.php?id='.$campaignId, $action==='create_list' ? 'Lista creada y asignada a la campaña.' : 'Cambios guardados.');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $campaign = bot_campaign_get($db, $campaignId);
    $lists = $db->lists($campaignId);
} catch (Throwable $e) {
    http_response_code($campaignId ? 404 : 400);
    $error = $e->getMessage();
}
const BOT_CAMPAIGN_STATUS_SUGGESTIONS = ['NEW','ANSWER','RESPONDE_F1','RESPONDE_F2','AGENDADO','AGENDADO_VALIDAR','NO_INTERESADO_F1','NO_INTERESADO_F2','SIN_AUDIO'];
function bot_campaign_field_row(string $key, array $campaign): void {
    $field = BOT_CAMPAIGN_FIELDS[$key];
    $value = (string)$campaign[$key];
    echo '<tr><th scope="row"><label for="field_' . h($key) . '">' . h($field['label']) . ':</label></th><td>';
    if ($key === 'dial_statuses') {
        echo '<div class="status-tags" data-input="field_dial_statuses"><div class="status-tags-list"></div>';
        echo '<div class="status-tags-add"><input type="text" class="status-tags-new" list="dial_statuses_suggestions" placeholder="Estado (ej. AGENDADO)" maxlength="80"><button type="button" class="carsa-btn secondary compact-submit status-tags-btn">+</button></div>';
        echo '<datalist id="dial_statuses_suggestions">';
        foreach (BOT_CAMPAIGN_STATUS_SUGGESTIONS as $suggestion) echo '<option value="' . h($suggestion) . '">';
        echo '</datalist>';
        echo '<input type="hidden" id="field_' . h($key) . '" name="' . h($key) . '" value="' . h($value) . '" required></div>';
    } elseif ($field['type'] === 'select') {
        echo '<select id="field_' . h($key) . '" name="' . h($key) . '">';
        foreach ($field['options'] as $option) {
            echo '<option value="' . h($option) . '"' . ($value === $option ? ' selected' : '') . '>' . h($option) . '</option>';
        }
        echo '</select>';
    } else {
        $numeric = in_array($field['type'], ['number','decimal'], true);
        echo '<input id="field_' . h($key) . '" name="' . h($key) . '" type="' . ($numeric ? 'number' : 'text') . '" value="' . h($value) . '"';
        if ($numeric) echo ' min="' . $field['min'] . '" max="' . $field['max'] . '" step="' . ($field['type'] === 'decimal' ? '0.01' : '1') . '" required';
        else echo ' maxlength="' . $field['length'] . '"' . (!empty($field['required']) ? ' required' : '');
        echo '>';
    }
    echo '</td></tr>';
}
bot_campaign_header($campaign ? 'Campaña #'.$campaignId.' — '.$campaign['name'] : 'Campaña');
if ($campaign): ?>
<style>
.main-content{min-width:0}.campaign-detail{width:100%;max-width:none}
.campaign-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start}
.campaign-column{min-width:0}.campaign-group{border:1px solid var(--border);margin-bottom:12px}
.campaign-group:last-child{margin-bottom:0}
.campaign-column h3{font-size:11px;text-align:left;margin:0;padding:7px 10px;color:var(--bg-card);background:var(--dark);text-transform:uppercase;letter-spacing:.3px}
.campaign-save{display:flex;justify-content:flex-end;border-top:1px solid var(--border);padding:10px 0 0;margin-top:14px}
.campaign-detail .carsa-card{padding:12px;margin-bottom:16px;background:var(--bg-card)}
.campaign-detail h2{text-align:left;margin:0 0 12px;font-size:13px;color:var(--text)}
.campaign-fields{table-layout:fixed;margin:0;width:100%;border-collapse:collapse;font-size:12px}
.campaign-fields tr:nth-child(odd){background:var(--bg-card)}
.campaign-fields tr:nth-child(even){background:var(--glass)}
.campaign-fields th{width:42%;padding:6px 10px;text-align:left;font-weight:normal;color:var(--text-muted);background:transparent;border:0;border-bottom:1px solid var(--border);text-transform:none;white-space:normal;letter-spacing:0}
.campaign-fields td{padding:5px 10px;text-align:left;border:0;border-bottom:1px solid var(--border);color:var(--text);overflow-wrap:anywhere}
.campaign-fields tr:last-child th,.campaign-fields tr:last-child td{border-bottom:0}
.campaign-fields label{text-transform:none;font-size:12px;letter-spacing:0;font-weight:normal;color:inherit;white-space:normal}
.campaign-fields input,.campaign-fields select{display:inline-block;width:auto;max-width:100%;margin:0;padding:4px 7px;min-height:28px;background:var(--bg-card);color:var(--text);border:1px solid var(--border);border-radius:2px;font-size:12px;box-sizing:border-box}
.campaign-fields input:focus,.campaign-fields select:focus{outline:2px solid var(--primary);outline-offset:1px}
.campaign-fields input[type=text]{width:100%}.campaign-fields input[type=time]{width:140px}
.campaign-fields select{min-width:70px}.campaign-fields input[type=number]{width:100px}
.campaign-detail .compact-submit{display:inline-block;width:auto;padding:6px 16px;font-size:12px}
.campaign-detail .list-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.campaign-detail .list-head h2{text-align:left;margin:0}.campaign-detail .list-summary{font-size:12px;color:var(--text-muted);margin-left:12px;font-weight:normal}
.campaign-detail .carsa-table{margin-top:0}.campaign-detail .carsa-table th,.campaign-detail .carsa-table td{padding:6px 8px}
.campaign-detail details summary{cursor:pointer;color:var(--primary-hover)}
.campaign-detail details .carsa-form{min-width:190px;max-width:260px;padding-top:8px}
.campaign-detail details input[type=checkbox]{width:auto;display:inline-block;margin:0 5px 0 0}
.status-tags-list{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px}
.status-tags-list:empty{margin-bottom:0}
.status-tag{display:inline-flex;align-items:center;gap:5px;padding:3px 6px 3px 9px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;font-size:11px;color:var(--text)}
.status-tag button{border:0;background:transparent;color:var(--text-muted);cursor:pointer;font-size:13px;line-height:1;padding:0 2px}
.status-tag button:hover{color:var(--primary-hover)}
.status-tags-add{display:flex;gap:6px}
.status-tags-add input{flex:1;min-width:0}
.status-tags-btn{padding:4px 12px}
@media(max-width:1050px){.campaign-columns{grid-template-columns:minmax(0,1fr)}}
@media(max-width:600px){.campaign-fields th{width:42%;padding:5px}.campaign-fields td{padding:5px}.campaign-detail .list-head{align-items:flex-start;flex-wrap:wrap}.campaign-detail .list-summary{display:block;margin:4px 0 0}}
</style>
<div class="campaign-detail">
<section class="carsa-card">
<h2>Configuración de campaña</h2>
<form method="post">
<input type="hidden" name="action" value="update_campaign"><?php bot_campaign_token(); ?>
<div class="campaign-columns">
<div class="campaign-column"><div class="campaign-group"><h3>Datos generales</h3>
<table class="campaign-fields"><tbody>
<tr><th scope="row">ID de campaña:</th><td><strong><?php echo (int)$campaignId; ?></strong></td></tr>
<tr><th scope="row"><label for="campaign_name">Nombre:</label></th><td><input type="text" id="campaign_name" name="name" maxlength="120" value="<?php echo h($campaign['name']); ?>" required></td></tr>
<?php bot_campaign_field_row('campaign_description', $campaign); ?>
<tr><th scope="row"><label for="campaign_active">Activo:</label></th><td><select id="campaign_active" name="active"><option value="1" <?php echo $campaign['active'] ? 'selected' : ''; ?>>Sí</option><option value="0" <?php echo !$campaign['active'] ? 'selected' : ''; ?>>No</option></select></td></tr>
<?php bot_campaign_field_row('user_group', $campaign); ?>
<tr><th scope="row">Fecha de creación:</th><td><?php echo h($campaign['created_at']); ?></td></tr>
<tr><th scope="row">Última modificación:</th><td><?php echo h($campaign['updated_at']); ?></td></tr>
</tbody></table></div>
<div class="campaign-group"><h3>Horarios de campaña</h3><table class="campaign-fields"><tbody>
<tr><th scope="row"><label for="campaign_scheduled">Habilitar horario diario:</label></th><td><select id="campaign_scheduled" name="scheduled"><option value="1" <?php echo $campaign['scheduled'] ? 'selected' : ''; ?>>Sí</option><option value="0" <?php echo !$campaign['scheduled'] ? 'selected' : ''; ?>>No</option></select></td></tr>
<tr><th scope="row"><label for="start_time">Hora de activación:</label></th><td><input id="start_time" type="time" name="start_time" value="<?php echo h(substr($campaign['start_time'],0,5)); ?>" required></td></tr>
<tr><th scope="row"><label for="end_time">Hora de bloqueo:</label></th><td><input id="end_time" type="time" name="end_time" value="<?php echo h(substr($campaign['end_time'],0,5)); ?>" required></td></tr>
</tbody></table></div></div>
<div class="campaign-column"><div class="campaign-group"><h3>Marcación</h3>
<table class="campaign-fields"><tbody>
<?php foreach (['dial_method','auto_dial_level','max_channels','dial_timeout','dial_prefix','campaign_cid'] as $key) bot_campaign_field_row($key, $campaign); ?>
</tbody></table></div>
<div class="campaign-group"><h3>Leads y grabación</h3><table class="campaign-fields"><tbody>
<?php foreach (['lead_order','dial_statuses','hopper_level','campaign_recording'] as $key) bot_campaign_field_row($key, $campaign); ?>
</tbody></table></div></div>
</div>
<div class="campaign-save"><button class="carsa-btn compact-submit">Guardar campaña</button></div>
</form>
</section>
<section class="carsa-card">
<div class="list-head"><h2>Listas de la campaña<span class="list-summary">Listas: <?php echo count($lists); ?> · Leads: <?php echo (int)array_sum(array_column($lists,'leads_count')); ?></span></h2><button type="button" class="carsa-btn compact-submit" onclick="document.getElementById('createListModal').showModal()">Crear lista</button></div>
<div class="bot-table-wrap"><table class="carsa-table"><thead><tr><th>List ID</th><th>Campaign ID</th><th>Nombre</th><th>ID flujo</th><th>Leads</th><th>Activo</th><th>Fecha de creación</th><th>Abrir</th><th>Modificar</th></tr></thead><tbody>
<?php foreach ($lists as $list): ?>
<tr><td><?php echo (int)$list['list_id']; ?></td><td><?php echo (int)$list['campaign_id']; ?></td><td><?php echo h($list['name']); ?></td><td><?php echo $list['id_flujo'] === null ? 'Sin asignar' : h($list['id_flujo']); ?></td><td><?php echo (int)$list['leads_count']; ?></td><td><?php echo $list['active'] ? 'Sí' : 'No'; ?></td><td><?php echo h($list['created_at']); ?></td><td><a class="carsa-btn secondary compact-submit" href="list_edit.php?id=<?php echo (int)$list['list_id']; ?>&amp;campaign_id=<?php echo (int)$campaignId; ?>">Abrir lista</a></td><td>
<details><summary>Modificar</summary><form method="post" class="carsa-form">
<input type="hidden" name="action" value="update_list"><input type="hidden" name="list_id" value="<?php echo (int)$list['list_id']; ?>"><?php bot_campaign_token(); ?>
<input aria-label="Nombre de lista <?php echo (int)$list['list_id']; ?>" name="list_name" maxlength="120" value="<?php echo h($list['name']); ?>" required>
<label>Flujo (IVR Builder)<select name="id_flujo" class="ivr-flow-select" data-selected="<?php echo h($list['id_flujo'] ?? ''); ?>" required><option value="">Cargando flujos...</option></select></label>
<label><input type="checkbox" name="active" value="1" <?php echo $list['active'] ? 'checked' : ''; ?>> Activo</label>
<button class="carsa-btn secondary compact-submit">Guardar lista</button></form></details></td></tr>
<?php endforeach; ?>
<?php if (!$lists): ?><tr><td colspan="9">Esta campaña todavía no tiene listas.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
</div>
<dialog id="createListModal" aria-labelledby="createListTitle">
<h2 id="createListTitle">Crear y asignar lista</h2>
<form method="post" class="carsa-form"><input type="hidden" name="action" value="create_list"><?php bot_campaign_token(); ?>
<div class="carsa-field"><label for="list_name">Nombre de lista</label><input id="list_name" name="list_name" maxlength="120" required></div>
<div class="carsa-field"><label for="list_flow">Flujo (IVR Builder)</label><select id="list_flow" name="id_flujo" class="ivr-flow-select" required><option value="">Cargando flujos...</option></select></div>
<label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="active" value="1" checked style="width:auto;margin:0"> Activo</label>
<div class="carsa-actions"><button class="carsa-btn">Crear lista</button><button type="button" class="carsa-btn secondary" onclick="document.getElementById('createListModal').close()">Cancelar</button></div>
</form>
</dialog>

<script>
(function () {
    document.querySelectorAll('.status-tags').forEach(box => {
        const hidden = document.getElementById(box.dataset.input);
        const list = box.querySelector('.status-tags-list');
        const input = box.querySelector('.status-tags-new');
        const addBtn = box.querySelector('.status-tags-btn');
        let tags = hidden.value.split(/\s+/).filter(Boolean);
        function render() {
            hidden.value = tags.join(' ');
            list.replaceChildren(...tags.map(tag => {
                const chip = document.createElement('span');
                chip.className = 'status-tag';
                chip.textContent = tag;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Quitar ' + tag);
                remove.addEventListener('click', () => { tags = tags.filter(t => t !== tag); render(); });
                chip.appendChild(remove);
                return chip;
            }));
        }
        function addFromInput() {
            const value = input.value.trim().toUpperCase().replace(/[^A-Z0-9_]/g, '');
            if (value && !tags.includes(value)) { tags.push(value); render(); }
            input.value = '';
            input.focus();
        }
        addBtn.addEventListener('click', addFromInput);
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ',' || event.key === ' ') { event.preventDefault(); addFromInput(); }
        });
        box.closest('form').addEventListener('submit', () => { hidden.value = tags.join(' '); });
        render();
    });
})();
(async function () {
    const selects = document.querySelectorAll('.ivr-flow-select');
    selects.forEach(select => { select.disabled = true; });
    try {
        const response = await fetch('../ivr_builder/api_flows.php', {credentials: 'same-origin', cache: 'no-store'});
        const data = await response.json();
        if (!response.ok || !data.ok || !Array.isArray(data.flows)) throw new Error('Catalog unavailable');
        const flows = data.flows.filter(flow => /^\d{2}$/.test(flow.code) && Number(flow.code) > 0);
        selects.forEach(select => {
            select.replaceChildren(new Option(flows.length ? 'Selecciona un flujo...' : 'No hay flujos creados', ''));
            flows.forEach(flow => {
                // IDs are integers in list storage; keep the two-digit code in the label.
                const value = String(Number(flow.code));
                select.add(new Option(flow.code + ' — ' + flow.name, value, false, value === select.dataset.selected));
            });
            select.disabled = false;
        });
    } catch (error) {
        selects.forEach(select => { select.replaceChildren(new Option('No se pudieron cargar los flujos. Recarga la página.', '')); select.disabled = false; });
    }
})();
</script>
<?php endif;
bot_campaign_footer();
