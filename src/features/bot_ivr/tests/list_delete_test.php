<?php
// Run against a scratch database with the zynervox_bot_* schema (never zynervox_core):
//   sudo php list_delete_test.php zv_scratch
declare(strict_types=1);
$database = $argv[1] ?? '';
if (!preg_match('/^zv_[a-z0-9_]+$/D', $database)) { fwrite(STDERR, "Usa una base temporal zv_*\n"); exit(2); }
$web = realpath(__DIR__.'/../../../../app/web');
require_once $web.'/zynervox_queries/contracts/BotIvrRepository.php';
require_once $web.'/zynervox_queries/mysql/bot_ivr/repository.php';
require_once $web.'/bot_ivr/list_service.php';
$pdo = new PDO('mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname='.$database.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$repo = new ZynervoxQueries\Mysql\BotIvr\Repository($pdo);
$fail = 0;
function check(string $what, bool $ok): void { global $fail; echo ($ok ? 'ok   ' : 'FAIL ').$what."\n"; if (!$ok) $fail++; }
function throws(callable $f): string { try { $f(); } catch (Throwable $e) { return $e->getMessage(); } return ''; }
function count1(PDO $p, string $sql, array $a = []): int { $s = $p->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); }

$camp = $repo->createCampaign('DEL-TEST', true);
$other = $repo->createCampaign('DEL-OTHER', true);
$list = $repo->createList($camp, 'lista-borrar', true);
$keep = $repo->createList($camp, 'lista-conservar', true);
$repo->importLeads($list, $camp, ['rows'=>[['phone'=>'51900000001','customer_name'=>'A','extra'=>[]],['phone'=>'51900000002','customer_name'=>'B','extra'=>[]]], 'duplicates'=>0, 'rejected'=>0, 'errors'=>[]]);
$repo->importLeads($keep, $camp, ['rows'=>[['phone'=>'51900000009','customer_name'=>'Z','extra'=>[]]], 'duplicates'=>0, 'rejected'=>0, 'errors'=>[]]);

check('rechaza campaña ajena', throws(fn() => bot_list_delete($repo, $list, $other, 't')) !== '');
$repo->startCall($list, '51900000001', 'zv2-deltest-open');
check('rechaza con llamada abierta', str_contains(throws(fn() => bot_list_delete($repo, $list, $camp, 't')), 'terminen las llamadas'));
check('rechazo no borra nada', count1($pdo, 'SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=?', [$list]) === 2);
$repo->finishCall('zv2-deltest-open', 'BUSY', '', '', 17, false);
$pdo->exec("INSERT INTO zynervox_bot_dial_runs(run_id,list_id,campaign_id,state,channels,flow_id,dial_prefix,config_json,created_by,created_at,updated_at) VALUES ('deltestrun000000000000000000000a',$list,$camp,'running',1,1,'7307','{}','t',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
check('rechaza con marcación activa', throws(fn() => bot_list_delete($repo, $list, $camp, 't')) !== '');
$pdo->exec("DELETE FROM zynervox_bot_dial_runs WHERE run_id='deltestrun000000000000000000000a'");

check('borra y devuelve 2 leads', bot_list_delete($repo, $list, $camp, 'test') === 2);
check('lista y leads desaparecen', count1($pdo, 'SELECT COUNT(*) FROM zynervox_bot_lists WHERE list_id=?', [$list]) === 0 && count1($pdo, 'SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=?', [$list]) === 0);
check('conserva los intentos', count1($pdo, "SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE call_id='zv2-deltest-open'") === 1);
check('otra lista intacta', count1($pdo, 'SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=?', [$keep]) === 1);
check('lista inexistente falla', throws(fn() => bot_list_delete($repo, $list, $camp, 't')) !== '');

$pdo->exec("DELETE FROM zynervox_bot_call_events WHERE call_id='zv2-deltest-open'");
$pdo->exec("DELETE FROM zynervox_bot_call_attempts WHERE call_id='zv2-deltest-open'");
$pdo->exec("DELETE FROM zynervox_bot_list WHERE list_id=$keep");
$pdo->exec("DELETE FROM zynervox_bot_lists WHERE list_id=$keep");
$pdo->exec("DELETE FROM zynervox_bot_campaigns WHERE campaign_id IN ($camp,$other)");
echo $fail ? "FALLOS: $fail\n" : "TODO OK\n";
exit($fail ? 1 : 0);
