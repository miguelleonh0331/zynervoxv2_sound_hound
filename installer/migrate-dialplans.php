<?php
// CLI deployment migration: preserve the existing route before removing it from carriers.
if (PHP_SAPI!=='cli') exit(1);
require_once $argv[1].'/includes/Database.php';
require_once $argv[1].'/includes/DialplanOrigins.php';
require_once $argv[1].'/includes/Dialplans.php';
use Includes\Database;
use Includes\DialplanOrigins;
if (!\Config\Config::deployment('isolated',false) || \Config\Config::get('CORE_DB_database')!=='zynervox_core') throw new RuntimeException('Isolated Core required');
$db=Database::getCoreInstance();
$db->beginTransaction();
try {
    $carriers=$db->query("SELECT carrier_id,carrier_name,dialplan_entry,active FROM v2_carriers WHERE TRIM(dialplan_entry)<>'' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    $moved=0;
    foreach ($carriers as $carrier) {
        $body=DialplanOrigins::body($carrier['dialplan_entry']);
        $prefixes=DialplanOrigins::prefixes($body);
        if (count($prefixes)!==1) throw new RuntimeException('Migration requires one prefix per carrier dialplan; split manually before deploying.');
        $stmt=$db->prepare('SELECT dialplan_id,dialplan_entry FROM v2_dialplans WHERE dial_prefix=? FOR UPDATE');
        $stmt->execute([$prefixes[0]]); $existing=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && DialplanOrigins::body($existing['dialplan_entry'])!==$body) throw new RuntimeException('Existing dialplan differs; migration aborted without removing carrier routes.');
        if (!$existing) {
            $stmt=$db->prepare('SELECT name FROM v2_dial_origins WHERE dial_prefix=? AND carrier_id=?');
            $stmt->execute([$prefixes[0],$carrier['carrier_id']]);
            $name=$stmt->fetchColumn() ?: $carrier['carrier_name'];
            $db->prepare('INSERT INTO v2_dialplans (name,dial_prefix,dialplan_entry,active) VALUES (?,?,?,?)')->execute([$name,$prefixes[0],$body,$carrier['active']]);
        }
        $db->prepare("UPDATE v2_carriers SET dialplan_entry='' WHERE carrier_id=?")->execute([$carrier['carrier_id']]);
        $moved++;
    }
    $stored=$db->query('SELECT dialplan_id,dialplan_entry FROM v2_dialplans FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stored as $plan) {
        $body=DialplanOrigins::body($plan['dialplan_entry']);
        if ($body!==$plan['dialplan_entry']) $db->prepare('UPDATE v2_dialplans SET dialplan_entry=? WHERE dialplan_id=?')->execute([$body,$plan['dialplan_id']]);
    }
    $plans=$db->query("SELECT CONCAT('DIALPLAN-',dialplan_id) carrier_id,dialplan_entry FROM v2_dialplans WHERE active='Y'")->fetchAll(PDO::FETCH_ASSOC);
    DialplanOrigins::render($plans);
    $db->commit();
    echo "DIALPLANS_MIGRATED=$moved\n";
} catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
require_once $argv[1].'/includes/Carriers.php';
$result=\Includes\Carriers::regenerateAndReload();
if (!$result['ok']) throw new RuntimeException($result['error']);
