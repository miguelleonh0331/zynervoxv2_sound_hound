<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/list_audio_jobs.php';
require_once __DIR__.'/../ivr_builder/published_flow.php';
require_once __DIR__.'/../includes/Carriers.php';
require_once __DIR__.'/../includes/PbxAmi.php';
function bot_list_call_directory(): string {
    $dir=bot_list_audio_root().'/bot_ivr/call_jobs';if(realpath($dir)!==$dir || !is_writable($dir))throw new RuntimeException('El worker de llamadas no esta instalado.');return $dir;
}
function bot_list_call_lease_active(int $listId): bool {
    $path=bot_list_call_directory().'/list_'.$listId.'.lock';if(!is_file($path))return false;
    $lease=fopen($path,'a');if(!$lease)throw new RuntimeException('No se pudo verificar el worker.');$free=flock($lease,LOCK_EX|LOCK_NB);fclose($lease);return !$free;
}
function bot_list_call_status(int $listId,int $campaignId): ?array {
    $repo=bot_ivr_repository();$run=$repo->dialRun($listId,$campaignId);
    if($run && in_array($run['state'],['starting','running','stopping'],true) && time()-strtotime($run['created_at'].' UTC')>15 && !bot_list_call_lease_active($listId)){
        $repo->transitionDialRun($run['run_id'],'failed','El worker se detuvo. Las llamadas existentes conservan su auditoria.');$run=$repo->dialRun($listId,$campaignId,$run['run_id']);
    }
    return $run;
}
function bot_list_call_public(?array $run): ?array {return $run?array_intersect_key($run,array_flip(['run_id','state','channels','launched','completed','active','active_list','pending','failed','error'])):null;}
function bot_list_call_configuration(int $listId,int $campaignId): array {
    $repo=bot_ivr_repository();$list=$repo->list($listId,$campaignId);$campaign=$repo->campaign($campaignId);
    if(!$list['active'] || !$campaign['active'] || empty($list['id_flujo']))throw new RuntimeException('Activa la lista y la campana y asigna su flujo.');
    $prefix=(string)$list['dial_prefix'];if($prefix==='' || !in_array($prefix,array_column(\Includes\Carriers::dialOrigins(),'dial_prefix'),true))throw new RuntimeException('Guarda un origen de llamada disponible.');
    $flow=ivr_builder_published_flow((int)$list['id_flujo']);
    $payload=bot_list_audio_payload($flow,$repo->audioLeads($listId,$campaignId),$listId,$campaignId,25);
    if(!bot_list_audio_ready(bot_list_audio_job_status($listId),$payload))throw new RuntimeException('Los audios de la lista deben estar listos.');
    $statuses=array_values(array_unique(preg_split('/\s+/',trim($campaign['dial_statuses']))));
    return ['statuses'=>$statuses,'order'=>$campaign['lead_order'],'prefix'=>$prefix,'flow_id'=>(int)$list['id_flujo'],'signature'=>$payload['signature'],'flow_sha256'=>hash('sha256',json_encode($flow,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'caller_id'=>$campaign['campaign_cid']];
}
function bot_list_call_start(int $listId,int $campaignId,int $channels,string $actor): array {
    $repo=bot_ivr_repository();$current=bot_list_call_status($listId,$campaignId);
    if($current && in_array($current['state'],['starting','running','stopping'],true))throw new RuntimeException('La lista ya tiene una marcacion activa.');
    $config=bot_list_call_configuration($listId,$campaignId);\Includes\PbxAmi::test(\Includes\PbxAmi::load());
    $run=bin2hex(random_bytes(16));$repo->createDialRun($listId,$campaignId,$run,$channels,$config,$actor);
    try {
        $state=$repo->dialRun($listId,$campaignId,$run);if(!$state['pending'])throw new RuntimeException('No hay leads pendientes en los estados a marcar de esta campana.');
        $dir=bot_list_call_directory();$python=__DIR__.'/../venvs/gtts_env/bin/python';$launcher=bot_list_audio_root().'/modules/bot_ivr/list_call_launcher.py';
        $process=proc_open([$python,$launcher,'--root',bot_list_audio_root(),'--web',realpath(__DIR__.'/..'),'--list',(string)$listId,'--campaign',(string)$campaignId,'--run',$run],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('No se pudo iniciar el worker.');$text=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($process);$answer=json_decode((string)$text,true);
        if($code!==0 || empty($answer['ok']))throw new RuntimeException('No se pudo iniciar el worker. Revisa su instalacion.');
        return $repo->dialRun($listId,$campaignId,$run);
    }catch(Throwable $e){$repo->transitionDialRun($run,'failed',$e instanceof RuntimeException?$e->getMessage():'No se pudo iniciar la marcacion.');throw $e;}
}
function bot_list_call_stop(int $listId,int $campaignId,string $runId): array {
    $repo=bot_ivr_repository();$run=bot_list_call_status($listId,$campaignId);if(!$run || $run['run_id']!==$runId)throw new RuntimeException('La marcacion cambio. Recarga la pagina.');
    if(in_array($run['state'],['starting','running'],true))$repo->transitionDialRun($runId,'stopping');return $repo->dialRun($listId,$campaignId,$runId);
}
