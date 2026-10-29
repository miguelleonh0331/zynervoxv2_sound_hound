<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/auth.php';
ob_end_clean();
initial_survey_require_login();
require __DIR__ . '/db.php';
require __DIR__ . '/campaign_recordings.php';

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$queueId = (int)($_GET['queue_id'] ?? 0);
$file = basename((string)($_GET['file'] ?? ''));
if ($campaignId <= 0 || $queueId <= 0 || $file === '') {
    http_response_code(400);
    exit('Solicitud invalida');
}

$db=carsa_db();
$stmt = $db->prepare(
    'SELECT id, phone, started_at, completed_at FROM carsa_initial_survey
     WHERE id=:queue_id AND campaign_id=:campaign_id'
);
$stmt->execute([':queue_id' => $queueId, ':campaign_id' => $campaignId]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
$recordings=$client?campaign_recordings_list_files($db,$campaignId):[];
$allowed = $client ? campaign_recordings_for_client($client,$recordings) : [];
$selected=null;foreach($recordings as $recording){if((int)$recording['queue_id']===$queueId&&$recording['file']===$file){$selected=$recording;break;}}
$path = (string)($selected['path']??'');
if (!$client || !in_array($file, array_column($allowed, 'file'), true) || !is_file($path)) {
    http_response_code(404);
    exit('Audio no encontrado');
}

$size = (int)filesize($path);
$start = 0;
$end = max(0, $size - 1);
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = min((int)$m[1], $end);
    if ($m[2] !== '') $end = min((int)$m[2], $end);
    if ($start > $end) { http_response_code(416); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Type: audio/wav');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Content-Disposition: inline; filename="' . $file . '"');
$handle = fopen($path, 'rb');
if ($handle === false) { http_response_code(500); exit; }
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(8192, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);

