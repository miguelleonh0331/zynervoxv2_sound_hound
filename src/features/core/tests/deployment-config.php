<?php
declare(strict_types=1);
require __DIR__ . '/../../../../app/web/includes/DeploymentConfig.php';
use Includes\DeploymentConfig;
function verify_config(bool $condition): void {
    if (!$condition) throw new RuntimeException('Deployment configuration regression');
}
$input = ['db_host'=>'127.0.0.1', 'db_port'=>'3306', 'db_name'=>'zynervox_core', 'db_user'=>'bot', 'db_pass'=>''];
$existing = ['db_pass'=>'private-test-password'];
verify_config(DeploymentConfig::database($input, $existing)['db_pass'] === $existing['db_pass']);
$input['db_pass'] = '••••••••';
verify_config(DeploymentConfig::database($input, $existing)['db_pass'] === $existing['db_pass']);
$input['db_pass'] = 'replacement-test-password';
verify_config(DeploymentConfig::database($input, $existing)['db_pass'] === $input['db_pass']);
foreach ([0, 65536, 'invalid'] as $invalidPort) {
    try {
        DeploymentConfig::database(array_replace($input, ['db_port'=>$invalidPort]), $existing);
        throw new LogicException('Invalid port accepted');
    } catch (InvalidArgumentException $expected) {}
}
try {
    DeploymentConfig::database(array_replace($input, ['db_host'=>'localhost;dbname=other']), $existing);
    throw new LogicException('DSN injection accepted');
} catch (InvalidArgumentException $expected) {}
verify_config(!DeploymentConfig::csrf('', ''));
verify_config(!DeploymentConfig::csrf('expected', 'wrong'));
verify_config(DeploymentConfig::csrf('expected', 'expected'));
echo "PASS: retained secrets, port/DSN validation and CSRF\n";
