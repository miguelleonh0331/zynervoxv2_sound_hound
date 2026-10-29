<?php
declare(strict_types=1);
// Endpoint receptor del JSON publicado del IVR Builder. Corre fisicamente
// en el servidor que tiene montado /etc/asterisk (hoy: el mismo mirmidon;
// en el cluster futuro, el servidor Asterisk dedicado). No usa la sesion
// de Zynervox -- se autentica con un token compartido guardado localmente
// en /etc/zynervox/zynervoxv2205-ivr-receiver.conf (RECEIVER_TOKEN), que
// es independiente de la BD (este server puede no tener acceso a ella).
require_once __DIR__ . '/../config/Config.php';

define('IVR_PUBLISHED_DIR', \Config\Config::deployment('runtime', '/etc/asterisk/synervox') . '/modules/flows/published');
define('RECEIVER_CONF', \Config\Config::deployment('directory', '/etc/zynervox') . '/zynervoxv2205-ivr-receiver.conf');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function receiver_token(): string {
    if (!file_exists(RECEIVER_CONF)) return '';
    foreach (file(RECEIVER_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^RECEIVER_TOKEN\s*=>\s*(.*)$/', trim($line), $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

$expected = receiver_token();
$given = (string) ($_SERVER['HTTP_X_IVR_TOKEN'] ?? '');
if ($expected === '') fail('Receptor sin configurar: falta ' . RECEIVER_CONF, 500);
if (!hash_equals($expected, $given)) fail('Token inválido', 401);

$action = (string) ($_GET['action'] ?? '');
if ($action === 'health') {
    echo json_encode(['ok' => true, 'message' => 'Receptor IVR operativo'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método inválido', 405);
$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) fail('JSON inválido');
$code = (string) ($data['flow_code'] ?? '');
$json = (string) ($data['json'] ?? '');
if (!preg_match('/^\d{2}$/', $code)) fail('Código de flujo inválido');
if ($json === '' || json_decode($json) === null) fail('Payload JSON inválido o vacío');

if (!is_dir(IVR_PUBLISHED_DIR) && !mkdir(IVR_PUBLISHED_DIR, 0770, true) && !is_dir(IVR_PUBLISHED_DIR)) {
    fail('No se pudo crear el directorio de publicación', 500);
}
$path = IVR_PUBLISHED_DIR . '/' . $code . '.json';
$tmp = tempnam(IVR_PUBLISHED_DIR, '.' . $code . '.tmp.');
if ($tmp === false) fail('No se pudo crear el archivo temporal', 500);
try {
    if (file_put_contents($tmp, $json, LOCK_EX) === false) fail('No se pudo escribir la publicación', 500);
    chmod($tmp, 0640);
    if (!rename($tmp, $path)) fail('No se pudo publicar atómicamente', 500);
} finally {
    if (is_file($tmp)) @unlink($tmp);
}

echo json_encode(['ok' => true, 'path' => $path, 'sha256' => hash('sha256', $json)], JSON_UNESCAPED_UNICODE);
