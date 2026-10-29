<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/flow_store.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$code = (string) ($_GET['code'] ?? '01');
if (!preg_match('/^\d{2}$/', $code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Código inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}
$db = bot_ivr_db();
$flow = flow_load($db, $code);
if ($flow === null) {
    // Flujo nuevo/vacio (aun no guardado): devolver plantilla minima en vez
    // de 404, para que el editor pueda arrancar de cero con este codigo.
    echo json_encode(['flow_code' => $code, 'name' => '', 'start' => '', 'list_id' => null, 'nodes' => new stdClass()], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode($flow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
