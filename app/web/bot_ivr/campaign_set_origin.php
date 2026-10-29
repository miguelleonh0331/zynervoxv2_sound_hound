<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/auth.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
initial_survey_require_login();
require __DIR__ . '/db.php';
require __DIR__ . '/campaign_runtime.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }
$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$origin = strtolower(trim((string) ($_POST['origin'] ?? '')));
$allowedOrigins = ['sipp_2006', 'zypad_3006', 'zypad_whisper_4006'];
if ($campaignId <= 0) { echo json_encode(['ok'=>false,'error'=>'Campaña inválida']); exit; }
if (!in_array($origin, $allowedOrigins, true)) { echo json_encode(['ok'=>false,'error'=>'Origen de llamada inválido']); exit; }

try {
    $db = carsa_db();
    if(campaign_process_active($db,$campaignId,'dialer')){echo json_encode(['ok'=>false,'error'=>'No se puede cambiar el origen mientras la campaña está activa.']);exit;}
    $stmt = $db->prepare('UPDATE synervox_campaigns SET call_origin=:origin WHERE id=:id');
    $stmt->execute([':origin'=>$origin, ':id'=>$campaignId]);
    if ($stmt->rowCount() === 0) {
        $check = $db->prepare('SELECT id FROM synervox_campaigns WHERE id=:id');
        $check->execute([':id'=>$campaignId]);
        if (!$check->fetch()) { echo json_encode(['ok'=>false,'error'=>'Campaña inexistente']); exit; }
    }
    echo json_encode([
        'ok'=>true, 'campaign_id'=>$campaignId, 'call_origin'=>$origin,
        'message'=>'Origen de llamada guardado. Se usará en Play y en el inicio programado.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
