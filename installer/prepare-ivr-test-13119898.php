<?php
// CLI-only preparation for the explicitly authorized flow 11 / 13119898 test.
if (PHP_SAPI!=='cli' || count($argv)!==2) exit(1);
require $argv[1].'/bot_ivr/db.php';
require $argv[1].'/bot_ivr/ivr_engine_service.php';
require $argv[1].'/ivr_builder/published_flow.php';
$db=carsa_db();
$stmt=$db->prepare('SELECT l.*,s.id_flujo,s.campaign_id FROM zynervox_bot_list l JOIN zynervox_bot_lists s ON s.list_id=l.list_id WHERE l.list_id=1 AND l.phone=?');
$stmt->execute(['13119898']);$lead=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$lead || (int)$lead['id_flujo']!==11) throw new RuntimeException('Test lead/flow mismatch');
$extra=json_decode($lead['extra_json'] ?? '{}',true) ?: [];
if (trim((string)($extra['nombre'] ?? $lead['customer_name']))==='') {
    $extra['nombre']='Prueba';
    $db->prepare('UPDATE zynervox_bot_list SET customer_name=?,extra_json=?,updated_at=NOW() WHERE lead_id=?')->execute(['Prueba',json_encode($extra),$lead['lead_id']]);
    $lead['customer_name']='Prueba';$lead['extra_json']=json_encode($extra);
}
$vars=bot_list_audio_variables($lead,1,(int)$lead['campaign_id']);
$templates=bot_list_audio_templates(ivr_builder_published_flow(11));
$python=$argv[1].'/venvs/gtts_env/bin/python';
$runtime=rtrim((string)\Config\Config::deployment('runtime'),'/');
foreach ($templates as $text) {
    $rendered=bot_list_audio_render($text,$vars);
    $code='import sys; from pathlib import Path; sys.path.insert(0,sys.argv[1]); from list_audio_worker import ensure_audio; print(ensure_audio(sys.argv[2],Path(sys.argv[3])))';
    $p=proc_open([$python,'-c',$code,$runtime.'/modules/bot_ivr',$rendered,$runtime.'/sounds/cache/ivr_builder/gtts'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
    fclose($pipes[0]);if(proc_close($p)!==0) throw new RuntimeException('Audio preparation failed');
}
echo "Test lead ready: ".$lead['lead_id']." flow 11\n";
