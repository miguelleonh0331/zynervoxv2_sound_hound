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
$channels = (int) ($_POST['channels'] ?? -1);
if ($campaignId <= 0) { echo json_encode(['ok'=>false,'error'=>'Campaña inválida']); exit; }
if ($channels < 0 || $channels > 200) { echo json_encode(['ok'=>false,'error'=>'Cantidad de canales inválida (0-200)']); exit; }

try {
    $db = carsa_db();
    $running=campaign_process_active($db,$campaignId,'dialer');
    $stmt = $db->prepare('UPDATE synervox_campaigns SET desired_channels=:ch WHERE id=:id');
    $stmt->execute([':ch' => $channels, ':id' => $campaignId]);
    $db->prepare('UPDATE synervox_campaign_processes SET requested_channels=:ch WHERE campaign_id=:id AND process_type="dialer"')->execute([':ch'=>$channels,':id'=>$campaignId]);
    if ($stmt->rowCount() === 0) {
        $check = $db->prepare('SELECT id FROM synervox_campaigns WHERE id=:id');
        $check->execute([':id' => $campaignId]);
        if (!$check->fetch()) { echo json_encode(['ok'=>false,'error'=>'Campaña inexistente']); exit; }
    }
    $message = $running
        ? "Canales deseados actualizados a {$channels}. El discador lo aplica solo en unos segundos, sin cortar llamadas en curso."
        : "Canales guardados ({$channels}). Se usarán la próxima vez que se lance esta campaña (Play manual o inicio programado).";
    echo json_encode(['ok'=>true,'campaign_id'=>$campaignId,'desired_channels'=>$channels,'running'=>$running,
        'message'=>$message]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
