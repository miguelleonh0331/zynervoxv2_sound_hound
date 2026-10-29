#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/call_audit_service.php';
if (($argv[1]??'')==='--replay') {
    $result=call_audit_replay();
    // Do not classify missing closures while captured events await DB recovery.
    if($result['pending']===0) $result['lost']=call_audit_reconcile();
    echo json_encode($result)."\n";exit;
}
$env=[];
while(($line=fgets(STDIN))!==false && trim($line)!=='') {
    $parts=explode(':',trim($line),2); if(count($parts)===2)$env[$parts[0]]=trim($parts[1]);
}
// HANGUPCAUSE(tech) may arrive as a bare code (486) or as text ("SIP 486 Busy Here"): store a readable value either way.
function sip_cause_text(string $raw): string {
    $raw=trim(preg_replace('/[\x00-\x1f\x7f]/','',$raw)??'');
    $names=[200=>'OK',400=>'Bad Request',401=>'Unauthorized',403=>'Forbidden',404=>'Not Found',408=>'Request Timeout',410=>'Gone',480=>'Temporarily Unavailable',484=>'Address Incomplete',486=>'Busy Here',487=>'Request Terminated',488=>'Not Acceptable Here',500=>'Server Internal Error',502=>'Bad Gateway',503=>'Service Unavailable',504=>'Server Time-out',600=>'Busy Everywhere',603=>'Decline',604=>'Does Not Exist Anywhere'];
    if (preg_match('/^[1-6][0-9]{2}$/D',$raw)) $raw='SIP '.$raw.(isset($names[(int)$raw])?' '.$names[(int)$raw]:'');
    return substr($raw,0,120);
}
function agi_set(string $name,string $value): void {
    echo 'SET VARIABLE '.$name.' "'.str_replace(['\\','"',"\r","\n"],['\\\\','\\"','',''],$value).'"'."\n";
    fflush(STDOUT); fgets(STDIN);
}
try {
    $action=$argv[1]??'';
    $data=['channel'=>$env['agi_channel']??'','unique_id'=>$env['agi_uniqueid']??''];
    if ($action==='start') {
        agi_set('ZV2_TRACK_OK','0');
        $list=filter_var($argv[2]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $phone=(string)($argv[3]??'');
        if(!$list || !preg_match('/^[0-9]{1,20}$/D',$phone))throw new RuntimeException('Invalid lead identity');
        $id=(string)($argv[4]??'');$type='START';
        $data += ['list_id'=>$list,'phone'=>$phone,'prefix'=>$argv[5]??'','carrier'=>$argv[6]??'','linked_id'=>$argv[7]??$id];
    } elseif ($action==='finish') {
        $id=(string)($argv[2]??'');$type='FINISH';
        $data += ['dial_status'=>$argv[3]??'','amd_status'=>$argv[4]??'','amd_cause'=>$argv[5]??'','hangup_cause'=>(int)($argv[6]??0),'answered'=>($argv[7]??'')==='1',
            // Marcador v1 (optional): argv[8] SIP code/text of the transfer leg, argv[9] dialplan stage (ANSWER|XFER|XFER_NODEST|AMD_MACHINE), argv[10] cause source.
            'sip_cause'=>sip_cause_text((string)($argv[8]??'')),'stage'=>(string)($argv[9]??''),'cause_source'=>(string)($argv[10]??'')];
    } elseif ($action==='event' && in_array($argv[3]??'',['OUTBOUND','ANSWER','AMD'],true)) {
        $id=(string)($argv[2]??'');$type=$argv[3];
        $data += ['amd_status'=>$argv[4]??'','amd_cause'=>$argv[5]??''];
    } else throw new RuntimeException('Invalid action');
    $entry=['call_id'=>$id,'type'=>$type,'epoch'=>time(),'data'=>$data,'event_id'=>hash('sha256',$id.'|'.$type.'|'.$data['unique_id'])];
    $journal=call_audit_write($entry);
    $lead=call_audit_apply($entry);
    unlink($journal);
    if($action==='start'){agi_set('ZV2_LEAD_ID',(string)$lead);agi_set('ZV2_TRACK_OK','1');}
    if($action==='finish')agi_set('ZV2_FINISH_OK','1');
} catch(Throwable $e) {
    fwrite(STDERR,'ZV2 audit pending/failure: '.get_class($e)."\n");exit(1);
}
