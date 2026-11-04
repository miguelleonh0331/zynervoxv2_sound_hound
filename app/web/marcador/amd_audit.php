<?php
declare(strict_types=1);
// Marcador: AMD parameters per campaign and AMD audit (migration 018). Own folder, outside bot_ivr/.
// The audit row is opened by the engine when a call is launched, the answered ones are completed by marcador_amd_audit_sync(),
// and the speech-to-text pass runs later in marcador/amd_stt_batch.php. Nothing here runs during a call.

/** First approved global version (user, 04/11/2026): 4000,2500,800,8000,100,50,4,256,5000. Used only when migration 019 is not applied. Order = the AMD() argument order. */
const MARCADOR_AMD_DEFAULTS = ['initial_silence'=>4000, 'greeting'=>2500, 'after_greeting_silence'=>800, 'total_analysis_time'=>8000, 'minimum_word_length'=>100,
    'between_words_silence'=>50, 'maximum_number_of_words'=>4, 'silence_threshold'=>256, 'maximum_word_length'=>5000];
/** Channel variables sent with the originate (agreed with Telefonia 04/11: numeric, one per AMD() argument; the dialplan falls back to the current value if one is missing). */
const MARCADOR_AMD_VARIABLES = ['initial_silence'=>'ZV2_AMD_INITIAL_SILENCE', 'greeting'=>'ZV2_AMD_GREETING', 'after_greeting_silence'=>'ZV2_AMD_AFTER_GREETING',
    'total_analysis_time'=>'ZV2_AMD_TOTAL_TIME', 'minimum_word_length'=>'ZV2_AMD_MIN_WORD', 'between_words_silence'=>'ZV2_AMD_BETWEEN_WORDS',
    'maximum_number_of_words'=>'ZV2_AMD_MAX_WORDS', 'silence_threshold'=>'ZV2_AMD_SILENCE_THRESHOLD', 'maximum_word_length'=>'ZV2_AMD_MAX_WORD_LENGTH'];
/** Recordings of the marcador live here; the delivery AGI rejects any other path. */
const MARCADOR_RECORDINGS_DIR = '/var/lib/zynervoxv2/asterisk/recordings/marcador/';
const MARCADOR_AMD_LABELS = ['initial_silence'=>'Silencio inicial (ms)', 'greeting'=>'Saludo (ms)', 'after_greeting_silence'=>'Silencio tras el saludo (ms)', 'total_analysis_time'=>'Tiempo total de análisis (ms)',
    'minimum_word_length'=>'Longitud mínima de palabra (ms)', 'between_words_silence'=>'Silencio entre palabras (ms)', 'maximum_number_of_words'=>'Máximo de palabras', 'silence_threshold'=>'Umbral de silencio', 'maximum_word_length'=>'Longitud máxima de palabra (ms)'];

/** Global AMD parameters (one set for every campaign): the current version, plus 'version_id'. Falls back to the first approved values (version_id null). */
function marcador_amd_global(\ZynervoxQueries\BotIvrRepository $repo): array {
    $cur = $repo->amdGlobalCurrent();
    if ($cur === null) return MARCADOR_AMD_DEFAULTS + ['version_id'=>null];
    $out = []; foreach (MARCADOR_AMD_DEFAULTS as $k=>$d) $out[$k] = (int)$cur[$k];
    return $out + ['version_id'=>(int)$cur['version_id'], 'created_at'=>$cur['created_at'], 'created_by'=>$cur['created_by'], 'note'=>$cur['note']];
}

/** Validates and saves a NEW global version (missing parameters keep their current value). Returns the new version (as marcador_amd_global). */
function marcador_amd_global_save(\ZynervoxQueries\BotIvrRepository $repo, array $input, string $author, string $note = ''): array {
    $current = marcador_amd_global($repo);
    $ranges = ['maximum_number_of_words'=>[1, 50], 'silence_threshold'=>[0, 32767]];
    $new = []; foreach (MARCADOR_AMD_DEFAULTS as $k=>$d) $new[$k] = $current[$k];
    foreach (MARCADOR_AMD_DEFAULTS as $key=>$default) {
        if (!array_key_exists($key, $input)) continue;
        [$min, $max] = $ranges[$key] ?? [0, 60000];
        $v = is_scalar($input[$key]) ? filter_var($input[$key], FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min, 'max_range'=>$max]]) : false;
        if ($v === false) throw new RuntimeException(MARCADOR_AMD_LABELS[$key].': debe ser un entero entre '.$min.' y '.$max.'.');
        $new[$key] = $v;
    }
    if (trim($author) === '') throw new RuntimeException('Indica quién guarda el cambio.');
    if (array_diff_assoc($new, array_intersect_key($current, $new)) === []) throw new RuntimeException('No hay cambios respecto a la versión actual.');
    $repo->amdGlobalAddVersion($new, $author, $note, $current['version_id']);
    return marcador_amd_global($repo);
}

/** Versions, newest first: version_id, created_at (UTC), created_by, note, from_version_id, the 9 values and is_current. */
function marcador_amd_global_history(\ZynervoxQueries\BotIvrRepository $repo, int $limit = 50): array {
    $cur = marcador_amd_global($repo)['version_id'];
    return array_map(fn($v) => $v + ['is_current'=>(int)$v['version_id'] === $cur], $repo->amdGlobalVersions($limit));
}

