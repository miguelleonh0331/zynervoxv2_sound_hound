<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/DevChecklist.php';

use Includes\Auth;
use Includes\DevChecklist;

Auth::checkAccess(9);

header('Content-Type: application/json; charset=UTF-8');

if (!DevChecklist::isAuthorized()) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $key = is_array($input) ? ($input['key'] ?? '') : '';
    $checked = is_array($input) ? !empty($input['checked']) : false;

    if ($key === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Falta key']);
        exit;
    }

    try {
        $state = DevChecklist::toggle($key, $checked);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
} else {
    $state = DevChecklist::getState();
}

echo json_encode([
    'state'    => $state,
    'progress' => DevChecklist::progress($state),
]);
