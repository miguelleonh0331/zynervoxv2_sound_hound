<?php
require $argv[1].'/includes/PbxAmi.php';
use Includes\PbxAmi;
function pbxCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
$config=['host'=>'127.0.0.1','port'=>5038,'username'=>'fixture','secret'=>'private-fixture'];
pbxCheck(PbxAmi::validate(array_merge($config,['secret'=>'']),$config)['secret']==='private-fixture','password preservation');
foreach([['host'=>'localhost/evil'],['port'=>0],['username'=>"user\r\nAction: Originate"],['secret'=>"secret\nAction: Originate"]] as $bad){$failed=false;try{PbxAmi::validate(array_merge($config,$bad));}catch(InvalidArgumentException $e){$failed=true;}pbxCheck($failed,'invalid AMI fields accepted');}
function fakeAmi(bool $success): void {
 $child= <<<'PHP'
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$server)exit(2);
echo substr(strrchr(stream_socket_get_name($server,false),':'),1),"\n";fflush(STDOUT);
$client=stream_socket_accept($server,5);if(!$client)exit(2);stream_set_timeout($client,5);fwrite($client,"Asterisk Call Manager/5.0\r\n");
$packet='';while(($line=fgets($client))!==false){$packet.=$line;if(trim($line)==='')break;}
preg_match('/ActionID: ([a-f0-9]+)/',$packet,$m);if(strpos($packet,'Events: off')===false || strpos($packet,'Action: Originate')!==false)exit(3);
$success=$argv[1]==='yes';fwrite($client,'Response: '.($success?'Success':'Error')."\r\nActionID: ".($m[1]??'')."\r\n\r\n");
if($success){$logoff='';while(($line=fgets($client))!==false){$logoff.=$line;if(trim($line)==='')break;}if(strpos($logoff,'Action: Logoff')===false)exit(4);}
fclose($client);fclose($server);
PHP;
 $process=proc_open([PHP_BINARY,'-r',$child,$success?'yes':'no'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('AMI fixture unavailable');
 stream_set_timeout($pipes[1],5);$port=(int)fgets($pipes[1]);$failed=false;
 try {pbxCheck($port>0,'fixture port');PbxAmi::test(['host'=>'127.0.0.1','port'=>$port,'username'=>'fixture','secret'=>'private-fixture']);}catch(RuntimeException $e){$failed=true;}
 fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);pbxCheck($exit===0,'AMI fixture protocol failed');pbxCheck($failed!==$success,'AMI login outcome wrong');
}
fakeAmi(true);fakeAmi(false);
// Test persistence against a temporary private directory, never deployed credentials.
$temp=sys_get_temp_dir().'/pbx-fixture-'.bin2hex(random_bytes(8));mkdir($temp,0700);mkdir($temp.'/config',0700);mkdir($temp.'/private',0700);mkdir($temp.'/private/pbx',0700);
$helper=file_get_contents($argv[1].'/includes/PbxAmi.php');$helper=str_replace("require_once __DIR__.'/../config/Config.php';","require_once ".var_export($argv[1].'/config/Config.php',true).";",$helper);$helper=str_replace('final class PbxAmi','final class PbxFixture',$helper);$helper=str_replace("return rtrim((string)\\Config\\Config::deployment('directory'),'/').'/pbx/ami.json';","return ".var_export($temp.'/private/pbx/ami.json',true).";",$helper);
// Persist helper file only for this isolated fixture.
pbxCheck(strpos($helper,var_export($temp.'/private/pbx/ami.json',true))!==false,'fixture isolation');
$file=$temp.'/helper.php';file_put_contents($file,$helper);require $file;
try{\Includes\PbxFixture::save($config);$loaded=\Includes\PbxFixture::load();pbxCheck($loaded===$config,'AMI config round trip');pbxCheck((fileperms($temp.'/private/pbx/ami.json')&0777)===0640,'secret permissions');}finally{unlink($temp.'/private/pbx/ami.json');unlink($file);rmdir($temp.'/private/pbx');rmdir($temp.'/private');rmdir($temp.'/config');rmdir($temp);}
echo "PBX_AMI_OK: validation, blank password, successful/rejected login, Logoff, atomic private persistence\n";
