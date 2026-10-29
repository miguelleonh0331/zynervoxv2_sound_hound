<?php
// Endpoint de control de Zynerdesk. Habla por HTTP con el control-daemon
// local (systemd, root) escrito por installer/zynerdesk.sh install-control
// -- NO usa sudo: ver app/web/modules/admin/services/zypad_api.php para el
// motivo (Apache endurecido con ProtectSystem=full inutiliza el setuid de
// /usr/bin/sudo para cualquier hijo de Apache).
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

header('Content-Type: application/json; charset=utf-8');

const ZYNERDESK_CONTROL_CONFIG = '/etc/zynervox/zynerdesk-control.php';

function zynerdeskControlRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_readable(ZYNERDESK_CONTROL_CONFIG)) {
    zynerdeskControlRespond(['ok' => false, 'error' => 'Control de Zynerdesk no está instalado en este servidor'], 404);
}
$config = require ZYNERDESK_CONTROL_CONFIG;

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (in_array($action, ['start', 'stop'], true)) {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['zynerdesk_control_csrf'] ?? '', $token)) {
        zynerdeskControlRespond(['ok' => false, 'error' => 'token CSRF inválido'], 403);
    }
}

function zynerdeskControl(string $method, string $path, array $config): array
{
    $url = 'http://127.0.0.1:' . (int)$config['control_port'] . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['X-Control-Token: ' . $config['control_token']],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $body === false) {
        return ['ok' => false, 'error' => 'control-daemon de Zynerdesk no responde'];
    }
    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : ['ok' => false, 'error' => 'respuesta inválida del control-daemon'];
}

switch ($action) {
    case 'start':
        zynerdeskControlRespond(zynerdeskControl('POST', '/start', $config));
        break;

    case 'stop':
        zynerdeskControlRespond(zynerdeskControl('POST', '/stop', $config));
        break;

    case 'status':
        zynerdeskControlRespond(zynerdeskControl('GET', '/status', $config));
        break;

    default:
        zynerdeskControlRespond(['ok' => false, 'error' => 'acción desconocida'], 400);
}
