<?php
declare(strict_types=1);
// Marcador: zypad (migration 021). Dial mode per campaign origin, word catalog and the closing delivery from the dialplan. Nothing here runs during a call.

/** Campaign origin (call_origin) => dial mode. Any other origin counts as AMD. 7315 is the simulated 7314. */
const MARCADOR_DIAL_MODES = ['7308'=>'AMD', '7309'=>'AMD', '7314'=>'ZYPAD_SOMBRA', '7315'=>'ZYPAD_SOMBRA', '7312'=>'ZYPAD_CORTE'];
const MARCADOR_DIAL_MODE_LABELS = ['AMD'=>'Solo AMD', 'ZYPAD_SOMBRA'=>'Zypad en sombra + AMD', 'ZYPAD_CORTE'=>'Zypad con corte + AMD'];

/** Dial origins offered on the Campañas screen. Both include AMD, so saving a campaign with one of them always leaves amd_enabled = 1. */
const MARCADOR_OFFERED_DIAL_ORIGINS = ['7308', '7314'];

function marcador_dial_mode(string $origin): string { return MARCADOR_DIAL_MODES[$origin] ?? 'AMD'; }

/**
 * Closing delivery, extra arguments of the AGI "audit" action (index 0 = "audit", 1 = call_id; Telefonia format, contract zypad v1):
 * 15 zypad_call_id, 16 palabra, 17 palabra_epoch_ms, 18 palabra_audio_ms, 19 preanswer_audio (SI|NO), 20 preanswer_bytes, 21 first_audio_epoch_ms, 22 answer_epoch_ms, 23 dial_mode.
 * Every field may be empty (zypad gave no data): the attempt keeps its dial_mode (deduced from the campaign origin; argument 23 is not trusted) and the rest stays empty.
 * Derived here: detectado_por (ZYPAD if a word appeared; else AMD if the attempt is MACHINE), momento (PRE if the word came before the answer, else POST), segundo (audio seconds of the word),
 * corte_por (NINGUNO in shadow mode).
 */
function marcador_zypad_apply(\ZynervoxQueries\BotIvrRepository $repo, array $args): array {
    $callId = (string)($args[1] ?? '');
    if (!preg_match('/^[0-9A-Za-z._-]{1,80}$/D', $callId)) throw new InvalidArgumentException('call_id inválido.');
    $num = fn(int $i) => preg_match('/^[0-9]{1,15}$/D', trim((string)($args[$i] ?? ''))) ? (int)trim((string)$args[$i]) : null;
    $word = mb_strtolower(trim((string)($args[16] ?? '')), 'UTF-8');
    if (mb_strlen($word, 'UTF-8') > 80 || preg_match('/[\x00-\x1f]/', $word)) $word = '';
    $zid = preg_match('/^[0-9A-Za-z._-]{1,80}$/D', (string)($args[15] ?? '')) ? (string)$args[15] : '';
    $pre = strtoupper(trim((string)($args[19] ?? '')));
    $d = ['zypad_call_id'=>$zid, 'palabra'=>$word, 'palabra_epoch_ms'=>$word === '' ? null : $num(17), 'palabra_audio_ms'=>$word === '' ? null : $num(18),
        'preanswer_audio'=>in_array($pre, ['SI', 'NO'], true) ? $pre : '', 'preanswer_bytes'=>$num(20), 'first_audio_epoch_ms'=>$num(21), 'answer_epoch_ms'=>$num(22)];
    $d['momento_palabra'] = $word === '' ? '' : (($d['answer_epoch_ms'] === null || ($d['palabra_epoch_ms'] !== null && $d['palabra_epoch_ms'] < $d['answer_epoch_ms'])) ? 'PRE' : 'POST');
    $d['segundo'] = $d['palabra_audio_ms'] === null ? null : round($d['palabra_audio_ms'] / 1000, 2);
    $repo->stampDialModes(MARCADOR_DIAL_MODES);
    $n = $repo->recordZypad($callId, $d);
    return ['call_id'=>$callId, 'updated'=>$n] + $d;
}

/** Catalog for the zypad bridge: {"version": hash of the content (changes on add, edit, deactivate and delete), "words":[{word,category,active}]}. */
function marcador_zypad_catalog(\ZynervoxQueries\BotIvrRepository $repo): array {
    $words = array_map(fn($w) => ['word'=>$w['word'], 'category'=>$w['category'], 'active'=>(bool)$w['active']], $repo->zypadWords());
    return ['version'=>substr(sha1(json_encode($words, JSON_UNESCAPED_UNICODE)), 0, 16), 'words'=>$words];
}

/**
 * Closing delivery of the dialplans 7314/7315, own AGI action (Telefonia, 04/11): args (index 0 = "zypad") = [zypad, call_id, detectado_por, momento, palabra, segundo, corte_por, preanswer_audio,
 * zypad_call_id, palabra_epoch_ms, first_audio_epoch_ms, answer_epoch_ms, preanswer_bytes]. The last four are optional. The dialplan fills detectado_por/momento/corte_por itself:
 * they are stored as given (empty = nobody detected). Called for every call, answered or not. dial_mode is deduced from the campaign origin.
 */
function marcador_zypad_closing(\ZynervoxQueries\BotIvrRepository $repo, array $args): array {
    $callId = (string)($args[1] ?? '');
    if (!preg_match('/^[0-9A-Za-z._-]{1,80}$/D', $callId)) throw new InvalidArgumentException('call_id inválido.');
    $pick = function (int $i, array $allowed) use ($args): string { $v = strtoupper(trim((string)($args[$i] ?? ''))); return in_array($v, $allowed, true) ? $v : ''; };
    $num = fn(int $i) => preg_match('/^[0-9]{1,15}$/D', trim((string)($args[$i] ?? ''))) ? (int)trim((string)$args[$i]) : null;
    $word = mb_strtolower(trim((string)($args[4] ?? '')), 'UTF-8');
    if (mb_strlen($word, 'UTF-8') > 80 || preg_match('/[\x00-\x1f]/', $word)) $word = '';
    $sec = trim((string)($args[5] ?? ''));
    $d = ['detectado_por'=>$pick(2, ['ZYPAD', 'AMD', 'NINGUNO']), 'momento'=>$pick(3, ['PRE', 'POST']), 'palabra'=>$word,
        'segundo'=>preg_match('/^[0-9]{1,5}(\.[0-9]{1,3})?$/D', $sec) ? round((float)$sec, 2) : null, 'corte_por'=>$pick(6, ['ZYPAD', 'AMD', 'NINGUNO']),
        'preanswer_audio'=>$pick(7, ['SI', 'NO']), 'zypad_call_id'=>preg_match('/^[0-9A-Za-z._-]{1,80}$/D', (string)($args[8] ?? '')) ? (string)$args[8] : '',
        'palabra_epoch_ms'=>$word === '' ? null : $num(9), 'palabra_audio_ms'=>null, 'first_audio_epoch_ms'=>$num(10), 'answer_epoch_ms'=>$num(11), 'preanswer_bytes'=>$num(12)];
    $repo->stampDialModes(MARCADOR_DIAL_MODES);
    $n = $repo->recordZypad($callId, $d);
    return ['call_id'=>$callId, 'updated'=>$n] + $d;
}
