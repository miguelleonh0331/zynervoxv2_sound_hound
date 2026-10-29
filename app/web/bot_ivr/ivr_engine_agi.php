#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
if (function_exists('pcntl_signal')) { pcntl_async_signals(true); pcntl_signal(SIGHUP,SIG_IGN); }
require __DIR__.'/db.php';
require __DIR__.'/ivr_engine_service.php';
require __DIR__.'/ivr_graph_service.php';
require __DIR__.'/ivr_graph_io.php';
require __DIR__.'/call_audit_service.php';
require_once __DIR__.'/../ivr_builder/published_flow.php';
$env=[];
while (($line=fgets(STDIN))!==false && trim($line)!=='') {
    $parts=explode(':',trim($line),2); if (count($parts)===2) $env[$parts[0]]=trim($parts[1]);
}
$sequence=0; $context=null; $lease=null; $hungup=false;
$call=(string)($argv[3] ?? '');
$audit=function(string $type,array $data) use (&$sequence,$call,$env): void {
    $data+=['channel'=>$env['agi_channel'] ?? '','unique_id'=>$env['agi_uniqueid'] ?? ''];
    $entry=['call_id'=>$call,'type'=>$type,'epoch'=>time(),'data'=>$data,'event_id'=>hash('sha256',$call.'|'.$type.'|ivr|'.($sequence++))];
    $journal=call_audit_write($entry); call_audit_apply($entry); unlink($journal);
};
$command=function(string $text) use (&$hungup): ?string {
    if ($hungup) return null;
    echo $text."\n"; fflush(STDOUT);
    $reply=fgets(STDIN);
    if ($reply===false || trim($reply)==='HANGUP') { $hungup=true; return null; }
    if (strpos($reply,'result=-1')!==false) $hungup=true;
    return trim($reply);
};
try {
    $list=filter_var($argv[1] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $lead=filter_var($argv[2] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$list || !$lead || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$call)) throw new RuntimeException('Invalid IVR identity');
    if (!\Config\Config::deployment('isolated',false)) throw new RuntimeException('Isolated IVR required');
    $runtime=rtrim((string)\Config\Config::deployment('runtime'),'/');
    $dir=$runtime.'/bot_ivr/ivr_calls';
    if (realpath($dir)!==$dir) throw new RuntimeException('IVR runtime unavailable');
    $lease=fopen($dir.'/'.$call.'.lock','c');
    if (!$lease || !flock($lease,LOCK_EX|LOCK_NB)) throw new RuntimeException('IVR already running');
    $context=bot_ivr_repository()->ivrCallContext($list,$lead,$call);
    $flow=ivr_builder_published_flow((int)$context['id_flujo']);
    $audit('IVR_START',['list_id'=>$list,'lead_id'=>$lead,'campaign_id'=>(int)$context['campaign_id'],'flow_id'=>(int)$context['id_flujo'],'flow_code'=>(string)$flow['flow_code'],'flow_name'=>(string)$flow['name'],'flow_sha256'=>hash('sha256',json_encode($flow,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))]);
    $index=$runtime.'/sounds/cache/ivr_builder/gtts/audio_registry.sqlite';
    if (!is_file($index)) throw new RuntimeException('Audio registry unavailable');
    $registry=new PDO('sqlite:'.$index,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $registry->exec('PRAGMA query_only=ON');
    $lookup=$registry->prepare('SELECT 1 FROM published_audio WHERE hash=?');
    $published=function($hash) use($lookup) { $lookup->execute([$hash]); return (bool)$lookup->fetchColumn(); };
    if (bot_ivr_graph($flow)) {
        $options=bot_ivr_graph_options();
        $callDir=$dir.'/'.$call;
        if (!is_dir($callDir) && !mkdir($callDir,0700)) throw new RuntimeException('IVR recording directory unavailable');
        if (realpath($callDir)!==$callDir || is_link($callDir)) throw new RuntimeException('Invalid IVR recording directory');
        $save=function($node,$fields,$settings) use($call,$options,$audit): bool {
            try {
                bot_ivr_repository()->saveIvrResult($call,$node,$fields);
                if (($options['result_destination'] ?? 'core')==='sqlserver') {
                    $out=bot_ivr_graph_io('sqlserver',['fields'=>$fields,'timeout_ms'=>$settings['timeout_ms'] ?? 6000]);
                    return !empty($out['ok']);
                }
                return true;
            } catch (Throwable $e) {
                $audit('IVR_NODE',['node'=>$node,'action'=>'inject_sql_error','error_class'=>get_class($e)]);
                return false;
            }
        };
        $runner=new BotIvrGraphRunner($flow,$context,$call,$runtime,$published,$command,$audit,'bot_ivr_graph_io',$save,$options);
        $result=$runner->run();
    } else {
        $steps=bot_ivr_steps($flow,$context,$runtime,$published);
        $result=bot_ivr_execute($steps,$command,$audit);
    }
    $audit('IVR_END',['result'=>$result]);
    $command('SET VARIABLE ZV2_IVR_RESULT "'.$result.'"');
} catch (Throwable $e) {
    if ($context!==null) { try { $audit('IVR_END',['result'=>'ERROR','error_class'=>get_class($e)]); } catch (Throwable $ignored) {} }
    $command('SET VARIABLE ZV2_IVR_RESULT "ERROR"');
    fwrite(STDERR,'ZV2 IVR failed: '.get_class($e)."\n");
    exit(1);
} finally { if (is_resource($lease)) fclose($lease); }
