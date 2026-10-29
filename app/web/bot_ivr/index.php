<?php
declare(strict_types=1);
require __DIR__ . '/campaigns_page.php';
$creating = ($_GET['view'] ?? '') === 'create';
$name = '';
$active = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create_campaign') {
    $creating = true;
    $name = (string)($_POST['name'] ?? '');
    $active = isset($_POST['active']);
    try {
        bot_campaign_csrf();
        $id = bot_campaign_create(bot_ivr_repository(), $name, $active);
        bot_campaign_redirect('campaign_edit.php?id='.$id, 'Campaña creada. Ahora puedes asignarle listas.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$campaigns = [];
if (!$creating) {
    try {
        $campaigns = bot_ivr_repository()->campaigns();
    } catch (Throwable $e) { if ($error === '') $error = $e->getMessage(); }
}
bot_campaign_header($creating ? 'Crear campaña' : 'Campañas');
if ($creating): ?>
<div class="carsa-card bot-form">
<form method="post" class="carsa-form">
  <input type="hidden" name="action" value="create_campaign"><?php bot_campaign_token(); ?>
  <div class="carsa-field"><label>ID de campaña</label><input value="Automático al guardar" readonly></div>
  <div class="carsa-field"><label for="campaign_name">Nombre</label><input id="campaign_name" name="name" maxlength="120" value="<?php echo h($name); ?>" required></div>
  <div><label><input type="checkbox" name="active" value="1" <?php echo $active ? 'checked' : ''; ?>> Activo</label></div>
  <div class="carsa-actions"><button class="carsa-btn">Crear campaña</button><a class="carsa-btn secondary" href="index.php">Cancelar</a></div>
</form>
</div>
<?php else: ?>
<div class="carsa-card">
<h2>Campañas actuales</h2>
<div class="bot-table-wrap"><table class="carsa-table">
<thead><tr><th>ID campaña</th><th>Nombre</th><th>Activo</th><th>Listas</th><th>Activación</th><th>Bloqueo</th><th>Horario habilitado</th><th>Modificar</th></tr></thead>
<tbody>
<?php foreach ($campaigns as $c): ?>
<tr><td><?php echo (int)$c['campaign_id']; ?></td><td><?php echo h($c['name']); ?></td><td><?php echo $c['active'] ? 'Sí' : 'No'; ?></td><td><?php echo (int)$c['lists_count']; ?></td><td><?php echo h(substr($c['start_time'],0,5)); ?></td><td><?php echo h(substr($c['end_time'],0,5)); ?></td><td><?php echo $c['scheduled'] ? 'Sí' : 'No'; ?></td><td><a href="campaign_edit.php?id=<?php echo (int)$c['campaign_id']; ?>">Modificar</a></td></tr>
<?php endforeach; ?>
<?php if (!$campaigns): ?><tr><td colspan="8">No hay campañas registradas.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
<?php endif;
bot_campaign_footer();