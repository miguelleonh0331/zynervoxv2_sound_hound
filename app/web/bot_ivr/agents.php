<?php
declare(strict_types=1);

function initial_survey_configured_agents(string $baseDir): array {
    require_once __DIR__.'/db.php';
    $rows=carsa_db()->query('SELECT extension FROM synervox_bot_agents WHERE active=1 ORDER BY sort_order,extension')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_map('strval',$rows));
}

function initial_survey_agent_statuses(string $baseDir): array {
    require_once __DIR__ . '/../config/Config.php';
    if (\Config\Config::deployment('isolated', false)) return [];
    $agents=initial_survey_configured_agents($baseDir);$output=(string)shell_exec("sudo -n /usr/sbin/asterisk -rx 'pjsip show endpoints' 2>&1");$statuses=[];
    foreach($agents as $agent){$found=preg_match('/^\s*Endpoint:\s+'.preg_quote($agent,'/').'\//m',$output)===1;$available=$found&&preg_match('/^\s*Endpoint:\s+'.preg_quote($agent,'/').'\/.*\bAvail\b/m',$output)===1;$statuses[]=['agent'=>$agent,'available'=>$available,'state'=>$available?'disponible':'no disponible','detail'=>$available?'Registrado en PJSIP':($found?'Endpoint PJSIP sin registro':'No existe como endpoint PJSIP'),'peer_found'=>$found];}
    return $statuses;
}

function initial_survey_available_agents(string $baseDir): array {return array_values(array_map(static fn(array $s):string=>$s['agent'],array_filter(initial_survey_agent_statuses($baseDir),static fn(array $s):bool=>$s['available'])));}