/** Goes back to an older version by creating a NEW version with its values (the history stays linear). */
function marcador_amd_global_rollback(\ZynervoxQueries\BotIvrRepository $repo, int $versionId, string $author): array {
    $old = $repo->amdGlobalVersion($versionId);
    if ($old === null) throw new RuntimeException('La versión AMD no existe.');
    $values = []; foreach (MARCADOR_AMD_DEFAULTS as $k=>$d) $values[$k] = (int)$old[$k];
    if (trim($author) === '') throw new RuntimeException('Indica quién guarda el cambio.');
    if (marcador_amd_global($repo)['version_id'] === $versionId) throw new RuntimeException('Esa ya es la versión actual.');
    $repo->amdGlobalAddVersion($values, $author, 'Vuelta a la versión '.$versionId, $versionId);
    return marcador_amd_global($repo);
}

/** Channel variables (with the __ prefix so the Local legs inherit them) for the originate. */
function marcador_amd_variables(array $params): array {
    $out = [];
    foreach (MARCADOR_AMD_VARIABLES as $key=>$var) $out[] = '__'.$var.'='.(int)$params[$key];
    return $out;
}

/** Snapshot of the parameters in the audit row of a launched call. Never lets an audit failure stop a call. */
function marcador_amd_audit_open(\ZynervoxQueries\BotIvrRepository $repo, array $call, int $campaignId, array $params): void {
    try { $repo->openAmdAudit($call, $campaignId, $params, $params['version_id'] ?? null); }
    catch (Throwable $e) { error_log('ZV2 amd_audit_open: '.$e->getMessage()); }
}

/** Entry point for facts that arrive with the call or later (decided_ms, audio_path, audio_seconds). Used by whoever receives the event (BotIVR AGI path). */
function marcador_amd_audit_record(\ZynervoxQueries\BotIvrRepository $repo, string $callId, array $data): void {
    $clean = [];
    if (isset($data['decided_ms'])) $clean['decided_ms'] = max(0, min(600000, (int)$data['decided_ms']));
    if (isset($data['audio_path']) && is_string($data['audio_path']) && $data['audio_path'] !== '' && !str_contains($data['audio_path'], '..') && strlen($data['audio_path']) <= 512) $clean['audio_path'] = $data['audio_path'];
    if (isset($data['contact_path']) && is_string($data['contact_path']) && $data['contact_path'] !== '' && !str_contains($data['contact_path'], '..') && strlen($data['contact_path']) <= 512) $clean['contact_path'] = $data['contact_path'];
    if (isset($data['audio_seconds'])) $clean['audio_seconds'] = max(0.0, min(86400.0, (float)$data['audio_seconds']));
    foreach (array_keys(MARCADOR_AMD_DEFAULTS) as $k) if (isset($data['params'][$k]) && ctype_digit((string)$data['params'][$k])) $clean[$k] = (int)$data['params'][$k];
    if (isset($data['reviewed_class'])) $clean['reviewed_class'] = substr(preg_replace('/[^A-Za-z_]/', '', (string)$data['reviewed_class']) ?? '', 0, 20);
    $repo->recordAmdAudit($callId, $clean);
}

/** After the calls: remove rows of unanswered calls, complete the answered ones. */
function marcador_amd_audit_sync(\ZynervoxQueries\BotIvrRepository $repo): array { return $repo->syncAmdAudit(); }

/**
 * Delivery from the dialplan: argv = [audit, call_id, amd_ms, audio_path, audio_ms, initial, greeting, after, total, minword, between, maxwords, silthr, maxwordlen, contact_path]
 * (the 9 values are the ones AMD() really used). Returns what was stored. Validates everything: it never trusts the channel.
 */
function marcador_amd_audit_agi_apply(\ZynervoxQueries\BotIvrRepository $repo, array $args): array {
    $callId = (string)($args[1] ?? '');
    if (!preg_match('/^[0-9A-Za-z._-]{1,80}$/D', $callId)) throw new InvalidArgumentException('call_id inválido.');
    $data = [];
    if (preg_match('/^[0-9]{1,7}$/D', (string)($args[2] ?? ''))) $data['decided_ms'] = (int)$args[2];
    $path = (string)($args[3] ?? '');
    if ($path !== '') {
        if (!str_starts_with($path, MARCADOR_RECORDINGS_DIR) || !preg_match('/^[0-9A-Za-z._\/-]+\.wav$/D', $path) || str_contains($path, '..')) throw new InvalidArgumentException('Ruta de audio fuera de la carpeta del marcador.');
        $data['audio_path'] = $path;
        if (preg_match('/^[0-9]{1,9}$/D', (string)($args[4] ?? '')) && (int)$args[4] > 0) $data['audio_seconds'] = round((int)$args[4] / 1000, 2);
    }
    $contact = (string)($args[14] ?? ''); // audio received from the contact only (dialplan v7), kept to rerun AMD without calling
    if ($contact !== '') {
        if (!str_starts_with($contact, MARCADOR_RECORDINGS_DIR) || !preg_match('/^[0-9A-Za-z._\/-]+\.wav$/D', $contact) || str_contains($contact, '..')) throw new InvalidArgumentException('Ruta de audio del contacto fuera de la carpeta del marcador.');
        $data['contact_path'] = $contact;
    }
    $keys = array_keys(MARCADOR_AMD_DEFAULTS); $params = [];
    foreach ($keys as $i=>$k) if (preg_match('/^[0-9]{1,6}$/D', (string)($args[5 + $i] ?? ''))) $params[$k] = (int)$args[5 + $i];
    if (count($params) === count($keys)) $data['params'] = $params;
    marcador_amd_audit_record($repo, $callId, $data);
    return ['call_id'=>$callId] + array_diff_key($data, ['params'=>1]) + ['params_stored'=>isset($data['params'])];
}
