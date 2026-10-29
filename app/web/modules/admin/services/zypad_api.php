<?php
// Endpoint de control/monitor de Zypad. Habla por HTTP con el control-daemon
// local (systemd, root) escrito por installer/zypad.sh -- NO usa sudo: en
// hosts con Apache endurecido (ProtectSystem=full en el unit systemd), /usr
// queda montado "nosuid" dentro del sandbox del servicio, lo que inutiliza
// el bit setuid de /usr/bin/sudo para cualquier hijo de Apache. Se verificó
// en docker_converxa (file_exists() de /etc/sudoers.d/* ya da false desde
// dentro de Apache); sudo -n deniega siempre ahí sin importar la regla.
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

header('Content-Type: application/json; charset=utf-8');

const ZYPAD_CONFIG = '/etc/zynervox/zynervox-zypad.php';

function zypadRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_readable(ZYPAD_CONFIG)) {
    zypadRespond(['ok' => false, 'error' => 'Zypad no está instalado en este servidor'], 404);
}
$config = require ZYPAD_CONFIG;

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Acciones que cambian estado requieren el token CSRF que zypad.php imprime
// en sesión; "status" es de solo lectura y no lo necesita.
if (in_array($action, ['start', 'stop'], true)) {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['zypad_csrf'] ?? '', $token)) {
        zypadRespond(['ok' => false, 'error' => 'token CSRF inválido'], 403);
    }
}

function zypadControl(string $method, string $path, array $config): array
{
    $url = 'http://127.0.0.1:' . (int)$config['control_port'] . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
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
        return ['ok' => false, 'error' => 'control-daemon de Zypad no responde'];
    }
    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : ['ok' => false, 'error' => 'respuesta inválida del control-daemon'];
}

switch ($action) {
    case 'start':
        zypadRespond(zypadControl('POST', '/start', $config));
        break;

    case 'stop':
        zypadRespond(zypadControl('POST', '/stop', $config));
        break;

    case 'status':
        zypadRespond(zypadControl('GET', '/status', $config));
        break;

    default:
        zypadRespond(['ok' => false, 'error' => 'acción desconocida'], 400);
}
