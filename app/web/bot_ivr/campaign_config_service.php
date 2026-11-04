<?php
declare(strict_types=1);
// Single write door for the dialer v1 campaign configuration (migration 014).
// modules/admin/campaigns.php calls these functions and writes no SQL of its own.
require_once __DIR__.'/db.php';
require_once __DIR__.'/campaigns_service.php';
require_once __DIR__.'/../ivr_builder/forwarding.php';
require_once __DIR__.'/../marcador/amd_audit.php';
require_once __DIR__.'/../marcador/list_summary.php';

const BOT_CFG_MAX_HEADERS = 16;

function bot_cfg_list(\ZynervoxQueries\BotIvrRepository $db): array {
    return $db->campaigns();
}
function bot_cfg_get(\ZynervoxQueries\BotIvrRepository $db, int $id): array {
    $config = $db->campaignConfig($id);
    $config['amd_actions'] = bot_cfg_amd_actions($config['amd_actions']);
    $config['lead_fields'] = $db->campaignLeadFields($id);
    $config['active_calls'] = $db->campaignActiveCalls($id);
    return $config;
}

function bot_cfg_int($value, int $min, int $max, string $label): int {
    $parsed = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min, 'max_range'=>$max]]) : false;
    if ($parsed === false) throw new RuntimeException($label.': debe ser un entero entre '.$min.' y '.$max.'.');
    return $parsed;
}

/** Header names must pass the IVRBuilder forwarding validator (X-* or User-to-User); values are validated again when each call is built. */
function bot_cfg_headers($input): array {
    if (!is_array($input)) throw new RuntimeException('Headers: formato inválido.');
    if (count($input) > BOT_CFG_MAX_HEADERS) throw new RuntimeException('Headers: máximo '.BOT_CFG_MAX_HEADERS.'.');
    $out = [];
    foreach (array_values($input) as $i=>$row) {
        $name = is_array($row) ? trim((string)($row['name'] ?? '')) : '';
        $field = is_array($row) ? trim((string)($row['field'] ?? '')) : '';
        if ($name === '' && $field === '') continue;
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name)) throw new RuntimeException('Header '.($i + 1).': el nombre solo admite letras, dígitos y guion.');
        if (!preg_match('/^[a-z0-9_]{1,60}$/D', $field)) throw new RuntimeException('Header '.$name.': indica el campo del lead (minúsculas, dígitos y guion bajo).');
        $out[] = ['name'=>$name, 'field'=>$field];
    }
    try { // names, uniqueness and count: same rules the transfer enforces
        ivr_forwarding_config(['forwarding'=>['mode'=>'trunk', 'endpoint'=>'x', 'number'=>'1', 'headers'=>array_map(fn($h) => ['name'=>$h['name'], 'value'=>'x'], $out)]]);
    } catch (InvalidArgumentException $e) {
        throw new RuntimeException('Headers: usa nombres X-* (p. ej. X-Cliente) o User-to-User, sin repetidos.');
    }
    return $out;
}

/** AMD results the user can configure. kind: status = AMDSTATUS, cause = AMDCAUSE (TOOLONG etc. are causes, not statuses). */
function bot_cfg_amd_results(): array {
    return [['result'=>'HUMAN', 'label'=>'Humano', 'kind'=>'status'], ['result'=>'MACHINE', 'label'=>'Contestadora', 'kind'=>'status'], ['result'=>'NOTSURE', 'label'=>'No seguro', 'kind'=>'status']];
}
function bot_cfg_amd_defaults(): array {
    return ['HUMAN'=>['action'=>'transfer', 'max_attempts'=>0, 'retry_delay_min'=>0], 'NOTSURE'=>['action'=>'transfer', 'max_attempts'=>0, 'retry_delay_min'=>0],
        'MACHINE'=>['action'=>'retry', 'max_attempts'=>2, 'retry_delay_min'=>240]]; // contestadora: 1 reintento a los 240 min
}
/** Final SIP codes with a retry rule. group: codes the page may show together (rows are still saved per exact code). 200 is decided by AMD. */
function bot_cfg_sip_codes(): array {
    $rows = [[486,'Ocupado','busy','Ocupado'], [600,'Ocupado (global)','busy','Ocupado'], [480,'No disponible','unavailable','No disponible o sin respuesta'], [408,'Sin respuesta (timeout)','unavailable','No disponible o sin respuesta'],
        [404,'Inexistente','missing','Inexistente'], [484,'Dirección incompleta','missing','Inexistente'],
        [500,'Error del servidor','trunk','Congestión o fallo de troncal'], [502,'Pasarela inválida','trunk','Congestión o fallo de troncal'], [503,'Servicio no disponible','trunk','Congestión o fallo de troncal'], [504,'Timeout de pasarela','trunk','Congestión o fallo de troncal'],
        [603,'Rechazado','declined','Rechazado'], [487,'Cancelado por timeout propio','cancelled','Cancelado por timeout'], [0,'Sin respuesta del proveedor','noresp','Sin respuesta del proveedor']];
    return array_map(fn($r) => ['sip_code'=>$r[0], 'label'=>$r[1], 'group'=>$r[2], 'group_label'=>$r[3]], $rows);
}

