<?php
declare(strict_types=1);
require $argv[1] . '/includes/Database.php';
require $argv[1] . '/includes/DeploymentConfig.php';
require $argv[1] . '/includes/Auth.php';
function verify(bool $condition): void {
    if (!$condition) throw new RuntimeException('Aislamiento invalido');
}
verify(\Config\Config::deployment('isolated') === true);
verify(session_name() === 'ZYNERVOXV2');
verify(\Config\Config::coreTable('deployment') === 'v2_ivr_deploy_config');
$configuration = \Includes\Database::getBotIvrConfig();
verify($configuration['database'] === getenv('ZYNERVOX_CORE_DB'));
$bot = \Includes\Database::getBotIvrInstance();
verify((int) $bot->query('SELECT COUNT(*) FROM zynervox_bot_campaigns')->fetchColumn() === 0);
verify((int) $bot->query('SELECT COUNT(*) FROM bot_ivr_flows')->fetchColumn() === 0);
try { $bot->query('SELECT * FROM v2_zynervox_users'); throw new RuntimeException('Permisos excesivos'); }
catch (PDOException $expected) {}
foreach (['zynervox.synervox_campaigns', 'ivr_deploy_config', 'zynervox_users'] as $table) {
    try { $bot->query('SELECT * FROM ' . $table . ' LIMIT 1'); throw new LogicException('Acceso productivo permitido'); }
    catch (PDOException $expected) {}
}
$core = \Includes\Database::getCoreInstance();
foreach (['zynervox_users', 'ivr_deploy_config'] as $table) {
    try { $core->query('SELECT * FROM ' . $table . ' LIMIT 1'); throw new LogicException('Core productivo accesible'); }
    catch (PDOException $expected) {}
}
try { \Includes\Database::getInstance(); throw new LogicException('Fallback productivo'); }
catch (RuntimeException $expected) {}
$secretFile = getenv('ZYNERVOX_CONFIG_DIR') . '/zynervox-core-admin.env';
$secret = file_get_contents($secretFile);
preg_match('/ZYNERVOX_CORE_ADMIN_PASSWORD=([^\r\n]+)/', $secret, $match);
verify(\Includes\Auth::login('admin', $match[1]));
verify(!\Includes\Auth::login('admin', 'incorrect-password'));
try {
    \Includes\DeploymentConfig::database(['db_host'=>'localhost','db_name'=>'zynervox','db_user'=>'zynervox_bot_ivr'], []);
    throw new LogicException('Configuracion productiva aceptada');
} catch (InvalidArgumentException $expected) {}
echo "PASS: login/sesion aislados, BD correcta, tablas vacias, permisos y fallback bloqueado\n";
