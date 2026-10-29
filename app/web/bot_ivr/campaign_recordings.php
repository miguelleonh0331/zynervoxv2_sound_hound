<?php
declare(strict_types=1);

function campaign_recordings_dir(): string {
    require_once __DIR__ . '/../config/Config.php';
    return \Config\Config::deployment('isolated', false) ? \Config\Config::deployment('runtime') . '/recordings' : '/var/lib/asterisk/sounds/voicebot/recordings';
}

function campaign_recordings_list_files(PDO $db,int $campaignId): array {
    $stmt=$db->prepare('SELECT queue_id,file_path,byte_size,created_at FROM synervox_campaign_audio_files WHERE campaign_id=:id AND audio_kind="recording" AND status="ready" ORDER BY created_at');
    $stmt->execute([':id'=>$campaignId]);
    return array_map(static function(array $row):array{$path=(string)$row['file_path'];return ['queue_id'=>(int)$row['queue_id'],'file'=>basename($path),'path'=>$path,'recorded_at'=>(string)$row['created_at'],'size'=>(int)$row['byte_size']];},$stmt->fetchAll(PDO::FETCH_ASSOC));
}

function campaign_recordings_for_client(array $client,?array $fileIndex=null): array {
    $id=(int)($client['id']??0);$matches=[];
    foreach($fileIndex??[] as $entry){if((int)$entry['queue_id']!==$id)continue;$matches[]=['file'=>$entry['file'],'recorded_at'=>$entry['recorded_at'],'size'=>$entry['size']];}
    return $matches;
}
