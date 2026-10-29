<?php
declare(strict_types=1);
require_once __DIR__ . '/service.php';
vpn_authorize();
$error = ''; $inventory = ['clients'=>[], 'status_available'=>false];
$showAudit = ($_GET['view'] ?? '') === 'audit';
$history = ['audit'=>[], 'snapshot_id'=>null, 'before_id'=>null, 'after_id'=>null];
$filters = [];
$message = (string) ($_SESSION['vpn_message'] ?? ''); unset($_SESSION['vpn_message']);
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['vpn_csrf'], $_POST['csrf'])) {
            vpn_request('access', ['event'=>'CSRF_DENIED']);
            http_response_code(403);
            throw new RuntimeException('Sesión inválida. Recarga la página.');
        }
        $action = $_POST['action'] ?? '';
        if (!is_string($action) || !in_array($action, ['create','update','revoke','delete','download'], true)) throw new RuntimeException('Acción inválida.');
        $data = [];
        foreach (['username','display_name','email','agent_ref','notes'] as $field) {
            if (!is_string($_POST[$field] ?? '')) throw new RuntimeException('Datos inválidos.');
            $data[$field] = $_POST[$field] ?? '';
        }
        $result = vpn_request($action, $data);
        if ($action === 'download') {
            header('Content-Type: application/x-openvpn-profile');
            header('Content-Disposition: attachment; filename="' . $result['username'] . '.ovpn"');
            echo $result['profile']; exit;
        }
        $_SESSION['vpn_message'] = ['create'=>'Cuenta creada.','update'=>'Inventario actualizado.',
            'revoke'=>'Acceso revocado.','delete'=>'Cuenta revocada y retirada del inventario.'][$action];
        header('Location: index.php', true, 303); exit;
    }
    vpn_request('access', ['event'=>'PANEL_VIEW']);
    $inventory = vpn_request('inventory');
    if ($showAudit) {
        foreach (['actor','target','action'] as $field) {
            if (!is_string($_GET[$field] ?? '')) throw new RuntimeException('Filtro inválido.');
            $filters[$field] = $_GET[$field] ?? '';
        }
        $params = $filters;
        foreach (['before_id','after_id','snapshot_id'] as $field) {
            if (isset($_GET[$field])) {
                $value = filter_var($_GET[$field], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($value === false) throw new RuntimeException('Página inválida.');
                $params[$field] = $value;
            }
        }
        $history = vpn_request('audit', $params);
    }
} catch (Throwable $e) {
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo completar la operación VPN.';
}
$edit = null;
foreach ($inventory['clients'] as $row) if ($row['username'] === ($_GET['edit'] ?? '')) $edit = $row;
$form = $edit ?? ['username'=>'','display_name'=>'','email'=>'','agent_ref'=>'','notes'=>''];
$active = count(array_filter($inventory['clients'], fn($row) => $row['state'] === 'active'));
$online = count(array_filter($inventory['clients'], fn($row) => !empty($row['session'])));
$labels = ['active'=>'Activa','revoked'=>'Revocada','unavailable'=>'No disponible'];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vpn · Zynervox</title><link rel="stylesheet" href="../admin/layout.css"><link rel="stylesheet" href="vpn.css"></head><body>
<?php require_once __DIR__ . '/../admin/sidebar.php'; renderSidebar('vpn'); ?>
<main class="main-content"><header class="top-bar"><div><h1>Vpn</h1><p>Administración de accesos remotos</p></div><a class="btn-primary" href="?new=1#account-form">+ Crear cuenta</a></header>
<?php if ($message): ?><p class="vpn-success" role="status"><?= vpn_h($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="vpn-error" role="alert"><?= vpn_h($error) ?></p><?php endif; ?>
<section class="vpn-stats" aria-label="Resumen"><div class="card"><span>Cuentas en inventario</span><strong><?= count($inventory['clients']) ?></strong></div><div class="card"><span>Accesos activos</span><strong><?= $active ?></strong></div><div class="card"><span>Conectados ahora</span><strong><?= $online ?></strong></div><div class="card"><span>Servidor VPN</span><strong class="vpn-status"><?= $inventory['status_available'] ? 'Operativo' : 'Sin lectura de estado' ?></strong></div></section>
<section class="card"><div class="vpn-heading"><h2>Inventario de agentes</h2><label>Buscar <input id="vpn-search" type="search" placeholder="Cuenta, agente o correo"></label><a href="index.php">Actualizar</a></div>
<p class="vpn-muted">Cada cuenta usa un certificado individual. La última conexión se registra desde la activación de la auditoría; las anteriores aparecen sin registro.</p>
<div class="vpn-scroll"><table><thead><tr><th>Cuenta / agente</th><th>Referencia / correo</th><th>Estado</th><th>Última conexión (UTC)</th><th>IP / sesión</th><th>Acciones</th></tr></thead><tbody id="vpn-inventory">
<?php foreach ($inventory['clients'] as $row): ?><tr>
<td><strong><?= vpn_h($row['username']) ?></strong><br><?= vpn_h($row['display_name']) ?></td>
<td><?= vpn_h($row['agent_ref'] ?: '—') ?><br><span class="vpn-muted"><?= vpn_h($row['email']) ?></span></td>
<td><span class="vpn-badge <?= $row['state']==='active' ? 'active' : 'revoked' ?>"><?= vpn_h($labels[$row['state']] ?? $row['state']) ?></span><?php if ($row['session']): ?><br><span class="vpn-badge online">Conectada</span><?php endif; ?></td>
<td><?= vpn_h($row['last_connected'] ?: 'Sin registro') ?><br><span class="vpn-muted"><?= $row['last_disconnected'] ? 'Salida: ' . vpn_h($row['last_disconnected']) : '' ?></span></td>
<td><?= vpn_h($row['last_ip'] ?: '—') ?><?php if ($row['session']): ?><br>VPN: <?= vpn_h($row['session']['vpn_ip']) ?><br><span class="vpn-muted">Recibidos <?= vpn_h($row['session']['bytes_received']) ?> B · Enviados <?= vpn_h($row['session']['bytes_sent']) ?> B</span><?php endif; ?></td>
<td><div class="vpn-actions"><a href="?edit=<?= rawurlencode($row['username']) ?>#account-form">Editar</a>
<?php foreach (['download'=>'Descargar .ovpn','revoke'=>'Revocar','delete'=>'Eliminar'] as $action=>$label): if ($row['state']!=='active' && $action!=='delete') continue; ?>
<form method="post" <?= $action!=='download' ? 'data-confirm="' . vpn_h($action==='delete' ? '¿Revocar el acceso y retirar esta cuenta del inventario? Se conservará la auditoría.' : '¿Revocar este certificado y desconectar al agente?') . '"' : '' ?>>
<input type="hidden" name="csrf" value="<?= vpn_h($_SESSION['vpn_csrf']) ?>"><input type="hidden" name="username" value="<?= vpn_h($row['username']) ?>"><button class="vpn-link <?= $action==='download'?'':'danger' ?>" name="action" value="<?= $action ?>"><?= $label ?></button></form>
<?php endforeach; ?></div></td></tr><?php endforeach; ?>
<?php if (!$inventory['clients']): ?><tr><td colspan="6">No hay cuentas disponibles.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php if ($edit || isset($_GET['new'])): ?><section class="card" id="account-form"><h2><?= $edit ? 'Editar agente · ' . vpn_h($edit['username']) : 'Crear cuenta VPN' ?></h2>
<form method="post"><input type="hidden" name="csrf" value="<?= vpn_h($_SESSION['vpn_csrf']) ?>"><input type="hidden" name="action" value="<?= $edit?'update':'create' ?>">
<div class="vpn-fields"><label>Identificador de cuenta<input name="username" required pattern="[a-zA-Z][a-zA-Z0-9_-]{2,39}" maxlength="40" value="<?= vpn_h($form['username']) ?>" <?= $edit?'readonly':'' ?>><small>Único y permanente, 3–40 caracteres.</small></label>
<label>Nombre del agente<input name="display_name" required maxlength="120" value="<?= vpn_h($form['display_name']) ?>"></label>
<label>Correo electrónico<input name="email" type="email" maxlength="160" value="<?= vpn_h($form['email']) ?>"></label><label>Referencia / inventario<input name="agent_ref" maxlength="80" value="<?= vpn_h($form['agent_ref']) ?>"></label></div>
<label class="vpn-notes">Notas<textarea name="notes" maxlength="1000" rows="3"><?= vpn_h($form['notes']) ?></textarea></label>
<div class="vpn-actions"><button class="btn-primary" type="submit"><?= $edit?'Guardar cambios':'Crear cuenta' ?></button><a href="index.php">Cancelar</a></div></form></section><?php endif; ?>
<section class="card" id="audit"><div class="vpn-heading"><h2>Auditoría de administración y accesos VPN</h2>
<?php if (!$showAudit): ?><a class="btn-primary" href="?view=audit#audit">Ver auditoría</a><?php else: ?><a href="index.php">Cerrar auditoría</a><?php endif; ?></div>
<?php if (!$showAudit): ?><p class="vpn-muted">Consulta el historial cuando lo necesites. Los accesos y operaciones siguen registrándose.</p>
<?php else: ?><p class="vpn-muted">20 registros por página. Fecha UTC, identidad, IP, operación y resultado. Se conserva el historial de cuentas eliminadas.</p>
<form method="get" class="vpn-filters"><input type="hidden" name="view" value="audit"><?php foreach (['actor'=>'Administrador / cuenta','target'=>'Cuenta afectada','action'=>'Operación'] as $field=>$label): ?><label><?= $label ?><input name="<?= $field ?>" maxlength="80" value="<?= vpn_h($filters[$field] ?? '') ?>"></label><?php endforeach; ?><button class="btn-primary">Filtrar</button><a href="?view=audit#audit">Limpiar</a></form>
<div class="vpn-scroll"><table id="vpn-audit"><thead><tr><th>Fecha (UTC)</th><th>Actor</th><th>IP</th><th>Operación</th><th>Cuenta</th><th>Resultado</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($history['audit'] as $event): ?><tr><td><?= vpn_h($event['time']) ?></td><td><?= vpn_h($event['actor']) ?></td><td><?= vpn_h($event['ip']) ?></td><td><?= vpn_h($event['action']) ?></td><td><?= vpn_h($event['target']) ?></td><td><?= vpn_h($event['result']) ?></td><td><?php if ($event['details']!=='{}'): ?><details><summary>Ver detalle</summary><pre><?= vpn_h(json_encode(json_decode($event['details']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$history['audit']): ?><tr><td colspan="7">Sin eventos para estos filtros.</td></tr><?php endif; ?></tbody></table></div>
<nav class="vpn-actions" aria-label="Páginas de auditoría">
<?php foreach (['after_id'=>'Más recientes','before_id'=>'Más antiguos'] as $cursor=>$label): if (!$history[$cursor]) continue; ?>
<a href="?<?= vpn_h(http_build_query(array_merge($filters, ['view'=>'audit', 'snapshot_id'=>$history['snapshot_id'], $cursor=>$history[$cursor]]))) ?>#audit"><?= $label ?></a>
<?php endforeach; ?><a href="?<?= vpn_h(http_build_query(array_merge($filters, ['view'=>'audit']))) ?>#audit">Actualizar historial</a>
</nav><?php endif; ?></section>
</main><script src="vpn.js"></script></body></html>
