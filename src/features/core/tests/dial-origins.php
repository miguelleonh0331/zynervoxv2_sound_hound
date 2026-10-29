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
foreach (['zynervoxv2-amd','zynervoxv2-call-finish','zynervoxv2-outbound'] as $context) checkOrigin(substr_count($rendered,'['.$context.']')===1,'common context present exactly once');
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
echo "PASS: origin parsing, leading zeros, helper isolation and multi-carrier header\n";
