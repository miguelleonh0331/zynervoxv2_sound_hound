<?php
namespace Includes;

use PDO;
use Exception;
use PDOException;

require_once __DIR__ . '/Database.php';

class Reporting {

    private static $configFile = __DIR__ . '/../config/reporting_mirror.json';

    public static function getConfig() {
        if (!file_exists(self::$configFile)) return null;
        $json = @file_get_contents(self::$configFile);
        if (!$json) return null;
        $config = json_decode($json, true);
        
        if ($config) {
            $config['remote_host'] = $config['remote_host'] ?? $config['host'] ?? '';
            $config['remote_port'] = $config['remote_port'] ?? $config['port'] ?? '3306';
            $config['remote_user'] = $config['remote_user'] ?? $config['user'] ?? '';
            $config['remote_pass'] = $config['remote_pass'] ?? $config['pass'] ?? '';
            $config['remote_db']   = $config['remote_db']   ?? $config['db']   ?? 'vox_sphere_mirror';
            $config['is_active']   = $config['is_active'] ?? 0;
        }
        return $config;
    }

    public static function saveConfig($data) {
        $config = self::getConfig() ?? [];
        
        $toSave = [
            'remote_host' => $data['host'] ?? $data['remote_host'] ?? $config['remote_host'] ?? '',
            'remote_port' => $data['port'] ?? $data['remote_port'] ?? $config['remote_port'] ?? '3306',
            'remote_user' => $data['user'] ?? $data['remote_user'] ?? $config['remote_user'] ?? '',
            'remote_pass' => $data['pass'] ?? $data['remote_pass'] ?? $config['remote_pass'] ?? '',
            'remote_db'   => $data['db']   ?? $data['remote_db']   ?? $config['remote_db']   ?? 'vox_sphere_mirror',
            'is_active'   => isset($data['is_active']) ? (int)$data['is_active'] : (int)($config['is_active'] ?? 0)
        ];

        return file_put_contents(self::$configFile, json_encode($toSave, JSON_PRETTY_PRINT));
    }

    public static function getRemoteConnection($config = null) {
        if (!$config) $config = self::getConfig();
        if (!$config) return null;

        $host = $config['remote_host'];
        $port = $config['remote_port'] ?? '3306';
        $user = $config['remote_user'];
        $pass = $config['remote_pass'];
        $dbname = $config['remote_db'];

        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
        return new PDO($dsn, $user, $pass, $options);
    }

    public static function setupMirror($config) {
        $host = $config['remote_host'] ?? $config['host'] ?? '';
        $port = $config['remote_port'] ?? $config['port'] ?? '3306';
        $user = $config['remote_user'] ?? $config['user'] ?? '';
        $pass = $config['remote_pass'] ?? $config['pass'] ?? '';
        $dbName = 'vox_sphere_mirror';

        if (empty($host)) throw new Exception("El host del servidor remoto está vacío.");

        try {
            $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
            $conn = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            
            $conn->exec("CREATE DATABASE IF NOT EXISTS $dbName");
            $conn->exec("USE $dbName");

            $sql = "CREATE TABLE IF NOT EXISTS vicidial_list (
                lead_id int(9) unsigned NOT NULL AUTO_INCREMENT,
                entry_date datetime DEFAULT NULL,
                modify_date timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                status varchar(6) DEFAULT NULL,
                user varchar(20) DEFAULT NULL,
                vendor_lead_code varchar(20) DEFAULT NULL,
                source_id varchar(50) DEFAULT NULL,
                list_id bigint(14) unsigned NOT NULL DEFAULT 0,
                gmt_offset_now decimal(4,2) DEFAULT 0.00,
                called_since_last_reset enum('Y','N','Y1','Y2','Y3','Y4','Y5','Y6','Y7','Y8','Y9','Y10','D') DEFAULT 'N',
                phone_code varchar(10) DEFAULT NULL,
                phone_number varchar(18) NOT NULL,
                title varchar(4) DEFAULT NULL,
                first_name varchar(30) DEFAULT NULL,
                middle_initial varchar(1) DEFAULT NULL,
                last_name varchar(30) DEFAULT NULL,
                address1 varchar(100) DEFAULT NULL,
                address2 varchar(100) DEFAULT NULL,
                address3 varchar(100) DEFAULT NULL,
                city varchar(50) DEFAULT NULL,
                state varchar(2) DEFAULT NULL,
                province varchar(50) DEFAULT NULL,
                postal_code varchar(10) DEFAULT NULL,
                country_code varchar(3) DEFAULT NULL,
                gender enum('M','F','U') DEFAULT 'U',
                date_of_birth date DEFAULT NULL,
                alt_phone varchar(12) DEFAULT NULL,
                email varchar(70) DEFAULT NULL,
                security_phrase varchar(100) DEFAULT NULL,
                comments varchar(255) DEFAULT NULL,
                called_count smallint(5) unsigned DEFAULT 0,
                last_local_call_time datetime DEFAULT NULL,
                `rank` smallint(5) NOT NULL DEFAULT 0,
                owner varchar(20) DEFAULT '',
                entry_list_id bigint(14) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (lead_id),
                KEY status (status),
                KEY list_id (list_id),
                KEY gmt_offset_now (gmt_offset_now),
                KEY phone_number (phone_number),
                KEY postal_code (postal_code),
                KEY last_local_call_time (last_local_call_time),
                KEY `rank` (`rank`),
                KEY owner (owner),
                KEY called_since_last_reset (called_since_last_reset)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $conn->exec($sql);
            
            // Actualizar config JSON para marcar como activo
            self::saveConfig(['is_active' => 1, 'db' => $dbName]);
            
            return true;
        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }
    }

    private static function logError($msg) {
        $logFile = __DIR__ . '/../tmp/vox_mirror_error.log';
        @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    }

    public static function syncLead($data) {
        $config = self::getConfig();
        if (!$config) {
            self::logError("SyncLead Aborted: No se encontró configuración.");
            return false;
        }
        if ($config['is_active'] != 1) {
            // self::logError("SyncLead Aborted: El espejo no está marcado como activo.");
            return false;
        }

        try {
            $remote = self::getRemoteConnection($config);
            if (!$remote) return false;

            $fields = array_keys($data);
            $placeholders = ":" . implode(", :", $fields);
            $cols = implode(", ", $fields);

            $updateFields = [];
            foreach ($fields as $field) {
                if ($field !== 'lead_id') {
                    $updateFields[] = "$field=VALUES($field)";
                }
            }
            $updateSql = implode(", ", $updateFields);

            $sql = "INSERT INTO vicidial_list ($cols) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateSql";
            $stmt = $remote->prepare($sql);
            return $stmt->execute($data);
        } catch (Exception $e) {
            self::logError("SyncLead Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sincroniza un lead específico al espejo consultando sus datos actuales
     */
    public static function syncLeadById($lead_id) {
        self::logError("--- syncLeadById INICIADO ($lead_id) ---");
        try {
            if (!$lead_id) {
                self::logError("SyncLeadById Error: lead_id está vacío.");
                return false;
            }
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM vicidial_list WHERE lead_id = ?");
            $stmt->execute([$lead_id]);
            $lead_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($lead_data) {
                return self::syncLead($lead_data);
            }
        } catch (Exception $e) {
            self::logError("SyncLeadById Error ($lead_id): " . $e->getMessage());
            return false;
        }
        return false;
    }
}
