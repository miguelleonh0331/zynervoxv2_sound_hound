<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || count($argv)!==2) throw new RuntimeException('Usage: php backfill-call-recordings.php WEB_ROOT');
require $argv[1].'/bot_ivr/db.php';
require $argv[1].'/bot_ivr/call_audit_service.php';
if (!\Config\Config::deployment('isolated',false)) throw new RuntimeException('Isolated v2 required');
$db=carsa_db(); $count=0;
foreach ($db->query('SELECT call_id,list_id,finished_at FROM zynervox_bot_call_attempts WHERE finished_at IS NOT NULL AND recording_finalized_at IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $call) {
    $s=$db->prepare("SELECT event_type,payload_json FROM zynervox_bot_call_events WHERE call_id=? AND event_type IN ('IVR_START','IVR_END') ORDER BY occurred_at");$s->execute([$call['call_id']]);
    $meta=['flow_id'=>null,'campaign_id'=>null,'result'=>null];
    foreach($s as $event) { $data=json_decode($event['payload_json'],true,512,JSON_THROW_ON_ERROR); if($event['event_type']==='IVR_START') { $meta['flow_id']=$data['flow_id']??null;$meta['campaign_id']=$data['campaign_id']??null; } else $meta['result']=$data['result']??null; }
    $file=call_recording_metadata($call['call_id']);
    $db->prepare('UPDATE zynervox_bot_call_attempts SET flow_id=COALESCE(flow_id,?),campaign_id=COALESCE(campaign_id,?,(SELECT p.campaign_id FROM zynervox_bot_lists p WHERE p.list_id=zynervox_bot_call_attempts.list_id)),ivr_result=COALESCE(ivr_result,?),recording_path=?,recording_status=?,recording_bytes=?,recording_finalized_at=? WHERE call_id=? AND recording_finalized_at IS NULL')->execute([$meta['flow_id'],$meta['campaign_id'],$meta['result'],$file['recording_path'],$file['recording_status'],$file['recording_bytes'],$call['finished_at'],$call['call_id']]);$count++;
}
echo json_encode(['backfilled'=>$count]),PHP_EOL;
