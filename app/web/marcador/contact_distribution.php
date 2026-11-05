<?php
declare(strict_types=1);
// Marcador: distribution by contact (contract "Distribución por contacto"). Every contact counts once, in one category, by its LAST attempt.
// The category rules live in ONE place: MARCADOR_CONTACT_CATEGORY_SQL (used by the dashboard summary, the reports and the zypad/AMD comparison).

/** Categories in the contract order: key => [label, color]. The colour never goes without its label. */
const MARCADOR_CONTACT_CATEGORIES = [
    'humano'=>['Humano', 'verde'], 'casilla_voz'=>['Casilla de voz', 'azul'], 'no_seguro'=>['No seguro', 'violeta'], 'contestada_sin_amd'=>['Contestada sin AMD', 'celeste'],
    'no_disponible'=>['No disponible', 'ambar'], 'sin_respuesta'=>['Sin respuesta', 'gris'], 'ocupado'=>['Ocupado', 'naranja'], 'errores'=>['Errores', 'rojo'], 'bloqueado'=>['Bloqueado', 'negro'],
    'en_llamada'=>['En llamada', 'azul oscuro'], 'sin_llamar'=>['Sin llamar', 'gris claro']];
/** Contacts called = contacts that have been dialed at least once. Needle: Humano ÷ called, «Sin datos» under this many called contacts. */
const MARCADOR_NEEDLE_MIN_CALLED = 20;

/**
 * SQL CASE over an attempt aliased `a`. {SIP} is the final SIP code parsed from sip_cause ("SIP 486 Busy Here"); 0 = the provider never answered.
 * Order = contract: Humano, Casilla (MACHINE or zypad cut), No seguro, Contestada sin AMD (200 without AMD result), No disponible 480/408,
 * Sin respuesta 487/1xx/no answer, Ocupado 486/600, Errores = ANY other code. An attempt still in progress is «en_llamada».
 */
const MARCADOR_CONTACT_CATEGORY_SQL = "CASE
    WHEN a.finished_at IS NULL AND a.end_source<>'RECOVERY' AND a.status<>'LOST' THEN 'en_llamada'
    WHEN a.disposition='BLOCKED' THEN 'bloqueado'
    WHEN a.amd_status='HUMAN' THEN 'humano'
    WHEN a.amd_status='MACHINE' OR a.corte_por='ZYPAD' THEN 'casilla_voz'
    WHEN a.amd_status='NOTSURE' THEN 'no_seguro'
    WHEN a.dial_status='ANSWER' OR a.disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL','AMD_MACHINE') OR {SIP}=200 THEN 'contestada_sin_amd'
    WHEN {SIP} IN (480,408) THEN 'no_disponible'
    WHEN {SIP}=487 OR {SIP} BETWEEN 100 AND 199 OR ({SIP}=0 AND a.disposition IN ('NOANSWER','')) THEN 'sin_respuesta'
    WHEN {SIP} IN (486,600) OR ({SIP}=0 AND a.disposition='BUSY') THEN 'ocupado'
    ELSE 'errores' END";
const MARCADOR_SIP_SQL = "IFNULL(CAST(REGEXP_SUBSTR(a.sip_cause,'[0-9]{3}') AS UNSIGNED),0)";

function marcador_contact_category_sql(): string { return str_replace('{SIP}', MARCADOR_SIP_SQL, MARCADOR_CONTACT_CATEGORY_SQL); }

/** Labels and colours in order, for the pages. */
function marcador_contact_categories(): array {
    $o = []; foreach (MARCADOR_CONTACT_CATEGORIES as $k=>[$label, $color]) $o[] = ['key'=>$k, 'label'=>$label, 'color'=>$color];
    return $o;
}

/** counts (key => n, any subset) → every category present, in order, plus the total. */
function marcador_contact_counts(array $counts): array {
    $out = []; foreach (array_keys(MARCADOR_CONTACT_CATEGORIES) as $k) $out[$k] = (int)($counts[$k] ?? 0);
    return $out;
}

