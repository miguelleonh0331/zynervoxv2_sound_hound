<?php
declare(strict_types=1);

namespace Includes;

final class DeploymentConfig {
    public static function database(array $input, array $existing): array {
        $host = trim((string) ($input['db_host'] ?? ''));
        $port = filter_var($input['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $name = trim((string) ($input['db_name'] ?? ''));
        $user = trim((string) ($input['db_user'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9._:\[\]-]+$/', $host) || $port === false || !preg_match('/^[a-zA-Z0-9_]+$/', $name) || $user === '' || strpos($user, "\0") !== false) {
            throw new \InvalidArgumentException('Revisa host, puerto (1–65535), base de datos y usuario.');
        }
        $password = (string) ($input['db_pass'] ?? '');
        if (class_exists('Config\\Config') && \Config\Config::deployment('isolated', false)) {
            if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $name !== \Config\Config::get('CORE_DB_database') || $user !== \Config\Config::get('BOT_IVR_DB_user')) {
                throw new \InvalidArgumentException('v2 aislado solo permite su base y usuario exclusivos.');
            }
        }
        if ($password === '' || $password === '••••••••') $password = (string) ($existing['db_pass'] ?? '');
        return ['db_host' => $host, 'db_port' => $port, 'db_name' => $name, 'db_user' => $user, 'db_pass' => $password];
    }

    public static function csrf(string $expected, string $provided): bool {
        return $expected !== '' && hash_equals($expected, $provided);
    }
}
