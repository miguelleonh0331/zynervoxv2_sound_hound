<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/db.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');
$data = json_decode((string) file_get_contents('php://input'), true);
$phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
$flowCode = (string) ($data['flow_code'] ?? '');
if (substr($phone, 0, 2) === '51' && strlen($phone) === 11) $phone = substr($phone, 2);
if ($phone === '') { echo json_encode(['ok'=>false,'error'=>'Teléfono vacío'], JSON_UNESCAPED_UNICODE); exit; }
if (!preg_match('/^\d{2}$/', $flowCode)) { echo json_encode(['ok'=>false,'error'=>'Flujo inválido'], JSON_UNESCAPED_UNICODE); exit; }
$db = carsa_db();
$exists = $db->prepare('SELECT COUNT(*) FROM bot_ivr_flows WHERE flow_code = :c');
$exists->execute([':c' => $flowCode]);
if ((int) $exists->fetchColumn() === 0) { echo json_encode(['ok'=>false,'error'=>'Flujo inválido'], JSON_UNESCAPED_UNICODE); exit; }

// PENDIENTE: el wrapper original (services/sip_agent/call_from_3004.py) es
// especifico del pool de anexos SIPp de mirmidon y no existe en zynerdesk.
// Aqui solo queda registrado el intento; el origen real de la llamada de
// prueba debe conectarse a nuestras propias troncales (ver Carriers.php)
// en una fase posterior.
echo json_encode(['ok'=>false,'error'=>'Llamada de prueba pendiente: falta conectar el originador de llamadas propio de Zynervox (troncal PJSIP) para esta funcion.'], JSON_UNESCAPED_UNICODE);