/** Percentages with one decimal that add up to exactly 100.0 (largest remainder). All zeros when there are no contacts. */
function marcador_contact_pct(array $counts): array {
    $total = array_sum($counts);
    $pct = array_fill_keys(array_keys($counts), 0.0);
    if ($total <= 0) return $pct;
    $units = []; $rem = []; $used = 0;
    foreach ($counts as $k=>$n) { $x = 1000 * $n / $total; $units[$k] = (int)floor($x); $rem[$k] = $x - $units[$k]; $used += $units[$k]; }
    arsort($rem);
    foreach (array_keys($rem) as $k) { if ($used >= 1000) break; $units[$k]++; $used++; }
    foreach ($units as $k=>$u) $pct[$k] = $u / 10;
    return $pct;
}

/** Needle: Humano ÷ contacts called ("12 de 100 contactos"). «Sin datos» under 20 called contacts. */
function marcador_contact_needle(array $counts): array {
    $total = array_sum($counts); $called = $total - (int)$counts['sin_llamar']; $h = (int)$counts['humano'];
    $nodata = $called < MARCADOR_NEEDLE_MIN_CALLED;
    return ['human'=>$h, 'called'=>$called, 'contacts'=>$total, 'pct'=>$called > 0 ? round(100 * $h / $called, 1) : 0.0, 'no_data'=>$nodata,
        'label'=>$nodata ? 'Sin datos' : $h.' de '.$called.' contactos llamados', 'min_called'=>MARCADOR_NEEDLE_MIN_CALLED];
}

/** One block ready for the pages: contacts, counts, pct (sum 100.0) and the needle. */
function marcador_contact_block(array $counts): array {
    $c = marcador_contact_counts($counts);
    return ['contacts'=>array_sum($c), 'counts'=>$c, 'pct'=>marcador_contact_pct($c), 'needle'=>marcador_contact_needle($c)];
}

/** Estado of one attempt (column «Estado» of Detalle de intentos): key => label. Single source for the report column, its filter and the CSV. */
const MARCADOR_ATTEMPT_STATES = [
    'casilla_voz'=>'Casilla de voz', 'transferida'=>'Transferida a tu destino', 'sin_respuesta'=>'Sin respuesta', 'no_disponible'=>'No disponible', 'ocupado'=>'Ocupado',
    'contesto_sin_amd'=>'Contestó y se cortó sin AMD', 'transferencia_fallida'=>'Contestó pero falló la transferencia', 'contesto'=>'Contestó', 'bloqueado'=>'Bloqueado', 'fallo'=>'Falló'];

/** SQL CASE over an attempt `a`, same order as the contract: 480/408 No disponible, 486/600 Ocupado, 487 Sin respuesta, 200 or answered dispositions, NOANSWER Sin respuesta, anything else Falló. */
function marcador_attempt_state_sql(): string {
    $sip = MARCADOR_SIP_SQL;
    return "(CASE WHEN a.disposition='BLOCKED' THEN 'bloqueado' WHEN $sip IN (480,408) THEN 'no_disponible' WHEN $sip IN (486,600) THEN 'ocupado' WHEN $sip=487 THEN 'sin_respuesta'
        WHEN $sip=200 OR a.disposition IN ('AMD_MACHINE','XFER_OK','ANSWER_HUMAN','XFER_FAIL') THEN
            (CASE a.disposition WHEN 'AMD_MACHINE' THEN 'casilla_voz' WHEN 'XFER_OK' THEN 'transferida' WHEN 'XFER_FAIL' THEN 'transferencia_fallida' WHEN 'ANSWER_HUMAN' THEN 'contesto_sin_amd' ELSE 'contesto' END)
        WHEN a.disposition='NOANSWER' THEN 'sin_respuesta' ELSE 'fallo' END)";
}
