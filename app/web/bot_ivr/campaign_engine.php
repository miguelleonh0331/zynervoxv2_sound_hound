<?php
declare(strict_types=1);
// Dialer v1 engine. CLI only (systemd unit zynervox-dialer.service). Keeps the active calls of every running campaign at max_channels.
// php campaign_engine.php --dry-run [--campaign N]   prints what would be dialed; touches no state, opens no AMI session.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/campaign_config_service.php';
require_once __DIR__.'/../includes/AmiClient.php';
require_once __DIR__.'/../marcador/amd_audit.php';
require_once __DIR__.'/../marcador/zypad.php';

const BOT_ENGINE_BURST = 50;
const BOT_ENGINE_AMI_GRACE = 10;

/** Variables the zynervoxv2-marcador context reads (contract with Telefonia; the __ prefix makes them inherit). */
function bot_engine_variables(array $c, array $call, array $headers, array $amdActions = []): array {
    $vars = ['__ZV2_CALL_ID='.$call['call_id'], '__ZV2_LIST_ID='.$call['list_id'], '__ZV2_LEAD_ID='.$call['lead_id'], '__ZV2_CAMPAIGN_ID='.(int)$c['campaign_id']];
    $vars[] = '__ZV2_DIAL_TIMEOUT='.(int)$c['dial_timeout'];
    // Action per AMD result (transfer|hangup|retry); the dialplan applies it after AMD() and finish records the stage.
    foreach ($amdActions as $a) $vars[] = '__ZV2_AMD_ACTION_'.$a['result'].'='.$a['action'];
    // Without trunk and destination the campaign only detects (AMD) and hangs up: the dialplan sees no ZV2_XFER_TRUNK.
    if ($c['transfer_carrier_id'] !== '') array_push($vars, '__ZV2_XFER_TRUNK='.$c['transfer_carrier_id'], '__ZV2_XFER_DEST='.$c['transfer_destination']);
    if ((int)$c['amd_enabled']) { $vars[] = '__ZV2_AMD=1'; array_push($vars, ...marcador_amd_variables($c['amd_params'] ?? MARCADOR_AMD_DEFAULTS)); } // the 9 AMD() arguments, per campaign
    if ($headers) {
        $b64 = base64_encode(json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (strlen($b64) > 32768) throw new RuntimeException('Headers demasiado grandes para __ZV2_FORWARD_HEADERS.');
        $vars[] = '__ZV2_FORWARD_HEADERS='.$b64;
    }
    return $vars;
}

/**
 * Originate on a Local channel that runs the dialplan of the origin prefix (context zynervoxv2, Telefonia): Local/<origin><phone>@zynervoxv2/n.
 * The dialplan dials the carrier, so even unanswered calls report SIP and Q.850 through the finish AGI. The Originate timeout must exceed
 * the dialplan's Dial timeout (__ZV2_DIAL_TIMEOUT), or the Local hangs up first and the SIP code is lost. Peer and carrier prefix live in the dialplan route.
 */
function bot_engine_originate(\Includes\AmiClient $ami, array $c, array $call, array $headers): bool {
    if (!preg_match('/^[0-9]{1,20}$/D', (string)$c['call_origin']) || !preg_match('/^[0-9]{1,20}$/D', (string)$call['phone'])) throw new RuntimeException('Origen o teléfono inválido para marcar.');
    $request = ['Action'=>'Originate', 'Channel'=>'Local/'.$c['call_origin'].$call['phone'].'@zynervoxv2/n', 'Application'=>'Wait', 'Data'=>'600',
        'Async'=>'true', 'Timeout'=>(string)(((int)$c['dial_timeout'] + 5) * 1000), 'ChannelId'=>$call['call_id']];
    if ($c['campaign_cid'] !== '') $request['CallerID'] = $c['campaign_cid'];
    return ($ami->request($request, bot_engine_variables($c, $call, $headers, $c['amd_actions'] ?? []))['Response'] ?? '') === 'Success';
}

/**
 * The finish AGI (hangup handler of the Local leg) is the primary source of causes. AMI is only the fallback: with $pending the failure is
 * deferred BOT_ENGINE_AMI_GRACE seconds so the AGI's SIP code wins; without it (tests) it closes at once. $hangups caches Hangup causes by Uniqueid.
 */
function bot_engine_handle_event(\ZynervoxQueries\BotIvrRepository $repo, array $frame, array &$hangups, ?array &$pending = null): void {
    $id = (string)($frame['Uniqueid'] ?? '');
    if (!str_starts_with($id, 'zv2-')) return;
    $event = $frame['Event'] ?? '';
    if ($event === 'Hangup') {
        $hangups[$id] = ['cause'=>(int)($frame['Cause'] ?? 0), 'text'=>substr((string)($frame['Cause-txt'] ?? ''), 0, 100)];
        if (count($hangups) > 2000) array_shift($hangups);
    } elseif ($event === 'OriginateResponse' && ($frame['Response'] ?? '') === 'Failure') {
        $h = $hangups[$id] ?? ['cause'=>0, 'text'=>''];
        unset($hangups[$id]);
        [$dial, $disposition] = [4=>['NOANSWER','NOANSWER'], 5=>['BUSY','BUSY'], 8=>['CONGESTION','CONGESTION']][(int)($frame['Reason'] ?? 0)] ?? ['CHANUNAVAIL','FAILED'];
        $args = [$id, $dial, $h['cause'], trim('AMI Reason '.(int)($frame['Reason'] ?? 0).' '.$h['text']), $disposition];
        if ($pending === null) $repo->finishUnansweredCall(...$args);
        else $pending[$id] = [time() + BOT_ENGINE_AMI_GRACE, $args];
    }
}

/** Closes the deferred AMI failures whose grace expired; finishUnansweredCall ignores attempts the AGI already finished. */
function bot_engine_flush_pending(\ZynervoxQueries\BotIvrRepository $repo, array &$pending): void {
    foreach ($pending as $id=>[$due, $args]) {
        if ($due > time()) continue;
        unset($pending[$id]);
        $repo->finishUnansweredCall(...$args);
    }
}

function bot_engine_events_connect(array $config) {
    $config = \Includes\PbxAmi::validate($config);
    $socket = @stream_socket_client('tcp://'.(str_contains($config['host'], ':') ? '['.$config['host'].']' : $config['host']).':'.$config['port'], $errno, $error, 3);
    if (!$socket) throw new RuntimeException('No se pudo conectar a AMI (eventos).');
    stream_set_timeout($socket, 4);
    if (strpos((string)fgets($socket, 256), 'Asterisk Call Manager/') !== 0) throw new RuntimeException('El servidor no responde como AMI.');
    fwrite($socket, "Action: Login\r\nUsername: ".$config['username']."\r\nSecret: ".$config['secret']."\r\nEvents: call\r\n\r\n");
    if (!str_contains((string)fgets($socket, 256), 'Success')) throw new RuntimeException('AMI rechazó las credenciales (eventos).');
    stream_set_blocking($socket, false);
    return $socket;
}

/** Reads AMI frames for up to $seconds and hands each one to $handle. */
function bot_engine_events_pump($socket, string &$buffer, float $seconds, callable $handle): void {
    $deadline = microtime(true) + $seconds;
    do {
        while (($end = strpos($buffer, "\r\n\r\n")) !== false) {
            $frame = [];
            foreach (explode("\r\n", substr($buffer, 0, $end)) as $line) { $pair = explode(':', $line, 2); if (count($pair) === 2) $frame[trim($pair[0])] = trim($pair[1]); }
            $buffer = substr($buffer, $end + 4);
            if (isset($frame['Event'])) $handle($frame);
        }
        $read = [$socket]; $write = $except = [];
        $left = max(0.0, $deadline - microtime(true));
        if (@stream_select($read, $write, $except, (int)$left, (int)(($left - (int)$left) * 1000000)) === false) throw new RuntimeException('Se perdió la conexión AMI (eventos).');
        if ($read) {
            $chunk = fread($socket, 65536);
            if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException('Se perdió la conexión AMI (eventos).');
            $buffer .= $chunk;
            if (strlen($buffer) > 1048576) throw new RuntimeException('Buffer AMI excedido.');
        }
    } while (microtime(true) < $deadline);
}

/**
 * One pass over the campaigns. $originate(campaign, call, headers): bool is null in dry-run.
 * Returns one report row per campaign.
 */
function bot_engine_tick(\ZynervoxQueries\BotIvrRepository $repo, ?callable $originate, ?int $only, DateTimeInterface $now): array {
    $dry = $originate === null;
    $report = [];
    foreach ($repo->dialerCampaignIds($only) as $id) {
        $row = ['campaign_id'=>$id];
        try {
            $config = $repo->campaignConfig($id);
            $c = $config['campaign'] + ['amd_actions'=>bot_cfg_amd_actions($config['amd_actions']), 'amd_params'=>marcador_amd_global($repo)];
            $row['state'] = $c['dialer_state'];
            if (!$dry && ($c['dialer_state'] !== 'running' || !(int)$c['active'])) { $row['skipped'] = 'detenida'; $report[] = $row; continue; }
            if (!bot_cfg_in_window($c, $config['schedules'], $now)) { $row['skipped'] = 'fuera de franja'; $report[] = $row; continue; }
            if ($c['call_origin'] === '' || ($c['transfer_carrier_id'] === '') !== ($c['transfer_destination'] === '')) { $row['skipped'] = 'configuración incompleta'; $report[] = $row; continue; }
            // Channels: per list (enforced in the candidate query, re-read every pass) and a server-wide cap. The campaign max_channels is not used.
            $active = $repo->campaignActiveCalls($id);
            $maxTotal = $repo->dialerMaxTotalChannels();
            $slots = min(BOT_ENGINE_BURST, $maxTotal - $repo->openCallsTotal());
            $row += ['active'=>$active, 'max_total_channels'=>$maxTotal, 'slots'=>max(0, $slots)];
            if ($slots < 1) { $report[] = $row; continue; }
            $resolve = fn(array $lead) => bot_cfg_resolve_headers($config['headers'], $lead);
            if ($dry) {
                $row['would_dial'] = [];
                $free = [];
                foreach ($repo->previewCampaignLeads($id, (string)$c['lead_order'], $slots) as $lead) {
                    $free[$lead['list_id']] ??= (int)$lead['list_free'];
                    if ($free[$lead['list_id']]-- < 1) continue;
                    try { $row['would_dial'][] = ['lead_id'=>(int)$lead['lead_id'], 'phone'=>$lead['phone'], 'headers'=>$resolve($lead)]; }
                    catch (RuntimeException $e) { $row['would_reject'][] = ['lead_id'=>(int)$lead['lead_id'], 'reason'=>$e->getMessage()]; }
                }
                $report[] = $row; continue;
            }
            $row['launched'] = 0;
            for ($i = 0; $i < $slots; $i++) {
                $call = $repo->claimCampaignLead($id, (string)$c['lead_order'], 'zv2-'.bin2hex(random_bytes(12)), $resolve);
                if (!$call) break;
                $ok = false;
                try { $ok = $originate($c, $call, $call['payload']); }
                catch (Throwable $e) { $repo->rejectCampaignClaim($call['call_id']); throw $e; }
                // A lost/ambiguous acknowledgement is never retried: the attempt stays auditable.
                if (!$ok) { $repo->rejectCampaignClaim($call['call_id']); $row['error'] = 'AMI rechazó el originate'; break; }
                if ((int)$c['amd_enabled']) marcador_amd_audit_open($repo, $call, $id, $c['amd_params']); // snapshot of the parameters this call used
                $row['launched']++;
            }
        } catch (Throwable $e) {
            $row['error'] = get_class($e).': '.$e->getMessage();
            error_log('ZV2 dialer campaign '.$id.': '.$row['error']);
        }
        $report[] = $row;
    }
    return $report;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
ini_set('display_errors', '0'); set_time_limit(0); umask(0027);
$options = getopt('', ['dry-run', 'once', 'campaign:', 'interval:', 'lock:', 'report', 'limit:']);
$dry = isset($options['dry-run']);
$only = isset($options['campaign']) ? filter_var($options['campaign'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : null;
if (isset($options['campaign']) && !$only) { fwrite(STDERR, "--campaign inválido\n"); exit(2); }
if ($only !== null && !$dry && !isset($options['report'])) { fwrite(STDERR, "--campaign solo se admite con --dry-run\n"); exit(2); }
$interval = max(1, (int)($options['interval'] ?? 1));
$ami = null; $lease = null; $events = null; $buffer = ''; $hangups = []; $pending = [];
try {
    $repo = bot_ivr_repository();
    if (isset($options['report'])) { // read-only: AMD result and hangup causes per attempt
        echo json_encode(bot_cfg_attempts_report($repo, $only, (int)($options['limit'] ?? 50)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n"; exit(0);
    }
    if (!$dry) {
        $lease = fopen((string)($options['lock'] ?? '/run/zynervox-dialer/engine.lock'), 'c');
        if (!$lease || !flock($lease, LOCK_EX | LOCK_NB)) throw new RuntimeException('Motor duplicado o sin directorio de ejecución.');
    }
    $lastPing = time(); $lastBeat = 0; $lastMaint = 0;
    do {
        $originate = null;
        if (!$dry) {
            $ami ??= new \Includes\AmiClient(\Includes\PbxAmi::load());
            if (time() - $lastPing >= 15) { $ami->ping(); $lastPing = time(); }
            $events ??= bot_engine_events_connect(\Includes\PbxAmi::load());
            $originate = fn(array $c, array $call, array $headers) => bot_engine_originate($ami, $c, $call, $headers);
        }
        if (!$dry && time() - $lastBeat >= 5) { $repo->touchDialerHeartbeat(); $lastBeat = time(); }
        if (!$dry && time() - $lastMaint >= 10) { try { marcador_list_summary_maintain($repo); $repo->stampDialModes(MARCADOR_DIAL_MODES); } catch (Throwable $e) { error_log('ZV2 summary: '.$e->getMessage()); } $lastMaint = time(); } // summaries, finished lists; never stops the dialing
        $report = bot_engine_tick($repo, $originate, $only, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($dry) echo json_encode(['dry_run'=>true, 'campaigns'=>$report], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
        elseif (array_filter($report, fn($r) => !empty($r['launched']) || !empty($r['error']))) echo json_encode($report, JSON_UNESCAPED_UNICODE)."\n";
        if (!$dry && array_filter($report, fn($r) => !empty($r['error']))) { $ami?->close(); $ami = null; }
        if ($dry || isset($options['once'])) break;
        $events ? bot_engine_events_pump($events, $buffer, (float)$interval, function(array $frame) use($repo, &$hangups, &$pending) { bot_engine_handle_event($repo, $frame, $hangups, $pending); }) : sleep($interval);
        bot_engine_flush_pending($repo, $pending);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, 'ZV2 dialer engine failed: '.get_class($e).': '.$e->getMessage()."\n"); exit(1);
} finally { $ami?->close(); if (is_resource($events)) fclose($events); if (is_resource($lease)) fclose($lease); }