/**
 * Default retries (contract 04/11/2026): 486/600 four attempts every 15 min; 480/408 four every 60; 487 and no provider answer (0) four every 120;
 * 500/502/503/504 four every 10; 404/484/603 no retry. max_attempts is the total cap of attempts of the lead (first call included).
 */
function bot_cfg_default_rules(): array {
    $rows = [];
    foreach ([[[486, 600], 15], [[480, 408], 60], [[487, 0], 120], [[500, 502, 503, 504], 10]] as [$codes, $delay]) foreach ($codes as $code) $rows[] = ['sip_code'=>$code, 'max_attempts'=>4, 'retry_delay_min'=>$delay, 'active'=>1];
    usort($rows, fn($a, $b) => $a['sip_code'] <=> $b['sip_code']);
    return $rows;
}
/** Puts the default retries (SIP rules and answering-machine retry) on an existing campaign; the rest of its configuration is untouched. */
function bot_cfg_apply_default_retries(\ZynervoxQueries\BotIvrRepository $db, int $campaignId): void {
    $c = bot_cfg_get($db, $campaignId); $k = $c['campaign'];
    $amd = array_map(fn($a) => $a['result'] === 'MACHINE' ? ['result'=>'MACHINE'] + bot_cfg_amd_defaults()['MACHINE'] : $a, $c['amd_actions']);
    bot_cfg_save($db, $campaignId, ['name'=>$k['name'], 'active'=>$k['active'], 'call_origin'=>$k['call_origin'], 'max_channels'=>$k['max_channels'], 'amd_enabled'=>$k['amd_enabled'],
        'transfer_carrier_id'=>$k['transfer_carrier_id'], 'transfer_destination'=>$k['transfer_destination'], 'timezone'=>$k['timezone'], 'lead_order'=>$k['lead_order'], 'dial_timeout'=>$k['dial_timeout'], 'campaign_cid'=>$k['campaign_cid'],
        'headers'=>array_map(fn($h) => ['name'=>$h['header_name'], 'field'=>$h['lead_field']], $c['headers']), 'schedules'=>$c['schedules'], 'rules'=>bot_cfg_default_rules(), 'amd_actions'=>$amd]);
}

/** Rules by exact SIP code (0 = no provider answer). Unknown codes and 200 are rejected. */
function bot_cfg_rules($input): array {
    if (!is_array($input) || count($input) > 64) throw new RuntimeException('Reintentos: formato inválido.');
    $known = array_column(bot_cfg_sip_codes(), 'sip_code');
    $out = [];
    foreach ($input as $row) {
        if (!is_array($row) || ($row['sip_code'] ?? '') === '') continue;
        $code = bot_cfg_int($row['sip_code'], 0, 699, 'Reintentos, código SIP');
        if (!in_array($code, $known, true)) throw new RuntimeException('Reintentos: el código '.$code.' no admite regla (el 200 lo decide el AMD).');
        if (isset($out[$code])) throw new RuntimeException('Reintentos: el código '.$code.' está repetido.');
        $out[$code] = ['sip_code'=>$code,
            'max_attempts'=>bot_cfg_int($row['max_attempts'] ?? null, 1, 99, 'Reintentos '.$code.', intentos máximos'),
            'retry_delay_min'=>bot_cfg_int($row['retry_delay_min'] ?? null, 0, 10080, 'Reintentos '.$code.', espera en minutos'),
            'active'=>(string)($row['active'] ?? '1') === '0' ? 0 : 1];
    }
    ksort($out);
    return array_values($out);
}

