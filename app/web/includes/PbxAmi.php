<?php
declare(strict_types=1);
namespace Includes;
require_once __DIR__.'/../config/Config.php';
final class PbxAmi {
    public static function path(): string {
        if (!\Config\Config::deployment('isolated',false)) throw new \RuntimeException('PBX requiere despliegue v2 aislado.');
        return rtrim((string)\Config\Config::deployment('directory'),'/').'/pbx/ami.json';
    }
    public static function load(): array {
        $path=self::path();
        if (!is_file($path)) return ['host'=>'127.0.0.1','port'=>5038,'username'=>'','secret'=>''];
        if (is_link($path)) throw new \RuntimeException('Configuracion PBX invalida.');
        $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        return self::validate($data);
    }
    public static function validate(array $input,array $current=[]): array {
        foreach (['host','username','secret'] as $field) if (isset($input[$field]) && !is_string($input[$field])) throw new \InvalidArgumentException('Datos AMI invalidos.');
        $host=trim($input['host']??'');$username=trim($input['username']??'');
        $port=filter_var($input['port']??5038,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $secret=$input['secret']??'';if($secret==='')$secret=$current['secret']??'';
        if (!$port || strlen($host)>253 || !(filter_var($host,FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D',$host))) throw new \InvalidArgumentException('Host o puerto AMI invalido.');
        if ($username==='' || strlen($username)>80 || preg_match('/[\x00-\x1f\x7f]/',$username) || $secret==='' || strlen($secret)>1024 || preg_match('/[\x00-\x1f\x7f]/',$secret)) throw new \InvalidArgumentException('Ingresa usuario y password AMI validos.');
        return ['host'=>$host,'port'=>$port,'username'=>$username,'secret'=>$secret];
    }
    public static function save(array $config): void {
        $config=self::validate($config);$path=self::path();$directory=dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) throw new \RuntimeException('Directorio privado PBX no preparado.');
        $tmp=tempnam($directory,'.ami-');if($tmp===false)throw new \RuntimeException('No se pudo guardar PBX.');
        try {
            if(!chmod($tmp,0640))throw new \RuntimeException('No se pudieron proteger las credenciales.');
            $json=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json) || !rename($tmp,$path))throw new \RuntimeException('No se pudo guardar PBX.');
        } finally {if(is_file($tmp))unlink($tmp);}
    }
    public static function test(array $config): void {
        $config=self::validate($config);$host=$config['host'];if(strpos($host,':')!==false)$host='['.$host.']';
        $socket=@stream_socket_client('tcp://'.$host.':'.$config['port'],$errno,$error,3,STREAM_CLIENT_CONNECT);
        if(!$socket)throw new \RuntimeException('No se pudo conectar al servidor AMI. Revisa host, puerto y acceso.');
        try {
            stream_set_timeout($socket,4);$banner=fgets($socket,256);
            if($banner===false || strpos($banner,'Asterisk Call Manager/')!==0)throw new \RuntimeException('El puerto no responde como AMI de Asterisk.');
            $id=bin2hex(random_bytes(8));
            $login="Action: Login\r\nActionID: ".$id."\r\nUsername: ".$config['username']."\r\nSecret: ".$config['secret']."\r\nEvents: off\r\n\r\n";
            if(fwrite($socket,$login)!==strlen($login))throw new \RuntimeException('No se pudo enviar la prueba AMI.');
            $frame='';$deadline=microtime(true)+5;
            while(microtime(true)<$deadline && strlen($frame)<8192) {
                $line=fgets($socket,1024);if($line===false)break;$frame.=$line;
                if(trim($line)==='') {
                    if(strpos($frame,'ActionID: '.$id)!==false) {
                        if(!preg_match('/^Response: Success\r?$/m',$frame))throw new \RuntimeException('AMI rechazo el login. Revisa usuario, password y permisos de acceso.');
                        fwrite($socket,"Action: Logoff\r\n\r\n");return;
                    }
                    $frame='';
                }
            }
            throw new \RuntimeException('AMI no confirmo la autenticacion dentro del tiempo esperado.');
        } finally {fclose($socket);}
    }
    public static function command(array $config,string $command): string {
        $config=self::validate($config);$host=$config['host'];if(strpos($host,':')!==false)$host='['.$host.']';
        $socket=@stream_socket_client('tcp://'.$host.':'.$config['port'],$errno,$error,3,STREAM_CLIENT_CONNECT);
        if(!$socket)throw new \RuntimeException('No se pudo conectar al servidor AMI. Revisa host, puerto y acceso.');
        try {
            stream_set_timeout($socket,4);$banner=fgets($socket,256);
            if($banner===false || strpos($banner,'Asterisk Call Manager/')!==0)throw new \RuntimeException('El puerto no responde como AMI de Asterisk.');
            $loginId=bin2hex(random_bytes(8));
            $login="Action: Login\r\nActionID: ".$loginId."\r\nUsername: ".$config['username']."\r\nSecret: ".$config['secret']."\r\nEvents: off\r\n\r\n";
            if(fwrite($socket,$login)!==strlen($login))throw new \RuntimeException('No se pudo enviar la prueba AMI.');
            $loginFrame=self::readPacket($socket,$loginId);
            if($loginFrame===null || !preg_match('/^Response: Success\r?$/m',$loginFrame))throw new \RuntimeException('AMI rechazo el login. Revisa usuario, password y permisos de acceso.');
            $cmdId=bin2hex(random_bytes(8));
            $request="Action: Command\r\nActionID: ".$cmdId."\r\nCommand: ".$command."\r\n\r\n";
            if(fwrite($socket,$request)!==strlen($request))throw new \RuntimeException('No se pudo enviar el comando AMI.');
            $frame=self::readPacket($socket,$cmdId);
            fwrite($socket,"Action: Logoff\r\n\r\n");
            if($frame===null)throw new \RuntimeException('AMI no confirmo el comando dentro del tiempo esperado.');
            if(preg_match('/^Response: Error\r?$/m',$frame)) {
                preg_match('/^Message: (.*)$/m',$frame,$m);
                throw new \RuntimeException('AMI rechazo el comando'.(isset($m[1])?': '.trim($m[1]):'.'));
            }
            $lines=preg_split('/\r?\n/',$frame);
            $output=[];$capture=false;
            foreach ($lines as $line) {
                if(preg_match('/^(Response|ActionID|Privilege):/',$line)) {$capture=true;continue;}
                if(strpos($line,'--END COMMAND--')!==false)break;
                if($capture && $line!=='')$output[]=$line;
            }
            return trim(implode("\n",$output));
        } finally {fclose($socket);}
    }
    private static function readPacket($socket,string $actionId): ?string {
        $frame='';$markerSeen=false;$deadline=microtime(true)+8;
        while(microtime(true)<$deadline) {
            $line=fgets($socket,4096);
            if($line===false)return null;
            $frame.=$line;
            if(strpos($line,'--END COMMAND--')!==false)$markerSeen=true;
            if(trim($line)==='') {
                if(strpos($frame,'ActionID: '.$actionId)===false) {$frame='';$markerSeen=false;continue;}
                if($markerSeen || strpos($frame,'Response: Follows')===false)return $frame;
            }
        }
        return null;
    }
}
