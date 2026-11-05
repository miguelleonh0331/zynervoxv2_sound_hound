#!/usr/bin/php
<?php
declare(strict_types=1);
// AGI called once per answered call from the closing of the 7308 dialplan, after finish:
//   AGI(amd_audit_agi.php,audit,<call_id>,<amd_ms>,<audio_path>,<audio_ms>,<9 effective AMD parameters>,<contact_audio_path>)
//   AGI(amd_audit_agi.php,zypad,<call_id>,<detectado_por>,<momento>,<palabra>,<segundo>,<corte_por>,<preanswer_audio>,<zypad_call_id>[,<palabra_epoch_ms>,<first_audio_epoch_ms>,<answer_epoch_ms>,<preanswer_bytes>])  (dialplans 7314/7315, every call)
//   AGI(amd_audit_agi.php,check,<digits>,<campaign_id>)  blacklist lookup before the Dial (no campaign id or option off in the campaign: NO): sets ZV2_BLOQ = NO | SI | ERROR (ERROR: database down, table missing or no digits; the dialplan must not dial)
//   AGI(amd_audit_agi.php,blocked,<call_id>[,ERROR])  (ERROR: the check itself failed, the attempt is a failure that can be retried, not a definitive «Bloqueado»)
//   AGI(amd_audit_agi.php,blocked,<call_id>)  the dialplan found the number in zynervox_blacklist and did not call: the attempt, if there is one, ends as «Bloqueado»
//   (older format) audit arguments 15..23 (zypad, dialplans 7314/7315): zypad_call_id, palabra, palabra_epoch_ms, palabra_audio_ms, preanswer_audio, preanswer_bytes, first_audio_epoch_ms, answer_epoch_ms, dial_mode. Called for every call, answered or not.
// It stores the facts of the AMD audit (ms to decide, recording, parameters used). It never fails the call: errors go to the log, exit 0.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('default_socket_timeout', '2'); // a dead database must not hold the calls (blacklist check)
while (($line = fgets(STDIN)) !== false && trim($line) !== '') { /* AGI environment is not needed */ }
try {
    $args = array_slice($argv, 1); // argv[0] is the script: [action, call_id, ...]
    $action = $args[0] ?? '';
    if (!in_array($action, ['audit', 'zypad', 'blocked', 'check'], true)) throw new InvalidArgumentException('Acción desconocida.');
    require_once __DIR__.'/../bot_ivr/db.php';
    require_once __DIR__.'/amd_audit.php';
    require_once __DIR__.'/zypad.php';
    $repo = null;
    try { $repo = bot_ivr_repository(); } catch (Throwable $e) { if ($action !== 'check') throw $e; error_log('ZV2 amd_audit_agi check: '.$e->getMessage()); }
    if ($action === 'check') {
        $answer = 'ERROR';
        try { require_once __DIR__.'/blacklist.php'; if ($repo !== null) $answer = marcador_blacklist_check($repo, (string)($args[1] ?? ''), (string)($args[2] ?? '')); } catch (Throwable $e) { error_log('ZV2 amd_audit_agi check: '.$e->getMessage()); }
        echo 'SET VARIABLE ZV2_BLOQ '.$answer."\n"; fflush(STDOUT); $answered = true; fgets(STDIN); // the AGI answer line
    }
    elseif ($action === 'blocked') { if (preg_match('/^[0-9A-Za-z._-]{1,80}$/D', (string)($args[1] ?? ''))) $repo->markCallBlocked((string)$args[1], strtoupper((string)($args[2] ?? '')) === 'ERROR'); }
    elseif ($action === 'zypad') marcador_zypad_closing($repo, $args);
    else {
        try { marcador_amd_audit_agi_apply($repo, $args); } catch (Throwable $e) { error_log('ZV2 amd_audit_agi audit: '.$e->getMessage()); }
        // Arguments 15..23 only exist in the older single-action format; without them the zypad action owns those columns and must not be wiped.
        if (count($args) > 15) try { marcador_zypad_apply($repo, $args); } catch (Throwable $e) { error_log('ZV2 amd_audit_agi zypad: '.$e->getMessage()); }
    }
} catch (Throwable $e) {
    error_log('ZV2 amd_audit_agi: '.get_class($e).': '.$e->getMessage());
    if (($argv[1] ?? '') === 'check' && empty($answered)) { echo "SET VARIABLE ZV2_BLOQ ERROR\n"; fflush(STDOUT); } // the dialplan always gets an answer: anything but NO means do not dial
}
exit(0);