/** One action per AMD result; a missing result takes its default. max_attempts/retry_delay_min only count with retry (otherwise stored as 0). */
function bot_cfg_amd_actions($input): array {
    if (!is_array($input) || count($input) > 20) throw new RuntimeException('Acciones AMD: formato inválido.');
    $defaults = bot_cfg_amd_defaults();
    $out = [];
    foreach ($input as $row) {
        $result = is_array($row) ? strtoupper(trim((string)($row['result'] ?? ''))) : '';
        if ($result === '') continue;
        if (!isset($defaults[$result])) throw new RuntimeException('Acciones AMD: resultado "'.$result.'" no configurable.');
        if (isset($out[$result])) throw new RuntimeException('Acciones AMD: el resultado '.$result.' está repetido.');
        $action = (string)($row['action'] ?? '');
        if (!in_array($action, ['transfer','hangup','retry'], true)) throw new RuntimeException('Acciones AMD '.$result.': la acción debe ser transfer, hangup o retry.');
        $retry = $action === 'retry';
        $out[$result] = ['result'=>$result, 'action'=>$action,
            'max_attempts'=>$retry ? bot_cfg_int($row['max_attempts'] ?? null, 1, 99, 'Acción AMD '.$result.', intentos máximos') : 0,
            'retry_delay_min'=>$retry ? bot_cfg_int($row['retry_delay_min'] ?? null, 0, 10080, 'Acción AMD '.$result.', espera en minutos') : 0];
    }
    foreach ($defaults as $result=>$d) $out[$result] ??= ['result'=>$result] + $d;
    return array_values($out);
}

function bot_cfg_schedules($input): array {
    if (!is_array($input) || count($input) > 70) throw new RuntimeException('Franjas: formato inválido.');
    $out = [];
    foreach ($input as $row) {
        if (!is_array($row) || (($row['start_time'] ?? '') === '' && ($row['end_time'] ?? '') === '')) continue;
        $day = bot_cfg_int($row['weekday'] ?? null, 1, 7, 'Franja, día (1=lunes … 7=domingo)');
        foreach (['start_time','end_time'] as $key) if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', (string)($row[$key] ?? ''))) throw new RuntimeException('Franja: la hora debe tener formato HH:MM.');
        if ($row['start_time'] >= $row['end_time']) throw new RuntimeException('Franja del día '.$day.': el inicio debe ser anterior al fin.');
        foreach ($out as $other) if ($other['weekday'] === $day && $row['start_time'] < $other['end_time'] && $other['start_time'] < $row['end_time']) throw new RuntimeException('Franjas del día '.$day.' se solapan.');
        $out[] = ['weekday'=>$day, 'start_time'=>$row['start_time'], 'end_time'=>$row['end_time']];
    }
    return $out;
}

/** $input: name, active, call_origin, max_channels, amd_enabled, transfer_carrier_id, transfer_destination, timezone, lead_order, dial_timeout, campaign_cid, headers[], rules[], amd_actions[], schedules[]. Returns the campaign id. */
function bot_cfg_save(\ZynervoxQueries\BotIvrRepository $db, ?int $id, array $input): int {
    $old = $id === null ? ['lead_order'=>'DOWN','dial_timeout'=>60,'campaign_cid'=>'','max_channels'=>1] : $db->campaign($id);
    $origin = trim((string)($input['call_origin'] ?? ''));
    if (!preg_match('/^[0-9]{0,20}$/D', $origin)) throw new RuntimeException('Origen de llamada inválido.');
    $carrier = trim((string)($input['transfer_carrier_id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.-]{0,80}$/D', $carrier)) throw new RuntimeException('Troncal de transferencia inválido.');
    $destination = trim((string)($input['transfer_destination'] ?? ''));
    if (!preg_match('/^[+0-9*#]{0,40}$/D', $destination)) throw new RuntimeException('Destino de transferencia inválido: solo dígitos, +, * y #.');
    $timezone = (string)($input['timezone'] ?? 'America/Lima');
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) throw new RuntimeException('Zona horaria inválida.');
    $order = (string)($input['lead_order'] ?? $old['lead_order']);
    if (!in_array($order, ['DOWN','UP','RANDOM'], true)) throw new RuntimeException('Orden de leads inválido.');
    $campaign = ['name'=>bot_campaign_name((string)($input['name'] ?? '')),
        'active'=>(string)($input['active'] ?? '') === '1' ? 1 : 0,
        'call_origin'=>$origin,
        'max_channels'=>bot_cfg_int($input['max_channels'] ?? $old['max_channels'], 1, 500, 'Canales'), // obsolete: the engine uses the list channels
        'amd_enabled'=>(string)($input['amd_enabled'] ?? '') === '1' ? 1 : 0,
        'transfer_carrier_id'=>$carrier, 'transfer_destination'=>$destination, 'timezone'=>$timezone, 'lead_order'=>$order,
        'dial_timeout'=>bot_cfg_int($input['dial_timeout'] ?? $old['dial_timeout'], 1, 255, 'Tiempo de llamada'),
        'campaign_cid'=>bot_campaign_field_value('campaign_cid', $input['campaign_cid'] ?? $old['campaign_cid'])];
    return $db->saveCampaignConfig($id, $campaign, bot_cfg_headers($input['headers'] ?? []), ['sip'=>bot_cfg_rules($input['rules'] ?? ($id === null ? bot_cfg_default_rules() : [])), 'amd'=>bot_cfg_amd_actions($input['amd_actions'] ?? [])], bot_cfg_schedules($input['schedules'] ?? []));
}

