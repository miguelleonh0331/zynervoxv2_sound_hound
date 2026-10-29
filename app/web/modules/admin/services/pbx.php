<?php
require_once __DIR__.'/../../../includes/Auth.php';
require_once __DIR__.'/../../../includes/PbxAmi.php';
\Includes\Auth::checkAccess(9);
header('Cache-Control: no-store');
if(empty($_SESSION['pbx_csrf']))$_SESSION['pbx_csrf']=bin2hex(random_bytes(32));
$message='';$kind='';$config=['host'=>'127.0.0.1','port'=>5038,'username'=>'','secret'=>''];
try {
 $config=\Includes\PbxAmi::load();
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
  if(!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['pbx_csrf'],$_POST['csrf']))throw new RuntimeException('Sesion invalida. Recarga la pagina.');
  $action=$_POST['action']??'';if(!in_array($action,['test','save','sip_reload','dialplan_reload'],true))throw new RuntimeException('Accion invalida.');
  $candidate=\Includes\PbxAmi::validate($_POST,$config);
  if($action==='sip_reload' || $action==='dialplan_reload') {
   $output=\Includes\PbxAmi::command($candidate,$action==='sip_reload'?'sip reload':'dialplan reload');
   $message=($action==='sip_reload'?'SIP reload ejecutado.':'Dialplan reload ejecutado.').($output!==''?' '.$output:'');
  } else {
   \Includes\PbxAmi::test($candidate);
   if($action==='save') {\Includes\PbxAmi::save($candidate);$message='Credenciales AMI guardadas. Conexion verificada.';} else $message='Conexion y autenticacion AMI correctas.';
  }
  $config=$candidate;$kind='ok';
 }
} catch(Throwable $e) {
 $message=$e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo cargar o guardar la configuracion PBX.';$kind='error';
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')foreach(['host','port','username'] as $field)if(is_scalar($_POST[$field]??null))$config[$field]=(string)$_POST[$field];
}
function pbx_h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Servicios / PBX - Zynervox</title>
<link rel="stylesheet" href="../layout.css">
<style>.pbx-row{display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem}.pbx-row label{min-width:110px;margin:0;font-size:.75rem}.pbx-row input{flex:1;min-width:0;font-family:monospace;font-size:.75rem}.pbx-actions{display:flex;gap:.5rem;margin-top:.4rem}.pbx-msg{font-size:.75rem}.pbx-msg.ok{color:#047857}.pbx-msg.error{color:#b91c1c}</style>
</head><body>
<?php require_once __DIR__.'/../sidebar.php';renderSidebar('pbx','../../../'); ?>
<main class="main-content"><header class="top-bar" style="margin-bottom:.5rem"><div><h1 style="font-size:1.1rem;font-weight:700">Servicios / PBX</h1><p style="color:var(--text-muted);font-size:.75rem">Configura la conexion AMI del servidor Asterisk para el motor de llamadas.</p></div></header>
<div class="card"><h2>Conexion AMI</h2><form method="post">
<input type="hidden" name="csrf" value="<?php echo pbx_h($_SESSION['pbx_csrf']); ?>">
<div class="pbx-row"><label for="ami-host">Host</label><input id="ami-host" name="host" value="<?php echo pbx_h($config['host']); ?>" maxlength="253" required></div>
<div class="pbx-row"><label for="ami-port">Puerto</label><input id="ami-port" name="port" type="number" min="1" max="65535" value="<?php echo pbx_h($config['port']); ?>" required></div>
<div class="pbx-row"><label for="ami-user">Usuario</label><input id="ami-user" name="username" value="<?php echo pbx_h($config['username']); ?>" maxlength="80" autocomplete="off" required></div>
<div class="pbx-row"><label for="ami-secret">Password</label><input id="ami-secret" name="secret" type="password" maxlength="1024" autocomplete="new-password" placeholder="<?php echo empty($config['secret'])?'Ingresa password AMI':'Vacio conserva el password guardado'; ?>"></div>
<div class="pbx-actions"><button class="btn-action" name="action" value="test">Probar conexion</button><button class="btn-action" name="action" value="save">Guardar</button></div>
<div class="pbx-actions" style="margin-top:.4rem"><button class="btn-action" name="action" value="sip_reload">SIP reload</button><button class="btn-action" name="action" value="dialplan_reload">Dialplan reload</button></div>
<?php if($message!==''): ?><p class="pbx-msg <?php echo $kind; ?>" role="status"><?php echo pbx_h($message); ?></p><?php endif; ?>
</form></div></main></body></html>
