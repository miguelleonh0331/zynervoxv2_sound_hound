<?php
declare(strict_types=1);
// Single write door for the dialer v1 campaign configuration (migration 014).
// modules/admin/campaigns.php calls these functions and writes no SQL of its own.
require_once __DIR__.'/db.php';
require_once __DIR__.'/campaigns_service.php';
require_once __DIR__.'/../ivr_builder/forwarding.php';

const BOT_CFG_MAX_HEADERS = 16;

function bot_cfg_list(\ZynervoxQueries\BotIvrRepository $db): array {
    return $db->campaigns();
}
function bot_cfg_get(\ZynervoxQueries\BotIvrRepository $db, int $id): array {
    $config = $db->campaignConfig($id);
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

function bot_cfg_rules($input): array {
    if (!is_array($input) || count($input) > 64) throw new RuntimeException('Reintentos: formato inválido.');
    $out = [];
    foreach ($input as $row) {
        $status = is_array($row) ? strtoupper(trim((string)($row['status'] ?? ''))) : '';
        if ($status === '') continue;
        if (!preg_match('/^[A-Z0-9_]{1,80}$/D', $status) || in_array($status, ['NEW','CALL'], true)) throw new RuntimeException('Reintentos: estado "'.$status.'" no permitido (NEW siempre se marca; CALL es interno).');
        if (isset($out[$status])) throw new RuntimeException('Reintentos: el estado '.$status.' está repetido.');
        $out[$status] = ['status'=>$status,
            'max_attempts'=>bot_cfg_int($row['max_attempts'] ?? null, 1, 99, 'Reintentos '.$status.', intentos máximos'),
            'retry_delay_min'=>bot_cfg_int($row['retry_delay_min'] ?? null, 0, 10080, 'Reintentos '.$status.', espera en minutos'),
            'active'=>(string)($row['active'] ?? '1') === '0' ? 0 : 1];
    }
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

/** $input: name, active, call_origin, max_channels, amd_enabled, transfer_carrier_id, transfer_destination, timezone, lead_order, dial_timeout, campaign_cid, headers[], retry_rules[], schedules[]. Returns the campaign id. */
function bot_cfg_save(\ZynervoxQueries\BotIvrRepository $db, ?int $id, array $input): int {
    $old = $id === null ? ['lead_order'=>'DOWN','dial_timeout'=>60,'campaign_cid'=>''] : $db->campaign($id);
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
        'max_channels'=>bot_cfg_int($input['max_channels'] ?? null, 1, 500, 'Canales'),
        'amd_enabled'=>(string)($input['amd_enabled'] ?? '') === '1' ? 1 : 0,
        'transfer_carrier_id'=>$carrier, 'transfer_destination'=>$destination, 'timezone'=>$timezone, 'lead_order'=>$order,
        'dial_timeout'=>bot_cfg_int($input['dial_timeout'] ?? $old['dial_timeout'], 1, 255, 'Tiempo de llamada'),
        'campaign_cid'=>bot_campaign_field_value('campaign_cid', $input['campaign_cid'] ?? $old['campaign_cid'])];
    return $db->saveCampaignConfig($id, $campaign, bot_cfg_headers($input['headers'] ?? []), bot_cfg_rules($input['retry_rules'] ?? []), bot_cfg_schedules($input['schedules'] ?? []));
}

/** Marks the campaign as running. The engine (campaign_engine.php) does the dialing; nothing is launched here. */
function bot_cfg_start(\ZynervoxQueries\BotIvrRepository $db, int $id, string $actor, ?array $origins = null): void {
    $config = $db->campaignConfig($id);
    $c = $config['campaign'];
    if (!(int)$c['active']) throw new RuntimeException('Activa la campaña antes de iniciar.');
    if ($c['call_origin'] === '') throw new RuntimeException('Elige el origen de llamada.');
    require_once __DIR__.'/../includes/Carriers.php';
    $origins ??= array_column(\Includes\Carriers::dialOrigins(), 'dial_prefix');
    if (!in_array($c['call_origin'], $origins, true)) throw new RuntimeException('El origen de llamada ya no está disponible.');
    if ($c['transfer_carrier_id'] === '' || $c['transfer_destination'] === '') throw new RuntimeException('Define el troncal y el destino de la transferencia.');
    if ($db->countEligibleLeads($id, (string)$c['lead_order']) < 1) throw new RuntimeException('No hay leads elegibles en las listas activas de la campaña.');
    $db->setDialerState($id, 'running', $actor);
}
function bot_cfg_stop(\ZynervoxQueries\BotIvrRepository $db, int $id, string $actor): void {
    $db->setDialerState($id, 'stopped', $actor); // calls in progress finish by themselves
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
