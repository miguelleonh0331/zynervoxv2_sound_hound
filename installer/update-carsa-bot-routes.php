<?php
if (PHP_SAPI !== 'cli' || count($argv)!==2) exit(1);
require_once $argv[1].'/includes/Dialplans.php';
$targets=[1=>['carsa_bot_amd','7306'],7=>['carsa_bot','7307']];
$plans=[];
foreach ($targets as $id=>[$name,$prefix]) {
    $row=\Includes\Dialplans::getById($id);
    if (!$row || $row['name']!==$name || $row['dial_prefix']!==$prefix) throw new RuntimeException('Target dialplan mismatch');
    $body=file_get_contents(__DIR__.'/../asterisk/synervox/modules/bot_ivr/'.$name.'.conf');
    if ($body===false) throw new RuntimeException('Missing route');
    $plans[$id]=[$row,$body];
}
foreach ($plans as $id=>[$row,$body]) {
    $result=\Includes\Dialplans::save($id,$row['name'],$body,$row['active']);
    if (!$result['ok']) throw new RuntimeException($result['error'] ?? 'Publication failed');
    echo $row['name'].' '.$row['dial_prefix']." updated\n";
}
