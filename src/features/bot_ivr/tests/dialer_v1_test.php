<?php
// Run against a scratch database that has the zynervox_bot_* schema + migration 014:
//   sudo php dialer_v1_test.php zv_scratch      (never point it at zynervox_core: it inserts and deletes rows)
declare(strict_types=1);
$database = $argv[1] ?? '';
if (!preg_match('/^zv_[a-z0-9_]+$/D', $database)) { fwrite(STDERR, "Usa una base temporal zv_*\n"); exit(2); }
$web = realpath(__DIR__.'/../../../../app/web');
require_once $web.'/zynervox_queries/contracts/BotIvrRepository.php';
require_once $web.'/zynervox_queries/mysql/bot_ivr/repository.php';
require_once $web.'/bot_ivr/campaign_engine.php';
$pdo = new PDO('mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname='.$database.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$repo = new ZynervoxQueries\Mysql\BotIvr\Repository($pdo);
$fail = 0;
function check(string $what, bool $ok): void { global $fail; echo ($ok ? 'ok   ' : 'FAIL ').$what."\n"; if (!$ok) $fail++; }
function throws(callable $f): string { try { $f(); } catch (Throwable $e) { return $e->getMessage(); } return ''; }
foreach (['call_events','ivr_results','call_attempts','list','lists','campaign_headers','campaign_retry_rules','campaign_schedules','campaigns'] as $t) $pdo->exec("DELETE FROM zynervox_bot_$t");

$base = ['name'=>'T', 'active'=>'1', 'call_origin'=>'7307', 'max_channels'=>'2', 'lead_order'=>'UP', 'amd_enabled'=>'1', 'transfer_carrier_id'=>'dest', 'transfer_destination'=>'5551000',
    'headers'=>[['name'=>'X-Monto', 'field'=>'monto'], ['name'=>'X-Cliente', 'field'=>'customer_name']],
    'retry_rules'=>[['status'=>'B', 'max_attempts'=>'2', 'retry_delay_min'=>'0']], 'schedules'=>[['weekday'=>'1', 'start_time'=>'08:00', 'end_time'=>'18:00']]];
$id = bot_cfg_save($repo, null, $base);
$cfg = bot_cfg_get($repo, $id);
check('guarda headers, reintentos y franjas', count($cfg['headers']) === 2 && count($cfg['retry_rules']) === 1 && $cfg['schedules'][0]['start_time'] === '08:00');
check('canales libres 1..500', bot_cfg_save($repo, $id, ['max_channels'=>'137'] + $base) === $id && (int)$repo->campaign($id)['max_channels'] === 137);
check('rechaza 501 canales', throws(fn() => bot_cfg_save($repo, $id, ['max_channels'=>'501'] + $base)) !== '');
check('rechaza header sin X-', str_contains(throws(fn() => bot_cfg_save($repo, $id, ['headers'=>[['name'=>'Via', 'field'=>'a']]] + $base)), 'Headers'));
check('rechaza header repetido', throws(fn() => bot_cfg_save($repo, $id, ['headers'=>[['name'=>'X-A', 'field'=>'a'], ['name'=>'x-a', 'field'=>'b']]] + $base)) !== '');
check('rechaza reintento NEW', throws(fn() => bot_cfg_save($repo, $id, ['retry_rules'=>[['status'=>'NEW', 'max_attempts'=>1, 'retry_delay_min'=>0]]] + $base)) !== '');
check('rechaza franjas solapadas', throws(fn() => bot_cfg_save($repo, $id, ['schedules'=>[['weekday'=>1, 'start_time'=>'08:00', 'end_time'=>'12:00'], ['weekday'=>1, 'start_time'=>'11:00', 'end_time'=>'13:00']]] + $base)) !== '');
check('mantiene la config tras un guardado fallido', count($repo->campaignConfig($id)['headers']) === 2);
bot_cfg_save($repo, $id, ['max_channels'=>'2'] + $base);

$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,id_flujo,dial_prefix) VALUES ($id,'L',1,1,'7307')");
$list = (int)$pdo->lastInsertId();
check('lista nueva: 1 canal por defecto', (int)$pdo->query("SELECT max_channels FROM zynervox_bot_lists WHERE list_id=$list")->fetchColumn() === 1);
bot_cfg_set_list_channels($repo, $id, $list, 2);
check('rechaza canales de lista sobre el máximo del servidor', throws(fn() => bot_cfg_set_list_channels($repo, $id, $list, 11)) !== '' && throws(fn() => bot_cfg_set_list_channels($repo, $id, 999, 1)) !== '');
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,customer_name,extra_json) VALUES
 ($list,'911111111','Ana','{\"monto\":\"7000\"}'),($list,'922222222','Bo','{\"monto\":\"\"}'),($list,'933333333','Cy','{\"monto\":\"\$5\"}'),($list,'944444444','Di','{\"monto\":\"90\"}'),($list,'955555555','Ed','{\"monto\":\"10\"}')");