/** Channels of one list (1..server max). Safe while the campaign runs: the engine re-reads it on every pass. */
function bot_cfg_set_list_channels(\ZynervoxQueries\BotIvrRepository $db, int $campaignId, int $listId, $channels): void {
    $max = $db->dialerMaxTotalChannels();
    $db->setListChannels($campaignId, $listId, bot_cfg_int($channels, 1, $max, 'Canales de la lista (máximo del servidor '.$max.')'));
}
/** All lists with max_channels and open_calls (live). */
function bot_cfg_lists_channels(\ZynervoxQueries\BotIvrRepository $db): array { return $db->listsChannels(); }
/** max_channels_server: cap for all open calls; open_calls: open right now; lists_sum: sum of the active lists' limits (informative, may exceed the cap: the engine never opens more than the cap). */
function bot_cfg_server_limits(\ZynervoxQueries\BotIvrRepository $db): array {
    return ['max_channels_server'=>$db->dialerMaxTotalChannels(), 'open_calls'=>$db->openCallsTotal(),
        'lists_sum'=>array_sum(array_map(fn($l) => (int)$l['active'] ? (int)$l['max_channels'] : 0, $db->listsChannels()))];
}
function bot_cfg_get_max_total_channels(\ZynervoxQueries\BotIvrRepository $db): int { return $db->dialerMaxTotalChannels(); }
function bot_cfg_set_max_total_channels(\ZynervoxQueries\BotIvrRepository $db, $channels): void {
    $db->setDialerMaxTotalChannels(bot_cfg_int($channels, 1, 500, 'Máximo de canales del servidor'));
}

/** Last attempts (newest first) with the AMD result and the hangup causes, for the test reports. */
function bot_cfg_attempts_report(\ZynervoxQueries\BotIvrRepository $db, ?int $campaignId, int $limit): array {
    return $db->campaignAttemptsReport($campaignId, max(1, min(500, $limit)));
}

/** Deletes a stopped campaign without lists and its configuration. History (attempts, events, dial runs) is kept. */
function bot_cfg_delete(\ZynervoxQueries\BotIvrRepository $db, int $id, string $actor): void {
    $db->deleteCampaign($id);
    error_log('ZV2 campaign_deleted '.json_encode(['campaign_id'=>$id, 'actor'=>$actor, 'at'=>gmdate('c')], JSON_UNESCAPED_UNICODE));
}

/** Checks that the campaign can dial (shared by starting a campaign and starting one of its lists). */
function bot_cfg_check_startable(\ZynervoxQueries\BotIvrRepository $db, array $c, ?array $origins): void {
    if (!(int)$c['active']) throw new RuntimeException('Activa la campaña antes de iniciar.');
    if ($c['call_origin'] === '') throw new RuntimeException('Elige el origen de llamada.');
    require_once __DIR__.'/../includes/Carriers.php';
    $origins ??= array_column(\Includes\Carriers::dialOrigins(), 'dial_prefix');
    if (!in_array($c['call_origin'], $origins, true)) throw new RuntimeException('El origen de llamada ya no está disponible.');
    if (($c['transfer_carrier_id'] === '') !== ($c['transfer_destination'] === '')) throw new RuntimeException('Define el troncal y el destino de la transferencia, o deja ambos vacíos para solo detectar.');
}

