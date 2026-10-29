<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/auth.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
initial_survey_require_login();
require __DIR__ . '/db.php';
require __DIR__ . '/campaign_state_summary.php';
require __DIR__ . '/campaign_runtime.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }
$campaignId = (int) ($_POST['campaign_id'] ?? 0);
if ($campaignId <= 0) { echo json_encode(['ok'=>false,'error'=>'Campaña inválida']); exit; }

try {
    $db=carsa_db();
    if(!campaign_process_active($db,$campaignId,'dialer')){echo json_encode(['ok'=>false,'error'=>'La campaña no está ejecutándose']);exit;}
    campaign_stop_request($db,$campaignId);
    campaign_summary_upsert($db, $campaignId, ['campaign_status'=>'stopping']);
    campaign_event($db,$campaignId,'dialer','info','stop_requested','Detención solicitada desde la consola.');
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'No se pudo registrar la detención']); exit;
}
echo json_encode(['ok'=>true,'campaign_id'=>$campaignId,'message'=>'Señal de detención enviada; las llamadas en curso terminan y el worker se detiene.']);
