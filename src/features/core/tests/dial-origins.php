<?php
require $argv[1].'/includes/DialplanOrigins.php';
use Includes\DialplanOrigins;
function checkOrigin($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$body = "exten => _07306X.,1,NoOp(test)\n same => n,Hangup()\n[helper]\nexten => _999X.,1,Return()\n";
checkOrigin(DialplanOrigins::prefixes(DialplanOrigins::HEADER.$body) === ['07306'], 'leading zero and helper exclusion');
checkOrigin(DialplanOrigins::prefixes("; exten => _12X.,1,Hangup()\nexten => _XXX.,1,Hangup()\nexten => 123,1,Hangup()") === [], 'reject comments and ambiguous patterns');
checkOrigin(DialplanOrigins::prefixes("exten => _12X.,1,Hangup()\nexten => _13X.,1,Hangup()\nexten => _12X.,1,Hangup()") === ['12','13'], 'multiple prefixes deduplicated');
$rendered = DialplanOrigins::render([
    ['carrier_id'=>'A','dialplan_entry'=>DialplanOrigins::HEADER.$body],
    ['carrier_id'=>'B','dialplan_entry'=>"exten => _888X.,1,Hangup()\n[other]\nexten => s,1,Return()"],
]);
checkOrigin(strpos($rendered,DialplanOrigins::template()) === 0 && substr_count($rendered,'[zynervoxv2]') === 1, 'common template followed by one route header');
checkOrigin(strpos($rendered,'_888X.') < strpos($rendered,'[helper]'), 'all carrier routes precede helper contexts');
checkOrigin(DialplanOrigins::body(DialplanOrigins::HEADER.$body) === $body, 'editable body without header');
checkOrigin(trim(DialplanOrigins::body($body."\n".DialplanOrigins::COMMON)) === trim($body), 'old common contexts removed from editable route');
foreach (['zynervoxv2-amd','zynervoxv2-call-finish','zynervoxv2-outbound','zynervoxv2-marcador','zynervoxv2-marcador-fin','zynervoxv2-marcador-salida','zynervoxv2-marcador-contestada','zynervoxv2-marcador-cierre'] as $context) checkOrigin(substr_count($rendered,'['.$context.']')===1,'common context present exactly once');
checkOrigin(strpos(DialplanOrigins::COMMON,'Dial(SIP/${ZV2_TRUNK}/${ZV2_DIAL_PREFIX}${ZV2_PHONE}')!==false && strpos(DialplanOrigins::COMMON,'U(zynervoxv2-marcador-contestada)')!==false,'marcador-salida dials from channel variables and runs AMD on the answered leg');
checkOrigin(strpos(DialplanOrigins::COMMON,'finish,${ZV2_CALL_ID},${DIALSTATUS},${ZV2_AMD_STATUS},${ZV2_AMD_CAUSE},${ZV2_Q850},${ZV2_ANSWERED},${ZV2_SIP},${ZV2_STAGE},${ZV2_CAUSE_SRC})')!==false,'marcador-cierre reports Q.850 and SIP code for every attempt');
checkOrigin(strpos(DialplanOrigins::COMMON,'Set(ZV2_AMDCAUSA=${CUT(AMDCAUSE,-,1)})')!==false && strpos(DialplanOrigins::COMMON,'Set(MASTER_CHANNEL(ZV2_AMD_CAUSE)=${ZV2_AMDCAUSA})')!==false,'marcador-contestada reports AMDCAUSE without numeric suffix and AMDSTATUS as is');
checkOrigin(!preg_match('/^\[[^\]]*ivr[^\]]*\]/mi',substr(DialplanOrigins::COMMON,strpos(DialplanOrigins::COMMON,'[zynervoxv2-marcador]'))),'marcador contexts carry no ivr in their name');
checkOrigin(strpos($rendered,'[zynervoxv2-outbound]')<strpos($rendered,'[zynervoxv2]'),'system contexts precede routes');
try {
    DialplanOrigins::body($body."\n".str_replace(' same => n,AMD()',' same => n,Hangup()',DialplanOrigins::COMMON));
    throw new RuntimeException('common context override accepted');
} catch (InvalidArgumentException $expected) {}
$sameHelpers = DialplanOrigins::render([
    ['carrier_id'=>'A','dialplan_entry'=>$body],
    ['carrier_id'=>'B','dialplan_entry'=>str_replace('_07306X.','_888X.',$body)],
]);
checkOrigin(substr_count($sameHelpers,'[helper]')===1, 'identical helpers emitted once');
try {
    DialplanOrigins::render([
        ['carrier_id'=>'A','dialplan_entry'=>$body],
        ['carrier_id'=>'B','dialplan_entry'=>str_replace('Return()','Hangup()',$body)],
    ]);
    throw new RuntimeException('conflicting helper accepted');
} catch (InvalidArgumentException $expected) {}
checkOrigin(strpos(DialplanOrigins::COMMON,'finish,${ZV2_CALL_ID},${DIALSTATUS},${ZV2_AMD_STATUS},${ZV2_AMD_CAUSE},${HANGUPCAUSE},${ZV2_ANSWERED},${ZV2_SIP},${ZV2_STAGE})')!==false,'marcador finish keeps the 5 legacy args and appends SIP cause and stage');
checkOrigin(strpos(DialplanOrigins::COMMON,'PJSIP_HEADER')===false,'no PJSIP_HEADER in chan_sip dialplan');
$real=implode("\n",['exten => _7307X.,1,NoOp(x)',' same => n,Set(__ZV2_PHONE=${EXTEN:4})',' same => n,Dial(SIP/carrier1/883651${ZV2_PHONE},60,U(zynervoxv2-bot-ivr-sin-amd))',' same => n,Hangup()'])."\n";
checkOrigin(DialplanOrigins::route('7307',DialplanOrigins::HEADER.$real)===['peer'=>'carrier1','prefix'=>'883651'],'route peer and dial prefix as data');
checkOrigin(DialplanOrigins::route('7307','exten => _7307X.,1,Dial(SIP/c-2/${ZV2_PHONE})')===['peer'=>'c-2','prefix'=>''],'route with empty dial prefix');
foreach (['7306','73'] as $bad) { try { DialplanOrigins::route($bad,$real); throw new RuntimeException('route without origin accepted'); } catch (InvalidArgumentException $expected) {} }
echo "PASS: origin parsing, leading zeros, helper isolation and multi-carrier header\n";
