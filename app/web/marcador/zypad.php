<?php
declare(strict_types=1);
// Marcador: zypad (migration 021). Dial mode per campaign origin, word catalog and the closing delivery from the dialplan. Nothing here runs during a call.

/** Campaign origin (call_origin) => dial mode. Any other origin counts as AMD. 7315 is the simulated 7314. */
const MARCADOR_DIAL_MODES = ['7308'=>'AMD', '7309'=>'AMD', '7314'=>'ZYPAD_SOMBRA', '7315'=>'ZYPAD_SOMBRA', '7312'=>'ZYPAD_CORTE'];
const MARCADOR_DIAL_MODE_LABELS = ['AMD'=>'Solo AMD', 'ZYPAD_SOMBRA'=>'Zypad en sombra + AMD', 'ZYPAD_CORTE'=>'Zypad con corte + AMD'];

function marcador_dial_mode(string $origin): string { return MARCADOR_DIAL_MODES[$origin] ?? 'AMD'; }

/**
 * Closing delivery: argv (action first) = [zypad, call_id, detectado_por, momento, palabra, segundo, corte_por, preanswer_audio, zypad_call_id].
 * Every field may be empty (zypad gave no data): the attempt keeps dial_mode and the rest stays empty. Validates everything.
 */
function marcador_zypad_apply(\ZynervoxQueries\BotIvrRepository $repo, array $args): array {
    $callId = (string)($args[1] ?? '');
    if (!preg_match('/^[0-9A-Za-z._-]{1,80}$/D', $callId)) throw new InvalidArgumentException('call_id inválido.');
    $pick = fn(int $i, array $allowed) => in_array($v = strtoupper(trim((string)($args[$i] ?? ''))), $allowed, true) ? $v : '';
    $word = trim((string)($args[4] ?? ''));
    if (mb_strlen($word, 'UTF-8') > 80 || preg_match('/[\x00-\x1f]/', $word)) $word = '';
    $second = trim((string)($args[5] ?? ''));
    $second = preg_match('/^[0-9]{1,5}(\.[0-9]{1,3})?$/D', $second) ? round((float)$second, 2) : null;
    $zid = preg_match('/^[0-9A-Za-z._-]{1,80}$/D', (string)($args[8] ?? '')) ? (string)$args[8] : '';
    $data = ['detectado_por'=>$pick(2, ['ZYPAD', 'AMD', 'NINGUNO']), 'momento'=>$pick(3, ['PRE', 'POST']), 'palabra'=>$word, 'segundo'=>$second,
        'corte_por'=>$pick(6, ['ZYPAD', 'AMD', 'NINGUNO']), 'preanswer_audio'=>$pick(7, ['SI', 'NO']), 'zypad_call_id'=>$zid];
    $repo->stampDialModes(MARCADOR_DIAL_MODES);
    $n = $repo->recordZypad($callId, $data);
    return ['call_id'=>$callId, 'updated'=>$n] + $data;
}
