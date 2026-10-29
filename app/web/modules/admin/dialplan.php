<?php
require_once __DIR__.'/../../includes/Auth.php';
require_once __DIR__.'/../../includes/Dialplans.php';
use Includes\Auth;
use Includes\Dialplans;
use Includes\DialplanOrigins;
Auth::checkAccess(9);
if (!\Config\Config::deployment('isolated',false)) { http_response_code(409); exit('Dialplan requiere despliegue v2 aislado.'); }
$_SESSION['dialplan_csrf']=$_SESSION['dialplan_csrf'] ?? bin2hex(random_bytes(32));
$message=''; $error=''; $edit=null; $rows=[];
function dialplan_h($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialplan_csrf'],$_POST['csrf_token'])) { http_response_code(403); exit('CSRF inválido'); }
        $id=filter_var($_POST['dialplan_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (isset($_POST['delete_dialplan'])) {
            if (!$id) throw new InvalidArgumentException('ID inválido.');
            $result=Dialplans::delete($id); $message='Dialplan eliminado.';
        } elseif (isset($_POST['save_dialplan'])) {
            if (($_POST['dialplan_id'] ?? '')!=='' && !$id) throw new InvalidArgumentException('ID inválido.');
            foreach (['name','dialplan_entry','active'] as $field) if (!is_string($_POST[$field] ?? null)) throw new InvalidArgumentException('Campos inválidos.');
            $edit=['dialplan_id'=>$id ?: '', 'name'=>$_POST['name'],'dialplan_entry'=>$_POST['dialplan_entry'],'active'=>$_POST['active']];
            $result=Dialplans::save($id ?: null,$_POST['name'],$_POST['dialplan_entry'],$_POST['active']);
            $edit=Dialplans::getById($result['id']); $message='Dialplan guardado. Prefijo disponible en Origen.';
        } else throw new InvalidArgumentException('Acción inválida.');
        if (!$result['ok']) $error=$result['error'];
    } elseif (isset($_GET['edit_id'])) {
        $id=filter_var($_GET['edit_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !($edit=Dialplans::getById($id))) throw new InvalidArgumentException('El dialplan no existe.');
    }
} catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
catch (Throwable $e) { $error='No se pudo guardar o cargar el dialplan. Revisa la conexión y permisos.'; }
try { $rows=Dialplans::getAll(); } catch (Throwable $e) { $error='No se pudieron cargar los dialplans.'; }
$showForm=$edit!==null || ($_GET['new'] ?? '')==='1' || isset($_POST['save_dialplan']);
$d=$edit ?? ['dialplan_id'=>'','name'=>'','dialplan_entry'=>'','active'=>'Y'];
try { $editableCode=DialplanOrigins::body($d['dialplan_entry']); }
catch (InvalidArgumentException $e) { $editableCode=$d['dialplan_entry']; }
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Dialplan - Zynervox</title><link rel="stylesheet" href="layout.css">
<style>
body,.main-content{font-family:Arial,Helvetica,sans-serif}.main-content{min-width:0}.dialplan-table{width:100%;border-collapse:collapse;font-size:12px}.dialplan-table th,.dialplan-table td{padding:7px 9px;border:1px solid var(--border);text-align:left}.dialplan-table th{background:var(--dark,#2d2f3b);color:white}.dialplan-table tbody tr:nth-child(even){background:var(--glass)}.dialplan-fields{display:grid;grid-template-columns:2fr 1fr;gap:16px}.dialplan-code{width:100%;min-height:360px;box-sizing:border-box;font-family:monospace;font-size:12px}.dialplan-header{white-space:pre-wrap;font-size:12px}.dialplan-actions{display:flex;gap:12px;align-items:center}.dialplan-actions form{margin:0}.dialplan-link{border:0;background:none;color:var(--primary);text-decoration:underline;cursor:pointer;padding:0}.dialplan-error{color:#b91c1c}.dialplan-success{color:#047857}@media(max-width:650px){.dialplan-fields{grid-template-columns:1fr}}
</style></head><body>
<?php require_once __DIR__.'/sidebar.php'; renderSidebar('dialplan'); ?>
<main class="main-content">
<header class="top-bar"><h1>Dialplan</h1></header>
<?php if ($message!==''): ?><p class="dialplan-success" role="status"><?php echo dialplan_h($message); ?></p><?php endif; ?>
<?php if ($error!==''): ?><p class="dialplan-error" role="alert"><?php echo dialplan_h($error); ?></p><?php endif; ?>
<div class="card">
<div class="dialplan-actions" style="justify-content:space-between;"><h2>Dialplans</h2><a href="dialplan.php?new=1">+ Nuevo dialplan</a></div>
<table class="dialplan-table"><thead><tr><th>ID</th><th>Nombre</th><th>Prefijo</th><th>Activo</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<td><?php echo (int)$row['dialplan_id']; ?></td><td><?php echo dialplan_h($row['name']); ?></td><td><?php echo dialplan_h($row['dial_prefix']); ?></td><td><?php echo $row['active']==='Y'?'Sí':'No'; ?></td>
<td><div class="dialplan-actions"><a href="?edit_id=<?php echo (int)$row['dialplan_id']; ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este dialplan?');"><input type="hidden" name="csrf_token" value="<?php echo dialplan_h($_SESSION['dialplan_csrf']); ?>"><input type="hidden" name="dialplan_id" value="<?php echo (int)$row['dialplan_id']; ?>"><button class="dialplan-link dialplan-error" name="delete_dialplan">Eliminar</button></form></div></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">Sin dialplans.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($showForm): ?>
<div class="card"><h2><?php echo $d['dialplan_id']?'Editar dialplan':'Nuevo dialplan'; ?></h2>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo dialplan_h($_SESSION['dialplan_csrf']); ?>"><input type="hidden" name="dialplan_id" value="<?php echo dialplan_h($d['dialplan_id']); ?>">
<div class="dialplan-fields"><div class="field"><label for="dialplan_name">Nombre</label><input id="dialplan_name" name="name" maxlength="100" required value="<?php echo dialplan_h($d['name']); ?>"></div><div class="field"><label for="dialplan_active">Activo</label><select id="dialplan_active" name="active"><option value="Y"<?php echo $d['active']==='Y'?' selected':''; ?>>Sí</option><option value="N"<?php echo $d['active']==='N'?' selected':''; ?>>No</option></select></div></div>
<div class="field"><label for="dialplan_code">Ruta del dialplan</label><textarea class="dialplan-code" id="dialplan_code" name="dialplan_entry" required><?php echo dialplan_h($editableCode); ?></textarea></div>
<p>Prefijo detectado: <strong id="detected-prefix"><?php echo dialplan_h($d['dial_prefix'] ?? ''); ?></strong></p>
<button class="btn-primary" name="save_dialplan">Guardar dialplan</button>
</form></div>
<?php endif; ?>
</main>
<script>
(() => { const code=document.getElementById('dialplan_code'), output=document.getElementById('detected-prefix'); if (!code || !output) return; function detect(){const body=code.value.split(/^\s*\[.*$/m)[0];const values=[...new Set([...body.matchAll(/^\s*exten\s*=>\s*_([0-9]{1,20})X\.\s*,\s*1\s*,/gmi)].map(m=>m[1]))];output.textContent=values.length===1?values[0]:'Ingresa un único prefijo, por ejemplo _7306X.';} code.addEventListener('input',detect);detect(); })();
</script></body></html>
