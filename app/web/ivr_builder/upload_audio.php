<?php
/**
 * upload_audio.php — IVR Builder
 * Recibe un blob de audio grabado desde el micrófono del navegador (webm/ogg),
 * lo convierte a WAV PCM 8000Hz mono 16bit con ffmpeg y lo cachea por hash SHA256.
 * Devuelve el mismo contrato que generate_audio.php:
 *   { ok, hash, audio, preview, cached, duration_ms, bytes }
 *   { ok:false, error }
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Servir WAV de mic directamente (GET ?serve=hash) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['serve'])) {
    $h = (string)$_GET['serve'];
    if (!preg_match('/^[a-f0-9]{64}$/', $h)) { http_response_code(400); exit; }
    $f = \Config\Config::audioRoot() . '/cache/ivr_builder/mic/' . $h . '.wav';
    if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: no-store');
    readfile($f);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodo invalido', 405);

// --- Validar archivo recibido ---
if (empty($_FILES['audio']) || (int)$_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $errCode = (int)($_FILES['audio']['error'] ?? -1);
    fail("Error al recibir el archivo (code=$errCode)");
}

$tmpIn = (string)$_FILES['audio']['tmp_name'];
$size  = (int)($_FILES['audio']['size'] ?? 0);

if ($size < 500)               fail('Grabacion demasiado corta');
if ($size > 20 * 1024 * 1024) fail('Archivo demasiado grande (max 20 MB)');

// --- Directorio de cache (mismo arbol que TTS builder) ---
$dir = \Config\Config::audioRoot() . '/cache/ivr_builder/mic';
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) fail('No se pudo crear cache', 500);

// --- Hash del contenido del archivo (equivalente al hash de texto en generate_audio) ---
$hash = (string)hash_file('sha256', $tmpIn);
$file = "$dir/$hash.wav";
$asteriskPath = "voicebot/cache/ivr_builder/mic/$hash";

// --- Cache hit: ya fue grabado antes ---
if (is_file($file) && filesize($file) > 500) {
    echo json_encode([
        'ok'          => true,
        'hash'        => $hash,
        'audio'       => $asteriskPath,
        'preview'     => 'upload_audio.php?serve=' . rawurlencode($hash) . '&v=' . time(),
        'cached'      => true,
        'duration_ms' => 0,
        'bytes'       => filesize($file),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Convertir con ffmpeg a WAV 8000Hz mono 16bit ---
$ffmpeg = '/usr/bin/ffmpeg';
if (!is_executable($ffmpeg)) {
    $found = trim((string)shell_exec('which ffmpeg 2>/dev/null'));
    if ($found && is_executable($found)) $ffmpeg = $found;
    else fail('ffmpeg no disponible en el servidor', 500);
}

$tmpOut = sys_get_temp_dir() . "/ivr_mic_{$hash}.wav";
$cmd    = sprintf(
    '%s -y -i %s -ar 8000 -ac 1 -sample_fmt s16 %s 2>&1',
    escapeshellarg($ffmpeg),
    escapeshellarg($tmpIn),
    escapeshellarg($tmpOut)
);

$start  = microtime(true);
$output = (string)shell_exec($cmd);
$ms     = (int)((microtime(true) - $start) * 1000);

if (!is_file($tmpOut) || filesize($tmpOut) < 100) {
    $detail = implode(' | ', array_slice(explode("\n", trim($output)), -3));
    fail("ffmpeg fallo: $detail", 500);
}

// --- Mover al cache definitivo ---
if (!rename($tmpOut, $file)) {
    copy($tmpOut, $file);
    @unlink($tmpOut);
}
chmod($file, 0666);

echo json_encode([
    'ok'          => true,
    'hash'        => $hash,
    'audio'       => $asteriskPath,
    'preview'     => 'upload_audio.php?serve=' . rawurlencode($hash) . '&v=' . time(),
    'cached'      => false,
    'duration_ms' => $ms,
    'bytes'       => (int)filesize($file),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
