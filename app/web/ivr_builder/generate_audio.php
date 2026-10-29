<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodo invalido', 405);
$data = json_decode((string) file_get_contents('php://input'), true);
$text = trim((string) ($data['text'] ?? ''));
if ($text === '' || mb_strlen($text, 'UTF-8') > 2000) fail('Texto requerido, maximo 2000 caracteres');
// PENDIENTE: este proveedor (macelioai/gTTS) depende de un venv de Python
// (venvs/gtts_env) que todavia no existe en zynerdesk -- fallara con un
// error claro hasta que se instale ese pipeline.
$base = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
$python = $base . '/venvs/gtts_env/bin/python';
$script = $base . '/services/tts/generate_macelioai_wav.py';
$hash = hash('sha256', 'macelioai|default|1.3|' . mb_strtolower(preg_replace('/\s+/', ' ', $text), 'UTF-8'));
$dir = \Config\Config::audioRoot() . '/cache/ivr_builder/macelioai';
$file = $dir . '/' . $hash . '.wav';
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) fail('No se pudo crear cache', 500);
$cached = is_file($file) && filesize($file) > 500;
$start = microtime(true);
if (!$cached) {
    if (!is_file($python) || !is_file($script)) {
        fail('Proveedor macelioai no instalado todavía en este servidor.', 501);
    }
    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script)
        . ' --text ' . escapeshellarg($text) . ' --output ' . escapeshellarg($file) . ' 2>&1';
    $output = (string) shell_exec($cmd);
    if (!is_file($file) || filesize($file) < 500) fail('No se genero audio: ' . $output, 500);
}
$ms = (int) ((microtime(true) - $start) * 1000);
echo json_encode([
    'ok' => true, 'provider' => 'macelioai', 'label' => 'Marcelo IA',
    'hash' => $hash, 'audio' => 'voicebot/cache/ivr_builder/macelioai/' . $hash,
    'preview' => 'audio_serve.php?hash=' . rawurlencode($hash) . '&v=' . time(),
    'cached' => $cached, 'duration_ms' => $ms, 'bytes' => filesize($file),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