/** Starts the campaign and its ACTIVE lists. The engine (campaign_engine.php) does the dialing; nothing is launched here. */
function bot_cfg_start(\ZynervoxQueries\BotIvrRepository $db, int $id, string $actor, ?array $origins = null): void {
    $c = $db->campaignConfig($id)['campaign'];
    bot_cfg_check_startable($db, $c, $origins);
    if ($db->countEligibleLeads($id, (string)$c['lead_order']) < 1) throw new RuntimeException('No hay leads elegibles en las listas activas de la campaña.');
    $db->setCampaignListsState($id, 'running', $actor);
    $db->setDialerState($id, 'running', $actor);
}
/** Stops the campaign and all its lists; calls in progress finish by themselves. */
function bot_cfg_stop(\ZynervoxQueries\BotIvrRepository $db, int $id, string $actor): void {
    $db->setDialerState($id, 'stopped', $actor);
    $db->setCampaignListsState($id, 'stopped', $actor);
    foreach ($db->listsChannels() as $l) if ((int)$l['campaign_id'] === $id) marcador_list_summary_refresh($db, (int)$l['list_id'], 'stopped');
}
/** Starts ONE list (it must be active) and puts its campaign in motion if it was not. */
function bot_cfg_list_start(\ZynervoxQueries\BotIvrRepository $db, int $campaignId, int $listId, string $actor, ?array $origins = null): void {
    $list = $db->listState($campaignId, $listId);
    if (!(int)$list['active']) throw new RuntimeException('Activa la lista antes de iniciarla.');
    $c = $db->campaignConfig($campaignId)['campaign'];
    bot_cfg_check_startable($db, $c, $origins);
    if ($db->listEligibleCount($campaignId, $listId, (string)$c['lead_order']) < 1) throw new RuntimeException('No hay contactos por llamar en la lista.');
    $db->setListDialerState($campaignId, $listId, 'running', $actor);
    if ($c['dialer_state'] !== 'running') $db->setDialerState($campaignId, 'running', $actor);
}
/** Stops ONE list; stopping the last started list stops the campaign. Calls in progress finish by themselves. */
function bot_cfg_list_stop(\ZynervoxQueries\BotIvrRepository $db, int $campaignId, int $listId, string $actor): void {
    $db->setListDialerState($campaignId, $listId, 'stopped', $actor);
    if ($db->runningListsCount($campaignId) < 1) $db->setDialerState($campaignId, 'stopped', $actor);
    marcador_list_summary_refresh($db, $listId, 'stopped'); // final figures of what was dialed; calls still open are added by the engine's next pass
}
/** Enables or disables a list. Disabling stops it (and the campaign when it was the last one running). */
function bot_cfg_list_set_active(\ZynervoxQueries\BotIvrRepository $db, int $campaignId, int $listId, bool $active, string $actor): void {
    $db->setListActive($campaignId, $listId, $active);
    if (!$active) bot_cfg_list_stop($db, $campaignId, $listId, $actor);
}

/** Why a list is not dialing right now, in plain Spanish ('' = it is dialing or has calls in progress). */
function bot_cfg_list_reason(array $row, array $c, bool $engineAlive, bool $inWindow, int $eligible, int $serverFree): string {
    if (!(int)$row['list_active']) return 'Lista inactiva';
    if ($row['list_state'] !== 'running') return 'Lista detenida';
    if ($row['campaign_state'] !== 'running' || !(int)$c['campaign_active']) return 'Campaña detenida';
    if (!$engineAlive) return 'Motor apagado';
    if (!$inWindow) return 'Fuera de horario';
    $open = (int)($row['att']['open_calls'] ?? 0);
    if ($open >= (int)$row['max_channels']) return '';
    if ($eligible < 1) return 'Sin contactos por llamar';
    if ($serverFree < 1) return 'Máximo de canales del servidor';
    return '';
}

/**
 * Operational view, one entry per campaign and list (never mixed). Light: grouped SQL plus one eligibility count per started list.
 * Returns ['server'=>[max_channels,open_calls,engine_alive], 'campaigns'=>[[campaign_id,name,active,started,lists=>[...]]]].
 */
/**
 * PROVISIONAL thresholds (the AMD is not calibrated yet). The temperature is read from the HUMAN rate (humans / calls),
 * not from the contact rate: answering machines also answer, so contact alone would make a list look hot when nobody talks.
 * Checked against the real tests of 03/11/2026 (human rate 0 % to 15 % per list of 20). Change them here only.
 */
const BOT_TEMP_MIN_CALLS = 20;    // fewer calls: "Sin datos"
const BOT_TEMP_HOT_HUMAN = 10;    // human % >= this: Caliente -> Seguir
const BOT_TEMP_WARM_HUMAN = 5;    // human % >= this: Tibia -> Vigilar; below: Fría -> Revisar lista

/** Rates in % of calls and the 15-minute pace, plus the temperature (by human rate) and its recommendation. */
function bot_cfg_list_temperature(array $totals, int $calls15): array {
    $calls = (int)$totals['calls'];
    $pct = fn(int $n) => $calls > 0 ? round(100 * $n / $calls, 1) : 0.0;
    $rates = ['contact'=>$pct((int)$totals['answered']), 'human'=>$pct((int)$totals['human']), 'machine'=>$pct((int)$totals['machine']),
        'unavailable'=>$pct((int)$totals['unavailable']), 'no_answer'=>$pct((int)$totals['no_answer'])];
    [$temp, $advice] = $calls < BOT_TEMP_MIN_CALLS ? ['Sin datos', 'Seguir'] : ($rates['human'] >= BOT_TEMP_HOT_HUMAN ? ['Caliente', 'Seguir'] : ($rates['human'] >= BOT_TEMP_WARM_HUMAN ? ['Tibia', 'Vigilar'] : ['Fría', 'Revisar lista']));
    return ['rates_pct'=>$rates, 'pace'=>['calls_last_15min'=>$calls15, 'calls_per_min'=>round($calls15 / 15, 2)], 'temperature'=>$temp, 'recommendation'=>$advice, 'basis'=>'human',
        'thresholds_provisional'=>true, 'thresholds'=>['min_calls'=>BOT_TEMP_MIN_CALLS, 'hot_human_pct'=>BOT_TEMP_HOT_HUMAN, 'warm_human_pct'=>BOT_TEMP_WARM_HUMAN]];
}

