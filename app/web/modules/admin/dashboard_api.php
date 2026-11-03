<?php
declare(strict_types=1);
// Datos y acciones del Dashboard. GET: métricas del servidor, servicios y listas. POST (CSRF): activar/desactivar, iniciar/detener una lista.
// Las listas y las acciones vienen del servicio de campañas; aquí no hay SQL.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../bot_ivr/campaign_config_service.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dash_json(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (empty($_SESSION['user']) || (int)($_SESSION['user_level'] ?? 0) !== 9) dash_json(401, ['ok' => false, 'error' => 'Sesión requerida.']);

/** CPU % entre dos lecturas de /proc/stat. */
function dash_cpu(): ?int {
    $read = function () {
        $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES)[0] ?? '';
        $p = array_map('intval', preg_split('/\s+/', trim($line)));
        array_shift($p);
        return count($p) >= 4 ? [array_sum($p), $p[3] + ($p[4] ?? 0)] : null;
    };
    $a = $read(); usleep(200000); $b = $read();
    if (!$a || !$b || $b[0] <= $a[0]) return null;
    return (int)round(100 * (1 - ($b[1] - $a[1]) / ($b[0] - $a[0])));
}
function dash_server(): array {
    $total = @disk_total_space('/'); $free = @disk_free_space('/');
    $mem = [];
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $l) if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int)$m[2] * 1024;
    $ramTotal = $mem['MemTotal'] ?? 0; $ramUsed = $ramTotal - ($mem['MemAvailable'] ?? 0);
    return [
        'cpu_pct' => dash_cpu(),
        'ram_pct' => $ramTotal > 0 ? (int)round(100 * $ramUsed / $ramTotal) : null, 'ram_used' => $ramUsed, 'ram_total' => $ramTotal,
        'disk_pct' => $total ? (int)round(100 * ($total - $free) / $total) : null, 'disk_used' => $total ? $total - $free : null, 'disk_total' => $total ?: null,
    ];
}
/** Servicio local escuchando en un puerto: true/false, o null si no se pudo comprobar (p. ej. bloqueado por SELinux). Bajo php-fpm /proc no deja ver otros servicios. */
function dash_port(int $port): ?bool {
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $err, 1);
    if ($s) { fclose($s); return true; }
    return $errno === 111 ? false : null;
}

try {
    $db = bot_ivr_repository();
    $actor = (string)$_SESSION['user'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
        if (empty($_SESSION['dashboard_csrf']) || !hash_equals($_SESSION['dashboard_csrf'], $token)) dash_json(403, ['ok' => false, 'error' => 'Sesión inválida. Recarga la página.']);
        $campaignId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $listId = filter_var($_POST['list_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$campaignId || !$listId) dash_json(400, ['ok' => false, 'error' => 'Lista inválida.']);
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'start') bot_cfg_list_start($db, $campaignId, $listId, $actor);
            elseif ($action === 'stop') bot_cfg_list_stop($db, $campaignId, $listId, $actor);
            elseif ($action === 'activate') bot_cfg_list_set_active($db, $campaignId, $listId, true, $actor);
            elseif ($action === 'deactivate') bot_cfg_list_set_active($db, $campaignId, $listId, false, $actor);
            else dash_json(400, ['ok' => false, 'error' => 'Acción inválida.']);
        } catch (RuntimeException $e) {
            dash_json(409, ['ok' => false, 'error' => $e->getMessage()]);
        }
        dash_json(200, ['ok' => true]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') dash_json(405, ['ok' => false, 'error' => 'Método no permitido.']);
    $out = ['ok' => true, 'at' => gmdate('c'), 'server' => dash_server(), 'services' => null, 'dashboard' => null, 'dashboard_error' => ''];
    try { $out['dashboard'] = bot_cfg_dashboard($db); } catch (Throwable $e) { $out['dashboard_error'] = 'No se pudo leer el estado de las campañas.'; }
    $out['services'] = [
        ['key' => 'asterisk', 'label' => 'Asterisk', 'on' => dash_port(5038)],
        ['key' => 'mysql', 'label' => 'MySQL', 'on' => $out['dashboard'] ? true : dash_port(3306)],
        ['key' => 'apache', 'label' => 'Apache', 'on' => true],
        ['key' => 'dialer', 'label' => 'Motor de marcación', 'on' => $out['dashboard'] ? (bool)$out['dashboard']['server']['engine_alive'] : null],
    ];
    dash_json(200, $out);
} catch (Throwable $e) {
    dash_json(503, ['ok' => false, 'error' => 'No se pudo consultar el sistema.']);
}
