<?php
declare(strict_types=1);
// Import the sanitized CARSA reference into the deployed v2 builder, never legacy DB.
if (PHP_SAPI !== 'cli' || count($argv) !== 2) throw new RuntimeException('Usage: php clone-carsa3-flow.php DEPLOYED_WEB_ROOT');
$web = realpath($argv[1]);
if (!$web) throw new RuntimeException('Invalid deployed web root');
require $web.'/ivr_builder/flow_store.php';
require $web.'/bot_ivr/ivr_graph_service.php';
if (!\Config\Config::deployment('isolated', false)) throw new RuntimeException('An isolated v2 deployment is required');
$flow = json_decode(file_get_contents(__DIR__.'/../src/features/bot_ivr/tests/fixtures/carsa3.json'), true, 512, JSON_THROW_ON_ERROR);
bot_ivr_validate_graph($flow);
$db = \Includes\Database::getBotIvrInstance();
$before = flow_load($db, '11');
$existing = flow_load($db, '02');
if ($existing === null) flow_store($db, $flow);
$loaded = flow_load($db, '02');
if ($loaded['name'] !== $flow['name'] || $loaded['start'] !== $flow['start']) throw new RuntimeException('Existing flow 02 differs from reference');
if (count($loaded['nodes']) !== 27) throw new RuntimeException('Incomplete clone');
foreach ($flow['nodes'] as $id => $node) {
    foreach ($node as $key => $value) {
        if ($key === 'stt_intents') {
            $sort = static function (&$items): void { usort($items, static function ($a, $b) { return strcmp($a['id'], $b['id']); }); };
            $sort($value); $sort($loaded['nodes'][$id][$key]);
        }
        if ($loaded['nodes'][$id][$key] != $value) throw new RuntimeException('Clone mismatch: '.$id.'/'.$key);
    }
}
bot_ivr_validate_graph($loaded);
$published = flow_publish($db, $loaded);
if (flow_load($db, '11') !== $before) throw new RuntimeException('Unexpected change to flow 11');
$path = IVR_PUBLISHED_DIR.'/02.json';
if (is_file($path)) { chgrp($path, 'www'); chmod($path, 0640); }
echo json_encode(['flow_code'=>'02','name'=>$loaded['name'],'nodes'=>count($loaded['nodes']),'published'=>$published,'flow_11_unchanged'=>true], JSON_UNESCAPED_SLASHES), PHP_EOL;
