<?php
require $argv[1] . '/includes/Carriers.php';
use Includes\Carriers;
use Includes\Database;

function verifyCarriers($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$_SESSION['user'] = 'admin';
$data = ['carrier_id' => 'TEST_V2', 'carrier_name' => 'Test', 'protocol'=>'PJSIP', 'server_ip' => '127.0.0.1', 'active' => 'Y', 'account_entry' => "[test-v2]\ntype=auth\npassword=private-fixture", 'dialplan_entry' => "[v2-test]\nexten => 1,1,Hangup()"];
verifyCarriers(Carriers::create($data)['ok'], 'create');
$duplicate = Carriers::create($data);
verifyCarriers(!$duplicate['ok'] && strpos($duplicate['error'], 'private-fixture') === false && strpos($duplicate['error'], 'SQLSTATE') === false, 'private errors');
verifyCarriers(Carriers::getById('TEST_V2')['carrier_name'] === 'Test', 'read');
$runtime = \Config\Config::deployment('runtime') . '/modules/asterisk';
verifyCarriers(strpos(file_get_contents($runtime . '/pjsip-zynervoxv2.conf'), 'private-fixture') !== false, 'generated');
$data['carrier_name'] = 'Updated';
$data['active'] = 'N';
verifyCarriers(Carriers::update('TEST_V2', $data)['ok'], 'update');
verifyCarriers(Carriers::getById('TEST_V2')['protocol'] === 'PJSIP', 'PJSIP stored');
$data['account_entry'] = "[test-v2]\ntype=peer\nhost=127.0.0.1";
try { Carriers::update('TEST_V2',$data); throw new RuntimeException('protocol mismatch accepted'); } catch (InvalidArgumentException $expected) {}
verifyCarriers(Carriers::getById('TEST_V2')['protocol'] === 'PJSIP', 'mismatch leaves stored protocol unchanged');
$data['protocol'] = 'SIP';
verifyCarriers(Carriers::update('TEST_V2', $data)['ok'], 'SIP update');
verifyCarriers(Carriers::getById('TEST_V2')['protocol'] === 'SIP', 'SIP stored');
verifyCarriers(strpos(file_get_contents($runtime . '/pjsip-zynervoxv2.conf'), 'private-fixture') === false, 'inactive omitted');
$logs = Carriers::recentChanges();
verifyCarriers(count($logs) >= 2 && strpos(json_encode($logs), 'private-fixture') === false, 'audit no secrets');
verifyCarriers(Carriers::delete('TEST_V2')['ok'], 'delete');
verifyCarriers(Carriers::getById('TEST_V2') === false, 'deleted');
try {
    $data['carrier_id'] = "BAD\nINJECTION";
    Carriers::create($data);
    throw new RuntimeException('invalid ID accepted');
} catch (InvalidArgumentException $expected) {}
verifyCarriers(Database::getCoreInstance()->query('SELECT DATABASE()')->fetchColumn() === 'zynervox_core', 'database');
echo "PASS: carriers CRUD, Core, inactive generation and private audit\n";
