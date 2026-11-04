#!/usr/bin/php
<?php
declare(strict_types=1);
// AGI called once per answered call from the closing of the 7308 dialplan, after finish:
//   AGI(amd_audit_agi.php,audit,<call_id>,<amd_ms>,<audio_path>,<audio_ms>,<9 effective AMD parameters>,<contact_audio_path>)
//   Arguments 15..23 (zypad, dialplans 7314/7315): zypad_call_id, palabra, palabra_epoch_ms, palabra_audio_ms, preanswer_audio, preanswer_bytes, first_audio_epoch_ms, answer_epoch_ms, dial_mode. Called for every call, answered or not.
// It stores the facts of the AMD audit (ms to decide, recording, parameters used). It never fails the call: errors go to the log, exit 0.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
while (($line = fgets(STDIN)) !== false && trim($line) !== '') { /* AGI environment is not needed */ }
try {
    $args = array_slice($argv, 1); // argv[0] is the script: [action, call_id, ...]
    $action = $args[0] ?? '';
    if ($action !== 'audit') throw new InvalidArgumentException('Acción desconocida.');
    require_once __DIR__.'/../bot_ivr/db.php';
    require_once __DIR__.'/amd_audit.php';
    require_once __DIR__.'/zypad.php';
    $repo = bot_ivr_repository();
    try { marcador_amd_audit_agi_apply($repo, $args); } catch (Throwable $e) { error_log('ZV2 amd_audit_agi audit: '.$e->getMessage()); }
    try { marcador_zypad_apply($repo, $args); } catch (Throwable $e) { error_log('ZV2 amd_audit_agi zypad: '.$e->getMessage()); } // independent: a failure of one must not lose the other
} catch (Throwable $e) {
    error_log('ZV2 amd_audit_agi: '.get_class($e).': '.$e->getMessage());
}
exit(0);
