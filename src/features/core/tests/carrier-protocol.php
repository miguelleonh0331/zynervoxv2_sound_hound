<?php
require $argv[1].'/includes/Carriers.php';
use Includes\Carriers;
function checkProtocol($ok,$message) { if (!$ok) throw new RuntimeException($message); }
checkProtocol(Carriers::protocol(['protocol'=>'PJSIP','account_entry'=>"[carrier]\ntype=peer\nhost=127.0.0.1"])==='SIP','SIP overrides historical PJSIP value');
checkProtocol(Carriers::protocol(['account_entry'=>"[carrier]\nTYPE = friend ; legacy\n"])==='SIP','case and comments');
checkProtocol(Carriers::protocol(['account_entry'=>"[carrier]\ntype=endpoint\n[carrier]\ntype=aor\n"])==='PJSIP','PJSIP endpoint and aor');
checkProtocol(Carriers::protocol(['protocol'=>'SIP','account_entry'=>'; type=endpoint'])==='SIP','comments ignored and stored fallback');
try { Carriers::protocol(['account_entry'=>"type=peer\ntype=endpoint"]); throw new RuntimeException('mixed protocols accepted'); } catch(InvalidArgumentException $expected) {}
echo "PASS: SIP/PJSIP detection, historical value correction, comments and mixed-block rejection\n";
