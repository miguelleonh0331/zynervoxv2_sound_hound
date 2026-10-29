<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/Config.php';
if (!\Config\Config::deployment('isolated', false)) {
    http_response_code(503);
    exit('Deployment aislado no configurado');
}
$webRoot = realpath(dirname(__DIR__)) . '/';
$script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
$relative = substr($script, strlen($webRoot));
$allowed = strpos($script, $webRoot) === 0 && (
    in_array($relative, ['index.php', 'logout.php', 'modules/vpn/index.php', 'modules/admin/index.php', 'modules/admin/campaign_monitor.php', 'modules/admin/campaign_monitor_api.php', 'modules/admin/bot_ivr.php', 'modules/admin/carriers.php', 'modules/admin/dialplan.php', 'modules/admin/phones.php', 'modules/admin/services/database.php', 'modules/admin/services/pbx.php', 'modules/admin/services/test.php'], true) ||
    strpos($relative, 'bot_ivr/') === 0 || strpos($relative, 'ivr_builder/') === 0
);
if (!$allowed) {
    http_response_code(403);
    exit('Integracion productiva deshabilitada en v2 aislado');
}
