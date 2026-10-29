<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/DialplanOrigins.php';

class Carriers {

    public static function protocol(array $carrier): string {
        preg_match_all('/^\s*type\s*=\s*(peer|friend|user|endpoint|aor|auth)\s*(?:;[^\r\n]*)?$/mi', (string)($carrier['account_entry'] ?? ''), $matches);
        $sip=$pjsip=false;
        foreach ($matches[1] as $type) {
            if (in_array(strtolower($type),['peer','friend','user'],true)) $sip=true;
            else $pjsip=true;
        }
        if ($sip && $pjsip) throw new \InvalidArgumentException('No mezcles bloques SIP y PJSIP en la misma troncal.');
        return $sip ? 'SIP' : ($pjsip ? 'PJSIP' : (string)($carrier['protocol'] ?? 'PJSIP'));
    }

    private static function selectedProtocol(array $data): string {
        $selected = $data['protocol'] ?? self::protocol($data);
        if (!is_string($selected) || !in_array($selected, ['SIP','PJSIP'], true)) throw new \InvalidArgumentException('Selecciona SIP o PJSIP.');
        if (self::protocol($data) !== $selected) throw new \InvalidArgumentException('El bloque de configuración no corresponde al protocolo seleccionado.');
        return $selected;
    }

    public static function dialOrigins(): array {
        if (!\Config\Config::deployment('isolated', false)) return [];
        require_once __DIR__.'/Dialplans.php';
        return Dialplans::origins();
    }

