<?php
declare(strict_types=1);
require_once '/var/www/html/zynervox/lib/db.php';
require_once '/var/www/html/zynervox/ivr_builder/flow_store.php';

$db = carsa_db();
$rows = $db->query("SELECT flow_code,data_json FROM bot_ivr_flows WHERE data_json <> '' ORDER BY flow_code")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $flow = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($flow) || ($flow['flow_code'] ?? '') !== $row['flow_code']) throw new RuntimeException('Flujo legado inválido: '.$row['flow_code']);
    flow_store($db, $flow);
    $published = flow_publish($db, $flow);
    echo $row['flow_code'].' '.$published['sha256'].PHP_EOL;
}
