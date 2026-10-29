<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/auth.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
initial_survey_require_login();
require __DIR__ . '/db.php';
\Config\Config::requireLegacyRuntime();
require __DIR__ . '/campaign_audio_readiness.php';
require __DIR__ . '/campaign_state_summary.php';
require __DIR__ . '/campaign_runtime.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$max = (int) ($_POST['max'] ?? 0);
$origin = strtolower(trim((string)($_POST['origin'] ?? '')));
if ($origin === '') $origin = 'sipp_2006';
$allowedOrigins = ['sipp_2006', 'zypad_3006', 'zypad_whisper_4006'];
if (!in_array($origin, $allowedOrigins, true)) { echo json_encode(['ok'=>false,'error'=>'Origen de llamada inválido']); exit; }
if ($campaignId <= 0) { echo json_encode(['ok'=>false,'error'=>'Campaña inválida']); exit; }
$max = max(0, min(80, $max));

// PENDIENTE: services/dynamic_ivr/campaign_worker.py (el discador real) y
// las troncales SIPp/Zypad de mirmidon no se migraron todavia -- este
// endpoint valida todo correctamente pero el proceso de marcado en si no
// arrancara hasta migrar ese motor y conectar una troncal PJSIP propia
// (ver Carriers.php).
$worker = '/etc/asterisk/synervox/modules/bot_ivr/campaign_worker.py';

try {
    $db = carsa_db();
    if (campaign_process_active($db,$campaignId,'dialer')) {
        $running=campaign_process_get($db,$campaignId,'dialer');
        echo json_encode(['ok'=>false,'error'=>'La campaña ya se está ejecutando','pid'=>(int)($running['pid']??0)]); exit;
    }
    $stmt = $db->prepare('SELECT id, flow_code FROM synervox_campaigns WHERE id=:id');
    $stmt->execute([':id'=>$campaignId]);
    if (!$stmt->fetch()) { echo json_encode(['ok'=>false,'error'=>'Campaña inexistente']); exit; }
    $audio = campaign_audio_readiness($db, $campaignId);
    if (!$audio['ready']) {
        echo json_encode(['ok'=>false,'error'=>'Audios no preparados: '.$audio['reason'],'audio'=>$audio], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'BD: '.$e->getMessage()]); exit; }

if (!is_file($worker)) {
    echo json_encode(['ok'=>false,'error'=>'El motor de llamadas (services/dynamic_ivr/campaign_worker.py) todavía no está instalado en este servidor.']);
    exit;
}

$cmd = 'nohup setsid /usr/bin/python3 ' . escapeshellarg($worker)
     . ' ' . escapeshellarg((string)$campaignId) . ' ' . escapeshellarg((string)$max)
     . ' --origin ' . escapeshellarg($origin)
     . ' > /dev/null 2>&1 < /dev/null & echo $!';
$pid = trim((string) shell_exec($cmd));
if (!preg_match('/^[0-9]+$/', $pid) || (int)$pid <= 0) {
    echo json_encode(['ok'=>false,'error'=>'No se pudo iniciar el discador']); exit;
}
campaign_summary_upsert($db, $campaignId, [
    'campaign_status'=>'running',
    'last_error'=>null,
    'last_zypad'=>$origin !== 'sipp_2006' ? 1 : 0,
]);
campaign_process_started($db,$campaignId,'dialer',(int)$pid,['channels'=>$max,'origin'=>$origin]);
campaign_event($db,$campaignId,'dialer','info','process_started','Discador iniciado con PID '.$pid.'.');
$db->prepare('UPDATE synervox_campaigns SET desired_channels=:m, call_origin=:origin WHERE id=:id')
   ->execute([':m' => $max, ':origin'=>$origin, ':id' => $campaignId]);

echo json_encode(['ok'=>true,'campaign_id'=>$campaignId,'max'=>$max,'origin'=>$origin,'pid'=>(int)$pid], JSON_UNESCAPED_SLASHES);
