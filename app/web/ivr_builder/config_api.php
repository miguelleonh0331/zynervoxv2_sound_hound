<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/DeploymentConfig.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function currentRow(PDO $core): array {
    $row = $core->query(
        'SELECT db_host,db_port,db_name,db_user,db_pass,db_tested_at,asterisk_api_url,asterisk_api_token,asterisk_tested_at FROM ' . \Config\Config::coreTable('deployment') . ' WHERE id=1'
    )->fetch();
    return $row ?: [
        'db_host' => '', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => '', 'db_tested_at' => null,
        'asterisk_api_url' => '', 'asterisk_api_token' => '', 'asterisk_tested_at' => null,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$data = $method === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];
$action = (string) ($data['action'] ?? ($_GET['action'] ?? 'get'));

if ($action !== 'get') {
    if ($method !== 'POST') fail('Método inválido', 405);
    if (!\Includes\DeploymentConfig::csrf((string) ($_SESSION['ivr_config_csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fail('Sesión inválida. Recarga la página.', 403);
}

try { $core = \Includes\Database::getCoreInstance(); }
catch (Throwable $e) { fail('Configuración central no disponible. Revisa la instalación.', 503); }

if ($action === 'get') {
    $row = currentRow($core);
    $row['db_pass'] = $row['db_pass'] !== '' ? '••••••••' : '';
    $row['asterisk_api_token'] = $row['asterisk_api_token'] !== '' ? '••••••••' : '';
    echo json_encode(['ok' => true, 'config' => $row, 'csrf' => $_SESSION['ivr_config_csrf']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'test_db') {
    $host = trim((string) ($data['db_host'] ?? ''));
    $port = (int) ($data['db_port'] ?? 3306);
    $name = trim((string) ($data['db_name'] ?? ''));
    $user = trim((string) ($data['db_user'] ?? ''));
    $pass = (string) ($data['db_pass'] ?? '');
    try { $validated = \Includes\DeploymentConfig::database($data, currentRow($core)); }
    catch (InvalidArgumentException $e) { fail($e->getMessage()); }
    $host = $validated['db_host']; $port = $validated['db_port']; $name = $validated['db_name']; $user = $validated['db_user']; $pass = $validated['db_pass'];
    if ($pass === '••••••••') {
        $existing = currentRow($core);
        $pass = $existing['db_pass'];
    }
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;port=$port;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->query('SELECT 1');
        echo json_encode(['ok' => true, 'message' => 'Conexión exitosa a ' . $name . '@' . $host], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo conectar. Revisa los datos y permisos.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save_db') {
    $host = trim((string) ($data['db_host'] ?? ''));
    $port = (int) ($data['db_port'] ?? 3306);
    $name = trim((string) ($data['db_name'] ?? ''));
    $user = trim((string) ($data['db_user'] ?? ''));
    $pass = (string) ($data['db_pass'] ?? '');
    try { $validated = \Includes\DeploymentConfig::database($data, currentRow($core)); }
    catch (InvalidArgumentException $e) { fail($e->getMessage()); }
    $host = $validated['db_host']; $port = $validated['db_port']; $name = $validated['db_name']; $user = $validated['db_user']; $pass = $validated['db_pass'];
    // Si el password viene enmascarado, conservar el que ya habia guardado.
    if ($pass === '••••••••') {
        $existing = currentRow($core);
        $pass = $existing['db_pass'];
    }
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;port=$port;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        fail('No se guardó: la conexión falló. Revisa los datos y permisos.');
    }
    $stmt = $core->prepare(
        'INSERT INTO ' . \Config\Config::coreTable('deployment') . ' (id,db_host,db_port,db_name,db_user,db_pass,db_tested_at)
         VALUES (1,:h,:p,:n,:u,:pw,NOW())
         ON DUPLICATE KEY UPDATE db_host=VALUES(db_host),db_port=VALUES(db_port),db_name=VALUES(db_name),
           db_user=VALUES(db_user),db_pass=VALUES(db_pass),db_tested_at=VALUES(db_tested_at)'
    );
    $stmt->execute([':h' => $host, ':p' => $port, ':n' => $name, ':u' => $user, ':pw' => $pass]);
    echo json_encode(['ok' => true, 'message' => 'Configuración de BD guardada'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'test_asterisk' || $action === 'save_asterisk') {
    $url = trim((string) ($data['asterisk_api_url'] ?? ''));
    $token = (string) ($data['asterisk_api_token'] ?? '');
    if ($url === '') fail('La URL del API Asterisk es obligatoria');
    if ($token === '••••••••') {
        $existing = currentRow($core);
        $token = $existing['asterisk_api_token'];
    }
    if ($token === '') fail('El token es obligatorio');

    $ch = curl_init(rtrim($url, '/') . '/asterisk_receive.php?action=health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['X-IVR-Token: ' . $token],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) fail('No se pudo contactar el servidor Asterisk: ' . $err, 502);
    $resp = json_decode((string) $body, true);
    if ($code !== 200 || !is_array($resp) || empty($resp['ok'])) {
        fail('El servidor Asterisk respondió con error (HTTP ' . $code . '): ' . (string) $body, 502);
    }

    if ($action === 'test_asterisk') {
        echo json_encode(['ok' => true, 'message' => 'Conexión exitosa con el API Asterisk'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $core->prepare(
        'INSERT INTO ' . \Config\Config::coreTable('deployment') . ' (id,asterisk_api_url,asterisk_api_token,asterisk_tested_at)
         VALUES (1,:u,:t,NOW())
         ON DUPLICATE KEY UPDATE asterisk_api_url=VALUES(asterisk_api_url),asterisk_api_token=VALUES(asterisk_api_token),asterisk_tested_at=VALUES(asterisk_tested_at)'
    );
    $stmt->execute([':u' => $url, ':t' => $token]);
    echo json_encode(['ok' => true, 'message' => 'Configuración de Asterisk guardada'], JSON_UNESCAPED_UNICODE);
    exit;
}

fail('Acción inválida');
