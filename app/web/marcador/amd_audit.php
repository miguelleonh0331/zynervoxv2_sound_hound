<?php
declare(strict_types=1);
// Marcador: AMD parameters per campaign and AMD audit (migration 018). Own folder, outside bot_ivr/.
// The audit row is opened by the engine when a call is launched, the answered ones are completed by marcador_amd_audit_sync(),
// and the speech-to-text pass runs later in marcador/amd_stt_batch.php. Nothing here runs during a call.

/** Current values (amd.conf plus totalAnalysisTime 8000). Order = the AMD() argument order. */
const MARCADOR_AMD_DEFAULTS = ['initial_silence'=>2500, 'greeting'=>1500, 'after_greeting_silence'=>800, 'total_analysis_time'=>8000, 'minimum_word_length'=>100,
    'between_words_silence'=>50, 'maximum_number_of_words'=>2, 'silence_threshold'=>256, 'maximum_word_length'=>5000];
/** Channel variables sent with the originate (agreed with Telefonia, one per AMD() argument). */
const MARCADOR_AMD_VARIABLES = ['initial_silence'=>'ZV2_AMD_INITIAL_SILENCE', 'greeting'=>'ZV2_AMD_GREETING', 'after_greeting_silence'=>'ZV2_AMD_AFTER_GREETING_SILENCE',
    'total_analysis_time'=>'ZV2_AMD_TOTAL_ANALYSIS_TIME', 'minimum_word_length'=>'ZV2_AMD_MIN_WORD_LENGTH', 'between_words_silence'=>'ZV2_AMD_BETWEEN_WORDS_SILENCE',
    'maximum_number_of_words'=>'ZV2_AMD_MAX_NUMBER_OF_WORDS', 'silence_threshold'=>'ZV2_AMD_SILENCE_THRESHOLD', 'maximum_word_length'=>'ZV2_AMD_MAX_WORD_LENGTH'];
const MARCADOR_AMD_LABELS = ['initial_silence'=>'Silencio inicial (ms)', 'greeting'=>'Saludo (ms)', 'after_greeting_silence'=>'Silencio tras el saludo (ms)', 'total_analysis_time'=>'Tiempo total de análisis (ms)',
    'minimum_word_length'=>'Longitud mínima de palabra (ms)', 'between_words_silence'=>'Silencio entre palabras (ms)', 'maximum_number_of_words'=>'Máximo de palabras', 'silence_threshold'=>'Umbral de silencio', 'maximum_word_length'=>'Longitud máxima de palabra (ms)'];

/** Parameters of a campaign: stored values over the defaults. */
function marcador_amd_params(\ZynervoxQueries\BotIvrRepository $repo, int $campaignId): array {
    return array_merge(MARCADOR_AMD_DEFAULTS, $repo->campaignAmdParams($campaignId));
}

/** Validates and saves the 9 parameters (missing ones keep their current value). Returns the saved set. */
function marcador_amd_params_save(\ZynervoxQueries\BotIvrRepository $repo, int $campaignId, array $input): array {
    $current = marcador_amd_params($repo, $campaignId);
    $ranges = ['maximum_number_of_words'=>[1, 50], 'silence_threshold'=>[0, 32767]];
    foreach (MARCADOR_AMD_DEFAULTS as $key=>$default) {
        if (!array_key_exists($key, $input)) continue;
        [$min, $max] = $ranges[$key] ?? [0, 60000];
        $v = is_scalar($input[$key]) ? filter_var($input[$key], FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min, 'max_range'=>$max]]) : false;
        if ($v === false) throw new RuntimeException(MARCADOR_AMD_LABELS[$key].': debe ser un entero entre '.$min.' y '.$max.'.');
        $current[$key] = $v;
    }
    $repo->setCampaignAmdParams($campaignId, $current);
    return $current;
}

/** Channel variables (with the __ prefix so the Local legs inherit them) for the originate. */
function marcador_amd_variables(array $params): array {
    $out = [];
    foreach (MARCADOR_AMD_VARIABLES as $key=>$var) $out[] = '__'.$var.'='.(int)$params[$key];
    return $out;
}

/** Snapshot of the parameters in the audit row of a launched call. Never lets an audit failure stop a call. */
function marcador_amd_audit_open(\ZynervoxQueries\BotIvrRepository $repo, array $call, int $campaignId, array $params): void {
    try { $repo->openAmdAudit($call, $campaignId, $params); }
    catch (Throwable $e) { error_log('ZV2 amd_audit_open: '.$e->getMessage()); }
}

/** Entry point for facts that arrive with the call or later (decided_ms, audio_path, audio_seconds). Used by whoever receives the event (BotIVR AGI path). */
function marcador_amd_audit_record(\ZynervoxQueries\BotIvrRepository $repo, string $callId, array $data): void {
    $clean = [];
    if (isset($data['decided_ms'])) $clean['decided_ms'] = max(0, min(600000, (int)$data['decided_ms']));
    if (isset($data['audio_path']) && is_string($data['audio_path']) && $data['audio_path'] !== '' && !str_contains($data['audio_path'], '..') && strlen($data['audio_path']) <= 512) $clean['audio_path'] = $data['audio_path'];
    if (isset($data['audio_seconds'])) $clean['audio_seconds'] = max(0.0, min(86400.0, (float)$data['audio_seconds']));
    if (isset($data['reviewed_class'])) $clean['reviewed_class'] = substr(preg_replace('/[^A-Za-z_]/', '', (string)$data['reviewed_class']) ?? '', 0, 20);
    $repo->recordAmdAudit($callId, $clean);
}

/** After the calls: remove rows of unanswered calls, complete the answered ones. */
function marcador_amd_audit_sync(\ZynervoxQueries\BotIvrRepository $repo): array { return $repo->syncAmdAudit(); }
