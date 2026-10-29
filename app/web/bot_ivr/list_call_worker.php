<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/list_call_service.php';
require_once __DIR__.'/../includes/AmiClient.php';
function bot_list_call_loop(int $listId,int $campaignId,string $runId,\ZynervoxQueries\BotIvrRepository $repo,$ami,?callable $wait=null): void {
    $wait=$wait??static function(){sleep(1);};$lastPing=time();
    while(true){
        $run=$repo->dialRun($listId,$campaignId,$runId);if(!$run)throw new RuntimeException('Marcacion no encontrada.');
        if($run['state']==='starting'){$repo->transitionDialRun($runId,'running');continue;}
        if(!in_array($run['state'],['running','stopping'],true))return;
        if(!$run['active'] && ($run['state']==='stopping' || !$run['pending'])){$repo->transitionDialRun($runId,$run['state']==='stopping'?'stopped':'completed');return;}
        if(time()-$lastPing>=15){$ami->ping();$lastPing=time();}
        if($run['state']==='running'){
            $cfg=json_decode($run['config_json'],true,512,JSON_THROW_ON_ERROR);
            if(isset($cfg['flow_sha256'])){ $flow=ivr_builder_published_flow((int)$run['flow_id']);if(hash('sha256',json_encode($flow,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))!==$cfg['flow_sha256'])throw new RuntimeException('El flujo cambio. Genera sus audios antes de volver a marcar.'); }
            for($slot=$run['active_list'];$slot<(int)$run['channels'];$slot++){
                $lead=$repo->claimDialLead($runId,'zv2-'.bin2hex(random_bytes(12)));if(!$lead)break;
                // Never retry a lost/ambiguous AMI acknowledgement: the claim remains auditable.
                if(!$ami->originate($lead,$run['dial_prefix'],$cfg['caller_id'])){$repo->rejectDialClaim($runId,$lead['call_id']);throw new RuntimeException('AMI rechazo iniciar la llamada. Revisa los permisos del usuario.');}
            }
        }
        $wait();
    }
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))!==__FILE__)return;
ini_set('display_errors','0');set_time_limit(0);umask(0027);
$options=getopt('',['list:','campaign:','run:','lease-fd:']);$lease=null;$ami=null;$repo=null;
try {
    $list=filter_var($options['list']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$campaign=filter_var($options['campaign']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$run=$options['run']??'';$fd=filter_var($options['lease-fd']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>3]]);
    if(!$list || !$campaign || !$fd || !preg_match('/^[a-f0-9]{32}$/D',$run))throw new RuntimeException('Identidad del worker invalida.');
    $lease=fopen('php://fd/'.$fd,'r+');if(!$lease || !flock($lease,LOCK_EX|LOCK_NB))throw new RuntimeException('Worker duplicado.');
    $repo=bot_ivr_repository();$state=$repo->dialRun($list,$campaign,$run);if(!$state)throw new RuntimeException('Marcacion no encontrada.');
    $expected=json_decode($state['config_json'],true,512,JSON_THROW_ON_ERROR);$current=bot_list_call_configuration($list,$campaign);if($expected!==$current)throw new RuntimeException('La lista o campana cambiaron antes de iniciar.');
    $ami=new \Includes\AmiClient(\Includes\PbxAmi::load());bot_list_call_loop($list,$campaign,$run,$repo,$ami);
}catch(Throwable $e){
    if($repo && isset($run)){try{$repo->transitionDialRun($run,'failed',$e instanceof RuntimeException?$e->getMessage():'No se pudo continuar la marcacion.');}catch(Throwable $ignored){}}
    fwrite(STDERR,'ZV2 call worker failed: '.get_class($e)."\n");exit(1);
}finally{if($ami)$ami->close();if(is_resource($lease))fclose($lease);}