    public static function extractDialOrigin(string $carrierId, string $prefix, string $name): void {
        if (!\Config\Config::deployment('isolated', false)) throw new \InvalidArgumentException('Requiere despliegue v2 aislado.');
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || preg_match('/[\x00-\x1f]/', $name)) throw new \InvalidArgumentException('Ingresa un nombre de hasta 100 caracteres.');
        $db = self::database();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT dialplan_entry FROM v2_carriers WHERE carrier_id=? FOR UPDATE');
            $stmt->execute([$carrierId]);
            $plan = $stmt->fetchColumn();
            if ($plan === false || !in_array($prefix, DialplanOrigins::prefixes($plan), true)) throw new \InvalidArgumentException('El prefijo no existe en el dialplan guardado de esta troncal.');
            $stmt = $db->prepare('SELECT carrier_id FROM v2_dial_origins WHERE dial_prefix=? FOR UPDATE');
            $stmt->execute([$prefix]);
            $owner = $stmt->fetchColumn();
            if ($owner !== false && $owner !== $carrierId) throw new \InvalidArgumentException('Este prefijo ya pertenece a otra troncal.');
            if ($owner === false) $db->prepare('INSERT INTO v2_dial_origins (dial_prefix,name,carrier_id) VALUES (?,?,?)')->execute([$prefix,$name,$carrierId]);
            else $db->prepare('UPDATE v2_dial_origins SET name=? WHERE dial_prefix=?')->execute([$name,$prefix]);
            self::audit('ORIGIN', $carrierId);
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }

    private static function database() {
        if (\Config\Config::deployment('isolated', false)) {
            if (\Config\Config::get('CORE_DB_database') !== 'zynervox_core') {
                throw new \RuntimeException('Carriers requiere zynervox_core');
            }
            return Database::getCoreInstance();
        }
        return Database::getInstance();
    }

    private static function table() {
        return \Config\Config::deployment('isolated', false) ? 'v2_carriers' : 'vicidial_server_carriers';
    }

    private static function audit($action, $carrierId) {
        if (\Config\Config::deployment('isolated', false)) {
            return Audit::logAccess($_SESSION['user'] ?? 'system', 'CARRIER_' . $action, $carrierId);
        }
        return Audit::logTableChange($_SESSION['user'] ?? 'system', $action, self::table(), 'carrier_id', $carrierId);
    }

    public static function recentChanges() {
        if (!\Config\Config::deployment('isolated', false)) return Audit::getRecent('vicidial_server_carriers', 20);
        return self::database()->query("SELECT created_at AS audit_timestamp, user AS audit_user, action AS audit_action, details AS carrier_id FROM v2_access_log WHERE action IN ('CARRIER_CREATE','CARRIER_UPDATE','CARRIER_DELETE') ORDER BY id DESC LIMIT 20")->fetchAll();
    }

    private static function validate($data) {
        if (!\Config\Config::deployment('isolated', false)) return;
        if (!preg_match('/^[A-Z0-9_-]{1,60}$/D', (string) ($data['carrier_id'] ?? ''))) throw new \InvalidArgumentException('ID de troncal invalido');
        if (!in_array($data['active'] ?? 'Y', ['Y', 'N'], true)) throw new \InvalidArgumentException('Estado invalido');
        foreach (['carrier_name', 'server_ip'] as $field) {
            if (strlen((string) ($data[$field] ?? '')) > 100 || preg_match('/[\r\n]/', (string) ($data[$field] ?? ''))) throw new \InvalidArgumentException('Campo invalido: ' . $field);
        }
    }

    const CONF_PATH = '/etc/asterisk/synervox/modules/asterisk/pjsip-zynervox.conf';
    const DIALPLAN_PATH = '/etc/asterisk/synervox/modules/asterisk/extensions-zynervox.conf';
    // Wrapper minimo con sudoers dedicado (www-data solo puede correr este
    // script exacto, no tiene acceso libre al CLI/AMI de Asterisk). El
    // wrapper hace "pjsip reload" y "dialplan reload" en un solo paso.
    const RELOAD_CMD = 'sudo /usr/local/sbin/zynervox-pjsip-reload.sh 2>&1';

    public static function getAll() {
        $db = self::database();
        $table = self::table();
        $stmt = $db->query("SELECT * FROM $table ORDER BY carrier_id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById($carrier_id) {
        $db = self::database();
        $table = self::table();
        $stmt = $db->prepare("SELECT * FROM $table WHERE carrier_id = :id LIMIT 1");
        $stmt->execute(['id' => $carrier_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function create($data) {
        $data['protocol'] = \Config\Config::deployment('isolated', false) ? self::selectedProtocol($data) : 'PJSIP';
        self::validate($data);
        if (\Config\Config::deployment('isolated', false)) $data['dialplan_entry'] = DialplanOrigins::body((string)($data['dialplan_entry'] ?? ''));
        $db = self::database();
        $table = self::table();
        try {
            $stmt = $db->prepare(
                "INSERT INTO $table
                 (carrier_id, carrier_name, template_id, protocol, account_entry, dialplan_entry, server_ip, active, carrier_description)
                 VALUES (:carrier_id, :carrier_name, 'CUSTOM', :protocol, :account_entry, :dialplan_entry, :server_ip, :active, :carrier_description)"
            );
            $stmt->execute([
                'carrier_id' => $data['carrier_id'],
                'carrier_name' => $data['carrier_name'],
                'protocol' => $data['protocol'],
                'account_entry' => $data['account_entry'] ?? '',
                'dialplan_entry' => $data['dialplan_entry'] ?? '',
                'server_ip' => $data['server_ip'],
                'active' => $data['active'] ?? 'Y',
                'carrier_description' => $data['carrier_description'] ?? '',
            ]);
            self::audit('CREATE', $data['carrier_id']);
            return self::regenerateAndReload();
        } catch (\PDOException $e) { return self::databaseError($e); }
    }

    public static function update($carrier_id, $data) {
        $data['carrier_id'] = $carrier_id;
        if (\Config\Config::deployment('isolated', false)) $data['protocol'] = self::selectedProtocol($data);
        else $data['protocol'] = self::getById($carrier_id)['protocol'] ?? 'PJSIP';
        self::validate($data);
        if (\Config\Config::deployment('isolated', false)) $data['dialplan_entry'] = DialplanOrigins::body((string)($data['dialplan_entry'] ?? ''));
        $db = self::database();
        $table = self::table();
        try {
            $stmt = $db->prepare(
                "UPDATE $table SET
                 carrier_name = :carrier_name, protocol = :protocol, account_entry = :account_entry, dialplan_entry = :dialplan_entry,
                 server_ip = :server_ip, active = :active, carrier_description = :carrier_description
                 WHERE carrier_id = :id"
            );
            $stmt->execute([
                'carrier_name' => $data['carrier_name'],
                'protocol' => $data['protocol'],
                'account_entry' => $data['account_entry'] ?? '',
                'dialplan_entry' => $data['dialplan_entry'] ?? '',
                'server_ip' => $data['server_ip'],
                'active' => $data['active'] ?? 'Y',
                'carrier_description' => $data['carrier_description'] ?? '',
                'id' => $carrier_id,
            ]);
            self::audit('UPDATE', $carrier_id);
            return self::regenerateAndReload();
        } catch (\PDOException $e) { return self::databaseError($e); }
    }

    public static function delete($carrier_id) {
        self::validate(['carrier_id' => $carrier_id]);
        $db = self::database();
        $table = self::table();
        try {
            if (!\Config\Config::deployment('isolated', false)) self::audit('DELETE', $carrier_id);
            $stmt = $db->prepare("DELETE FROM $table WHERE carrier_id = :id");
            $stmt->execute(['id' => $carrier_id]);
            if (\Config\Config::deployment('isolated', false) && $stmt->rowCount() > 0) self::audit('DELETE', $carrier_id);
            return self::regenerateAndReload();
        } catch (\PDOException $e) { return self::databaseError($e); }
    }

    // Reconstruye modules/asterisk/pjsip-zynervox.conf (account_entry) y
    // modules/asterisk/extensions-zynervox.conf
    // (dialplan_entry) con las troncales PJSIP activas, y dispara
    // "pjsip reload" + "dialplan reload" via el wrapper sudo restringido.
    public static function regenerateAndReload() {
        $db = self::database();
        $table = self::table();
        $protocolFilter = \Config\Config::deployment('isolated', false) ? "protocol IN ('SIP','PJSIP')" : "protocol = 'PJSIP'";
        $stmt = $db->query("SELECT carrier_id, carrier_name, account_entry, dialplan_entry FROM $table WHERE active = 'Y' AND $protocolFilter ORDER BY carrier_id ASC");
        $carriers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $confOut = "; Generado por Zynervox (carriers.php) - " . date('Y-m-d H:i:s') . "\n";
        $confOut .= "; NO EDITAR A MANO, se sobreescribe desde el panel de administracion.\n\n";

        $dialOut = "; Generado por Zynervox (carriers.php) - " . date('Y-m-d H:i:s') . "\n";
        $dialOut .= "; NO EDITAR A MANO, se sobreescribe desde el panel de administracion.\n\n";

        foreach ($carriers as $c) {
            $confOut .= "; ---- Carrier: {$c['carrier_id']} ({$c['carrier_name']}) ----\n";
            $confOut .= rtrim($c['account_entry']) . "\n\n";

            if (trim($c['dialplan_entry']) !== '') {
                $dialOut .= "; ---- Carrier: {$c['carrier_id']} ({$c['carrier_name']}) ----\n";
                $dialOut .= rtrim($c['dialplan_entry']) . "\n\n";
            }
        }

        $isolated = \Config\Config::deployment('isolated', false);
        if ($isolated) {
            require_once __DIR__.'/Dialplans.php';
            foreach (Dialplans::getAll() as $plan) {
                if ($plan['active']==='Y') $carriers[]=['carrier_id'=>'DIALPLAN-'.$plan['dialplan_id'],'dialplan_entry'=>$plan['dialplan_entry']];
            }
            try { $dialOut = DialplanOrigins::render($carriers); }
            catch (\InvalidArgumentException $e) { return ['ok'=>false,'error'=>$e->getMessage()]; }
        }
        $runtime = (string) \Config\Config::deployment('runtime', '');
        if ($isolated && ($runtime === '' || $runtime === '/' || strpos($runtime, '/etc/asterisk') === 0 || strpos($runtime, '/var/lib/asterisk') === 0)) {
            return ['ok' => false, 'error' => 'Runtime Carriers aislado invalido'];
        }
        $directory = $runtime . '/modules/asterisk';
        $confPath = $isolated ? $directory . '/pjsip-zynervoxv2.conf' : self::CONF_PATH;
        $dialplanPath = $isolated ? $directory . '/extensions-zynervoxv2.conf' : self::DIALPLAN_PATH;
        $w1 = $isolated ? self::writeConfiguration($confPath, $confOut) : @file_put_contents($confPath, $confOut);
        $w2 = $isolated ? self::writeConfiguration($dialplanPath, $dialOut) : @file_put_contents($dialplanPath, $dialOut);
        if ($w1 === false || $w2 === false) {
            return ['ok' => false, 'error' => 'Datos guardados; no se pudo generar ' . $confPath . ' o ' . $dialplanPath . ' (revisar permisos).'];
        }

        if ($isolated) return ['ok' => true, 'reload_output' => 'Configuracion v2 generada. No se modifica ni recarga Asterisk; activacion pendiente.'];

        $reloadOutput = shell_exec(self::RELOAD_CMD);
        return ['ok' => true, 'reload_output' => trim($reloadOutput ?? '')];
    }

    private static function writeConfiguration($path, $contents) {
        if (!is_dir(dirname($path)) || realpath(dirname($path)) !== dirname($path) || is_link($path)) return false;
        $temporary = tempnam(dirname($path), '.carriers-');
        if ($temporary === false) return false;
        $written = file_put_contents($temporary, $contents, LOCK_EX) !== false && chmod($temporary, 0640) && rename($temporary, $path);
        if (!$written) @unlink($temporary);
        return $written;
    }

    private static function databaseError($error) {
        return ['ok' => false, 'error' => \Config\Config::deployment('isolated', false) ? 'No se pudo guardar la troncal; revise campos, ID y permisos de Core.' : $error->getMessage()];
    }
}
