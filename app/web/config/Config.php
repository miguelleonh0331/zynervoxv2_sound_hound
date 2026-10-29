<?php

namespace Config;

class Config {
    private static $settings = [];
    private static $configFile = null;
    public static function deployment($key, $default = null) {
        static $deployment = null;
        if ($deployment === null) {
            $path = __DIR__ . '/deployment.php';
            $deployment = is_file($path) ? require $path : [];
            if (!is_array($deployment)) throw new \RuntimeException('Deployment invalido');
        }
        return $deployment[$key] ?? $default;
    }

    public static function coreTable($purpose) {
        $tables = ['users' => 'zynervox_users', 'deployment' => 'ivr_deploy_config'];
        if (!isset($tables[$purpose])) throw new \InvalidArgumentException('Tabla invalida');
        return self::deployment('isolated', false) ? 'v2_' . $tables[$purpose] : $tables[$purpose];
    }
    public static function requireLegacyRuntime() {
        if (self::deployment('isolated', false)) {
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Motor de llamadas productivo deshabilitado. Configure un motor aislado para v2.']);
            exit;
        }
    }
    public static function audioRoot() {
        return self::deployment('isolated', false) ? self::deployment('runtime') . '/sounds' : '/var/lib/asterisk/sounds/voicebot';
    }

    public static function load() {
        if (!empty(self::$settings)) {
            return self::$settings;
        }
        if (self::deployment('isolated', false)) {
            $directory = self::deployment('directory');
            foreach (['zynervox-core.conf', 'zynervoxv2205-bot_ivr.conf'] as $filename) {
                $path = $directory . '/' . $filename;
                if (!is_file($path)) throw new \RuntimeException('Configuracion aislada incompleta');
                self::loadFile($path);
            }
            return self::$settings;
        }

        self::$configFile = getenv('ZYNERVOX_CONFIG_FILE') ?: (
            file_exists('/etc/zynervox/astguiclient.conf')
                ? '/etc/zynervox/astguiclient.conf'
                : '/etc/astguiclient.conf'
        );

        if (!file_exists(self::$configFile)) {
            // Para pruebas en entornos donde el archivo no existe físicamente (como Windows con Z:)
            // Podríamos mockearlo o lanzar una excepción.
            // En producción debe existir.
            error_log("Config file not found: " . self::$configFile);
        } else {
            self::loadFile(self::$configFile);
        }

        // BD propia de zynervox (zynervox_core / zynervox_users), separada de
        // astguiclient.conf: claves distintas (CORE_DB_*), sin colisión.
        self::loadFile(getenv('ZYNERVOX_CORE_CONFIG_FILE') ?: '/etc/zynervox/zynervox-core.conf');

        // BD propia del IVR Builder (tablas bot_ivr_flow*), base "zynervox"
        // dedicada, separada de astguiclient.conf y de zynervox-core.conf.
        self::loadFile(getenv('ZYNERVOX_BOT_IVR_CONFIG_FILE') ?: '/etc/zynervox/zynervoxv2205-bot_ivr.conf');

        return self::$settings;
    }

    private static function loadFile($path) {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) continue;

            if (preg_match('/^([A-Za-z0-9_]+)\s*=>\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                self::$settings[$key] = $value;
            }
        }
    }

    public static function get($key, $default = null) {
        self::load();
        return self::$settings[$key] ?? $default;
    }
}
if (Config::deployment('isolated', false) && session_status() === PHP_SESSION_NONE) {
    session_name('ZYNERVOXV2');
    session_set_cookie_params(['path' => rtrim(Config::deployment('url', '/zynervoxv2'), '/') . '/', 'httponly' => true, 'samesite' => 'Lax']);
}
