<?php
declare(strict_types=1);
namespace ZynervoxQueries;
function engine(array $config): string {
    $value = $config['engine'] ?? 'mysql';
    if (!is_string($value) || !in_array($value, ['mysql', 'mariadb'], true)) {
        throw new \RuntimeException('Motor no disponible. Esta instalación admite MySQL y MariaDB.');
    }
    return $value;
}
function connect(array $config): \PDO {
    engine($config);
    foreach (['server', 'database', 'user'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '' || preg_match('/[;\x00-\x1f]/', $config[$key])) {
            throw new \RuntimeException('Servidor, base de datos y usuario son obligatorios y deben ser válidos.');
        }
    }
    $port = filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>65535]]);
    if ($port === false) throw new \RuntimeException('El puerto debe estar entre 1 y 65535.');
    return new \PDO("mysql:host={$config['server']};port=$port;dbname={$config['database']};charset=utf8mb4",
        $config['user'], (string)($config['password'] ?? ''), [
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES=>false,
            \PDO::ATTR_TIMEOUT=>5,
        ]);
}
