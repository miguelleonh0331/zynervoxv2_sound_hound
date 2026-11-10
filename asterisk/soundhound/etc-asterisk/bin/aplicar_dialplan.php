<?php
// Guarda un dialplan con Dialplans::save (regenera extensions-zynervoxv2.conf; NO recarga Asterisk).
// Uso (como apache, script y fichero en /tmp legible): php aplicar_dialplan.php <raiz web> <id|nuevo> <fichero.txt> [nombre]   (nombre solo para "nuevo"; ACTIVO=Y|N en el entorno cambia el estado)
if (PHP_SAPI!=='cli' || $argc<4) exit(1);
[$x,$root,$id,$file]=$argv;
require_once $root.'/includes/Database.php'; require_once $root.'/includes/DialplanOrigins.php'; require_once $root.'/includes/Dialplans.php'; require_once $root.'/includes/Audit.php';
if ($id==='nuevo') { $name=$argv[4]??''; $row=['name'=>$name,'active'=>'Y']; $id=null; }
else { $row=Includes\Dialplans::getById((int)$id); if(!$row){fwrite(STDERR,"no existe\n");exit(2);} $id=(int)$id; }
if (getenv('ACTIVO')==='Y'||getenv('ACTIVO')==='N') $row['active']=getenv('ACTIVO');
$r=Includes\Dialplans::save($id,$row['name'],file_get_contents($file),$row['active']);
echo json_encode($r),"\n";
