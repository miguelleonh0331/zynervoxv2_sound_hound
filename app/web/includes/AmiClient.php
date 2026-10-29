<?php
declare(strict_types=1);
namespace Includes;
require_once __DIR__.'/PbxAmi.php';
final class AmiClient {
    private $socket;private $buffer='';
    public function __construct(array $config) {
        $config=PbxAmi::validate($config);$host=$config['host'];if(strpos($host,':')!==false)$host='['.$host.']';
        $this->socket=@stream_socket_client('tcp://'.$host.':'.$config['port'],$errno,$error,3,STREAM_CLIENT_CONNECT);
        if(!$this->socket)throw new \RuntimeException('No se pudo conectar a AMI.');
        stream_set_timeout($this->socket,4);$banner=fgets($this->socket,256);
        if($banner===false || strpos($banner,'Asterisk Call Manager/')!==0){$this->close();throw new \RuntimeException('El servidor no responde como AMI.');}
        stream_set_blocking($this->socket,false);
        try {$reply=$this->request(['Action'=>'Login','Username'=>$config['username'],'Secret'=>$config['secret'],'Events'=>'off']);if(($reply['Response']??'')!=='Success')throw new \RuntimeException('AMI rechazo las credenciales.');}catch(\Throwable $e){$this->close();throw $e;}
    }
    private function frame(float $deadline): array {
        while(microtime(true)<$deadline){
            $end=strpos($this->buffer,"\r\n\r\n");
            if($end!==false){$text=substr($this->buffer,0,$end);$this->buffer=substr($this->buffer,$end+4);$frame=[];foreach(explode("\r\n",$text) as $line){$pair=explode(':',$line,2);if(count($pair)===2)$frame[trim($pair[0])]=trim($pair[1]);}if($frame)return $frame;continue;}
            if(strlen($this->buffer)>65536)throw new \RuntimeException('Respuesta AMI demasiado grande.');
            $read=[$this->socket];$write=$except=[];$remaining=max(0,$deadline-microtime(true));$seconds=(int)$remaining;$micros=(int)(($remaining-$seconds)*1000000);
            if(@stream_select($read,$write,$except,$seconds,$micros)===false)throw new \RuntimeException('Se perdio la conexion AMI.');
            if(!$read)continue;$chunk=fread($this->socket,8192);if($chunk===false || ($chunk==='' && feof($this->socket)))throw new \RuntimeException('Se perdio la conexion AMI.');$this->buffer.=$chunk;
        }
        throw new \RuntimeException('AMI no respondio dentro del tiempo esperado.');
    }
    public function request(array $headers,array $variables=[]): array {
        if(!is_resource($this->socket))throw new \RuntimeException('Sesion AMI cerrada.');
        $id=bin2hex(random_bytes(12));$headers['ActionID']=$id;$packet='';
        foreach($headers as $key=>$value){if(!preg_match('/^[A-Za-z]+$/D',(string)$key) || !is_scalar($value) || preg_match('/[\x00-\x1f\x7f]/',(string)$value))throw new \RuntimeException('Solicitud AMI invalida.');$packet.=$key.': '.$value."\r\n";}
        foreach($variables as $variable){if(!is_string($variable) || preg_match('/[\x00-\x1f\x7f]/',$variable))throw new \RuntimeException('Variable AMI invalida.');$packet.='Variable: '.$variable."\r\n";}$packet.="\r\n";
        $deadline=microtime(true)+5;$offset=0;
        while($offset<strlen($packet)){if(microtime(true)>$deadline)throw new \RuntimeException('No se pudo enviar la solicitud AMI.');$n=fwrite($this->socket,substr($packet,$offset));if($n===false)throw new \RuntimeException('No se pudo enviar la solicitud AMI.');if($n===0){usleep(10000);continue;}$offset+=$n;}
        do {$frame=$this->frame($deadline);}while(($frame['ActionID']??'')!==$id || !isset($frame['Response']));return $frame;
    }
    public function originate(array $lead,string $prefix,string $callerId=''): bool {
        if(!preg_match('/^[0-9]{1,20}$/D',$prefix) || !preg_match('/^[0-9]{1,20}$/D',$lead['phone']) || !preg_match('/^[A-Za-z0-9_.-]{1,70}$/D',$lead['call_id']))throw new \RuntimeException('Destino AMI invalido.');
        $headers=['Action'=>'Originate','Channel'=>'Local/'.$prefix.$lead['phone'].'@zynervoxv2/n','Application'=>'Wait','Data'=>'600','Timeout'=>'600000','Async'=>'true','ChannelId'=>$lead['call_id'].'-origin','OtherChannelId'=>$lead['call_id']];
        if($callerId!=='')$headers['CallerID']=$callerId;
        return ($this->request($headers,['__ZV2_LIST_ID='.(int)$lead['list_id'],'__ZV2_LEAD_ID='.(int)$lead['lead_id']])['Response']??'')==='Success';
    }
    public function ping(): void {if(($this->request(['Action'=>'Ping'])['Response']??'')!=='Success')throw new \RuntimeException('AMI no confirmo la sesion.');}
    public function close(): void {if(is_resource($this->socket)){@fwrite($this->socket,"Action: Logoff\r\n\r\n");fclose($this->socket);}$this->socket=null;}
    public function __destruct() {$this->close();}
}
