<?php
// Backend del bloque "Servidor" del sidebar (ver sidebar.php): entrega
// hostname/IP calculados y guarda la nota libre que el admin escribe para
// diferenciar en que servidor esta parado. Mismo patron que
// api_dev_checklist.php (Auth::checkAccess(9) + JSON in/out).
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/ServerInfo.php';

use Includes\Auth;
use Includes\ServerInfo;

Auth::checkAccess(9);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notes = is_array($input) ? (string)($input['notes'] ?? '') : '';
    ServerInfo::saveNotes($notes);
}

echo json_encode([
    'hostname' => ServerInfo::hostname(),
    'lan_ip'   => ServerInfo::lanIp(),
    'notes'    => ServerInfo::getNotes(),
]);
