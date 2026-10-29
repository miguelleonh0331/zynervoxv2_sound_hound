<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/flow_store.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = bot_ivr_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode((string) file_get_contents('php://input'), true);
    $action = (string) ($data['action'] ?? '');
    $code = (string) ($data['code'] ?? '');
    if (!preg_match('/^\d{2}$/', $code)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Solicitud inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'clone') {
        $sourceCode = (string) ($data['source_code'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        if (!preg_match('/^\d{2}$/', $sourceCode) || $name === '' || mb_strlen($name, 'UTF-8') > 100) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Origen o nombre inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $flow = flow_load($db, $sourceCode);
        if ($flow === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Flujo origen inexistente'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $exists = $db->prepare('SELECT COUNT(*) FROM bot_ivr_flows WHERE flow_code = :c');
        $exists->execute([':c' => $code]);
        if ((int) $exists->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Código destino existente'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $flow['flow_code'] = $code;
        $flow['name'] = $name;
        $flow['updated_at'] = gmdate('c');
        flow_store($db, $flow);
        flow_publish($db, $flow);
        echo json_encode(['ok' => true, 'code' => $code, 'name' => $name], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action !== 'delete') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($code === '01') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El flujo 01 está protegido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->prepare('DELETE FROM bot_ivr_flows WHERE flow_code = :c')->execute([':c' => $code]);
    $published = IVR_PUBLISHED_DIR . '/' . $code . '.json';
    if (is_file($published) && !unlink($published)) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Flujo eliminado de BD, pero no se pudo retirar la publicación'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
$rows = $db->query('SELECT flow_code, name, updated_at FROM bot_ivr_flows ORDER BY flow_code ASC')->fetchAll();
foreach ($rows as $row) {
    $items[] = ['code' => $row['flow_code'], 'name' => (string) $row['name'], 'updated_at' => (string) $row['updated_at']];
}
echo json_encode(['ok' => true, 'flows' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
