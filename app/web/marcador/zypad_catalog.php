<?php
declare(strict_types=1);
// CLI for the zypad bridge: prints one JSON line {"version","words":[{word,category,active}]} and exits 0; on any failure prints nothing to stdout, logs to stderr and exits 1.
// Reads the database with the web configuration, so it runs as apache: sudo -n -u apache php /var/www/html/zynervoxv2/marcador/zypad_catalog.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
try {
    require_once __DIR__.'/../bot_ivr/db.php';
    require_once __DIR__.'/zypad.php';
    echo json_encode(marcador_zypad_catalog(bot_ivr_repository()), JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ZV2 zypad_catalog: '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