check('campos de lead disponibles', in_array('monto', $repo->campaignLeadFields($id), true));
$mon = new DateTimeImmutable('2026-11-02 14:00:00', new DateTimeZone('UTC')); // Monday 09:00 Lima
$sun = new DateTimeImmutable('2026-11-01 14:00:00', new DateTimeZone('UTC'));
check('franja: lunes 09:00 Lima dentro', bot_cfg_in_window($repo->campaign($id), $repo->campaignConfig($id)['schedules'], $mon));
check('franja: domingo fuera', !bot_cfg_in_window($repo->campaign($id), $repo->campaignConfig($id)['schedules'], $sun));

$dry = bot_engine_tick($repo, null, $id, $mon)[0];
check('dry-run lista la llamada con sus headers y el lead sin valor como rechazado', count($dry['would_dial']) === 1 && $dry['would_dial'][0]['headers'] === [['name'=>'X-Monto', 'value'=>'7000'], ['name'=>'X-Cliente', 'value'=>'Ana']] && count($dry['would_reject']) === 1);
check('dry-run no cambia estado', (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_call_attempts")->fetchColumn() === 0 && $pdo->query("SELECT status FROM zynervox_bot_list WHERE phone='911111111'")->fetchColumn() === 'NEW');
check('no inicia sin campaña en running', bot_engine_tick($repo, fn() => true, null, $mon) === []);
bot_cfg_start($repo, $id, 'test', ['7307']);
$calls = [];
$orig = function(array $c, array $call, array $headers) use(&$calls) { $calls[] = [$call, $headers, bot_engine_variables($c, $call, $headers)]; return true; };
$r = bot_engine_tick($repo, $orig, null, $sun)[0];
check('fuera de franja no marca', ($r['skipped'] ?? '') === 'fuera de franja' && !$calls);
$r = bot_engine_tick($repo, $orig, null, $mon)[0];
$mapped = $calls ? $calls[0][2] : [];
check('lanza hasta max_channels con el lead 2 rechazado por header vacío y el 3 por "$"', $r['launched'] === 2 && count($calls) === 2 && $calls[0][0]['phone'] === '911111111' && $calls[1][0]['phone'] === '944444444');
check('los leads con header inválido quedan HDR_ERR (no se pierden en silencio)', (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_list WHERE status='HDR_ERR'")->fetchColumn() === 2);
check('variables __ZV2_*', in_array('__ZV2_AMD=1', $mapped, true) && in_array('__ZV2_XFER_DEST=5551000', $mapped, true) && in_array('__ZV2_XFER_TRUNK=dest', $mapped, true) && in_array('__ZV2_CALL_ID='.$calls[0][0]['call_id'], $mapped, true) && (bool)preg_grep('/^__ZV2_FORWARD_HEADERS=[A-Za-z0-9+\/=]+$/', $mapped));
$r = bot_engine_tick($repo, $orig, null, $mon)[0];
check('con canales llenos no lanza', ($r['launched'] ?? 0) === 0 && $r['active'] === 2);
$first = $calls[0][0]['call_id'];
$repo->finishCall($first, 'BUSY', '', '', 17, false, ['sip_cause'=>'SIP 486 Busy Here', 'cause_source'=>'dialplan']);
$row = $pdo->query("SELECT status,sip_cause,hangup_cause,disposition,cause_source FROM zynervox_bot_call_attempts WHERE call_id='$first'")->fetch();
check('guarda SIP, Q.850 y disposición', $row === ['status'=>'B', 'sip_cause'=>'SIP 486 Busy Here', 'hangup_cause'=>17, 'disposition'=>'BUSY', 'cause_source'=>'dialplan']);
$r = bot_engine_tick($repo, $orig, null, $mon)[0];
check('rellena el canal liberado con el siguiente elegible', $r['launched'] === 1 && count($calls) === 3);
$repo->finishCall($calls[1][0]['call_id'], 'ANSWER', 'MACHINE', 'LONGGREETING', 16, true);
check('MACHINE => AMD_MACHINE y estado AA sin reintento', $pdo->query("SELECT disposition FROM zynervox_bot_call_attempts WHERE call_id='{$calls[1][0]['call_id']}'")->fetchColumn() === 'AMD_MACHINE');
check('el reintento es el mismo lead (regla B)', $calls[2][0]['phone'] === '911111111');
$repo->finishCall($calls[2][0]['call_id'], 'BUSY', '', '', 17, false);
check('tope de intentos: B con 2 intentos ya no es elegible', $repo->countEligibleLeads($id, 'UP') === 1 /* solo Ed, nunca marcado */);
$pdo->exec("UPDATE zynervox_bot_campaign_retry_rules SET retry_delay_min=60");
$repo->finishCall($calls[2][0]['call_id'], 'BUSY', '', '', 17, false); // already finished: no-op
check('finishCall repetido no cambia la causa', $pdo->query("SELECT sip_cause FROM zynervox_bot_call_attempts WHERE call_id='{$calls[2][0]['call_id']}'")->fetchColumn() === '');
$repo->rejectCampaignClaim('zv2-inexistente');
// Unanswered call closed from AMI events (Hangup then OriginateResponse), and transfer stages from the finish AGI
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,customer_name,extra_json) VALUES ($list,'966666666','Fa','{\"monto\":\"1\"}')");
$more = bot_engine_tick($repo, $orig, null, $mon); $hang = [];
$open = $pdo->query("SELECT call_id FROM zynervox_bot_call_attempts WHERE finished_at IS NULL ORDER BY attempt_id")->fetchAll(PDO::FETCH_COLUMN);
check('hay llamadas abiertas para cerrar', count($open) >= 2);
bot_engine_handle_event($repo, ['Event'=>'Hangup', 'Uniqueid'=>$open[0], 'Cause'=>'17', 'Cause-txt'=>'User busy'], $hang);
bot_engine_handle_event($repo, ['Event'=>'OriginateResponse', 'Uniqueid'=>$open[0], 'Response'=>'Failure', 'Reason'=>'5'], $hang);
$row = $pdo->query("SELECT status,disposition,hangup_cause,sip_cause,cause_source,end_source FROM zynervox_bot_call_attempts WHERE call_id='{$open[0]}'")->fetch();
check('no contestada: causa por AMI', $row['status'] === 'B' && $row['disposition'] === 'BUSY' && (int)$row['hangup_cause'] === 17 && str_contains($row['sip_cause'], 'User busy') && $row['cause_source'] === 'ami' && $row['end_source'] === 'AMI');
bot_engine_handle_event($repo, ['Event'=>'OriginateResponse', 'Uniqueid'=>'otro-123', 'Response'=>'Failure', 'Reason'=>'5'], $hang);
$repo->finishCall($open[1], 'ANSWER', '', '', 16, true, ['sip_cause'=>'200', 'stage'=>'XFER']);
$row = $pdo->query("SELECT status,disposition FROM zynervox_bot_call_attempts WHERE call_id='{$open[1]}'")->fetch();
check('transferencia contestada => XFER_OK', $row === ['status'=>'XFER_OK', 'disposition'=>'XFER_OK']);
// Hot channel change on the list, campaign still running; global cap
bot_cfg_set_list_channels($repo, $id, $list, 1);
bot_cfg_set_max_total_channels($repo, 1);
$r = bot_engine_tick($repo, $orig, null, $mon)[0];
check('máximo del servidor 1 con llamadas abiertas: no lanza', ($r['launched'] ?? 0) === 0 && $r['max_total_channels'] === 1);
bot_cfg_set_max_total_channels($repo, 10);
bot_cfg_stop($repo, $id, 'test');
check('detener deja la campaña stopped', $repo->campaign($id)['dialer_state'] === 'stopped');
// Detection-only campaign (test stage): no trunk and no destination is valid, half-configured is not
$det = bot_cfg_save($repo, null, ['name'=>'Solo detectar', 'active'=>'1', 'call_origin'=>'7307', 'max_channels'=>'1', 'amd_enabled'=>'1']);
$pdo->prepare("INSERT INTO zynervox_bot_lists (campaign_id,name,active) VALUES (?,'det',1)")->execute([$det]);
$detList = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO zynervox_bot_list (list_id,phone,status) VALUES (?,'955555555','NEW')")->execute([$detList]);
bot_cfg_start($repo, $det, 'test', ['7307']);
$calls = [];
$r = bot_engine_tick($repo, $orig, $det, $mon)[0];
$vars = $calls ? $calls[0][2] : [];
check('sin troncal marca y no pasa __ZV2_XFER_*', ($r['launched'] ?? 0) === 1 && in_array('__ZV2_AMD=1', $vars, true) && !preg_grep('/^__ZV2_XFER_/', $vars));
$pdo->prepare("INSERT INTO zynervox_bot_list (list_id,phone,status) VALUES (?,'966666666','NEW')")->execute([$detList]);
$r = bot_engine_tick($repo, $orig, $det, $mon)[0];
check('lista con 1 canal y 1 llamada abierta: no lanza más', ($r['launched'] ?? 0) === 0 && count($calls) === 1);
bot_cfg_set_list_channels($repo, $det, $detList, 2);
$r = bot_engine_tick($repo, $orig, $det, $mon)[0];
check('subir los canales de la lista en caliente lanza la segunda', ($r['launched'] ?? 0) === 1 && count($calls) === 2);
$repo->setDialerState($det, 'stopped', 'test');
$repo->saveCampaignConfig($det, ['name'=>'Solo detectar','active'=>1,'call_origin'=>'7307','max_channels'=>1,'amd_enabled'=>1,'transfer_carrier_id'=>'T','transfer_destination'=>'','timezone'=>'America/Lima','lead_order'=>'DOWN','dial_timeout'=>60,'campaign_cid'=>''], [], [], []);
check('troncal sin destino no inicia', str_contains(throws(fn() => bot_cfg_start($repo, $det, 'test', ['7307'])), 'ambos vacíos'));
$rep = bot_cfg_attempts_report($repo, $det, 10);
check('reporte: intentos con las columnas de AMD y causas', count($rep) === 2 && array_key_exists('amd_status', $rep[0]) && array_key_exists('sip_cause', $rep[0]) && array_key_exists('q850', $rep[0]));
echo $fail ? "$fail FALLOS\n" : "TODO OK\n";
exit($fail ? 1 : 0);
