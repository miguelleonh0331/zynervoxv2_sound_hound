<?php
declare(strict_types=1);
require_once __DIR__.'/ivr_engine_service.php';
require_once __DIR__.'/../ivr_builder/forwarding.php';

const BOT_IVR_GRAPH_TYPES=['noop','goto','create_audio','create_audio_dynamic','playback','hangup','amd','menu','capture_stt','bridge','inject_sql','execute','reenviar'];
const BOT_IVR_SQL_FIELDS=['ID','FECHA','TELEFONO_BOT','ESTADO','RESULTADO','CLIENTE','NUMERO_CLIENTE','DIRECCION','TIENDA','MONTO','FECHA_AGENDADA','RESPUESTA_REGISTRADA','FECHA_INDICADA','HORA_VISITA','MINUTO_VISITA','ID_CONTACTO','CAMPAÑA','COLA','CALL_ID','DURACION_LLAMADA','PROVEEDOR','ID_CLASE','ID_USUARIO','FECHA_DE_GESTION','HORA_GESTION','HORA_INICIA_GESTION','HORA_FIN_GESTION','NUM_DOC','PRI_NOMBRE','SEG_NOMBRE','APE_PATERNO','APE_MATERNO','ID_TIPIFICACION_01','ID_TIPIFICACION_02','ID_TIPIFICACION_03','ID_AGENCIA'];

function bot_ivr_graph(array $flow): bool {
    foreach ($flow['nodes'] ?? [] as $node) if (!in_array($node['type'] ?? '',['noop','goto','create_audio','create_audio_dynamic','playback','hangup'],true)) return true;
    return false;
}

function bot_ivr_validate_graph(array $flow): void {
    $nodes=$flow['nodes'] ?? [];
    if (!$nodes || count($nodes)>100 || !isset($nodes[$flow['start'] ?? ''])) throw new RuntimeException('Invalid IVR graph');
    $visiting=[]; $visited=[];
    $walk=function($id) use (&$walk,&$visiting,&$visited,$nodes): void {
        if (isset($visiting[$id])) throw new RuntimeException('IVR cycle or step limit');
        if (isset($visited[$id])) return;
        $node=$nodes[$id] ?? null;
        if (!is_array($node) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)$id)) throw new RuntimeException('IVR node missing');
        $type=$node['type'] ?? '';
        if (!in_array($type,BOT_IVR_GRAPH_TYPES,true)) throw new RuntimeException('IVR node not supported: '.$type);
        if ($type==='capture_stt' && (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D',(string)($node['variable'] ?? '')) || !in_array($node['capture_mode'] ?? 'date',['date','text','name','number'],true))) throw new RuntimeException('Invalid IVR capture');
        if ((int)($node['retry_count'] ?? 0)<0 || (int)($node['retry_count'] ?? 0)>3 || (int)($node['timeout_ms'] ?? 6000)<500 || (int)($node['timeout_ms'] ?? 6000)>30000) throw new RuntimeException('Invalid IVR timeout or retries');
        if ($type==='inject_sql') {
            if (!is_array($node['inject_sql_fields'] ?? null) || !$node['inject_sql_fields']) throw new RuntimeException('Empty IVR SQL mapping');
            foreach ($node['inject_sql_fields'] as $field=>$template) if (!in_array($field,BOT_IVR_SQL_FIELDS,true) || !is_string($template) || strlen($template)>4000) throw new RuntimeException('Invalid IVR SQL field');
        }
        if ($type==='reenviar') ivr_forwarding_config($node);
        $targets=in_array($type,['hangup','reenviar'],true)?[]:[$node['next'] ?? '',$node['fallback'] ?? ''];
        foreach ($node['edges'] ?? [] as $digit=>$target) { if (!preg_match('/^[0-9*#]$/D',(string)$digit)) throw new RuntimeException('Invalid IVR DTMF'); $targets[]=$target; }
        foreach ($node['stt_intents'] ?? [] as $intent) {
            if (($intent['match'] ?? 'contains_any')!=='contains_any' || !is_array($intent['phrases'] ?? null) || !$intent['phrases']) throw new RuntimeException('Invalid IVR intent');
            foreach ($intent['phrases'] as $phrase) if (!is_string($phrase) || strlen($phrase)>240) throw new RuntimeException('Invalid IVR phrase');
            $targets[]=$intent['target'] ?? '';
        }
        $targets=array_values(array_filter($targets,fn($target)=>$target!==''));
        if (!in_array($type,['hangup','reenviar'],true) && !$targets) throw new RuntimeException('IVR flow has no terminal hangup');
        $visiting[$id]=true;
        foreach ($targets as $target) { if (!is_string($target) || !isset($nodes[$target])) throw new RuntimeException('IVR node missing'); $walk($target); }
        unset($visiting[$id]);$visited[$id]=true;
    };
    $walk($flow['start']);
}