function bot_cfg_dashboard(\ZynervoxQueries\BotIvrRepository $db, ?DateTimeInterface $now = null): array {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $age = $db->dialerHeartbeatAge();
    $alive = $age !== null && $age <= 15;
    $max = $db->dialerMaxTotalChannels(); $openTotal = $db->openCallsTotal();
    $out = ['server'=>['max_channels'=>$max, 'open_calls'=>$openTotal, 'engine_alive'=>$alive], 'campaigns'=>[]];
    $schedules = []; $calls15 = [];
    foreach ($db->dashboardRows() as $r) {
        $cid = (int)$r['campaign_id'];
        if (!isset($out['campaigns'][$cid])) {
            $schedules[$cid] ??= $db->campaignConfig($cid)['schedules'];
            $out['campaigns'][$cid] = ['campaign_id'=>$cid, 'name'=>$r['campaign_name'], 'active'=>(int)$r['campaign_active'] === 1, 'started'=>$r['campaign_state'] === 'running', 'lists'=>[]];
        }
        if ($r['list_id'] === null) continue;
        $c = ['campaign_active'=>$r['campaign_active'], 'timezone'=>$r['timezone'], 'scheduled'=>$r['scheduled'], 'start_time'=>$r['start_time'], 'end_time'=>$r['end_time']];
        $inWindow = bot_cfg_in_window($c, $schedules[$cid], $now);
        $L = $r['leads'] ?? ['total'=>0, 'pending'=>0, 'called'=>0]; $A = $r['att'] ?? [];
        $started = (int)$r['list_active'] === 1 && $r['list_state'] === 'running';
        $eligible = ($started && $r['campaign_state'] === 'running') ? $db->listEligibleCount($cid, (int)$r['list_id'], (string)$r['lead_order']) : 0;
        $total = (int)$L['total']; $calls15[(int)$r['list_id']] = (int)($A['calls15'] ?? 0);
        $out['campaigns'][$cid]['lists'][] = ['list_id'=>(int)$r['list_id'], 'name'=>$r['list_name'], 'active'=>(int)$r['list_active'] === 1, 'started'=>$started, 'channels'=>(int)$r['max_channels'],
            'open_calls'=>(int)($A['open_calls'] ?? 0),
            'totals'=>['contacts'=>$total, 'not_called'=>(int)$L['pending'], 'calls'=>(int)($A['calls'] ?? 0), 'answered'=>(int)($A['answered'] ?? 0), 'human'=>(int)($A['human'] ?? 0), 'machine'=>(int)($A['machine'] ?? 0), 'not_sure'=>(int)($A['notsure'] ?? 0),
                'unavailable'=>(int)($A['unavailable'] ?? 0), 'no_answer'=>(int)($A['noanswer'] ?? 0), 'failed'=>(int)($A['failed'] ?? 0)],
            'insight'=>null,
            'progress_pct'=>$total > 0 ? (int)round(100 * (int)$L['called'] / $total) : 0,
            'reason'=>bot_cfg_list_reason($r + ['att'=>$A], $c + ['campaign_active'=>$r['campaign_active']], $alive, $inWindow, $eligible, $max - $openTotal)];
    }
    foreach ($out['campaigns'] as &$camp) foreach ($camp['lists'] as &$l) $l['insight'] = bot_cfg_list_temperature($l['totals'], $calls15[$l['list_id']] ?? 0);
    unset($camp, $l);
    $out['campaigns'] = array_values($out['campaigns']);
    return $out;
}

/** True when $now (any timezone) is inside a call window. Schedule rows win; without rows scheduled=1 uses start/end_time, else always open. */
function bot_cfg_in_window(array $campaign, array $schedules, DateTimeInterface $now): bool {
    $local = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone((string)($campaign['timezone'] ?? 'America/Lima')));
    $time = $local->format('H:i');
    if ($schedules) {
        foreach ($schedules as $w) if ((int)$w['weekday'] === (int)$local->format('N') && $time >= substr((string)$w['start_time'], 0, 5) && $time < substr((string)$w['end_time'], 0, 5)) return true;
        return false;
    }
    if (!(int)($campaign['scheduled'] ?? 0)) return true;
    $start = substr((string)$campaign['start_time'], 0, 5); $end = substr((string)$campaign['end_time'], 0, 5);
    return $start < $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
}

