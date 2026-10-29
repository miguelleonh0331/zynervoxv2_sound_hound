<?php
declare(strict_types=1);
require_once __DIR__.'/../config/Config.php';
function call_audit_directory(): string {
    if (!\Config\Config::deployment('isolated',false)) throw new RuntimeException('Isolated runtime required');
    $dir=rtrim((string)\Config\Config::deployment('runtime'),'/').'/bot_ivr/call_audit_pending';
    if (realpath($dir)!==$dir || !is_writable($dir)) throw new RuntimeException('Audit spool unavailable');
    return $dir;
}
function call_recording_metadata(string $callId): array {
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$callId)) throw new RuntimeException('Invalid call ID');
    $root=rtrim((string)\Config\Config::deployment('runtime'),'/');
    $path=$root.'/recordings/bot_ivr/'.$callId.'.wav';
    clearstatcache(true,$path);
    $bytes=!is_link($path) && is_file($path) ? filesize($path) : false;
    return ['recording_path'=>$bytes!==false?$path:null,'recording_status'=>$bytes===false?'none':($bytes>44?'ready':'empty'),'recording_bytes'=>$bytes===false?null:$bytes];
}
function call_audit_write(array &$entry): string {
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$entry['call_id'])) throw new RuntimeException('Invalid call ID');
    if ($entry['type']==='FINISH') $entry['data']=array_merge($entry['data'],call_recording_metadata($entry['call_id']));
    $dir=call_audit_directory();
    $tmp=tempnam($dir,'.audit-');
    if ($tmp===false) throw new RuntimeException('Audit spool unavailable');
    try {
        chmod($tmp,0600);
        $h=fopen($tmp,'wb');
        if (!$h) throw new RuntimeException('Audit spool unavailable');
        try {
            $text=json_encode($entry,JSON_THROW_ON_ERROR);
            if (fwrite($h,$text)!==strlen($text)) throw new RuntimeException('Incomplete audit journal');
            fflush($h); if (function_exists('fsync')) fsync($h);
        } finally {fclose($h);}
        $path=$dir.'/'.sprintf('%020.0f',microtime(true)*1000000).'-'.bin2hex(random_bytes(8)).'.json';
        if (!rename($tmp,$path)) throw new RuntimeException('Audit publication failed');
        return $path;
    } finally {if(is_file($tmp))unlink($tmp);}
}
function call_audit_apply(array $entry): ?int {
    require_once __DIR__.'/db.php';
    $db=carsa_db();
    if ($db->query('SELECT DATABASE()')->fetchColumn()!=='zynervox_core') throw new RuntimeException('Invalid tracking database');
    $repo=bot_ivr_repository(); $lead=null;
    $db->beginTransaction();
    try {
        $data=$entry['data']; $id=$entry['call_id'];
        if ($entry['type']==='FINISH' && !array_key_exists('recording_status',$data)) $data=array_merge($data,call_recording_metadata($id));
        if ($entry['type']==='START') $lead=$repo->startCall((int)$data['list_id'],$data['phone'],$id);
        elseif ($entry['type']==='FINISH') $repo->finishCall($id,$data['dial_status'],$data['amd_status'],$data['amd_cause'],(int)$data['hangup_cause'],(bool)$data['answered']);
        $repo->recordCallEvent($id,$entry['type'],$entry['event_id'],(int)$entry['epoch'],$data);
        $db->commit();
        return $lead;
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack(); throw $e;}
}
function call_audit_replay(): array {
    $dir=call_audit_directory();
    $lease=fopen($dir.'/.replay.lock','a');
    if (!$lease || !flock($lease,LOCK_EX|LOCK_NB)) return ['replayed'=>0,'pending'=>count(glob($dir.'/*.json'))];
    $count=0;
    try {
        $files=glob($dir.'/*.json'); sort($files,SORT_STRING);
        foreach($files as $file) {
            try {
                if(is_link($file)||filesize($file)>65536)throw new RuntimeException('Invalid journal');
                call_audit_apply(json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR));
                unlink($file); $count++;
            } catch(Throwable $e) {fwrite(STDERR,'ZV2 audit replay pending: '.get_class($e)."\n");}
        }
    } finally {fclose($lease);}
    return ['replayed'=>$count,'pending'=>count(glob($dir.'/*.json'))];
}
function call_audit_reconcile(): int {
    // Run from the root cron; fail closed when the PBX snapshot is unavailable.
    $process=proc_open(['/usr/sbin/asterisk','-rx','core show channels concise'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)) throw new RuntimeException('PBX snapshot unavailable');
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0 || strpos($output.$error,'Unable to connect')!==false) throw new RuntimeException('PBX snapshot unavailable');
    $tokens=[];
    foreach(explode("\n",trim($output)) as $line) foreach(explode('!',$line) as $token) $tokens[$token]=true;
    require_once __DIR__.'/db.php';
    $repo=bot_ivr_repository();$count=0;
    foreach($repo->openCallsForRecovery() as $call) {
        $active=false;
        foreach(['call_id','caller_channel','callee_channel','callee_unique_id','linked_id'] as $key) {
            if($call[$key]!=='' && isset($tokens[$call[$key]])) {$active=true;break;}
        }
        if($repo->observeCallPresence($call['call_id'],$active,time()))$count++;
    }
    return $count;
}
