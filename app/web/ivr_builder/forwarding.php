<?php
declare(strict_types=1);

function ivr_forwarding_config(array $node): array {
    $config=$node['forwarding'] ?? ['mode'=>'dialplan','endpoint'=>'','number'=>(string)($node['anexo'] ?? ''),'headers'=>[]];
    if (!is_array($config)) throw new InvalidArgumentException('Reenviar: configuración inválida.');
    $mode=$config['mode'] ?? 'anexo';
    $endpoint=$config['endpoint'] ?? '';
    $number=$config['number'] ?? '';
    $headers=$config['headers'] ?? [];
    $protocol=$config['protocol'] ?? 'PJSIP';
    if (!in_array($protocol,['SIP','PJSIP'],true)) throw new InvalidArgumentException('Reenviar: protocolo inválido.');
    if (!in_array($mode,['dialplan','anexo','trunk'],true) || !is_string($endpoint) || !is_string($number) || !is_array($headers) || count($headers)>16) throw new InvalidArgumentException('Reenviar: destino inválido.');
    if ($mode!=='dialplan' && !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$endpoint)) throw new InvalidArgumentException('Reenviar: indica el endpoint del anexo o troncal.');
    if ($mode==='dialplan' && (!preg_match('/^[A-Za-z0-9_*#.-]{1,80}$/D',$number) || $headers)) throw new InvalidArgumentException('Reenviar por contexto no admite headers.');
    if ($mode==='trunk' && (!preg_match('/^(?:[+0-9*#]|\{[A-Za-z_][A-Za-z0-9_]*\}){1,120}$/D',$number) || strlen($number)>240)) throw new InvalidArgumentException('Reenviar: número o variable inválidos.');
    if ($mode==='anexo') $number='';
    $clean=[];$seen=[];
    foreach ($headers as $header) {
        if (!is_array($header)) throw new InvalidArgumentException('Reenviar: header inválido.');
        $name=$header['name'] ?? '';$value=$header['value'] ?? '';
        if (!is_string($name) || !preg_match('/^(?:X-[A-Za-z0-9-]{1,60}|User-to-User)$/Di',$name) || isset($seen[strtolower($name)]) || !is_string($value) || $value==='' || strlen($value)>1024 || preg_match('/[\x00-\x1f\x7f$]/',$value)) throw new InvalidArgumentException('Reenviar: usa headers X-* o User-to-User, sin controles ni duplicados.');
        $withoutVariables=preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/','',$value);
        if (strpbrk($withoutVariables,'{}')!==false) throw new InvalidArgumentException('Reenviar: variable inválida en header.');
        $clean[]=['name'=>$name,'value'=>$value];$seen[strtolower($name)]=true;
    }
    return ['mode'=>$mode,'protocol'=>$protocol,'endpoint'=>$endpoint,'number'=>$number,'headers'=>$clean];
}

function ivr_forwarding_render(array $node,array $variables): array {
    $config=ivr_forwarding_config($node);
    $render=function(string $template) use($variables): string {
        return preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',function($match) use($variables) {
            if (!array_key_exists($match[1],$variables) || !is_scalar($variables[$match[1]]) || (string)$variables[$match[1]]==='') throw new RuntimeException('Reenviar: variable ausente.');
            return (string)$variables[$match[1]];
        },$template);
    };
    if ($config['mode']==='trunk') {
        $config['number']=$render($config['number']);
        if (!preg_match('/^[+0-9*#]{1,40}$/D',$config['number'])) throw new RuntimeException('Reenviar: número resuelto inválido.');
    }
    foreach ($config['headers'] as &$header) {
        $header['value']=$render($header['value']);
        if (strlen($header['value'])>1024 || preg_match('/[\x00-\x1f\x7f${}]/',$header['value'])) throw new RuntimeException('Reenviar: valor de header inseguro.');
        if ($config['protocol']==='SIP' && str_contains($header['value'],'\\')) throw new RuntimeException('Reenviar: escapes de header SIP no permitidos.');
    }
    return $config;
}

function ivr_forwarding_agi_quote(string $value): string {
    return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
}