/** Resolves the campaign headers for one lead into [{name,value}] (the name/value contract with IVRBuilder). Throws RuntimeException if any value is unusable. */
function bot_cfg_resolve_headers(array $headers, array $lead): array {
    if (!$headers) return [];
    $extra = (array)json_decode((string)($lead['extra_json'] ?? ''), true);
    $out = [];
    foreach ($headers as $h) {
        $field = $h['lead_field'] ?? $h['field'];
        $value = $field === 'phone' ? $lead['phone'] : ($field === 'customer_name' ? $lead['customer_name'] : ($extra[$field] ?? ''));
        if (!is_scalar($value) || trim((string)$value) === '') throw new RuntimeException('El lead no tiene valor para el campo '.$field.' (header '.($h['header_name'] ?? $h['name']).').');
        $out[] = ['name'=>$h['header_name'] ?? $h['name'], 'value'=>trim((string)$value)];
    }
    try { ivr_forwarding_config(['forwarding'=>['mode'=>'trunk', 'endpoint'=>'x', 'number'=>'1', 'headers'=>$out]]); }
    catch (InvalidArgumentException $e) { throw new RuntimeException('Valor de header no permitido para el lead.'); }
    return $out;
}

const BOT_AMD_VALID_FROM_LIMA = '2026-11-03 15:31:54'; // audio of the provider reached Asterisk from here on; earlier AMD results are not valid