function bot_ivr_normalize(string $text): string {
    $text=preg_replace('/[\[(][^\])]*[\])]/u',' ',$text) ?? '';
    $text=strtr(mb_strtolower($text,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return trim(preg_replace('/[^a-z0-9]+/',' ',$text) ?? '');
}

function bot_ivr_intent(array $node,string $text): ?array {
    $text=bot_ivr_normalize($text);
    if ($text==='') return null;
    $intents=$node['stt_intents'] ?? [];
    usort($intents,fn($a,$b)=>(int)($a['priority'] ?? 100)<=>(int)($b['priority'] ?? 100));
    foreach ($intents as $intent) foreach ($intent['phrases'] ?? [] as $phrase) {
        $phrase=bot_ivr_normalize((string)$phrase);
        if ($phrase!=='' && preg_match('/(?<!\w)'.preg_quote($phrase,'/').'(?!\w)/',$text)) return $intent;
        if (!empty($intent['fuzzy']) && $phrase!=='') {
            $words=explode(' ',$text);$count=count(explode(' ',$phrase));
            for ($i=0;$i<=count($words)-$count;$i++) {
                $candidate=implode(' ',array_slice($words,$i,$count));
                $score=1-levenshtein($phrase,$candidate)/max(strlen($phrase),strlen($candidate),1);
                if ($score>=0.78) return $intent;
            }
        }
    }
    return null;
}

function bot_ivr_render_fields(array $fields,array $vars): array {
    $result=[];
    foreach ($fields as $field=>$template) {
        if (!in_array($field,BOT_IVR_SQL_FIELDS,true)) throw new RuntimeException('Invalid IVR SQL field');
        $text=preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',fn($m)=>(string)($vars[$m[1]] ?? ''),(string)$template);
        $result[$field]=$text===''?null:$text;
    }
    return $result;
}

function bot_ivr_url(string $template,array $vars,array $allow): string {
    // Variables can fill only query values, never change the service host/path.
    $parts=parse_url($template);
    if (!$parts || !in_array($parts['scheme'] ?? '',['http','https'],true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new RuntimeException('Invalid IVR URL');
    $base=$parts['scheme'].'://'.($parts['host'] ?? '').(isset($parts['port'])?':'.$parts['port']:'').($parts['path'] ?? '');
    if (strpos($base,'{')!==false || !in_array($base,$allow,true)) throw new RuntimeException('IVR URL service not allowed');
    $query=preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',function($m) use($vars) {
        if (!array_key_exists($m[1],$vars) || (string)$vars[$m[1]]==='') throw new RuntimeException('IVR URL variable missing');
        $value=(string)$vars[$m[1]];
        if ($m[1]==='numero') $value=substr(preg_replace('/\D/','',$value) ?? '',-9);
        return rawurlencode($value);
    },$parts['query'] ?? '');
    return $base.($query!==''?'?'.$query:'');
}

final class BotIvrGraphRunner {
    private $flow; private $vars; private $runtime; private $published; private $command; private $event; private $io; private $result; private $options; private $callDir; private $sequence=0; private $hungup=false;
    public function __construct(array $flow,array $lead,string $call,string $runtime,callable $published,callable $command,callable $event,callable $io,callable $result,array $options=[]) {
        bot_ivr_validate_graph($flow);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$call)) throw new RuntimeException('Invalid IVR identity');
        $this->flow=$flow;$this->runtime=$runtime;$this->published=$published;$this->command=$command;$this->event=$event;$this->io=$io;$this->result=$result;$this->options=$options;
        $this->vars=bot_list_audio_variables($lead,(int)$lead['list_id'],(int)$lead['campaign_id']);
        $this->vars=array_merge($this->vars,$options['variables'] ?? []);
        $this->vars=array_merge($this->vars,['call_id'=>$call,'id_contacto'=>(string)$lead['lead_id'],'queue_id'=>'','numero'=>(string)$lead['phone'],'lead_id'=>(string)$lead['lead_id'],'list_id'=>(string)$lead['list_id'],'campaign_id'=>(string)$lead['campaign_id']]);
        foreach ($flow['nodes'] as $node) if (($node['type'] ?? '')==='capture_stt' && in_array($node['variable'],array_merge(['call_id','id_contacto','queue_id','numero','lead_id','list_id','campaign_id','nombre'],array_keys($options['variables'] ?? [])),true)) throw new RuntimeException('IVR capture cannot overwrite system variables');
        // System/captured values cannot originate from an uploaded contact.
        foreach (bot_list_audio_response_variables($flow) as $key) $this->vars[$key]='';
        $this->callDir=$runtime.'/bot_ivr/ivr_calls/'.$call;
    }
    private function send(string $command): ?string {
        if ($this->hungup) return null;
        $reply=($this->command)($command);
        if ($reply===null || preg_match('/result=-1(?:\s|$)/',$reply)) { $this->hungup=true;return null; }
        return $reply;
    }
    private function exec(string $app,string $args=''): ?string {
        $reply=$this->send('EXEC '.$app.($args!==''?' '.$args:''));
        if ($reply!==null && !preg_match('/^200 result=\d+(?:\s|$)/',$reply)) throw new RuntimeException('IVR application failed: '.$app);
        return $reply;
    }
    private function audio(array $node,string $field='audio_text',bool $listen=false): void {
        $text=(string)($node[$field] ?? '');
        $hash=null;
        if ($text==='') {
            if ($field==='audio_text' && ($node['type'] ?? '')==='create_audio_dynamic') $text=(string)($node['message'] ?? '');
            if ($text==='' && $field==='audio_text' && ($node['type'] ?? '')==='playback') {
                if (!preg_match('#^(?:'.preg_quote($this->runtime,'#').'/sounds/)?cache/ivr_builder/gtts/([a-f0-9]{64})(?:\.wav)?$#D',(string)($node['audio'] ?? ''),$m)) throw new RuntimeException('IVR audio outside published cache');
                $hash=$m[1];
            }
        }
        if ($text!=='' && $hash===null) {
            $rendered=bot_list_audio_render($text,$this->vars);$hash=bot_ivr_audio_hash($rendered);
            if (!(($this->published)($hash))) {
                $captured=bot_list_audio_response_variables($this->flow);
                preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',$text,$matches);
                if (!array_intersect($captured,$matches[1])) throw new RuntimeException('IVR audio not published');
                $generated=($this->io)('audio',['text'=>$rendered]);
                if (empty($generated['ok']) || ($generated['hash'] ?? '')!==$hash) throw new RuntimeException('IVR dynamic audio failed');
            }
        }
        if ($hash===null) return;
        if (!(($this->published)($hash))) throw new RuntimeException('IVR audio not published');
        ($this->event)('IVR_NODE',['node'=>$this->flow['_current'],'action'=>'playback','hash'=>$hash]);
        $reply=$this->send('STREAM FILE "'.$this->runtime.'/sounds/cache/ivr_builder/gtts/'.$hash.'" "'.($listen?'0123456789*#':'').'"');
        if ($reply!==null && (!preg_match('/^200 result=\d+(?:\s|$)/',$reply) || !preg_match('/endpos=([1-9][0-9]*)/',$reply))) throw new RuntimeException('IVR playback failed');
        if ($listen && $reply!==null && preg_match('/result=(\d+)/',$reply,$m) && (int)$m[1]>0) $this->vars['_digit']=chr((int)$m[1]);
    }
    private function listen(array $node,string $id,int $attempt,bool $amd=false): array {
        $base=$this->callDir.'/'.sprintf('%03d',$this->sequence++).'_'.$id.'_'.$attempt;
        $timeout=(int)($node['timeout_ms'] ?? ($amd?3000:6000));
        $digit='';
        if ($amd || !empty($node['stt_intents'])) {
            // Receive-only monitor catches speech while a menu prompt is playing.
            $this->vars['_digit']='';
            $this->exec('MixMonitor',$base.'_mix.wav,r('.$base.'.wav)i(ZV2_CAPTURE_MONITOR)');
            $reply=$this->send('GET VARIABLE ZV2_CAPTURE_MONITOR');
            if ($reply===null) return ['ok'=>false,'text'=>''];
            if (!preg_match('/^200 result=1 \(([A-Za-z0-9_.-]+)\)$/',$reply,$m)) throw new RuntimeException('IVR recording monitor unavailable');
            $monitor=$m[1];
            try {
                if (!$amd) $this->audio($node,$attempt?'retry_text':'audio_text',true);
                if (!$this->hungup && ($this->vars['_digit'] ?? '')==='') {
                    if ($amd) $this->exec('Wait',(string)($timeout/1000));
                    else { $reply=$this->send('WAIT FOR DIGIT '.$timeout);if ($reply!==null && preg_match('/result=(\d+)/',$reply,$m) && (int)$m[1]>0) $digit=chr((int)$m[1]); }
                }
                $digit=$this->vars['_digit'] ?: $digit;
            } finally { $this->exec('StopMixMonitor',$monitor); }
        } else {
            $this->audio($node,$attempt?'retry_text':'audio_text');
            if (!$this->hungup) {
                $reply=$this->send('RECORD FILE "'.$base.'" wav "0123456789*#" '.$timeout.' 0 s=3');
                if ($reply!==null && preg_match('/result=(\d+)/',$reply,$m) && (int)$m[1]>0) $digit=chr((int)$m[1]);
            }
        }
        if ($this->hungup) return ['ok'=>false,'text'=>''];
        $data=$digit!==''?['ok'=>true,'text'=>$digit,'digit'=>$digit]:($this->io)('transcribe',['path'=>$base.'.wav','provider'=>$amd?'amd':'menu']);
        ($this->event)('IVR_NODE',['node'=>$id,'action'=>'capture','attempt'=>$attempt,'provider'=>$data['provider'] ?? ($amd?'vosk':'stt'),'text'=>$data['text'] ?? '','error'=>$data['error'] ?? '', 'recording'=>$base.'.wav']);
        return $data;
    }
    public function run(): string {
        // Preflight contact-dependent audio before running any phone or URL action.
        $responses=bot_list_audio_response_variables($this->flow);
        foreach (bot_list_audio_templates($this->flow,false,false) as $template) {
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',$template,$matches);
            $vars=$this->vars;
            foreach ($responses as $key) $vars[$key]='captured';
            $rendered=bot_list_audio_render($template,$vars);
            if (!array_intersect($responses,$matches[1]) && !(($this->published)(bot_ivr_audio_hash($rendered)))) throw new RuntimeException('IVR audio not published');
        }
        $id=$this->flow['start'];$seen=[];
        while ($id!=='') {
            if ($this->hungup) return 'INTERRUPTED';
            if (isset($seen[$id]) || count($seen)>=100) throw new RuntimeException('IVR cycle or step limit');
            $seen[$id]=true;$this->flow['_current']=$id;$node=$this->flow['nodes'][$id];$type=$node['type'];$next=$node['next'] ?? '';
            ($this->event)('IVR_NODE',['node'=>$id,'node_type'=>$type]);
            // CARSA response stages and terminal dispositions segment the uploaded base.
            $stages=['interesado'=>'RESPONDE_F1','interesado_nivel2'=>'RESPONDE_F2','nointeresado'=>'NO_INTERESADO_F1','nointeresado_nivel2'=>'NO_INTERESADO_F2'];
            $status=$stages[$id] ?? ($type==='hangup' && !in_array(strtolower($id),['amd_hangup','hangup','fin','final'],true) ? strtoupper($id) : null);
            if ($status!==null && preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D',$status)) ($this->event)('IVR_NODE',['node'=>$id,'action'=>'status','status'=>$status]);

            if (in_array($type,['create_audio','create_audio_dynamic','playback','hangup'],true)) {
                $this->audio($node); if ($this->hungup) return 'INTERRUPTED';if ($type==='hangup') return 'COMPLETED';
            } elseif ($type==='bridge') $this->audio($node,'bridge_text');
            elseif ($type==='amd' || $type==='menu') {
                $next=$node['fallback'] ?? '';$tries=$type==='amd'?0:(int)($node['retry_count'] ?? 0);
                for ($attempt=0;$attempt<=$tries;$attempt++) {
                    $capture=$this->listen($node,$id,$attempt,$type==='amd');if ($this->hungup) return 'INTERRUPTED';
                    $intent=!empty($capture['ok'])?bot_ivr_intent($node,(string)($capture['text'] ?? '')):null;
                    $digit=$capture['digit'] ?? '';
                    if ($digit!=='' && isset($node['edges'][$digit])) { $next=$node['edges'][$digit];break; }
                    if ($intent) { $next=$intent['target'] ?? $next;if ($type==='amd') $this->vars['amd_categoria']=$intent['id'];break; }
                }
            } elseif ($type==='capture_stt') {
                $capture=['ok'=>false,'text'=>''];$value=['ok'=>false];
                for ($attempt=0;$attempt<=(int)($node['retry_count'] ?? 0);$attempt++) {
                    $capture=$this->listen($node,$id,$attempt);if ($this->hungup) return 'INTERRUPTED';
                    if (!empty($capture['ok'])) $value=($this->io)('capture_value',['text'=>(string)($capture['text'] ?? ''),'mode'=>$node['capture_mode'] ?? 'date']);
                    if (!empty($value['ok'])) break;
                }
                $variable=$node['variable'];
                $this->vars[$variable]=!empty($value['ok'])?(string)$value['value']:'';
                $this->vars[$variable.'_raw']=(string)($capture['text'] ?? '');
                $this->vars[$variable.'_spoken']=!empty($value['ok'])?(string)$value['spoken']:'';
                ($this->event)('IVR_NODE',['node'=>$id,'action'=>'capture_value','variable'=>$variable,'value'=>$this->vars[$variable],'raw'=>$this->vars[$variable.'_raw'],'ok'=>!empty($value['ok'])]);
                $next=$node[!empty($value['ok'])?'next':'fallback'] ?? '';
            } elseif ($type==='inject_sql') {
                $this->vars['fecha_sistema']=gmdate('Y-m-d H:i:s');$this->vars['fecha_sistema_fecha']=gmdate('Y-m-d');
                $fields=bot_ivr_render_fields($node['inject_sql_fields'],$this->vars);
                $ok=($this->result)($id,$fields,$node);
                ($this->event)('IVR_NODE',['node'=>$id,'action'=>'inject_sql','ok'=>$ok,'result'=>$fields['RESULTADO'] ?? null]);
                $next=$node[$ok?'next':'fallback'] ?? '';
            } elseif ($type==='reenviar') {
                $forward=ivr_forwarding_render($node,$this->vars);
                if ($forward['mode']==='dialplan') {
                    $context=(string)($node['contexto'] ?? '');
                    if ($context==='') {
                        $reply=$this->send('GET VARIABLE CONTEXT');
                        if (!preg_match('/^200 result=1 \(([A-Za-z0-9_-]{1,80})\)$/D',(string)$reply,$match)) throw new RuntimeException('Forward context unavailable');
                        $context=$match[1];
                    }
                    if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$context)) throw new RuntimeException('Invalid forward context');
                    $priority=(int)($node['prioridad'] ?? 1);
                    if ($priority<1 || $priority>99) throw new RuntimeException('Invalid forward priority');
                    $this->exec('Goto',$context.','.$forward['number'].','.$priority);
                    if ($this->hungup) return 'INTERRUPTED';
                    ($this->event)('IVR_NODE',['node'=>$id,'action'=>'forward','mode'=>'dialplan']);
                    return 'TRANSFERRED';
                }
                $endpoint=$forward['endpoint'];
                $protocol=$forward['protocol'];
                $lookup=$protocol==='SIP'?'SIPPEER('.$endpoint.',ip)':'PJSIP_ENDPOINT('.$endpoint.',aors)';
                $reply=$this->send('GET FULL VARIABLE "${'.$lookup.'}"');
                if (!preg_match('/^200 result=1 \(([^)]+)\)$/D',(string)$reply)) throw new RuntimeException('Forward endpoint unavailable');
                if ($protocol==='SIP') {
                    if (preg_match('/\((?:0\.0\.0\.0|\(null\))\)$/',(string)$reply)) throw new RuntimeException('Forward SIP peer unavailable');
                    $slots=[];$slot=1;
                    try {
                        foreach ($forward['headers'] as $header) {
                            while ($slot<=50) {
                                $reply=$this->send('GET VARIABLE SIPADDHEADER'.sprintf('%02d',$slot));
                                if ($reply==='200 result=0') break;
                                if ($reply===null || !preg_match('/^200 result=1 \(.*\)$/D',$reply)) throw new RuntimeException('Forward SIP header slot unavailable');
                                $slot++;
                            }
                            if ($slot>50) throw new RuntimeException('Forward SIP headers full');
                            $created=$slot++;$slots[]=$created;
                            $this->exec('SIPAddHeader',ivr_forwarding_agi_quote($header['name'].':'.$header['value']));
                            $reply=$this->send('GET VARIABLE SIPADDHEADER'.sprintf('%02d',$created));
                            if ($reply!=='200 result=1 ('.$header['name'].':'.$header['value'].')') throw new RuntimeException('Forward SIP header was not applied');
                        }
                        $target='SIP/'.$endpoint.($forward['mode']==='trunk'?'/'.$forward['number']:'');
                        $this->exec('Dial',$target.',30');
                    } finally {
                        // Clear only slots created here; existing channel headers survive.
                        foreach ($slots as $created) $this->send('SET VARIABLE __SIPADDHEADER'.sprintf('%02d',$created).' ""');
                    }
                } else {
                    $encoded=base64_encode(json_encode($forward['headers'],JSON_THROW_ON_ERROR));
                    $reply=$this->send('SET VARIABLE __ZV2_FORWARD_HEADERS "'.$encoded.'"');
                    if (!preg_match('/^200 result=1(?:\s|$)/',(string)$reply)) throw new RuntimeException('Forward headers unavailable');
                    $target='PJSIP/'.($forward['mode']==='trunk'?$forward['number'].'@':'').$endpoint;
                    $this->exec('Dial',$target.',30,b(zynervoxv2-forward-headers^s^1)');
                }
                if ($this->hungup) return 'INTERRUPTED';
                $reply=$this->send('GET VARIABLE DIALSTATUS');
                $ok=preg_match('/^200 result=1 \(ANSWER\)$/D',(string)$reply)===1;
                ($this->event)('IVR_NODE',['node'=>$id,'action'=>'forward','mode'=>$forward['mode'],'ok'=>$ok]);
                return $ok?'TRANSFERRED':'FORWARD_FAILED';
            } elseif ($type==='execute') {
                try {
                    $url=bot_ivr_url((string)($node['execute_url'] ?? ''),$this->vars,$this->options['execute_allowlist'] ?? []);
                    $out=($this->io)('execute',['url'=>$url,'timeout_ms'=>$node['timeout_ms'] ?? 6000]);$ok=!empty($out['ok']);
                } catch (RuntimeException $e) { $ok=false; }
                ($this->event)('IVR_NODE',['node'=>$id,'action'=>'execute','ok'=>$ok]);
                $next=$node[$ok?'next':'fallback'] ?? '';
            }
            if ($this->hungup) return 'INTERRUPTED';
            ($this->event)('IVR_NODE',['node'=>$id,'action'=>'transition','target'=>$next]);$id=$next;
        }
        throw new RuntimeException('IVR flow has no terminal hangup');
    }
}
