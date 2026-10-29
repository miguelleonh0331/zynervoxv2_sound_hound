<?php
declare(strict_types=1);
require_once __DIR__.'/list_audio_service.php';

function bot_ivr_audio_hash(string $text): string {
    $text=trim(preg_replace('/\s+/u',' ',$text) ?? '');
    $profile=['provider'=>'gtts','voice'=>'google-es-com','language'=>'es','speed'=>'1.3','format'=>'pcm_s16le-mono-8000','pipeline'=>'v1','text'=>$text];
    ksort($profile);
    return hash('sha256',json_encode($profile,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS));
}

function bot_ivr_steps(array $flow, array $lead, string $runtime, callable $published): array {
    $vars=bot_list_audio_variables($lead,(int)$lead['list_id'],(int)$lead['campaign_id']);
    $id=$flow['start'] ?? ''; $seen=[]; $steps=[];
    while ($id!=='') {
        if (isset($seen[$id]) || count($steps)>=100) throw new RuntimeException('IVR cycle or step limit');
        $seen[$id]=true;
        $node=$flow['nodes'][$id] ?? null;
        if (!is_array($node)) throw new RuntimeException('IVR node missing');
        $type=$node['type'] ?? '';
        if (!in_array($type,['noop','goto','create_audio','create_audio_dynamic','playback','hangup'],true)) throw new RuntimeException('IVR node not supported: '.$type);
        $hash=null;
        $text=$node['audio_text'] ?? '';
        if ($type==='create_audio_dynamic' && trim($text)==='') $text=$node['message'] ?? '';
        if (!in_array($type,['noop','goto'],true)) {
            if (trim($text)!=='') $hash=bot_ivr_audio_hash(bot_list_audio_render($text,$vars));
            elseif ($type==='playback') {
                if (!preg_match('#^(?:'.preg_quote($runtime,'#').'/sounds/)?cache/ivr_builder/gtts/([a-f0-9]{64})(?:\.wav)?$#D',(string)($node['audio'] ?? ''),$match)) throw new RuntimeException('IVR audio outside published cache');
                $hash=$match[1];
            } elseif (in_array($type,['create_audio','create_audio_dynamic'],true)) throw new RuntimeException('IVR audio text missing');
        }
        if ($hash!==null && !$published($hash)) throw new RuntimeException('IVR audio not published');
        $steps[]=['node'=>(string)$id,'type'=>$type,'hash'=>$hash,'audio'=>$hash===null?null:$runtime.'/sounds/cache/ivr_builder/gtts/'.$hash];
        if ($type==='hangup') return $steps;
        $id=(string)($node['next'] ?? '');
    }
    throw new RuntimeException('IVR flow has no terminal hangup');
}

function bot_ivr_execute(array $steps, callable $command, callable $event): string {
    foreach ($steps as $step) {
        $event('IVR_NODE',['node'=>$step['node'],'node_type'=>$step['type'],'hash'=>$step['hash']]);
        if ($step['audio']!==null) {
            $reply=$command('STREAM FILE "'.$step['audio'].'" ""');
            if ($reply===null || preg_match('/result=-1(?:\s|$)/',$reply)) return 'INTERRUPTED';
            if (!preg_match('/^200 result=0(?:\s|$)/',$reply) || !preg_match('/endpos=([1-9][0-9]*)/',$reply)) throw new RuntimeException('IVR playback failed');
        }
    }
    return 'COMPLETED';
}
