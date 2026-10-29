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
$provider = strtolower(trim((string) ($_POST['provider'] ?? '')));
$allowedProviders = ['macelioai', 'macelioai_remote', 'deepgram', 'deepgram_pool', 'voicescloning', 'deepgram_pod'];
if ($campaignId <= 0) { echo json_encode(['ok'=>false,'error'=>'Campaña inválida']); exit; }
if (!in_array($provider, $allowedProviders, true)) { echo json_encode(['ok'=>false,'error'=>'Proveedor TTS inválido']); exit; }

try {
    $db = carsa_db();
    if(campaign_process_active($db,$campaignId,'dialer')||campaign_process_active($db,$campaignId,'audio_build')){echo json_encode(['ok'=>false,'error'=>'No se puede cambiar el proveedor mientras la campaña o la generación están activas.']);exit;}
    $stmt = $db->prepare('UPDATE synervox_campaigns SET tts_provider=:provider WHERE id=:id');
    $stmt->execute([':provider'=>$provider, ':id'=>$campaignId]);
    if ($stmt->rowCount() === 0) {
        $check = $db->prepare('SELECT id FROM synervox_campaigns WHERE id=:id');
        $check->execute([':id'=>$campaignId]);
        if (!$check->fetch()) { echo json_encode(['ok'=>false,'error'=>'Campaña inexistente']); exit; }
    }
    echo json_encode([
        'ok'=>true, 'campaign_id'=>$campaignId, 'tts_provider'=>$provider,
        'message'=>'Proveedor guardado. Si los audios actuales se generaron con otro proveedor, debes regenerarlos antes de Play.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
