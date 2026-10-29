<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/Auth.php';

function vpn_request(string $action, array $data = []): array {
    $path = \Config\Config::deployment('directory', '/etc/zynervox/zynervoxv2') . '/vpn/api-token';
    $token = trim((string) @file_get_contents($path));
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Servicio VPN no configurado.');
    $body = json_encode(['action' => $action, 'data' => (object) $data,
        'actor' => (string) ($_SESSION['user'] ?? 'anonymous'),
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 75,
        'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $token . "\r\n",
        'content' => $body]]);
    $response = @file_get_contents('http://127.0.0.1:18081/api', false, $context);
    if ($response === false) throw new RuntimeException('No se pudo contactar con el servicio VPN.');
    $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Respuesta inválida del servicio VPN.');
    if (isset($decoded['error'])) throw new RuntimeException((string) $decoded['error']);
    return $decoded;
}

function vpn_authorize(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (!isset($_SESSION['user']) || (int) ($_SESSION['user_level'] ?? 0) !== 9) {
        try { vpn_request('access', ['event' => 'ACCESS_DENIED']); }
        catch (Throwable $e) { error_log('VPN denied access: audit service unavailable'); }
        http_response_code(403);
        exit('Acceso exclusivo para administradores. <a href="../../index.php">Iniciar sesión</a>');
    }
    if (empty($_SESSION['vpn_csrf'])) $_SESSION['vpn_csrf'] = bin2hex(random_bytes(32));
}

function vpn_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
