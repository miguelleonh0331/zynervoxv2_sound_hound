<?php
declare(strict_types=1);
function bot_ivr_graph_options(): array {
    $file=rtrim((string)\Config\Config::deployment('directory'),'/').'/ivr_engine.json';
    if (!is_readable($file)) throw new RuntimeException('IVR interactive providers unavailable');
    $options=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($options)) throw new RuntimeException('IVR interactive config invalid');
    return $options;
}
function bot_ivr_graph_io(string $action,array $data): array {
    $runtime=rtrim((string)\Config\Config::deployment('runtime'),'/');
    $config=rtrim((string)\Config\Config::deployment('directory'),'/').'/ivr_engine.json';
    $python=__DIR__.'/../venvs/gtts_env/bin/python';
    $p=proc_open([$python,$runtime.'/modules/bot_ivr/ivr_node_io.py',$config,$runtime], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('IVR adapter unavailable');
    fwrite($pipes[0],json_encode(['action'=>$action,'data'=>$data],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $deadline=microtime(true)+($action==='audio'?110:35);$output='';$exit=null;
    try {
        do {
            $output.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
            if(strlen($output)>65536) throw new RuntimeException('IVR adapter output too large');
            $status=proc_get_status($p);
            if (!$status['running']) { $exit=$status['exitcode'];break; }
            if(microtime(true)>$deadline) {proc_terminate($p,9);throw new RuntimeException('IVR adapter timeout');}
            usleep(20000);
        } while(true);
        $output.=stream_get_contents($pipes[1]);
    } finally {fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
    $result=json_decode($output,true);
    if($exit!==0 || !is_array($result)) throw new RuntimeException('IVR adapter failed');
    return $result;
}