/** Local time (Lima, no DST) to UTC string, or null. 'Y-m-d' as an upper bound means the end of that day. */
function bot_cfg_report_time($value, bool $isEnd, DateTimeZone $tz): ?string {
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/D', $value)) throw new RuntimeException('Fecha inválida: usa AAAA-MM-DD o AAAA-MM-DD HH:MM.');
    $dt = new DateTimeImmutable($value, $tz);
    if ($isEnd && strlen($value) === 10) $dt = $dt->modify('+1 day');
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
/** Normalizes the report filters: from, to (America/Lima), campaign_id, list_id, include_test, include_invalid_amd. */
function bot_cfg_report_filters(array $in): array {
    $tz = new DateTimeZone('America/Lima');
    $id = fn($v, string $label) => ($v === null || $v === '' || $v === 0 || $v === '0') ? null : bot_cfg_int($v, 1, PHP_INT_MAX, $label);
    $from = bot_cfg_report_time($in['from'] ?? null, false, $tz); $to = bot_cfg_report_time($in['to'] ?? null, true, $tz);
    if ($from !== null && $to !== null && $from >= $to) throw new RuntimeException('El rango de fechas es inválido: "desde" debe ser anterior a "hasta".');
    return ['from_utc'=>$from, 'to_utc'=>$to, 'campaign_id'=>$id($in['campaign_id'] ?? null, 'Campaña'), 'list_id'=>$id($in['list_id'] ?? null, 'Lista'),
        'include_test'=>!empty($in['include_test']), 'include_invalid_amd'=>!empty($in['include_invalid_amd']),
        'invalid_before_utc'=>(new DateTimeImmutable(BOT_AMD_VALID_FROM_LIMA, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'offset_seconds'=>$tz->getOffset(new DateTimeImmutable('now', $tz))];
}
function bot_cfg_report_mask_phone(string $phone): string { return strlen($phone) > 3 ? str_repeat('*', strlen($phone) - 3).substr($phone, -3) : $phone; }

/**
 * Report data: kpi, by_hour, by_list, by_amd, by_cause, transfers and a paginated detail. Hours in America/Lima (started_at is UTC).
 * Deleted lists/campaigns stay in the history as "lista eliminada"/"campaña eliminada". TEST-% attempts are excluded unless include_test.
 * AMD results before 03/11/2026 15:31:54 Lima are flagged amd_valid=false and left out of the AMD figures unless include_invalid_amd.
 * Phones are masked (last 3 digits).
 */
function bot_cfg_report(\ZynervoxQueries\BotIvrRepository $db, array $filters = [], int $page = 1, int $perPage = 50): array {
    $f = bot_cfg_report_filters($filters);
    $perPage = max(1, min(500, $perPage)); $page = max(1, $page);
    // Whole history with the default options: the incremental per-list summary answers (finished and deleted lists included) without scanning every attempt.
    $sum = ($f['from_utc'] === null && $f['to_utc'] === null && !$f['include_test'] && !$f['include_invalid_amd']) ? $db->listSummaries($f['campaign_id'], $f['list_id']) : [];
    $agg = $db->reportAggregates($f + ['skip_totals'=>(bool)$sum]);
    $detail = $db->reportDetail($f, $perPage, ($page - 1) * $perPage);
    foreach ($detail['rows'] as &$r) { $r['phone'] = bot_cfg_report_mask_phone((string)$r['phone']); $r['amd_valid'] = (bool)$r['amd_valid']; }
    unset($r);
    $kpi = $agg['kpi'] ?? []; $byList = $agg['by_list'] ?? [];
    {
        if ($sum) {
            $lima = new DateTimeZone('America/Lima');
            $local = fn($v) => $v === null ? null : (new DateTimeImmutable($v, new DateTimeZone('UTC')))->setTimezone($lima)->format('Y-m-d H:i:s');
            $byList = array_map(fn($u) => ['campaign_id'=>$u['campaign_id'], 'campaign'=>$u['campaign_name'] ?: 'campaña eliminada', 'list_id'=>(int)$u['list_id'], 'list'=>$u['list_name'] ?: 'lista eliminada',
                'list_deleted'=>$u['state'] === 'deleted', 'state'=>$u['state'], 'source'=>'summary', 'calls'=>(int)$u['calls'], 'answered'=>(int)$u['answered'], 'human'=>(int)$u['human'], 'machine'=>(int)$u['machine'], 'not_sure'=>(int)$u['not_sure'],
                'unavailable'=>(int)$u['unavailable'], 'no_answer'=>(int)$u['no_answer'], 'failed'=>(int)$u['failed'], 'xfer_ok'=>(int)$u['xfer_ok'], 'xfer_fail'=>(int)$u['xfer_fail'], 'contacts'=>(int)$u['contacts'],
                'contact_pct'=>(float)$u['contact_pct'], 'human_pct'=>(float)$u['human_pct'], 'machine_pct'=>(float)$u['machine_pct'], 'first_call'=>$local($u['first_call_at']), 'last_call'=>$local($u['last_call_at']),
                'duration_seconds'=>(int)$u['duration_seconds'], 'finished_at'=>$local($u['finished_at'])], $sum);
            $tot = ['calls'=>0, 'answered'=>0, 'human'=>0, 'machine'=>0, 'not_sure'=>0, 'unavailable'=>0, 'no_answer'=>0, 'failed'=>0, 'xfer_ok'=>0, 'xfer_fail'=>0];
            foreach ($byList as $l) foreach ($tot as $k=>$_) $tot[$k] += $l[$k];
            $kpi = $tot + ['avg_billsec'=>null, 'first_call'=>min(array_filter(array_column($byList, 'first_call')) ?: [null]), 'last_call'=>max(array_filter(array_column($byList, 'last_call')) ?: [null])];
        }
    }
    $calls = (int)$kpi['calls'];
    $kpi['contact_pct'] = $calls > 0 ? round(100 * (int)$kpi['answered'] / $calls, 1) : 0.0;
    return ['filters'=>['from_utc'=>$f['from_utc'], 'to_utc'=>$f['to_utc'], 'campaign_id'=>$f['campaign_id'], 'list_id'=>$f['list_id'], 'include_test'=>$f['include_test'], 'include_invalid_amd'=>$f['include_invalid_amd'], 'timezone'=>'America/Lima', 'amd_valid_from_local'=>BOT_AMD_VALID_FROM_LIMA],
        'kpi'=>$kpi, 'by_hour'=>$agg['by_hour'], 'by_list'=>$byList, 'by_amd'=>$agg['by_amd'], 'by_cause'=>$agg['by_cause'],
        'transfers'=>['ok'=>(int)$kpi['xfer_ok'], 'failed'=>(int)$kpi['xfer_fail']],
        'detail'=>['page'=>$page, 'per_page'=>$perPage, 'total'=>$detail['total'], 'rows'=>$detail['rows']]];
}

/** CSV (UTF-8 with BOM) of the whole filtered detail, local Lima times, masked phones; cells that start like a formula are neutralized. */
function bot_cfg_report_csv(\ZynervoxQueries\BotIvrRepository $db, array $filters = [], int $maxRows = 50000): string {
    $f = bot_cfg_report_filters($filters);
    $rows = $db->reportDetail($f, max(1, min(100000, $maxRows)), 0)['rows'];
    $head = ['attempt_id','inicio_lima','amd_valido','campana_id','campana','lista_id','lista','contacto_id','telefono','estado','disposicion','amd','amd_causa','sip','q850','billsec','origen_causa'];
    $h = fopen('php://temp', 'r+'); fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, $head, ',', '"', '');
    $safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'".$v : $v;
    foreach ($rows as $r) fputcsv($h, array_map($safe, [$r['attempt_id'], $r['started_at_local'], $r['amd_valid'] ? 'si' : 'no', $r['campaign_id'], $r['campaign'], $r['list_id'], $r['list'], $r['lead_id'],
        bot_cfg_report_mask_phone((string)$r['phone']), $r['status'], $r['disposition'], $r['amd_status'], $r['amd_cause'], $r['sip_cause'], $r['q850'], $r['billsec'], $r['cause_source']]), ',', '"', '');
    rewind($h); $csv = (string)stream_get_contents($h); fclose($h);
    return $csv;
}
