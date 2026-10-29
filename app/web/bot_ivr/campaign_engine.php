<?php
declare(strict_types=1);
// Dialer v1 engine. CLI only (systemd unit zynervox-dialer.service). Keeps the active calls of every running campaign at max_channels.
// php campaign_engine.php --dry-run [--campaign N]   prints what would be dialed; touches no state, opens no AMI session.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/campaign_config_service.php';
require_once __DIR__.'/../includes/AmiClient.php';

const BOT_ENGINE_BURST = 50;

/** Variables the marcador context in the dialplan reads (all inherited with the __ prefix). */
function bot_engine_variables(array $c, array $call, array $headers): array {
    $vars = ['__ZV2_DIALER=1', '__ZV2_CAMPAIGN_ID='.(int)$c['campaign_id'], '__ZV2_LIST_ID='.$call['list_id'], '__ZV2_LEAD_ID='.$call['lead_id'],
        '__ZV2_AMD='.((int)$c['amd_enabled'] ? '1' : '0'), '__ZV2_TRANSFER_CARRIER='.$c['transfer_carrier_id'], '__ZV2_TRANSFER_DEST='.$c['transfer_destination']];
    if ($headers) {
        $b64 = base64_encode(json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (strlen($b64) > 32768) throw new RuntimeException('Headers demasiado grandes para __ZV2_FORWARD_HEADERS.');
        $vars[] = '__ZV2_FORWARD_HEADERS='.$b64;
    }
    return $vars;
}

function bot_engine_originate(\Includes\AmiClient $ami, array $c, array $call, array $headers, string $context): bool {
    if (!preg_match('/^[0-9]{1,20}$/D', (string)$c['call_origin']) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $context)) throw new RuntimeException('Destino AMI invalido.');
    $request = ['Action'=>'Originate', 'Channel'=>'Local/'.$c['call_origin'].$call['phone'].'@'.$context.'/n', 'Application'=>'Wait', 'Data'=>'600',
        'Timeout'=>(string)((int)$c['dial_timeout'] * 1000), 'Async'=>'true', 'ChannelId'=>$call['call_id'].'-origin', 'OtherChannelId'=>$call['call_id']];
    if ($c['campaign_cid'] !== '') $request['CallerID'] = $c['campaign_cid'];
    return ($ami->request($request, bot_engine_variables($c, $call, $headers))['Response'] ?? '') === 'Success';
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
            $c = $config['campaign'];
            $row['state'] = $c['dialer_state'];
            if (!$dry && ($c['dialer_state'] !== 'running' || !(int)$c['active'])) { $row['skipped'] = 'detenida'; $report[] = $row; continue; }
            if (!bot_cfg_in_window($c, $config['schedules'], $now)) { $row['skipped'] = 'fuera de franja'; $report[] = $row; continue; }
            if ($c['call_origin'] === '' || $c['transfer_carrier_id'] === '' || $c['transfer_destination'] === '') { $row['skipped'] = 'configuración incompleta'; $report[] = $row; continue; }
            $active = $repo->campaignActiveCalls($id);
            $slots = min(BOT_ENGINE_BURST, (int)$c['max_channels'] - $active);
            $row += ['active'=>$active, 'max_channels'=>(int)$c['max_channels'], 'slots'=>max(0, $slots)];
            if ($slots < 1) { $report[] = $row; continue; }
            $resolve = fn(array $lead) => bot_cfg_resolve_headers($config['headers'], $lead);
            if ($dry) {
                $row['would_dial'] = [];
                foreach ($repo->previewCampaignLeads($id, (string)$c['lead_order'], $slots) as $lead) {
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
$options = getopt('', ['dry-run', 'once', 'campaign:', 'context:', 'interval:', 'lock:']);
$dry = isset($options['dry-run']);
$only = isset($options['campaign']) ? filter_var($options['campaign'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : null;
if (isset($options['campaign']) && !$only) { fwrite(STDERR, "--campaign inválido\n"); exit(2); }
if ($only !== null && !$dry) { fwrite(STDERR, "--campaign solo se admite con --dry-run\n"); exit(2); }
$context = (string)($options['context'] ?? 'zynervoxv2-dialer');
$interval = max(1, (int)($options['interval'] ?? 1));
$ami = null; $lease = null;
try {
    $repo = bot_ivr_repository();
    if (!$dry) {
        $lease = fopen((string)($options['lock'] ?? '/run/zynervox-dialer/engine.lock'), 'c');
        if (!$lease || !flock($lease, LOCK_EX | LOCK_NB)) throw new RuntimeException('Motor duplicado o sin directorio de ejecución.');
    }
    $lastPing = time();
    do {
        $originate = null;
        if (!$dry) {
            $ami ??= new \Includes\AmiClient(\Includes\PbxAmi::load());
            if (time() - $lastPing >= 15) { $ami->ping(); $lastPing = time(); }
            $originate = fn(array $c, array $call, array $headers) => bot_engine_originate($ami, $c, $call, $headers, $context);
        }
        $report = bot_engine_tick($repo, $originate, $only, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($dry) echo json_encode(['dry_run'=>true, 'campaigns'=>$report], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
        elseif (array_filter($report, fn($r) => !empty($r['launched']) || !empty($r['error']))) echo json_encode($report, JSON_UNESCAPED_UNICODE)."\n";
        if (!$dry && array_filter($report, fn($r) => !empty($r['error']))) { $ami?->close(); $ami = null; }
        if ($dry || isset($options['once'])) break;
        sleep($interval);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, 'ZV2 dialer engine failed: '.get_class($e).': '.$e->getMessage()."\n"); exit(1);
} finally { $ami?->close(); if (is_resource($lease)) fclose($lease); }
