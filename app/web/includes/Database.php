<?php

namespace Includes;

use Config\Config;
use PDO;
use PDOException;

require_once __DIR__ . '/../config/Config.php';

class Database {
    private static $instance = null;
    private static $coreInstance = null;
    private static $botIvrInstance = null;
    private $conn;

    private function __construct($host, $db, $user, $pass, $port, $missingMessage) {
        if (!$host || !$db || !$user || $pass === null) {
            throw new PDOException($missingMessage);
        }

        $dsn = "mysql:host=$host;dbname=$db;port=$port;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public static function getInstance() {
        if (Config::deployment('isolated', false)) throw new PDOException('Integracion VICIdial productiva deshabilitada en v2 aislado');
        if (self::$instance == null) {
            self::$instance = new Database(
                Config::get('VARDB_server'),
                Config::get('VARDB_database'),
                Config::get('VARDB_user'),
                Config::get('VARDB_pass'),
                Config::get('VARDB_port', '3306'),
                "Error de configuración: Faltan credenciales de BD en /etc/astguiclient.conf"
            );
        }
        return self::$instance->conn;
    }

    // BD propia de zynervox (zynervox_core / zynervox_users): login admin
    // independiente de vicidial_users y de astguiclient.conf. Ver
    // installer/zynervox-core.sh.
    public static function getCoreInstance() {
        if (self::$coreInstance == null) {
            self::$coreInstance = new Database(
                Config::get('CORE_DB_server'),
                Config::get('CORE_DB_database'),
                Config::get('CORE_DB_user'),
                Config::get('CORE_DB_pass'),
                Config::get('CORE_DB_port', '3306'),
                "Error de configuración: falta /etc/zynervox/zynervox-core.conf"
            );
        }
        return self::$coreInstance->conn;
    }

    // BD propia del IVR Builder (tablas bot_ivr_flow*). Configurable desde
    // el panel de engranaje del IVR Builder (tabla ivr_deploy_config en la
    // BD "core"): eso tiene prioridad. Si todavia no se guardo nada ahi,
    // cae al conf estatico /etc/zynervox/zynervoxv2205-bot_ivr.conf
    // (bootstrap inicial, mismo server hoy: zynervox/zynervox_bot_ivr).
    public static function getBotIvrInstance() {
        if (self::$botIvrInstance == null) {
            $cfg = self::getBotIvrConfig();
            self::$botIvrInstance = new Database(
                $cfg['server'], $cfg['database'], $cfg['user'], $cfg['password'],
                (int) $cfg['port'] ?: 3306,
                "Error de configuración: revisa Servicios > Base de datos"
            );
        }
        return self::$botIvrInstance->conn;
    }

    // Fila singleton (id=1) de ivr_deploy_config, o null si la tabla no
    // existe todavia / la BD core no responde (bootstrap temprano).
    public static function loadIvrDeployConfig(bool $strict = false): ?array {
        try {
            $core = self::getCoreInstance();
            $row = $core->query(
                'SELECT db_host,db_port,db_name,db_user,db_pass,asterisk_api_url,asterisk_api_token FROM ' . Config::coreTable('deployment') . ' WHERE id=1'
            )->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            if ($strict) throw new \RuntimeException('Configuración central no disponible. Revisa Servicios > Base de datos.');
            return null;
        }
    }

    public static function getBotIvrConfig(): array {
        $config = self::loadIvrDeployConfig(true);
        if ($config !== null && (string) $config['db_host'] !== '') {
            return [
                'engine' => 'mysql', 'server' => $config['db_host'],
                'port' => $config['db_port'], 'database' => $config['db_name'],
                'user' => $config['db_user'], 'password' => $config['db_pass'],
            ];
        }
        $legacyPath = getenv('BOT_IVR_LEGACY_CONFIG') ?: '/etc/asterisk/synervox/secrets/bot_ivr_db.json';
        if (Config::deployment('isolated', false)) $legacyPath = '';
        if (is_file($legacyPath)) {
            $legacy = json_decode((string) file_get_contents($legacyPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($legacy)) throw new \RuntimeException('Configuración legacy inválida');
            return $legacy;
        }
        if (Config::get('BOT_IVR_DB_server')) {
            return [
                'engine' => 'mysql', 'server' => Config::get('BOT_IVR_DB_server'),
                'port' => Config::get('BOT_IVR_DB_port', '3306'),
                'database' => Config::get('BOT_IVR_DB_database'),
                'user' => Config::get('BOT_IVR_DB_user'), 'password' => Config::get('BOT_IVR_DB_pass'),
            ];
        }
        throw new \RuntimeException('Configura la conexión desde Servicios > Base de datos.');
    }
}
