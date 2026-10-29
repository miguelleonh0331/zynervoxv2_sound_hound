<?php
declare(strict_types=1);
require __DIR__ . '/campaigns_page.php';
require __DIR__ . '/list_service.php';
require_once __DIR__ . '/list_audio_jobs.php';
require_once __DIR__ . '/list_call_service.php';
require_once __DIR__ . '/list_audio_service.php';
require_once __DIR__ . '/../ivr_builder/published_flow.php';
require_once __DIR__ . '/../includes/Carriers.php';
$dialOrigins = [];
$originLoadError = '';
try { $dialOrigins = \Includes\Carriers::dialOrigins(); }
catch (Throwable $e) { $originLoadError = 'No se pudieron cargar los orígenes de llamada.'; }
$listId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$list = null;
$count = 0;
$audioCheck = null;
$audioCheckError = '';
$audioPayload = null;
$audioJob = null;
$audioActive = false;
$audioReady = false;
try {
    if (!$listId || !$campaignId) throw new RuntimeException('ID de lista o campaña inválido.');
    $db = bot_ivr_repository();
    $list = bot_list_get($db, $listId, $campaignId);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== 'save_db_config') {
        try {
            if (empty($_POST) && empty($_FILES)) throw new RuntimeException('La carga supera el límite de esta instalación. Usa un archivo más pequeño.');
            bot_campaign_csrf();
            $running=bot_list_call_status($listId,$campaignId);
            if($running && in_array($running['state'],['starting','running','stopping'],true))throw new RuntimeException('Deten la marcacion antes de modificar la lista.');
            if (($_POST['action'] ?? '') === 'save_list_prefix') {
                if (!is_string($_POST['dial_prefix'] ?? null)) throw new RuntimeException('Prefijo inválido.');
                if ($originLoadError !== '') throw new RuntimeException($originLoadError);
                if ($_POST['dial_prefix'] !== '' && !in_array($_POST['dial_prefix'], array_column($dialOrigins, 'dial_prefix'), true)) throw new RuntimeException('Selecciona un origen disponible.');
                $db->updateListDialPrefix($listId, $campaignId, trim($_POST['dial_prefix']));
                bot_campaign_redirect('list_edit.php?id='.$listId.'&campaign_id='.$campaignId, 'Prefijo de la lista guardado.');
            }
            if (($_POST['action'] ?? '') === 'generate_list_audio') {
                if (($_POST['audio_provider'] ?? '') !== 'gtts') throw new RuntimeException('Proveedor no disponible.');
                if (empty($list['id_flujo'])) throw new RuntimeException('Asigna un flujo a la lista.');
                $workers = filter_var($_POST['audio_workers'] ?? null, FILTER_VALIDATE_INT);
                if ($workers === false) throw new RuntimeException('Velocidad de generación inválida.');
                $flow = ivr_builder_published_flow((int)$list['id_flujo']);
                $payload = bot_list_audio_payload($flow, $db->audioLeads($listId, $campaignId), $listId, $campaignId, $workers);
                if (bot_list_audio_ready(bot_list_audio_job_status($listId), $payload)) throw new RuntimeException('Los audios de esta lista ya están listos.');
                bot_list_audio_start($payload);
                bot_campaign_redirect('list_edit.php?id='.$listId.'&campaign_id='.$campaignId, 'Generación iniciada. El progreso se actualizará automáticamente.');
            }
            if (($_POST['action'] ?? '') !== 'upload_list') throw new RuntimeException('Acción inválida o archivo mayor del límite de esta instalación.');
            if (bot_list_audio_active($listId)) throw new RuntimeException('Espera a que termine la generación antes de reemplazar la base.');
            $upload = $_FILES['clients_txt'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona un archivo TXT dentro del límite de subida de esta instalación.');
            if (strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)) !== 'txt') throw new RuntimeException('El archivo debe tener extensión .txt.');
            if (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) throw new RuntimeException('Archivo de carga inválido.');
            if ((int)$upload['size'] > BOT_LIST_UPLOAD_BYTES) throw new RuntimeException('El archivo supera 10 MB.');
            $contents = file_get_contents((string)$upload['tmp_name']);
            if ($contents === false) throw new RuntimeException('No se pudo leer el archivo.');
            $parsed = bot_list_parse_txt($contents);
            $result = bot_list_import($db, $listId, $campaignId, $parsed);
            $summary = 'Base reemplazada. Cargados: '.$result['saved'].'. Duplicados: '.$result['duplicates'].'. Rechazados: '.$result['rejected'].'.';
            if ($result['errors']) $summary .= ' '.implode(' · ', $result['errors']);
            bot_campaign_redirect('list_edit.php?id='.$listId.'&campaign_id='.$campaignId, $summary);
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $count = $db->leadCount($listId);
    $statistics = $db->listStatistics($listId,$campaignId);
    try {
        if (empty($list['id_flujo'])) throw new RuntimeException('Asigna un flujo a la lista para validar sus variables.');
        $flow = ivr_builder_published_flow((int)$list['id_flujo']);
        $audioLeads = $db->audioLeads($listId, $campaignId);
        $audioCheck = bot_list_audio_preflight($flow, $audioLeads, $listId, $campaignId);
        $audioPayload = bot_list_audio_payload($flow, $audioLeads, $listId, $campaignId, 25);
    } catch (Throwable $e) { $audioCheckError = $e->getMessage(); }
    try {
        $audioJob = bot_list_audio_job_status($listId);
        $audioActive = bot_list_audio_active($listId);
        $audioReady = bot_list_audio_ready($audioJob, $audioPayload);
    } catch (Throwable $e) { $audioCheckError = 'No se pudo leer el estado de generación.'; }
} catch (Throwable $e) {
    http_response_code($listId && $campaignId ? 404 : 400);
    $error = $e->getMessage();
}
bot_campaign_header($list ? 'Lista #'.$listId.' — '.$list['name'] : 'Lista');
if ($list): ?>
<style>
.main-content{min-width:0}.list-layout{display:grid;grid-template-columns:minmax(240px,1fr) minmax(0,2fr);gap:16px;margin-top:12px}
.list-card h2{font-size:13px;margin:0 0 12px}.list-meta{margin:0}.list-meta th{width:42%;background:var(--glass);color:var(--text-muted);text-transform:none;font-weight:normal;border:1px solid var(--border)}
.list-meta td{border:1px solid var(--border)}.list-guide{padding:12px;background:var(--glass);border:1px solid var(--border);font-size:12px;line-height:1.6}
.list-guide code{color:var(--text)}.list-form-actions{display:flex;gap:10px;flex-wrap:wrap}
.list-audio{grid-column:1 / -1}.list-audio-controls{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap}.list-audio-provider{flex:1;min-width:240px}.list-audio-speed{width:220px;max-width:100%}.list-audio-controls .carsa-btn{min-height:36px}.list-audio-controls .carsa-btn:disabled{opacity:.55;cursor:not-allowed}.list-audio-note{font-size:12px;color:var(--text-muted);margin:12px 0 0}
.list-audio [hidden]{display:none!important}
.list-back{color:var(--primary-hover);font-size:13px;text-decoration:none}
.audio-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px;padding-top:14px;border-top:1px solid var(--border)}.audio-summary .list-audio-note{margin:0}
.audio-dialog{width:100%;padding-top:20px;color:var(--text);box-sizing:border-box;font-family:Arial,Helvetica,sans-serif}
.audio-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.audio-dialog h2{font-size:20px;margin:0 0 6px}.audio-dialog p{font-size:13px;line-height:1.5}.audio-dialog-context{color:var(--text-muted);margin:0}
.audio-state{display:inline-block;margin:20px 0 14px;padding:6px 10px;border-radius:20px;background:var(--glass);font-size:12px;font-weight:bold}.audio-state[data-state="ready"]{background:#e6f4eb;color:#19643a}.audio-state[data-state="failed"]{background:#fdebec;color:#a12532}.audio-state[data-state="running"],.audio-state[data-state="starting"]{background:#fff0df;color:#8a4d06}
.audio-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.audio-stat{padding:18px 14px;border:1px solid var(--border);border-top:3px solid #f58220;border-radius:8px;background:var(--glass)}.audio-stat[data-kind="generated"]{border-top-color:#23834b}.audio-stat[data-kind="reused"]{border-top-color:#347cc5}.audio-stat[data-kind="failed"]{border-top-color:#c74451}.audio-stat span{display:block;font-size:12px;color:var(--text-muted)}.audio-stat strong{display:block;font-size:30px;margin:10px 0 6px;font-variant-numeric:tabular-nums}.audio-stat small{display:block;font-size:11px;color:var(--text-muted);line-height:1.5}
.audio-progress-label{display:flex;justify-content:space-between;gap:12px;margin:22px 0 8px;font-size:13px}.audio-progress{display:block;width:100%;height:14px;accent-color:#f58220}.audio-dialog-note{color:var(--text-muted);margin:10px 0}.audio-dialog-errors{white-space:pre-line;color:#a12532;font-size:13px}.audio-dialog-errors:empty{display:none}
@media(max-width:650px){.audio-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.audio-stat strong{font-size:26px}}
@media(max-width:1000px){.list-layout{grid-template-columns:minmax(0,1fr)}}
.call-stats{margin-top:16px}.call-stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.call-stats-grid div{padding:16px;border:1px solid var(--border);border-radius:8px;background:var(--glass);text-align:center}.call-stats-grid strong{display:block;font-size:28px}.call-stats-grid span{font-size:12px;color:var(--text-muted)}.call-state{margin-top:12px}.call-state-label{display:flex;justify-content:space-between;gap:12px;font-size:12px}.call-state progress{display:block;width:100%;height:10px;accent-color:#347cc5}.call-errors{overflow-x:auto;margin-top:18px}.call-errors h3{font-size:13px}.call-errors code{display:block;font-size:10px;overflow-wrap:anywhere}.call-stats-error{color:#c74451}
.list-accordion{align-self:start}.list-accordion summary{cursor:pointer;font-size:13px;font-weight:bold}.list-accordion[open] summary{margin-bottom:12px}
</style>
<a class="list-back" href="campaign_edit.php?id=<?php echo (int)$campaignId; ?>">← Volver a campaña #<?php echo (int)$campaignId; ?></a>
<section class="carsa-card list-card call-stats" aria-label="Estadisticas de llamadas">
<h2>Estadisticas de la lista</h2>
<div class="call-stats-grid">
<div><strong id="call-stat-total">0</strong><span>Total leads</span></div>
<div><strong id="call-stat-calling">0</strong><span>Llamando</span></div>
<div><strong id="call-stat-finished">0</strong><span>Terminados</span></div>
</div>
<h3 style="font-size:13px;margin-top:18px">Resumen por estado del lead</h3>
<div id="call-state-summary"></div>
<div class="call-errors"><h3>Ultimos 30 intentos con error</h3>
<table class="carsa-table"><thead><tr><th>Numero / llamada</th><th>Estado</th><th>Causa</th></tr></thead><tbody id="call-error-rows"></tbody></table>
</div><p id="call-stats-error" class="call-stats-error" role="status"></p>
</section>
<div class="list-layout">
<details class="carsa-card list-card list-accordion"><summary>Datos de la lista</summary>
<table class="carsa-table list-meta"><tbody>
<tr><th scope="row">List ID</th><td><?php echo (int)$listId; ?></td></tr>
<tr><th scope="row">Nombre</th><td><?php echo h($list['name']); ?></td></tr>
<tr><th scope="row">ID de flujo</th><td><?php echo $list['id_flujo'] === null ? 'Sin asignar' : h($list['id_flujo']); ?></td></tr>
<tr><th scope="row">Campaña</th><td>#<?php echo (int)$campaignId; ?> — <?php echo h($list['campaign_name']); ?></td></tr>
<tr><th scope="row">Activo</th><td><?php echo $list['active'] ? 'Sí' : 'No'; ?></td></tr>
<tr><th scope="row">Fecha de creación</th><td><?php echo h($list['created_at']); ?></td></tr>
<tr><th scope="row">Leads</th><td><?php echo $count; ?></td></tr>
</tbody></table></details>
<details class="carsa-card list-card list-accordion"><summary>Cargar base de clientes</summary>
<form method="post" enctype="multipart/form-data" class="carsa-form">
<input type="hidden" name="action" value="upload_list"><?php bot_campaign_token(); ?>
<div class="carsa-field"><label for="clients_txt">Base de clientes (TXT)</label><input id="clients_txt" type="file" name="clients_txt" accept=".txt,text/plain" required></div>
<div class="list-guide"><strong>Cabecera del TXT — formato libre</strong>
<p>De 2 a 10 columnas separadas por comas. La columna obligatoria es <code>numero</code>; las demás se conservan como variables del contacto.</p>
<p>Ejemplo: <code>numero,nombre,monto,direccion</code></p>
<p>Archivo en <strong>UTF-8</strong>. <strong>Esta carga reemplaza todos los contactos actuales de esta lista.</strong> Se descartan teléfonos repetidos dentro del archivo y se informa cuántos registros se cargaron, duplicaron o rechazaron. Si el archivo no contiene contactos válidos o la carga falla, se conserva la base anterior.</p>
<p>Máximo: 10 MB o el límite de esta instalación (<?php echo h(ini_get('upload_max_filesize')); ?> por archivo; <?php echo h(ini_get('post_max_size')); ?> por solicitud). Hasta 50000 registros.</p></div>
<div class="list-form-actions"><button class="carsa-btn">Reemplazar base</button><a class="carsa-btn secondary" href="plantilla_carga.php" download>Descargar plantilla</a></div>
</form></details>
<section class="carsa-card list-card list-audio" aria-labelledby="list-audio-title">
<h2 id="list-audio-title"<?php echo $audioReady ? ' hidden' : ''; ?>>Creación de audios</h2>
<form method="post" id="list-prefix-form"><input type="hidden" name="action" value="save_list_prefix"><?php bot_campaign_token(); ?></form>
<form method="post" class="list-audio-controls">
<input type="hidden" name="action" value="generate_list_audio"><?php bot_campaign_token(); ?>
<div id="list-audio-provider-control" class="carsa-field list-audio-provider"<?php echo $audioReady ? ' hidden' : ''; ?>><label for="list_audio_provider">Proveedor TTS</label>
<select id="list_audio_provider" name="audio_provider">
<?php foreach (bot_list_audio_providers() as $key => $label): ?>
<option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
<?php endforeach; ?>
</select></div>
<div id="list-audio-speed-control" class="carsa-field list-audio-speed"<?php echo $audioReady ? ' hidden' : ''; ?>><label for="list_audio_workers">Velocidad de generación</label>
<select id="list_audio_workers" name="audio_workers">
<?php foreach ([1, 3, 10, 25, 60, 100] as $workers): ?>
<option value="<?php echo $workers; ?>"<?php echo $workers === 25 ? ' selected' : ''; ?>><?php echo $workers; ?>x</option>
<?php endforeach; ?>
</select></div>
<button type="submit" id="list-audio-generate" class="carsa-btn"<?php echo $audioReady ? ' hidden' : ''; ?><?php echo $audioPayload === null || $audioActive || $audioReady ? ' disabled' : ''; ?> aria-describedby="list-audio-availability">Generar</button>
<div class="carsa-field list-audio-speed"><label for="list_call_channels">Canales</label>
<select id="list_call_channels" name="call_channels">
<?php foreach ([1, 3, 5, 10, 20, 25, 50, 100] as $channels): ?>
<option value="<?php echo $channels; ?>"<?php echo $channels === 3 ? ' selected' : ''; ?>><?php echo $channels; ?></option>
<?php endforeach; ?>
</select></div>
<div class="carsa-field list-audio-speed"><label for="list_call_prefix">Origen</label>
<select id="list_call_prefix" name="dial_prefix" form="list-prefix-form">
<option value="">Selecciona...</option>
<?php foreach ($dialOrigins as $origin): ?><option value="<?php echo h($origin['dial_prefix']); ?>"<?php echo ($list['dial_prefix'] ?? '') === $origin['dial_prefix'] ? ' selected' : ''; ?>><?php echo h($origin['name'].' · '.$origin['dial_prefix']); ?></option><?php endforeach; ?>
<?php if (($list['dial_prefix'] ?? '') !== '' && !in_array($list['dial_prefix'], array_column($dialOrigins, 'dial_prefix'), true)): ?><option selected disabled>Origen no disponible: <?php echo h($list['dial_prefix']); ?></option><?php endif; ?>
</select></div>
<button type="submit" id="list-save-prefix" form="list-prefix-form" class="carsa-btn secondary"<?php echo $originLoadError !== '' ? ' disabled' : ''; ?>>Guardar origen</button>
<button type="button" id="list-call-play" class="carsa-btn"<?php echo !$audioReady ? ' hidden' : ''; ?><?php echo $audioReady ? '' : ' disabled'; ?>>▶ Play</button>
<button type="button" id="list-call-stop" class="carsa-btn secondary"<?php echo !$audioReady ? ' hidden' : ''; ?> disabled>■ Stop</button>
<p id="list-call-status" class="list-audio-note" role="status" style="width:100%"></p>
</form>
<?php if ($originLoadError !== ''): ?><p class="list-audio-note" role="status"><?php echo h($originLoadError); ?></p><?php endif; ?>
<?php if ($audioCheckError !== ''): ?>
<p class="list-audio-note" role="status"><?php echo h($audioCheckError); ?></p>
<?php elseif ($audioCheck !== null): ?>
<?php foreach ($audioCheck['errors'] as $audioError): ?><p class="list-audio-note"><?php echo h($audioError); ?></p><?php endforeach; ?>
<?php endif; ?>
<div id="list-audio-summary" class="audio-summary"<?php echo $audioReady ? ' hidden' : ''; ?>><p id="list-audio-availability" class="list-audio-note" role="status">Listo para generar con gTTS local.</p></div>
<section id="list-audio-dialog" class="audio-dialog" aria-labelledby="audio-dialog-title"<?php echo $audioReady ? ' hidden' : ''; ?>>
<div class="audio-dialog-head"><div><h2 id="audio-dialog-title">Generación de audios</h2><p class="audio-dialog-context">Lista #<?php echo (int)$listId; ?> · Flujo <?php echo h($list['id_flujo'] ?? 'Sin asignar'); ?> · gTTS local</p></div></div>
<span id="audio-dialog-state" class="audio-state" role="status">Sin generación</span>
<div class="audio-stats">
<article class="audio-stat" data-kind="generated"><span>Generados</span><strong id="audio-stat-generated">0</strong><small>Audios nuevos creados en esta ejecución</small></article>
<article class="audio-stat" data-kind="reused"><span>Reutilizados</span><strong id="audio-stat-reused">0</strong><small>Audios recuperados de la caché</small></article>
<article class="audio-stat" data-kind="failed"><span>Fallidos</span><strong id="audio-stat-failed">0</strong><small>Audios que necesitan otro intento</small></article>
<article class="audio-stat"><span>Pendientes</span><strong id="audio-stat-pending">0</strong><small>Audios que todavía no terminaron</small></article>
</div>
<div class="audio-progress-label"><span id="audio-dialog-count">0 de 0 procesados</span><strong id="audio-dialog-percent">0%</strong></div>
<progress id="audio-dialog-progress" class="audio-progress" max="100" value="0" aria-label="Progreso de generación"></progress>
<p class="audio-dialog-note">Se cuentan audios únicos: varios leads pueden compartir un mismo audio.</p>
<p class="audio-dialog-note">Leads validados: <?php echo (int)($audioCheck['ready'] ?? 0); ?> de <?php echo (int)$count; ?>. Almacenamiento local en Asterisk · WAV mono, 8000 Hz.</p>
<p id="audio-dialog-errors" class="audio-dialog-errors"></p>
</section>
</section>
</div>
<script>
(() => {
    const output = document.getElementById('list-audio-availability');
    const button = document.getElementById('list-audio-generate');
    const play = document.getElementById('list-call-play');
    const state = document.getElementById('audio-dialog-state');
    const errors = document.getElementById('audio-dialog-errors');
    const valid = <?php echo $audioPayload !== null ? 'true' : 'false'; ?>;
    const signature = <?php echo json_encode($audioPayload['signature'] ?? ''); ?>;
    const url = <?php echo json_encode('list_audio_status.php?id='.(int)$listId.'&campaign_id='.(int)$campaignId); ?>;
    let timer, callTimer, callRun = null, callBusy = false, lastAudioReady = false;
    const stop = document.getElementById('list-call-stop');
    const channels = document.getElementById('list_call_channels');
    const prefix = document.getElementById('list_call_prefix');
    const callsOutput = document.getElementById('list-call-status');
    const callsUrl = <?php echo json_encode('list_call_api.php?id='.(int)$listId.'&campaign_id='.(int)$campaignId); ?>;
    const callsCsrf = <?php echo json_encode($_SESSION['bot_campaign_csrf']); ?>;
    const savedPrefix = <?php echo json_encode((string)($list['dial_prefix'] ?? '')); ?>;
    function renderStatistics(stats) {
        for (const key of ['total','calling','finished']) document.getElementById('call-stat-' + key).textContent = stats[key];
        const summary = document.getElementById('call-state-summary'); summary.replaceChildren();
        for (const row of stats.states) {
            const item = document.createElement('div'); item.className = 'call-state';
            const label = document.createElement('div'); label.className = 'call-state-label';
            const name = document.createElement('span'); name.textContent = row.status;
            const count = document.createElement('strong'); count.textContent = row.count;
            label.append(name,count);
            const bar = document.createElement('progress'); bar.max = Math.max(1,stats.total); bar.value = row.count; bar.setAttribute('aria-label',row.status);
            item.append(label,bar); summary.append(item);
        }
        const errors = document.getElementById('call-error-rows'); errors.replaceChildren();
        for (const row of stats.errors) {
            const tr = document.createElement('tr');
            const phone = document.createElement('td'); phone.textContent = row.phone;
            const id = document.createElement('code'); id.textContent = row.call_id; phone.append(id);
            const state = document.createElement('td'); state.textContent = row.status; state.className = 'call-stats-error';
            const cause = document.createElement('td');
            cause.textContent = [row.ivr_result === 'ERROR' ? 'Error IVR' : '',row.dial_status,row.end_source === 'RECOVERY' ? 'Cierre recuperado' : row.end_source === 'AMI_REJECTED' ? 'Rechazado por AMI' : '',Number(row.hangup_cause) ? 'Causa Asterisk: ' + row.hangup_cause : ''].filter(Boolean).join(' | ') || row.status;
            tr.append(phone,state,cause); errors.append(tr);
        }
        if (!stats.errors.length) { const tr = document.createElement('tr'); const cell = document.createElement('td'); cell.colSpan = 3; cell.textContent = 'Sin errores registrados.'; tr.append(cell); errors.append(tr); }
        document.getElementById('call-stats-error').textContent = '';
    }
    renderStatistics(<?php echo json_encode($statistics, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    function renderCalls(run) {
        callRun = run;
        const active = run && ['starting','running','stopping'].includes(run.state);
        play.hidden = !lastAudioReady;
        play.disabled = !lastAudioReady || callBusy || !!active || !savedPrefix || prefix.value !== savedPrefix;
        stop.hidden = !lastAudioReady && !active;
        stop.disabled = callBusy || !active || run.state === 'stopping';
        channels.disabled = !!active || callBusy;
        prefix.disabled = !!active || callBusy;
        document.getElementById('list-save-prefix').disabled = !!active || callBusy;
        if (run) {
            const labels = {starting:'Iniciando llamadas',running:'Llamadas en marcha',stopping:'Deteniendo: esperando llamadas activas',stopped:'Detenido',completed:'Marcacion finalizada',failed:'Marcacion detenida con error'};
            callsOutput.textContent = (labels[run.state] || run.state) + ' | Activas: ' + run.active + '/' + run.channels + ' | Procesadas: ' + run.completed + '/' + run.launched + ' | Pendientes: ' + run.pending + (run.error ? ' ? ' + run.error : '');
        }
        clearTimeout(callTimer);
        if (!document.hidden) callTimer = setTimeout(pollCalls, active ? 2000 : 10000);
    }
    async function pollCalls() {
        try {
            const response = await fetch(callsUrl, {cache:'no-store', credentials:'same-origin'});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo consultar la marcacion.');
            renderStatistics(data.statistics);
            renderCalls(data.run);
        } catch (error) {
            play.disabled = true;
            callsOutput.textContent = error.message;
            document.getElementById('call-stats-error').textContent = 'No se pudieron actualizar las estadisticas.';
            callTimer = setTimeout(pollCalls, 5000);
        }
    }
    async function callAction(action) {
        if (callBusy) return;
        callBusy = true;
        renderCalls(callRun);
        callsOutput.textContent = action === 'play' ? 'Iniciando llamadas...' : 'Solicitando detener...';
        try {
            const response = await fetch(callsUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf:callsCsrf,channels:Number(channels.value),run_id:callRun && callRun.run_id})});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo completar la operacion.');
            callBusy = false;
            renderStatistics(data.statistics);
            renderCalls(data.run);
        } catch (error) {
            callBusy = false;
            renderCalls(callRun);
            callsOutput.textContent = error.message;
            callTimer = setTimeout(pollCalls, 5000);
        }
    }
    play.addEventListener('click', () => callAction('play'));
    stop.addEventListener('click', () => callAction('stop'));
    prefix.addEventListener('change', () => renderCalls(callRun));

    function render(job, ready) {
        const active = job && (job.state === 'starting' || job.state === 'running');
        const stale = job && valid && job.signature !== signature;
        button.hidden = !!ready && !stale;
        document.getElementById('list-audio-summary').hidden = button.hidden;
        document.getElementById('list-audio-dialog').hidden = button.hidden;
        document.getElementById('list-audio-title').hidden = button.hidden;
        document.getElementById('list-audio-provider-control').hidden = button.hidden;
        document.getElementById('list-audio-speed-control').hidden = button.hidden;
        button.disabled = !valid || active || (ready && !stale);
        lastAudioReady = !!ready && valid && !active && !stale;
        renderCalls(callRun);
        const total = stale ? 0 : Number(job && job.total || 0);
        const completed = stale ? 0 : Number(job && job.completed || 0);
        const percent = total > 0 ? Math.min(100, Math.round(completed * 100 / total)) : 0;
        for (const name of ['generated', 'reused', 'failed']) document.getElementById('audio-stat-' + name).textContent = stale ? 0 : Number(job && job[name] || 0);
        document.getElementById('audio-stat-pending').textContent = Math.max(0, total - completed);
        document.getElementById('audio-dialog-count').textContent = completed + ' de ' + total + ' procesados';
        document.getElementById('audio-dialog-percent').textContent = percent + '%';
        document.getElementById('audio-dialog-progress').value = percent;
        errors.textContent = job && job.errors && !stale ? job.errors.join('\n') : '';
        state.dataset.state = stale ? 'stale' : job && job.state || 'idle';
        if (!job) {
            state.textContent = valid ? 'Listo para generar' : 'Datos pendientes';
            output.textContent = valid ? 'Listo para generar con gTTS local.' : 'Corrige los datos del flujo o la lista antes de generar.';
        } else if (stale) {
            state.textContent = 'Audios desactualizados';
            output.textContent = 'La base o el flujo cambió. Genera nuevamente sus audios.';
        } else {
            const labels = {starting:'Iniciando generación…',running:'Generando audios…',ready:'Audios listos.',failed:'Generación con errores.'};
            state.textContent = labels[job.state] || 'Estado desconocido';
            output.textContent = state.textContent + ' ' + completed + '/' + total + ' audios procesados.';
        }
        if (active) timer = setTimeout(poll, 2500);
    }
    async function poll() {
        try {
            const response = await fetch(url, {cache:'no-store'});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error();
            render(data.job, data.ready === true);
        } catch (error) {
            output.textContent = 'No se pudo actualizar el progreso. Reintentando…';
            timer = setTimeout(poll, 5000);
        }
    }
    document.addEventListener('visibilitychange', () => { if (document.hidden) { clearTimeout(timer); clearTimeout(callTimer); } else { poll(); pollCalls(); } });
    const initial = <?php echo json_encode($audioJob, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    render(initial, <?php echo $audioReady ? 'true' : 'false'; ?>);
    pollCalls();
})();
</script>
<?php endif;
bot_campaign_footer();
