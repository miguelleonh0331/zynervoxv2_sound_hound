<?php
declare(strict_types=1);

function campaign_process_get(PDO $db, int $campaignId, string $type): ?array {
    $stmt=$db->prepare('SELECT * FROM synervox_campaign_processes WHERE campaign_id=:id AND process_type=:type');
    $stmt->execute([':id'=>$campaignId,':type'=>$type]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function campaign_pid_alive(?array $process): bool {
    $pid=(int)($process['pid']??0);
    return $pid>0 && is_dir('/proc/'.$pid);
}

function campaign_process_active(PDO $db, int $campaignId, string $type): bool {
    $process=campaign_process_get($db,$campaignId,$type);
    if(!$process || !in_array((string)$process['state'],['starting','running','stopping'],true)) return false;
    if(campaign_pid_alive($process)) return true;
    $stmt=$db->prepare('UPDATE synervox_campaign_processes SET state="failed",finished_at=NOW(),last_error="Proceso ausente" WHERE campaign_id=:id AND process_type=:type');
    $stmt->execute([':id'=>$campaignId,':type'=>$type]);
    campaign_event($db,$campaignId,$type,'error','process_missing','El proceso registrado ya no existe.');
    return false;
}

function campaign_process_started(PDO $db,int $campaignId,string $type,int $pid,array $options=[]): void {
    $stmt=$db->prepare('INSERT INTO synervox_campaign_processes
      (campaign_id,process_type,pid,state,requested_workers,requested_channels,provider,origin,stop_requested_at,started_at,heartbeat_at,finished_at,last_error)
      VALUES (:id,:type,:pid,"running",:workers,:channels,:provider,:origin,NULL,NOW(),NOW(),NULL,NULL)
      ON DUPLICATE KEY UPDATE pid=VALUES(pid),state="running",requested_workers=VALUES(requested_workers),requested_channels=VALUES(requested_channels),provider=VALUES(provider),origin=VALUES(origin),stop_requested_at=NULL,started_at=NOW(),heartbeat_at=NOW(),finished_at=NULL,last_error=NULL');
    $stmt->execute([':id'=>$campaignId,':type'=>$type,':pid'=>$pid,':workers'=>$options['workers']??null,':channels'=>$options['channels']??null,':provider'=>$options['provider']??null,':origin'=>$options['origin']??null]);
}

function campaign_stop_request(PDO $db,int $campaignId): void {
    $stmt=$db->prepare('UPDATE synervox_campaign_processes SET state="stopping",stop_requested_at=NOW() WHERE campaign_id=:id AND process_type="dialer" AND state IN ("starting","running","stopping")');
    $stmt->execute([':id'=>$campaignId]);
}

function campaign_stop_requested(?array $process): bool {
    return $process && $process['stop_requested_at']!==null && in_array((string)$process['state'],['stopping','running'],true);
}

function campaign_event(PDO $db,int $campaignId,?string $processType,string $level,string $eventType,string $message): void {
    $stmt=$db->prepare('INSERT INTO synervox_campaign_events (campaign_id,process_type,level,event_type,message) VALUES (:id,:process,:level,:event,:message)');
    $stmt->execute([':id'=>$campaignId,':process'=>$processType,':level'=>$level,':event'=>$eventType,':message'=>mb_substr($message,0,5000)]);
}
