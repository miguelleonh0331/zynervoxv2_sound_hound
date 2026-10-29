<?php
require $argv[1].'/includes/AmiClient.php';
function amiCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
$child= <<<'PHP'
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);echo substr(strrchr(stream_socket_get_name($server,false),':'),1),"\n";fflush(STDOUT);$client=stream_socket_accept($server,5);stream_set_timeout($client,5);fwrite($client,"Asterisk Call Manager/5.0\r\n");$seen=0;
while(true){$packet='';while(($line=fgets($client))!==false){$packet.=$line;if(trim($line)==='')break;}if($packet==='' || strpos($packet,'Action: Logoff')!==false)break;
 preg_match('/ActionID: ([a-f0-9]+)/',$packet,$id);$response='Success';
 if(strpos($packet,'Action: Originate')!==false){$seen++;if(strpos($packet,'Channel: Local/7307999000123@zynervoxv2/n')===false || strpos($packet,'OtherChannelId: fixture-call')===false || strpos($packet,'ChannelId: fixture-call-origin')===false || strpos($packet,'Variable: __ZV2_LIST_ID=26')===false || strpos($packet,'Variable: __ZV2_LEAD_ID=61')===false || strpos($packet,'Async: true')===false)exit(3);if($seen===2)$response='Error';}
 fwrite($client,"Event: Ignored\r\n\r\nResponse: ".$response."\r\nActionID: ".($id[1]??'')."\r\n\r\n");}
fclose($client);fclose($server);if($seen!==2)exit(4);
PHP;
$p=proc_open([PHP_BINARY,'-r',$child],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);stream_set_timeout($pipes[1],5);$port=(int)fgets($pipes[1]);
try{$ami=new \Includes\AmiClient(['host'=>'127.0.0.1','port'=>$port,'username'=>'fixture','secret'=>'private-fixture']);$lead=['phone'=>'999000123','call_id'=>'fixture-call','list_id'=>26,'lead_id'=>61];amiCheck($ami->originate($lead,'7307'),'originate failed');amiCheck(!$ami->originate($lead,'7307'),'rejection accepted');$ami->ping();$ami->close();}finally{fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);}amiCheck($exit===0,'AMI request fixture failed');
echo "AMI_CLIENT_OK: async originate, Local prefix/list correlation, interleaved events, rejection, Ping and Logoff\n";
