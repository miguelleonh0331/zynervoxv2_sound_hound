<?php
declare(strict_types=1);
// Marcador: speech-to-text pass over the audios of answered calls, OUTSIDE any call.
//   php amd_stt_batch.php [--config /etc/zynervoxv2/stt.json] [--limit 50] [--force] [--status]
// The engine is interchangeable: an external command that receives the audio path as its last argument and prints JSON {"text":"..."}.
// Config file: {"engine":"name","command":["/path/to/cmd","--opt"],"timeout":120}. Without a configured engine the pass reports and leaves the audios pending (exit 0).
// It does not run while calls are open (unless --force): the pass is for after the sweep.
require_once __DIR__.'/amd_audit.php';

/** Duration in seconds of a PCM WAV file, or null if it cannot be read cheaply. */
function marcador_audio_seconds(string $path): ?float {
    $h = @fopen($path, 'rb');
    if (!$h) return null;
    $head = (string)fread($h, 12);
    if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') { fclose($h); return null; }
    $byteRate = 0; $seconds = null;
    while (($chunk = fread($h, 8)) !== false && strlen($chunk) === 8) {
        $id = substr($chunk, 0, 4); $size = unpack('V', substr($chunk, 4, 4))[1];
        if ($id === 'fmt ') { $fmt = (string)fread($h, $size); if (strlen($fmt) >= 12) $byteRate = unpack('V', substr($fmt, 8, 4))[1]; }
        elseif ($id === 'data') { $seconds = $byteRate > 0 ? round($size / $byteRate, 2) : null; break; }
        else fseek($h, $size, SEEK_CUR);
        if ($size % 2) fseek($h, 1, SEEK_CUR);
    }
    fclose($h);
    return $seconds;
}

/** Loads the engine config, or null when there is none (the caller reports it). */
function marcador_stt_engine(?string $configPath): ?array {
    $path = $configPath ?? '/etc/zynervoxv2/stt.json';
    if (!is_file($path)) return null;
    $cfg = json_decode((string)file_get_contents($path), true);
    if (!is_array($cfg) || !isset($cfg['command']) || !is_array($cfg['command']) || !$cfg['command'] || array_filter($cfg['command'], fn($a) => !is_string($a))) return null;
    return ['name'=>substr((string)($cfg['engine'] ?? basename((string)$cfg['command'][0])), 0, 60), 'command'=>array_values($cfg['command']), 'timeout'=>max(5, min(900, (int)($cfg['timeout'] ?? 120)))];
}

/** Runs the engine on one audio. Returns [text, error]. No shell is involved. */
function marcador_stt_transcribe(array $engine, string $audio): array {
    $proc = @proc_open(array_merge($engine['command'], [$audio]), [1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return ['', 'No se pudo ejecutar el motor STT.'];
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $deadline = microtime(true) + $engine['timeout'];
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        if (microtime(true) > $deadline) { proc_terminate($proc, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); return ['', 'El motor STT superó el tiempo máximo.']; }
        usleep(100000);
    }
    $out .= (string)stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    $json = json_decode($out, true);
    if ($code > 0 && !is_array($json)) return ['', 'El motor STT terminó con error '.$code.'.'];
    if (!is_array($json) || !isset($json['text']) || !is_string($json['text'])) return ['', 'El motor STT no devolvió JSON con "text".'];
    return [trim($json['text']), ''];
}

/**
 * One pass. Returns ['engine'=>name|null, 'sync'=>[...], 'pending'=>n, 'done'=>n, 'errors'=>n, 'message'=>string].
 * $force skips the "no open calls" guard.
 */
function marcador_stt_run(\ZynervoxQueries\BotIvrRepository $repo, ?array $engine, int $limit = 50, bool $force = false): array {
    $r = ['engine'=>$engine['name'] ?? null, 'sync'=>marcador_amd_audit_sync($repo), 'pending'=>0, 'done'=>0, 'errors'=>0, 'message'=>''];
    $r['pending'] = $repo->countPendingSttAudits();
    if ($engine === null) { $r['message'] = 'STT sin motor configurado: '.$r['pending'].' audios pendientes.'; return $r; }
    if (!$force && $repo->openCallsTotal() > 0) { $r['message'] = 'Hay llamadas en curso: la pasada STT se aplaza.'; return $r; }
    foreach ($repo->pendingSttAudits($limit) as $a) {
        $path = (string)$a['audio_path'];
        $real = str_contains($path, '..') ? false : realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) { $repo->saveSttResult((int)$a['audit_id'], 'error', '', $engine['name'], 'Audio no disponible.', null); $r['errors']++; continue; }
        if (filesize($real) <= 44) { $repo->saveSttResult((int)$a['audit_id'], 'no_audio', '', $engine['name'], 'Grabación sin audio (solo cabecera).', 0.0); continue; } // header-only wav: nothing to transcribe
        [$text, $error] = marcador_stt_transcribe($engine, $real);
        $seconds = marcador_audio_seconds($real);
        if ($error !== '') { $repo->saveSttResult((int)$a['audit_id'], 'error', '', $engine['name'], $error, $seconds); $r['errors']++; }
        else { $repo->saveSttResult((int)$a['audit_id'], 'done', $text, $engine['name'], '', $seconds); $r['done']++; }
    }
    $r['pending'] = $repo->countPendingSttAudits();
    $r['message'] = 'STT: '.$r['done'].' transcritos, '.$r['errors'].' con error, '.$r['pending'].' pendientes.';
    return $r;
}

if (PHP_SAPI !== 'cli' || realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
$options = getopt('', ['config:', 'limit:', 'force', 'status']);
try {
    require_once __DIR__.'/../bot_ivr/db.php';
    $repo = bot_ivr_repository();
    $engine = marcador_stt_engine(isset($options['config']) ? (string)$options['config'] : null);
    if (isset($options['status'])) { echo json_encode(['sync'=>marcador_amd_audit_sync($repo), 'pending'=>$repo->countPendingSttAudits(), 'engine'=>$engine['name'] ?? null], JSON_UNESCAPED_UNICODE)."\n"; exit(0); }
    $r = marcador_stt_run($repo, $engine, (int)($options['limit'] ?? 50), isset($options['force']));
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ZV2 amd_stt_batch: '.get_class($e).': '.$e->getMessage()."\n"); exit(1);
}
