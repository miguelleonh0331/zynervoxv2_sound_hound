<?php
require $argv[1].'/includes/Dialplans.php';
use Includes\Dialplans;
use Includes\Carriers;
function checkDialplan($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$ids=[];
$prefix='009'.random_int(100000000,999999999);
$other=$prefix.'1';
$context='fixture-'.bin2hex(random_bytes(4));
$body="exten => _{$prefix}X.,1,Hangup()\n[{$context}]\nexten => s,1,Return()";
try {
    $saved=Dialplans::save(null,'Fixture',$body,'Y'); $ids[]=$saved['id'];
    checkDialplan($saved['ok'],'generated');
    checkDialplan(Dialplans::getById($ids[0])['dial_prefix']===$prefix,'prefix extracted with leading zeros');
    checkDialplan(in_array($prefix,array_column(Carriers::dialOrigins(),'dial_prefix'),true),'origin exposed');
    try { Dialplans::save(null,'Duplicate',$body,'Y'); throw new RuntimeException('duplicate accepted'); } catch(InvalidArgumentException $expected) {}
    try { Dialplans::save(null,'Ambiguous',"exten => _123X.,1,Hangup()\nexten => _124X.,1,Hangup()",'Y'); throw new RuntimeException('ambiguous accepted'); } catch(InvalidArgumentException $expected) {}
    $shared=str_replace('_'.$prefix.'X.','_'.$other.'X.',$body);
    $saved=Dialplans::save(null,'Shared helpers',$shared,'Y'); $ids[]=$saved['id']; checkDialplan($saved['ok'],'identical helpers shared');
    try { Dialplans::save($ids[1],'Conflicting helpers',str_replace('Return()','Hangup()',$shared),'Y'); throw new RuntimeException('conflict accepted'); } catch(InvalidArgumentException $expected) {}
    Dialplans::save($ids[0],'Renamed',$body,'N');
    checkDialplan(!in_array($prefix,array_column(Carriers::dialOrigins(),'dial_prefix'),true),'inactive omitted');
    checkDialplan(Dialplans::getById($ids[0])['name']==='Renamed','name and state updated');
    Dialplans::delete($ids[0]);
    checkDialplan(Dialplans::getById($ids[0])===null,'delete'); array_shift($ids);
    echo "PASS: independent dialplan CRUD, origin, leading zeros, duplicates, inactivity and shared-context conflicts\n";
} finally {
    foreach($ids as $id) if (Dialplans::getById($id)) Dialplans::delete($id);
}
