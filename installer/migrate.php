<?php
declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Uso: php migrate.php WEB_ROOT MIGRATIONS_DIR\n");
    exit(2);
}

$webRoot = rtrim($argv[1], '/');
$migrationDir = rtrim($argv[2], '/');
require_once $webRoot . '/includes/Database.php';
$db = \Includes\Database::getInstance();
$db->exec('CREATE TABLE IF NOT EXISTS zynervox_schema_migrations (' .
    'name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');

$files = glob($migrationDir . '/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $name = basename($file);
    $check = $db->prepare('SELECT 1 FROM zynervox_schema_migrations WHERE name = ?');
    $check->execute([$name]);
    if ($check->fetchColumn()) {
        echo "omitida: {$name}\n";
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("No se pudo leer {$name}");
    }
    $db->beginTransaction();
    try {
        $db->exec($sql);
        $save = $db->prepare('INSERT INTO zynervox_schema_migrations (name) VALUES (?)');
        $save->execute([$name]);
        $db->commit();
        echo "aplicada: {$name}\n";
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
