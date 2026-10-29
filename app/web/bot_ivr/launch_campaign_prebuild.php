<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/auth.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
initial_survey_require_login();
require __DIR__ . '/db.php';
\Config\Config::requireLegacyRuntime();
require __DIR__ . '/campaign_state_summary.php';
require __DIR__ . '/campaign_runtime.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }
$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$allowedWorkers = [1, 3, 10, 25, 60, 100];
$requestedWorkers = (int) ($_POST['workers'] ?? 3);
$workers = in_array($requestedWorkers, $allowedWorkers, true) ? $requestedWorkers : 3;
$allowedProviders = ['macelioai_remote'];
$provider = strtolower(trim((string) ($_POST['provider'] ?? '')));
if ($campaignId <= 0) { echo json_encode(['ok'=>false,'error'=>'Campaña inválida']); exit; }
if (!in_array($provider, $allowedProviders, true)) { echo json_encode(['ok'=>false,'error'=>'Solo macelioai_remote está disponible para pregeneración en este servidor.']); exit; }

$script = '/etc/asterisk/synervox/modules/bot_ivr/prebuild_campaign_audios.py';

try {
    $db = carsa_db();
    if (campaign_process_active($db,$campaignId,'audio_build')) throw new RuntimeException('La generación de audios ya está activa');
    $stmt = $db->prepare('SELECT COUNT(*) FROM synervox_campaigns WHERE id=:id');
    $stmt->execute([':id'=>$campaignId]);
    if ((int)$stmt->fetchColumn() === 0) throw new RuntimeException('Campaña inexistente');
    $stmt = $db->prepare('SELECT COUNT(*) FROM carsa_initial_survey WHERE campaign_id=:id AND status="pending"');
    $stmt->execute([':id'=>$campaignId]);
    if ((int)$stmt->fetchColumn() === 0) throw new RuntimeException('La campaña no tiene clientes pendientes');
} catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

if (!is_file($script)) {
    echo json_encode(['ok'=>false,'error'=>'El generador de audios todavía no está instalado en este servidor.']);
    exit;
}

$cmd = 'nohup setsid /usr/bin/python3 ' . escapeshellarg($script)
     . ' --campaign-id ' . $campaignId . ' --workers ' . $workers
     . ' --provider ' . escapeshellarg($provider)
     . ' > /dev/null 2>&1 < /dev/null & echo $!';
$pid = trim((string)shell_exec($cmd));
if (!preg_match('/^[0-9]+$/', $pid) || (int)$pid <= 0) {
    campaign_summary_upsert($db, $campaignId, [
        'audio_status'=>'error',
        'last_error'=>'No se pudo iniciar el proceso de generación.',
    ]);
    echo json_encode(['ok'=>false,'error'=>'No se pudo iniciar la generación']); exit;
}
$db->prepare('UPDATE synervox_campaigns SET tts_provider=:provider WHERE id=:id')
   ->execute([':provider'=>$provider, ':id'=>$campaignId]);
campaign_summary_upsert($db, $campaignId, [
    'audio_status'=>'building',
    'campaign_status'=>'idle',
    'last_error'=>null,
]);
campaign_process_started($db,$campaignId,'audio_build',(int)$pid,['workers'=>$workers,'provider'=>$provider]);
campaign_event($db,$campaignId,'audio_build','info','process_started','Pregeneración iniciada con PID '.$pid.'.');
$build=$db->prepare('INSERT INTO synervox_campaign_audio_builds (campaign_id,provider,status,started_at,completed_at,error_message) VALUES (:id,:provider,"building",NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE provider=VALUES(provider),status="building",started_at=NOW(),completed_at=NULL,error_message=NULL');
$build->execute([':id'=>$campaignId,':provider'=>$provider]);
echo json_encode(['ok'=>true,'campaign_id'=>$campaignId,'workers'=>$workers,'provider'=>$provider,'pid'=>(int)$pid], JSON_UNESCAPED_SLASHES);
