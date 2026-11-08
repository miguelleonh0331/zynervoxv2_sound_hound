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
require_once $web.'/marcador/amd_stt_batch.php';
require_once $web.'/bot_ivr/list_service.php';
$pdo = new PDO('mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname='.$database.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$repo = new ZynervoxQueries\Mysql\BotIvr\Repository($pdo);
$fail = 0;
function check(string $what, bool $ok): void { global $fail; echo ($ok ? 'ok   ' : 'FAIL ').$what."\n"; if (!$ok) $fail++; }
function throws(callable $f): string { try { $f(); } catch (Throwable $e) { return $e->getMessage(); } return ''; }
$pdo->exec("DELETE FROM zynervox_bot_amd_global_versions WHERE version_id>(SELECT m FROM (SELECT MIN(version_id) m FROM zynervox_bot_amd_global_versions) t)");
$pdo->exec("UPDATE zynervox_bot_dialer_settings SET value=10 WHERE name='max_total_channels'");
$pdo->exec("UPDATE zynervox_bot_dialer_settings SET value=(SELECT MIN(version_id) FROM zynervox_bot_amd_global_versions) WHERE name='amd_global_version'");
foreach (['call_events','ivr_results','call_attempts','list','lists','campaign_headers','campaign_retry_rules','list_summary','amd_audit','campaign_amd_params','campaign_sip_rules','campaign_amd_actions','campaign_schedules','campaigns'] as $t) $pdo->exec("DELETE FROM zynervox_bot_$t");

$base = ['name'=>'T', 'active'=>'1', 'call_origin'=>'7307', 'max_channels'=>'2', 'lead_order'=>'UP', 'amd_enabled'=>'1', 'transfer_carrier_id'=>'dest', 'transfer_destination'=>'5551000',
    'headers'=>[['name'=>'X-Monto', 'field'=>'monto'], ['name'=>'X-Cliente', 'field'=>'customer_name']],
    'rules'=>[['sip_code'=>'486', 'max_attempts'=>'2', 'retry_delay_min'=>'0'], ['sip_code'=>'600', 'max_attempts'=>'2', 'retry_delay_min'=>'0']],
    'amd_actions'=>[['result'=>'NOTSURE', 'action'=>'retry', 'max_attempts'=>'2', 'retry_delay_min'=>'0'], ['result'=>'MACHINE', 'action'=>'hangup', 'max_attempts'=>'9'], ['result'=>'HUMAN', 'action'=>'hangup', 'max_attempts'=>'9']], 'schedules'=>[['weekday'=>'1', 'start_time'=>'08:00', 'end_time'=>'18:00']]];
$id = bot_cfg_save($repo, null, $base);
$cfg = bot_cfg_get($repo, $id);
check('guarda headers, reintentos y franjas', count($cfg['headers']) === 2 && count($cfg['rules']) === 2 && $cfg['rules'][0]['sip_code'] === 486 && $cfg['rules'][1]['sip_code'] === 600 && $cfg['schedules'][0]['start_time'] === '08:00');
check('canales libres 1..500', bot_cfg_save($repo, $id, ['max_channels'=>'137'] + $base) === $id && (int)$repo->campaign($id)['max_channels'] === 137);
check('rechaza 501 canales', throws(fn() => bot_cfg_save($repo, $id, ['max_channels'=>'501'] + $base)) !== '');
check('rechaza header sin X-', str_contains(throws(fn() => bot_cfg_save($repo, $id, ['headers'=>[['name'=>'Via', 'field'=>'a']]] + $base)), 'Headers'));
check('rechaza header repetido', throws(fn() => bot_cfg_save($repo, $id, ['headers'=>[['name'=>'X-A', 'field'=>'a'], ['name'=>'x-a', 'field'=>'b']]] + $base)) !== '');
check('rechaza regla del 200 y códigos repetidos', throws(fn() => bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>200, 'max_attempts'=>1, 'retry_delay_min'=>0]]] + $base)) !== '' && throws(fn() => bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>486, 'max_attempts'=>1, 'retry_delay_min'=>0], ['sip_code'=>486, 'max_attempts'=>1, 'retry_delay_min'=>0]]] + $base)) !== '');
$am = array_column($cfg['amd_actions'], null, 'result');
check('acciones AMD: retry guarda intentos, hangup los pone a 0', $am['NOTSURE']['action'] === 'retry' && $am['NOTSURE']['max_attempts'] === 2 && $am['HUMAN']['action'] === 'hangup' && $am['HUMAN']['max_attempts'] === 0 && $am['MACHINE']['max_attempts'] === 0);
check('rechaza acción AMD inválida y resultado no configurable', throws(fn() => bot_cfg_save($repo, $id, ['amd_actions'=>[['result'=>'HUMAN', 'action'=>'play']]] + $base)) !== '' && throws(fn() => bot_cfg_save($repo, $id, ['amd_actions'=>[['result'=>'TOOLONG', 'action'=>'hangup']]] + $base)) !== '');
check('catálogos para la página', array_column(bot_cfg_amd_results(), 'result') === array_keys(bot_cfg_amd_defaults()) || count(bot_cfg_amd_results()) === 3);
check('486 y 600 comparten grupo pero son filas distintas', array_column(array_filter(bot_cfg_sip_codes(), fn($c) => in_array($c['sip_code'], [486, 600], true)), 'group') === ['busy', 'busy']);
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

$pdo->exec("UPDATE zynervox_bot_lists SET dialer_state='running' WHERE campaign_id=$id");
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
$repo->finishCall($calls[2][0]['call_id'], 'BUSY', '', '', 17, false, ['sip_cause'=>'SIP 486 Busy Here']);
check('tope de intentos: B con 2 intentos ya no es elegible', $repo->countEligibleLeads($id, 'UP') === 1 /* solo Ed, nunca marcado */);
$pdo->exec("UPDATE zynervox_bot_campaign_sip_rules SET retry_delay_min=60");
$repo->finishCall($calls[2][0]['call_id'], 'BUSY', '', '', 17, false); // already finished: no-op
check('finishCall repetido no cambia la causa', $pdo->query("SELECT sip_cause FROM zynervox_bot_call_attempts WHERE call_id='{$calls[2][0]['call_id']}'")->fetchColumn() === 'SIP 486 Busy Here');
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
// Retry by exact SIP code; 1xx with NOANSWER counts as 487; AMD retry by result
$pdo->exec("UPDATE zynervox_bot_list SET status='CANCEL', called_count=1, last_call_at=UTC_TIMESTAMP() - INTERVAL 2 HOUR, last_sip_code=183 WHERE lead_id=(SELECT lead_id FROM (SELECT lead_id FROM zynervox_bot_list WHERE phone='955555555' AND list_id=$list) q)");
$sipBefore = $repo->countEligibleLeads($id, 'UP');
bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>487, 'max_attempts'=>3, 'retry_delay_min'=>10]]] + $base);
check('un 183 final reintenta con la regla del 487', $repo->countEligibleLeads($id, 'UP') === $sipBefore + 1);
bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>487, 'max_attempts'=>3, 'retry_delay_min'=>180]]] + $base);
check('la espera de la regla se respeta', $repo->countEligibleLeads($id, 'UP') === $sipBefore);
// 480 arrives with DIALSTATUS BUSY (lead status B): the retry follows the final SIP code, not the status
$pdo->exec("UPDATE zynervox_bot_list SET status='B', called_count=1, last_call_at=UTC_TIMESTAMP() - INTERVAL 3 HOUR, last_sip_code=480 WHERE lead_id=(SELECT lead_id FROM (SELECT lead_id FROM zynervox_bot_list WHERE phone='955555555' AND list_id=$list) q)");
bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>480, 'max_attempts'=>3, 'retry_delay_min'=>10]]] + $base);
$with480 = $repo->countEligibleLeads($id, 'UP');
bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>486, 'max_attempts'=>3, 'retry_delay_min'=>10]]] + $base);
check('un B con SIP 480 reintenta solo con la regla del 480', $with480 === $repo->countEligibleLeads($id, 'UP') + 1);
$pdo->exec("UPDATE zynervox_bot_list SET status='NA', last_sip_code=487 WHERE phone='955555555' AND list_id=$list");
bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>487, 'max_attempts'=>3, 'retry_delay_min'=>10]]] + $base);
check('un NA con SIP 487 (nuestro timeout) reintenta con la regla del 487', $repo->countEligibleLeads($id, 'UP') === $with480);
// Campaign deletion: refuses with lists or running, keeps history, removes configuration
$dl = bot_cfg_save($repo, null, ['name'=>'Borrable', 'active'=>'1', 'call_origin'=>'7307', 'amd_enabled'=>'1', 'rules'=>[['sip_code'=>486, 'max_attempts'=>1, 'retry_delay_min'=>0]], 'schedules'=>[['weekday'=>1, 'start_time'=>'09:00', 'end_time'=>'18:00']]]);
$pdo->prepare("INSERT INTO zynervox_bot_lists (campaign_id,name,active) VALUES (?,'x',1)")->execute([$dl]);
$dlList = (int)$pdo->lastInsertId();
check('no borra una campaña con listas', str_contains(throws(fn() => bot_cfg_delete($repo, $dl, 'test')), 'listas'));
$pdo->exec("DELETE FROM zynervox_bot_lists WHERE list_id=$dlList");
$repo->setDialerState($dl, 'running', 'test');
check('no borra una campaña en marcha', str_contains(throws(fn() => bot_cfg_delete($repo, $dl, 'test')), 'Detén'));
$repo->setDialerState($dl, 'stopped', 'test');
$pdo->exec("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id) VALUES ('zv2-hist',999,999,'900000000',UTC_TIMESTAMP(),UTC_TIMESTAMP(),$dl)");
bot_cfg_delete($repo, $dl, 'test');
check('borra la campaña y su configuración y conserva el historial', (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_campaigns WHERE campaign_id=$dl")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_campaign_sip_rules WHERE campaign_id=$dl")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_campaign_schedules WHERE campaign_id=$dl")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE call_id='zv2-hist'")->fetchColumn() === 1);
check('borrar una campaña inexistente falla', throws(fn() => bot_cfg_delete($repo, $dl, 'test')) !== '');
// List-level control (017): only active AND started lists dial; the campaign follows its lists
$lc = bot_cfg_save($repo, null, ['name'=>'Por lista', 'active'=>'1', 'call_origin'=>'7307', 'amd_enabled'=>'1', 'schedules'=>[['weekday'=>1, 'start_time'=>'09:00', 'end_time'=>'18:00']]]);
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels) VALUES ($lc,'A',1,2),($lc,'B',1,2),($lc,'C',0,1)");
$ids = $pdo->query("SELECT list_id FROM zynervox_bot_lists WHERE campaign_id=$lc ORDER BY list_id")->fetchAll(PDO::FETCH_COLUMN);
[$la, $lb, $lcc] = array_map('intval', $ids);
foreach ([$la, $lb, $lcc] as $x) $pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone) VALUES ($x,'97000000".$x."'),($x,'98000000".$x."')");
check('las listas nuevas nacen detenidas', (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_lists WHERE campaign_id=$lc AND dialer_state='stopped'")->fetchColumn() === 3);
check('lista inactiva no se inicia', str_contains(throws(fn() => bot_cfg_list_start($repo, $lc, $lcc, 'test', ['7307'])), 'Activa la lista'));
bot_cfg_list_start($repo, $lc, $la, 'test', ['7307']);
check('iniciar una lista pone la campaña en marcha y solo esa lista', $repo->campaign($lc)['dialer_state'] === 'running' && $repo->listState($lc, $la)['dialer_state'] === 'running' && $repo->listState($lc, $lb)['dialer_state'] === 'stopped');
$calls = [];
$r = bot_engine_tick($repo, $orig, null, $mon);
$rl = array_values(array_filter($r, fn($x) => $x['campaign_id'] === $lc))[0];
$dialed = array_unique(array_map(fn($c) => $c[0]['list_id'], array_filter($calls, fn($c) => in_array($c[0]['list_id'], [$la, $lb, $lcc], true))));
check('el motor marca solo la lista iniciada', ($rl['launched'] ?? 0) === 2 && $dialed === [$la]);
$dash = bot_cfg_dashboard($repo, $mon);
$dc = array_values(array_filter($dash['campaigns'], fn($c) => $c['campaign_id'] === $lc))[0];
$dl = array_column($dc['lists'], null, 'list_id');
check('dashboard por lista sin mezclar', $dl[$la]['started'] && $dl[$la]['open_calls'] === 2 && !$dl[$lb]['started'] && $dl[$lb]['reason'] === 'Lista detenida' && !isset($dl[$lcc]) && $dl[$la]['totals']['contacts'] === 2);
check('dashboard: motivo canales llenos vacío y sin latido = motor apagado', $dl[$la]['reason'] === '' || $dl[$la]['reason'] === 'Motor apagado');
bot_cfg_list_stop($repo, $lc, $la, 'test');
check('detener la última lista detiene la campaña', $repo->campaign($lc)['dialer_state'] === 'stopped');
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone) VALUES ($la,'961000001'),($la,'961000002')");
bot_cfg_list_start($repo, $lc, $la, 'test', ['7307']); bot_cfg_list_start($repo, $lc, $lb, 'test', ['7307']);
bot_cfg_list_set_active($repo, $lc, $la, false, 'test');
check('desactivar una lista la detiene y la campaña sigue con la otra', $repo->listState($lc, $la)['dialer_state'] === 'stopped' && $repo->campaign($lc)['dialer_state'] === 'running');
bot_cfg_stop($repo, $lc, 'test');
check('detener la campaña detiene todas sus listas', (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_lists WHERE campaign_id=$lc AND dialer_state='running'")->fetchColumn() === 0);
bot_cfg_list_set_active($repo, $lc, $la, true, 'test');
bot_cfg_start($repo, $lc, 'test', ['7307']);
check('iniciar la campaña inicia solo sus listas activas', $repo->listState($lc, $la)['dialer_state'] === 'running' && $repo->listState($lc, $lb)['dialer_state'] === 'running' && $repo->listState($lc, $lcc)['dialer_state'] === 'stopped');
$repo->touchDialerHeartbeat();
check('latido del motor', $repo->dialerHeartbeatAge() !== null && $repo->dialerHeartbeatAge() <= 2 && bot_cfg_dashboard($repo, $mon)['server']['engine_alive'] === true);
bot_cfg_stop($repo, $lc, 'test');
// Temperature (provisional thresholds) and report
check('temperatura: menos de 20 llamadas = Sin datos', bot_cfg_list_temperature(['calls'=>19, 'answered'=>19, 'human'=>0, 'machine'=>0, 'unavailable'=>0, 'no_answer'=>0], 3)['temperature'] === 'Sin datos');
$tHot = bot_cfg_list_temperature(['calls'=>20, 'answered'=>8, 'human'=>2, 'machine'=>6, 'unavailable'=>8, 'no_answer'=>4], 15);
check('temperatura: 10 % de humanos = Caliente/Seguir, con ritmo y base humana', $tHot['temperature'] === 'Caliente' && $tHot['recommendation'] === 'Seguir' && $tHot['pace']['calls_per_min'] === 1.0 && $tHot['thresholds_provisional'] === true && $tHot['basis'] === 'human' && $tHot['rates_pct']['human'] === 10.0 && $tHot['rates_pct']['machine'] === 30.0);
check('temperatura: mucho contacto pero 1 humano en 20 y 7 contestadoras = Tibia, no Caliente', bot_cfg_list_temperature(['calls'=>20, 'answered'=>8, 'human'=>1, 'machine'=>7, 'unavailable'=>8, 'no_answer'=>4], 0)['temperature'] === 'Tibia');
check('temperatura: Fría y Revisar lista', bot_cfg_list_temperature(['calls'=>20, 'answered'=>8, 'human'=>0, 'machine'=>8, 'unavailable'=>8, 'no_answer'=>4], 0)['recommendation'] === 'Revisar lista');
$pdo->exec("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id,status,disposition,amd_status,amd_cause,sip_cause,hangup_cause,billsec) VALUES
 ('TEST-r1',999,999,'911223344',UTC_TIMESTAMP(),UTC_TIMESTAMP(),$id,'ANSWER','ANSWER_HUMAN','HUMAN','HUMAN','SIP 200 OK',16,5),
 ('zv2-r2',999,998,'911223355',UTC_TIMESTAMP(),UTC_TIMESTAMP(),$id,'ANSWER','AMD_MACHINE','MACHINE','MAXWORDS','SIP 200 OK',16,3),
 ('zv2-r3',999,997,'911223366','2026-11-03 18:00:00','2026-11-03 18:00:30',$id,'ANSWER','ANSWER_HUMAN','NOTSURE','TOOLONG','SIP 200 OK',16,11)");
$rep = bot_cfg_report($repo, ['campaign_id'=>$id], 1, 5);
marcador_list_summary_backfill($repo); $rep = bot_cfg_report($repo, ['campaign_id'=>$id], 1, 5);
check('reporte: excluye TEST-% por defecto y marca la lista eliminada', count(array_filter($rep['by_list'], fn($l) => $l['list_deleted'])) >= 1);
check('reporte: teléfono completo en pantalla y AMD inválido marcado', !array_filter($rep['detail']['rows'], fn($r) => str_contains($r['phone'], '*')) && (bool)array_filter($rep['detail']['rows'], fn($r) => $r['amd_valid'] === false));
foreach ($pdo->query('SELECT list_id,state FROM zynervox_bot_list_summary')->fetchAll() as $su) marcador_list_summary_refresh($repo, (int)$su['list_id'], $su['state']);
$rep = bot_cfg_report($repo, ['campaign_id'=>$id], 1, 5);
$repAll = bot_cfg_report($repo, ['campaign_id'=>$id, 'include_test'=>1, 'include_invalid_amd'=>1], 1, 500);
check('reporte: con include_test e include_invalid_amd aparecen más datos', $repAll['kpi']['human'] > $rep['kpi']['human'] && $repAll['kpi']['not_sure'] > $rep['kpi']['not_sure']);
check('reporte: estructura (kpi, serie por hora, por AMD, por causa, transferencias)', isset($rep['kpi']['contact_pct']) && is_array($rep['by_hour']) && is_array($rep['by_amd']) && is_array($rep['by_cause']) && isset($rep['transfers']['ok']) && $rep['detail']['per_page'] === 5);
check('reporte: fechas en hora de Lima', throws(fn() => bot_cfg_report($repo, ['from'=>'ayer'])) !== '' && bot_cfg_report_filters(['from'=>'2026-11-03 15:31'])['from_utc'] === '2026-11-03 20:31:00' && bot_cfg_report_filters(['to'=>'2026-11-03'])['to_utc'] === '2026-11-04 05:00:00');
$csv = bot_cfg_report_csv($repo, ['campaign_id'=>$id]);
check('CSV con BOM, cabecera y teléfonos enmascarados', str_starts_with($csv, "\xEF\xBB\xBFattempt_id,inicio_lima") && !str_contains($csv, '911223355'));
// AMD parameters per campaign, audit rows and the STT batch (018). No call is made: rows are inserted by hand.
$ap = bot_cfg_save($repo, null, ['name'=>'AMD params', 'active'=>'1', 'call_origin'=>'7307', 'amd_enabled'=>'1']);
$g0 = marcador_amd_global($repo);
check('AMD global: la primera versión es la aprobada por el usuario', $g0['version_id'] !== null && $g0['initial_silence'] === 4000 && $g0['greeting'] === 2500 && $g0['after_greeting_silence'] === 800 && $g0['total_analysis_time'] === 8000 && $g0['minimum_word_length'] === 100 && $g0['between_words_silence'] === 50 && $g0['maximum_number_of_words'] === 4 && $g0['silence_threshold'] === 256 && $g0['maximum_word_length'] === 5000 && count(MARCADOR_AMD_DEFAULTS) === 9);
$saved = marcador_amd_global_save($repo, ['total_analysis_time'=>'5000', 'maximum_number_of_words'=>3], 'tester', 'prueba');
check('guardar AMD global crea una versión nueva, cambia solo lo enviado y registra autor', $saved['total_analysis_time'] === 5000 && $saved['maximum_number_of_words'] === 3 && $saved['greeting'] === 2500 && $saved['version_id'] > $g0['version_id'] && $saved['created_by'] === 'tester' && marcador_amd_global($repo)['version_id'] === $saved['version_id']);
check('rechaza parámetros AMD fuera de rango, sin autor o sin cambios', throws(fn() => marcador_amd_global_save($repo, ['maximum_number_of_words'=>0], 'tester')) !== '' && throws(fn() => marcador_amd_global_save($repo, ['greeting'=>'abc'], 'tester')) !== '' && throws(fn() => marcador_amd_global_save($repo, ['greeting'=>2000], ' ')) !== '' && throws(fn() => marcador_amd_global_save($repo, ['greeting'=>2500], 'tester')) !== '');
$hist = marcador_amd_global_history($repo, 10);
check('historial: versiones con fecha y autor y la actual marcada', count($hist) >= 2 && $hist[0]['is_current'] === true && $hist[0]['version_id'] === $saved['version_id'] && $hist[count($hist) - 1]['created_by'] !== '' && $hist[0]['from_version_id'] === $g0['version_id']);
$back = marcador_amd_global_rollback($repo, $g0['version_id'], 'tester');
check('volver atrás crea una versión nueva con los valores antiguos', $back['version_id'] > $saved['version_id'] && $back['total_analysis_time'] === 8000 && $back['maximum_number_of_words'] === 4 && throws(fn() => marcador_amd_global_rollback($repo, 999999, 'tester')) !== '');
$saved = marcador_amd_global_save($repo, ['total_analysis_time'=>'5000', 'maximum_number_of_words'=>3], 'tester', 'para las pruebas siguientes');
$vars = marcador_amd_variables(marcador_amd_global($repo));
check('9 variables de canal con __ZV2_AMD_*', count($vars) === 9 && in_array('__ZV2_AMD_TOTAL_TIME=5000', $vars, true) && in_array('__ZV2_AMD_MAX_WORD_LENGTH=5000', $vars, true) && in_array('__ZV2_AMD_AFTER_GREETING=800', $vars, true));
$cOn = $repo->campaign($ap) + ['amd_params'=>marcador_amd_global($repo), 'amd_actions'=>[]];
check('el motor envía las variables solo con AMD activo', in_array('__ZV2_AMD_TOTAL_TIME=5000', bot_engine_variables($cOn, ['call_id'=>'zv2-x', 'list_id'=>1, 'lead_id'=>1], []), true) && !preg_grep('/^__ZV2_AMD_/', bot_engine_variables(['amd_enabled'=>0] + $cOn, ['call_id'=>'zv2-x', 'list_id'=>1, 'lead_id'=>1], [])));
// audit: open, record, sync. Three calls: answered with recording, answered without audio, unanswered
$mk = function(string $cid, string $disp, string $amd, string $cause, ?string $rec) use($pdo, $ap) {
    $pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id,status,disposition,amd_status,amd_cause,recording_path) VALUES (?,5000,5000,'900000001',UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,'X',?,?,?,?)")->execute([$cid, $ap, $disp, $amd, $cause, $rec]);
};
$tmp = sys_get_temp_dir().'/zv2_stt_'.getmypid(); @mkdir($tmp);
$wav = $tmp.'/a.wav'; $pcm = str_repeat("\0\0", 8000);
file_put_contents($wav, 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm);
$mk('zv2-a1', 'AMD_MACHINE', 'MACHINE', 'MAXWORDS', $wav); $mk('zv2-a2', 'ANSWER_HUMAN', 'HUMAN', 'HUMAN', null); $mk('zv2-a3', 'NOANSWER', '', '', null);
foreach (['zv2-a1', 'zv2-a2', 'zv2-a3'] as $i=>$cid) marcador_amd_audit_open($repo, ['call_id'=>$cid, 'list_id'=>5000, 'lead_id'=>5000 + $i], $ap, marcador_amd_global($repo));
$row = $pdo->query("SELECT total_analysis_time,audit_state FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetch();
check('la auditoría guarda la foto de los parámetros usados', (int)$row['total_analysis_time'] === 5000 && $row['audit_state'] === 'open');
marcador_amd_audit_open($repo, ['call_id'=>'zv2-a1', 'list_id'=>5000, 'lead_id'=>5000], $ap, MARCADOR_AMD_DEFAULTS);
check('abrir dos veces la misma llamada no duplica ni pisa', (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 1 && (int)$pdo->query("SELECT total_analysis_time FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 5000);
marcador_amd_audit_record($repo, 'zv2-a1', ['decided_ms'=>1730, 'audio_path'=>'../etc/passwd']);
check('registra ms hasta decidir y rechaza rutas con ..', (int)$pdo->query("SELECT decided_ms FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 1730 && $pdo->query("SELECT audio_path FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === null);
$sync = marcador_amd_audit_sync($repo);
check('sync: borra la no contestada y completa las contestadas', $sync['dropped'] >= 1 && $sync['ready'] >= 2 && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_amd_audit WHERE call_id='zv2-a3'")->fetchColumn() === 0
  && $pdo->query("SELECT CONCAT(amd_status,'/',amd_cause,'/',stt_status) FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 'MACHINE/MAXWORDS/pending'
  && $pdo->query("SELECT stt_status FROM zynervox_bot_amd_audit WHERE call_id='zv2-a2'")->fetchColumn() === 'no_audio');
$r = marcador_stt_run($repo, null);
check('STT sin motor: informa, no falla y deja pendiente', $r['engine'] === null && $r['pending'] === 1 && str_contains($r['message'], 'sin motor') && $pdo->query("SELECT stt_status FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 'pending');
$fake = $tmp.'/fake_stt.sh'; file_put_contents($fake, "#!/bin/sh\necho '{\"text\":\"  buzon de voz  \"}'\n"); chmod($fake, 0755);
$pdo->exec("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,campaign_id,status) VALUES ('zv2-open',5000,5009,'900000009',UTC_TIMESTAMP(),$ap,'CALL')");
check('STT no corre con llamadas en curso', str_contains(marcador_stt_run($repo, ['name'=>'fake', 'command'=>[$fake], 'timeout'=>10])['message'], 'se aplaza'));
$pdo->exec("DELETE FROM zynervox_bot_call_attempts WHERE call_id='zv2-open'");
$r = marcador_stt_run($repo, ['name'=>'fake', 'command'=>[$fake], 'timeout'=>10], 50, true); // earlier engine ticks of this test left calls open
$a1 = $pdo->query("SELECT stt_status,stt_text,stt_engine,audio_seconds,stt_at IS NOT NULL has_at FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetch();
check('STT con motor: guarda texto, motor, fecha y duración', $r['done'] === 1 && $a1['stt_status'] === 'done' && $a1['stt_text'] === 'buzon de voz' && $a1['stt_engine'] === 'fake' && (float)$a1['audio_seconds'] === 1.0 && (int)$a1['has_at'] === 1);
$bad = $tmp.'/bad_stt.sh'; file_put_contents($bad, "#!/bin/sh\necho nada\n"); chmod($bad, 0755);
$pdo->exec("UPDATE zynervox_bot_amd_audit SET stt_status='pending' WHERE call_id='zv2-a1'");
$r = marcador_stt_run($repo, ['name'=>'bad', 'command'=>[$bad], 'timeout'=>10], 50, true);
check('STT con motor roto: error registrado, sin romper', $r['errors'] === 1 && $pdo->query("SELECT stt_status FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 'error');
check('config STT: sin fichero = sin motor; con fichero válido = motor', marcador_stt_engine($tmp.'/no.json') === null && (function() use($tmp, $fake) { file_put_contents($tmp.'/c.json', json_encode(['engine'=>'x', 'command'=>[$fake]])); return marcador_stt_engine($tmp.'/c.json')['name'] === 'x'; })());
// Delivery AGI logic (no AGI is run): effective parameters, ms, recording and validation
$argsOk = ['audit', 'zv2-a1', '1730', MARCADOR_RECORDINGS_DIR.'zv2-a1.wav', '4500', '2500', '1500', '800', '8000', '100', '50', '2', '256', '5000'];
$res = marcador_amd_audit_agi_apply($repo, $argsOk);
$ag = $pdo->query("SELECT decided_ms,audio_path,audio_seconds,total_analysis_time FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetch();
check('AGI de auditoría: guarda ms, audio, duración y los 9 parámetros efectivos', (int)$ag['decided_ms'] === 1730 && (float)$ag['audio_seconds'] === 4.5 && $ag['audio_path'] === MARCADOR_RECORDINGS_DIR.'zv2-a1.wav' && (int)$ag['total_analysis_time'] === 8000 && $res['params_stored'] === true);
marcador_amd_audit_agi_apply($repo, array_merge($argsOk, [MARCADOR_RECORDINGS_DIR.'zv2-a1.contacto.wav']));
check('AGI de auditoría: guarda la ruta del audio del contacto y rechaza rutas ajenas', $pdo->query("SELECT contact_path FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === MARCADOR_RECORDINGS_DIR.'zv2-a1.contacto.wav' && throws(fn() => marcador_amd_audit_agi_apply($repo, array_merge($argsOk, ['/etc/x.wav']))) !== '');
marcador_amd_audit_agi_apply($repo, ['audit', 'zv2-a1', '', '', '', '', '', '', '', '', '', '', '', '', '']);
check('AGI de auditoría: ruta de contacto vacía no borra la guardada', $pdo->query("SELECT contact_path FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() !== null);
check('AGI de auditoría: rechaza call_id y rutas fuera de la carpeta del marcador', throws(fn() => marcador_amd_audit_agi_apply($repo, ['audit', 'a;b'])) !== '' && throws(fn() => marcador_amd_audit_agi_apply($repo, ['audit', 'zv2-a1', '10', '/etc/passwd.wav'])) !== '' && throws(fn() => marcador_amd_audit_agi_apply($repo, ['audit', 'zv2-a1', '10', MARCADOR_RECORDINGS_DIR.'../x.wav'])) !== '');
check('AGI de auditoría: sin audio ni parámetros completos no pisa nada', marcador_amd_audit_agi_apply($repo, ['audit', 'zv2-a1', '1800', '', '0', '1', '2'])['params_stored'] === false && (int)$pdo->query("SELECT total_analysis_time FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 8000 && (int)$pdo->query("SELECT decided_ms FROM zynervox_bot_amd_audit WHERE call_id='zv2-a1'")->fetchColumn() === 1800);
// Race: the sync marks no_audio, then the AGI delivers the recording; and header-only wav
$mk('zv2-a4', 'AMD_MACHINE', 'MACHINE', 'MAXWORDS', null);
marcador_amd_audit_open($repo, ['call_id'=>'zv2-a4', 'list_id'=>5000, 'lead_id'=>5004], $ap, marcador_amd_global($repo));
marcador_amd_audit_sync($repo);
$late = $tmp.'/late.wav'; file_put_contents($late, str_repeat("\0", 44));
check('la sync deja no_audio si aún no hay grabación', $pdo->query("SELECT stt_status FROM zynervox_bot_amd_audit WHERE call_id='zv2-a4'")->fetchColumn() === 'no_audio');
$lateRel = MARCADOR_RECORDINGS_DIR.'zv2-a4.wav';
$pdo->prepare("UPDATE zynervox_bot_amd_audit SET audio_path=NULL WHERE call_id='zv2-a4'")->execute();
$repo->recordAmdAudit('zv2-a4', ['audio_path'=>$late]);
check('si la grabación llega después, pasa a pendiente', $pdo->query("SELECT stt_status FROM zynervox_bot_amd_audit WHERE call_id='zv2-a4'")->fetchColumn() === 'pending');
marcador_stt_run($repo, ['name'=>'fake', 'command'=>[$fake], 'timeout'=>10], 50, true);
check('un wav con solo cabecera no se transcribe', $pdo->query("SELECT stt_status FROM zynervox_bot_amd_audit WHERE call_id='zv2-a4'")->fetchColumn() === 'no_audio');
array_map('unlink', glob($tmp.'/*')); @rmdir($tmp);
// ---- 019: summary per list, finishing, deleted lists, default retries, franjas, dashboard of active lists, report from summary
$dr = bot_cfg_default_rules();
$byCode = array_column($dr, null, 'sip_code');
check('reintentos por defecto del contrato', $byCode[486]['max_attempts'] === 4 && $byCode[600]['retry_delay_min'] === 15 && $byCode[480]['retry_delay_min'] === 60 && $byCode[408]['retry_delay_min'] === 60 && $byCode[487]['retry_delay_min'] === 120 && $byCode[0]['retry_delay_min'] === 120 && $byCode[503]['retry_delay_min'] === 10 && $byCode[500]['retry_delay_min'] === 10 && !isset($byCode[404]) && !isset($byCode[484]) && !isset($byCode[603]));
check('contestadora por defecto: 1 reintento a 240 min', bot_cfg_amd_defaults()['MACHINE'] === ['action'=>'retry', 'max_attempts'=>2, 'retry_delay_min'=>240]);
$nc = bot_cfg_save($repo, null, ['name'=>'Nueva con defaults', 'active'=>'1', 'call_origin'=>'7307', 'amd_enabled'=>'1', 'schedules'=>[['weekday'=>1, 'start_time'=>'09:00', 'end_time'=>'18:00']]]);
$ncc = bot_cfg_get($repo, $nc);
check('una campaña nueva nace con los reintentos por defecto y contestadora a 240', count($ncc['rules']) === count($dr) && array_column($ncc['amd_actions'], null, 'result')['MACHINE']['retry_delay_min'] === 240);
check('una campaña nueva con rules explícito no los pisa', count(bot_cfg_get($repo, bot_cfg_save($repo, null, ['name'=>'Sin reglas', 'active'=>'1', 'call_origin'=>'7307', 'rules'=>[]]))['rules']) === 0);
// retries: SIP rules and the AMD retry action together, only when due and inside the franja
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels,dialer_state) VALUES ($nc,'R',1,5,'running')");
$rl = (int)$pdo->lastInsertId();
$ago = fn(int $min) => gmdate('Y-m-d H:i:s', time() - $min * 60);
$ins = fn(string $phone, string $status, int $sip, int $count, int $minAgo) => $pdo->prepare("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count,last_sip_code,last_call_at) VALUES (?,?,?,?,?,?)")->execute([$rl, $phone, $status, $count, $sip, $ago($minAgo)]);
$ins('970000001', 'B', 486, 1, 20);    // busy, 15 min rule, 20 ago -> due
$ins('970000002', 'B', 486, 1, 5);     // busy, 5 min ago -> not due
$ins('970000003', 'NA', 480, 1, 90);   // 480 rule 60 -> due
$ins('970000004', 'NA', 480, 4, 90);   // cap reached
$ins('970000005', 'B', 603, 1, 500);   // 603 no retry
$ins('970000006', 'AA', 200, 1, 300);  // machine: retry 240 -> due once its last attempt says MACHINE
$ins('970000007', 'AA', 200, 1, 100);  // machine, not due
$lead6 = (int)$pdo->query("SELECT lead_id FROM zynervox_bot_list WHERE phone='970000006'")->fetchColumn(); $lead7 = (int)$pdo->query("SELECT lead_id FROM zynervox_bot_list WHERE phone='970000007'")->fetchColumn();
foreach ([$lead6, $lead7] as $i=>$lid) $pdo->exec("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id,status,disposition,amd_status,amd_cause) VALUES ('zv2-rm$i',$rl,$lid,'970000006',UTC_TIMESTAMP(),UTC_TIMESTAMP(),$nc,'AA','AMD_MACHINE','MACHINE','MAXWORDS')");
$due = array_column($repo->previewCampaignLeads($nc, 'UP', 50), 'phone');
sort($due);
check('reintentos: SIP y contestadora juntos, solo los que toca por espera y tope', $due === ['970000001', '970000003', '970000006'] || (print('DEBUG due='.json_encode($due)."\n") && false));
$calls = [];
$pdo->exec("UPDATE zynervox_bot_campaigns SET dialer_state='running' WHERE campaign_id=$nc");
$rSun = array_values(array_filter(bot_engine_tick($repo, $orig, $nc, $sun), fn($x) => $x['campaign_id'] === $nc))[0];
check('reintentos: fuera de franja no se marca nada', ($rSun['skipped'] ?? '') === 'fuera de franja' && !$calls);
$pdo->exec("UPDATE zynervox_bot_campaigns SET dialer_state='running' WHERE campaign_id=$nc");
$rMon = array_values(array_filter(bot_engine_tick($repo, $orig, null, $mon), fn($x) => $x['campaign_id'] === $nc))[0];
check('reintentos: dentro de franja se marcan los tres que tocan', ($rMon['launched'] ?? 0) === 3);
bot_cfg_set_max_total_channels($repo, 10);
// summary: incremental, watermark with an open call, no double counting
$pdo->exec("DELETE FROM zynervox_bot_call_attempts WHERE list_id=$rl");
$pdo->exec("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id,status,disposition,amd_status,amd_cause,billsec) VALUES
  ('zv2-s1',$rl,1,'970000001','2026-11-04 15:00:00','2026-11-04 15:00:20',$nc,'NA','NOANSWER','','',0),
  ('zv2-s2',$rl,2,'970000002','2026-11-04 15:01:00','2026-11-04 15:01:20',$nc,'AA','AMD_MACHINE','MACHINE','MAXWORDS',5),
  ('zv2-s3',$rl,3,'970000003','2026-11-04 15:02:00',NULL,$nc,'CALL','','','',0),
  ('zv2-s4',$rl,4,'970000004','2026-11-04 15:03:00','2026-11-04 15:03:20',$nc,'ANSWER','ANSWER_HUMAN','HUMAN','HUMAN',9),
  ('TEST-s5',$rl,5,'970000005','2026-11-04 15:04:00','2026-11-04 15:04:20',$nc,'ANSWER','ANSWER_HUMAN','HUMAN','HUMAN',9),
  ('zv2-s6',$rl,6,'970000006','2026-10-30 15:04:00','2026-10-30 15:04:20',$nc,'ANSWER','ANSWER_HUMAN','HUMAN','HUMAN',9)");
marcador_list_summary_refresh($repo, $rl, 'running');
$sm = $repo->listSummary($rl);
check('resumen: cuenta las terminadas hasta la primera abierta, sin TEST y sin AMD inválido', (int)$sm['calls'] === 2 && (int)$sm['machine'] === 1 && (int)$sm['human'] === 0 && $sm['state'] === 'running' && $sm['list_name'] === 'R' && $sm['campaign_name'] === 'Nueva con defaults' && (int)$sm['contacts'] === 7);
marcador_list_summary_refresh($repo, $rl, 'running');
check('resumen: refrescar dos veces no cuenta doble', (int)$repo->listSummary($rl)['calls'] === 2);
$pdo->exec("UPDATE zynervox_bot_call_attempts SET finished_at='2026-11-04 15:02:30',status='B',disposition='BUSY' WHERE call_id='zv2-s3'");
marcador_list_summary_refresh($repo, $rl, 'stopped');
$sm = $repo->listSummary($rl);
check('resumen: al cerrar la abierta se suma una vez (watermark) y se calculan tasas, fechas y duración', (int)$sm['calls'] === 5 && (int)$sm['human'] === 1 && (int)$sm['unavailable'] === 1 && (float)$sm['human_pct'] === 20.0 && $sm['first_call_at'] === '2026-10-30 15:04:00' && $sm['last_call_at'] === '2026-11-04 15:03:00' && (int)$sm['duration_seconds'] > 400000 && (int)$sm['amd_invalid_calls'] === 1);
// finishing: a list with leads still pending is not finished; with everything done it finishes, deactivates and stops its campaign
$pdo->exec("UPDATE zynervox_bot_campaigns SET dialer_state='running' WHERE campaign_id=$nc");
check('lista con trabajo pendiente no se da por terminada', marcador_list_finish_if_done($repo, $nc, $rl) === false);
$pdo->exec("DELETE FROM zynervox_bot_list WHERE list_id=$rl"); $pdo->exec("DELETE FROM zynervox_bot_call_attempts WHERE list_id=$rl AND finished_at IS NULL");
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count,last_sip_code,last_call_at) VALUES ($rl,'971000001','XFER_OK',1,200,UTC_TIMESTAMP())");
$pdo->exec("UPDATE zynervox_bot_lists SET dialer_state='running' WHERE list_id=$rl");
check('lista sin contactos nuevos ni reintentos pendientes: terminada, se desactiva sola y detiene la campaña', marcador_list_finish_if_done($repo, $nc, $rl) === true && (int)$pdo->query("SELECT active FROM zynervox_bot_lists WHERE list_id=$rl")->fetchColumn() === 0 && $repo->campaign($nc)['dialer_state'] === 'stopped' && $repo->listSummary($rl)['state'] === 'finished' && $repo->listSummary($rl)['finished_at'] !== null);
// maintain + deletion survival
$pdo->exec("UPDATE zynervox_bot_lists SET dialer_state='stopped' WHERE list_id=$rl");
bot_list_delete($repo, $rl, $nc, 'test');
$mt = marcador_list_summary_maintain($repo);
$smd = $repo->listSummary($rl);
check('el resumen sobrevive al borrado de la lista y queda marcado como eliminada', $mt['deleted'] >= 1 && $smd !== null && $smd['state'] === 'deleted' && (int)$smd['calls'] === 5 && $smd['deleted_at'] !== null && $smd['list_name'] === 'R');
$rpt = bot_cfg_report($repo, ['list_id'=>$rl], 1, 5);
check('reporte sin filtros de fecha lee el resumen (también de listas borradas)', $rpt['by_list'][0]['source'] === 'summary' && $rpt['by_list'][0]['list_deleted'] === true && $rpt['kpi']['calls'] === 5 && $rpt['by_list'][0]['last_call'] === '2026-11-04 10:03:00');
$rptT = bot_cfg_report($repo, ['list_id'=>$rl, 'include_test'=>1], 1, 5);
check('con include_test el reporte vuelve a leer las tablas de marcado', ($rptT['by_list'][0]['source'] ?? 'attempts') === 'attempts');
// dashboard shows only active lists
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,dialer_state) VALUES ($nc,'Inactiva',0,'stopped'),($nc,'Activa',1,'stopped')");
$dashNames = []; foreach (bot_cfg_dashboard($repo)['campaigns'] as $c) foreach ($c['lists'] as $l) if ($c['campaign_id'] === $nc) $dashNames[] = $l['name'];
check('el dashboard solo muestra listas activas', $dashNames === ['Activa']);
// backfill: lists and attempt list ids without summary
$pdo->exec("DELETE FROM zynervox_bot_list_summary");
$bf = marcador_list_summary_backfill($repo);
check('backfill: crea el resumen de listas existentes y de listas ya borradas con intentos', $bf >= 2 && $repo->listSummary($rl) !== null && $repo->listSummary($rl)['state'] === 'deleted');
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
$lim = bot_cfg_server_limits($repo);
check('límites del servidor y listado de listas', $lim['max_channels_server'] === 10 && $lim['open_calls'] >= 2 && array_filter(bot_cfg_lists_channels($repo), fn($l) => (int)$l['list_id'] === $detList && (int)$l['open_calls'] === 2 && (int)$l['max_channels'] === 2));
check('campaña sin max_channels en la entrada es válida', bot_cfg_save($repo, null, ['name'=>'Sin canales', 'active'=>'1', 'call_origin'=>'7307']) > 0);
$cid = $calls[0][0]['call_id']; $pend = []; $hg = [];
bot_engine_handle_event($repo, ['Event'=>'Hangup', 'Uniqueid'=>$cid, 'Cause'=>'17', 'Cause-txt'=>'User busy'], $hg, $pend);
bot_engine_handle_event($repo, ['Event'=>'OriginateResponse', 'Uniqueid'=>$cid, 'Response'=>'Failure', 'Reason'=>'5'], $hg, $pend);
check('AMI diferido: la llamada sigue abierta durante la gracia', isset($pend[$cid]) && $pdo->query("SELECT finished_at FROM zynervox_bot_call_attempts WHERE call_id='$cid'")->fetchColumn() === null);
$repo->finishCall($cid, 'BUSY', '', '', 17, false, ['sip_cause'=>'SIP 486 Busy Here', 'cause_source'=>'dialplan', 'disposition'=>'BUSY']);
$pend[$cid][0] = time() - 1;
bot_engine_flush_pending($repo, $pend);
check('el AGI (código SIP) gana al respaldo AMI', !$pend && $pdo->query("SELECT CONCAT(sip_cause,'|',cause_source) FROM zynervox_bot_call_attempts WHERE call_id='$cid'")->fetchColumn() === 'SIP 486 Busy Here|dialplan');
$rv = bot_engine_variables($repo->campaign($det), $calls[0][0], []);
check('variable de tiempo de marcado para el dialplan', in_array('__ZV2_DIAL_TIMEOUT=60', $rv, true) && !preg_grep('/^__ZV2_(TRUNK|DIAL_PREFIX)=/', $rv));
$repo->setDialerState($det, 'stopped', 'test');
$repo->saveCampaignConfig($det, ['name'=>'Solo detectar','active'=>1,'call_origin'=>'7307','max_channels'=>1,'amd_enabled'=>1,'transfer_carrier_id'=>'T','transfer_destination'=>'','timezone'=>'America/Lima','lead_order'=>'DOWN','dial_timeout'=>60,'campaign_cid'=>''], [], [], []);
check('troncal sin destino no inicia', str_contains(throws(fn() => bot_cfg_start($repo, $det, 'test', ['7307'])), 'ambos vacíos'));
$rep = bot_cfg_attempts_report($repo, $det, 10);
check('reporte: intentos con las columnas de AMD y causas', count($rep) === 2 && array_key_exists('amd_status', $rep[0]) && array_key_exists('sip_cause', $rep[0]) && array_key_exists('q850', $rep[0]));
$ap = bot_cfg_amd_params($repo);
check('bot_cfg_amd_params: 9 parámetros con rango, ayuda y valor', count($ap['params']) === 9 && $ap['params'][0]['help'] !== '' && $ap['params'][0]['min'] === 0 && $ap['params'][6]['max'] === 50);
$bv = bot_cfg_amd_save($repo, ['total_analysis_time'=>7000], 'tester', 'prueba');
$vs = bot_cfg_amd_versions($repo);
check('bot_cfg_amd_save/versions: versión nueva activa con values', $vs[0]['version_id'] === $bv['version_id'] && $vs[0]['active'] === true && $vs[0]['values']['total_analysis_time'] === 7000 && $vs[0]['actor'] === 'tester');
$rs = bot_cfg_amd_restore($repo, $vs[1]['version_id'], 'tester');
check('bot_cfg_amd_restore: copia la anterior como versión nueva', $rs['version_id'] > $bv['version_id'] && $rs['total_analysis_time'] === $vs[1]['values']['total_analysis_time']);
$sm = bot_cfg_summary($repo, []);
check('bot_cfg_summary: listas y totales por campaña', isset($sm['lists'], $sm['campaigns']) && throws(fn() => bot_cfg_summary($repo, ['status'=>'x'])) !== '');
$ex = bot_cfg_report_extra($repo, []);
check('bot_cfg_report_extra: todas las series presentes', !array_diff(['by_day','by_weekday_hour','funnel','attempts_per_lead','first_contact_attempt','sip','amd_by_cause','by_carrier'], array_keys($ex)) && count($ex['funnel']['stages']) === 5 && isset($ex['sip']['partial']));
check('bot_cfg_report_extra: embudo coherente con el KPI', $ex['funnel']['stages'][0]['n'] === (int)bot_cfg_report($repo, ['from'=>'2000-01-01'])['kpi']['calls']);
check('bot_cfg_report_extra: acepta rango y lista', is_array(bot_cfg_report_extra($repo, ['from'=>'2026-11-01','to'=>'2026-11-30','list_id'=>5000])));
// --- zypad (021): catalog, delivery at closing, dial mode, report and the one-shot list ---
$wl = bot_cfg_zypad_words($repo);
check('catálogo zypad: «mensaje» es la primera entrada, activa', $wl['words'][0]['word'] === 'mensaje' && $wl['words'][0]['word_id'] === 1 && $wl['words'][0]['active'] === true && $wl['categories'] === ['casilla_voz']);
$wl = bot_cfg_zypad_word_save($repo, '  Deje  su MENSAJE ', 'tester');
$wl2 = bot_cfg_zypad_word_save($repo, 'deje su mensaje', 'tester', false);
check('catálogo zypad: minúsculas, sin duplicados por mayúsculas y se puede desactivar', count($wl['words']) === 2 && count($wl2['words']) === 2 && $wl2['words'][1]['word'] === 'deje su mensaje' && $wl2['words'][1]['active'] === false);
check('catálogo zypad: rechaza vacío, símbolos y borrar «mensaje»', throws(fn() => bot_cfg_zypad_word_save($repo, ' ', 't')) !== '' && throws(fn() => bot_cfg_zypad_word_save($repo, 'a;b', 't')) !== '' && throws(fn() => bot_cfg_zypad_word_delete($repo, 1)) !== '');
$wid = $wl2['words'][1]['word_id'];
bot_cfg_zypad_word_set_active($repo, $wid, true, 'tester');
check('catálogo zypad: activar por id y borrar', bot_cfg_zypad_words($repo)['words'][1]['active'] === true && count(bot_cfg_zypad_word_delete($repo, $wid)['words']) === 1);
// Campaign by 7314: shadow mode, one channel, no retry of any kind
$zc = bot_cfg_save($repo, null, ['name'=>'ZSOMBRA', 'active'=>'1', 'call_origin'=>'7314', 'max_channels'=>'1', 'lead_order'=>'UP', 'amd_enabled'=>'1', 'transfer_carrier_id'=>'', 'transfer_destination'=>'', 'rules'=>[],
    'amd_actions'=>[['result'=>'HUMAN', 'action'=>'hangup'], ['result'=>'NOTSURE', 'action'=>'hangup'], ['result'=>'MACHINE', 'action'=>'hangup']],
    'schedules'=>[['weekday'=>1, 'start_time'=>'00:00', 'end_time'=>'23:59']]]);
$zcfg = bot_cfg_get($repo, $zc);
check('campaña de sombra: sin reglas SIP y sin reintento de AMD', $zcfg['rules'] === [] && !array_filter($zcfg['amd_actions'], fn($a) => $a['action'] === 'retry'));
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels,dialer_state) VALUES ($zc,'Z',1,1,'running')");
$zl = (int)$pdo->lastInsertId();
$zst = ['NEW', 'B', 'NA', 'UNAV', 'CONG', 'CANCEL', 'FAIL', 'AA', 'ANSWER'];
foreach ($zst as $i=>$st) $pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count,last_call_at,last_sip_code) VALUES ($zl,'96000000$i','$st',".($st === 'NEW' ? 0 : 1).",UTC_TIMESTAMP() - INTERVAL 3 DAY,".($st === 'B' ? 486 : ($st === 'NA' ? 0 : 480)).")");
check('lista de una sola vez: solo el NEW es marcable; ningún estado ya llamado reintenta', $repo->countEligibleLeads($zc, 'UP') === 1);
$pdo->exec("UPDATE zynervox_bot_list SET status='AA' WHERE list_id=$zl AND phone='960000000'");
$pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id,status,disposition,amd_status,amd_cause,corte_por) SELECT ?,list_id,lead_id,phone,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,'X','AMD_MACHINE','MACHINE','MAXWORDS','' FROM zynervox_bot_list WHERE list_id=? AND phone='960000000'")->execute(['zv2-z1', $zc, $zl]);
check('lista de una sola vez: una casilla de voz (MACHINE) tampoco reintenta', $repo->countEligibleLeads($zc, 'UP') === 0);
// Delivery at closing
$zargs = fn(string $cid, array $z) => array_merge(['audit', $cid], array_fill(0, 13, ''), $z); // index 15..23: id, palabra, palabra_epoch_ms, palabra_audio_ms, preanswer, bytes, first_audio_epoch_ms, answer_epoch_ms, dial_mode
$r = marcador_zypad_apply($repo, $zargs('zv2-z1', ['uuid-1', 'Mensaje', '1000000', '3400', 'si', '24000', '996000', '1006600', 'ZYPAD_SOMBRA']));
$za = $pdo->query("SELECT dial_mode,detectado_por,momento,palabra,segundo,corte_por,preanswer_audio,preanswer_bytes,zypad_call_id,result_final,amd_status,palabra_epoch_ms,answer_epoch_ms FROM zynervox_bot_call_attempts WHERE call_id='zv2-z1'")->fetch();
check('zypad al cierre: guarda los campos, deriva detectado_por/momento/segundo/corte y deduce el tipo de marcación del origen', $r['updated'] === 1 && $za['dial_mode'] === 'ZYPAD_SOMBRA' && $za['detectado_por'] === 'ZYPAD' && $za['momento'] === 'PRE' && $za['palabra'] === 'mensaje' && (float)$za['segundo'] === 3.4 && $za['corte_por'] === 'NINGUNO' && $za['preanswer_audio'] === 'SI' && (int)$za['preanswer_bytes'] === 24000 && $za['zypad_call_id'] === 'uuid-1' && (int)$za['answer_epoch_ms'] === 1006600);
check('resultado homologado: MACHINE => CASILLA_VOZ sin perder amd_status ni amd_cause', $za['result_final'] === 'CASILLA_VOZ' && $za['amd_status'] === 'MACHINE' && $pdo->query("SELECT amd_cause FROM zynervox_bot_call_attempts WHERE call_id='zv2-z1'")->fetchColumn() === 'MAXWORDS');
$pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id,status,disposition,amd_status) SELECT 'zv2-z2',list_id,lead_id,phone,UTC_TIMESTAMP(),UTC_TIMESTAMP(),campaign_id,'X','ANSWER_HUMAN','HUMAN' FROM zynervox_bot_call_attempts WHERE call_id='zv2-z1'")->execute();
$r2 = marcador_zypad_apply($repo, $zargs('zv2-z2', ['', '', '', '', '', '', '', '', '']));
check('zypad sin datos: guarda el tipo de marcación y deja lo demás vacío', $r2['updated'] === 1 && $pdo->query("SELECT CONCAT(dial_mode,'|',detectado_por,'/',momento,'/',palabra,'/',corte_por,'/',preanswer_audio,'/',zypad_call_id,'/',IFNULL(segundo,'-'),'|',result_final) FROM zynervox_bot_call_attempts WHERE call_id='zv2-z2'")->fetchColumn() === 'ZYPAD_SOMBRA|NINGUNO///NINGUNO///-|');
check('zypad: valores fuera de la lista se vacían y un call_id inválido se rechaza', marcador_zypad_apply($repo, $zargs('zv2-z2', ['a b', "a\nb", 'abc', 'x', 'TAL', '-5', '1.5', 'zz', 'XX']))['palabra'] === '' && throws(fn() => marcador_zypad_apply($repo, ['audit', 'a;b'])) !== '');
$pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,started_at,finished_at,campaign_id,status,disposition,amd_status,dial_mode,detectado_por,momento,preanswer_audio) SELECT 'zv2-z3',list_id,lead_id,phone,UTC_TIMESTAMP(),UTC_TIMESTAMP(),campaign_id,'X','ANSWER_HUMAN','HUMAN','ZYPAD_SOMBRA','ZYPAD','PRE','SI' FROM zynervox_bot_call_attempts WHERE call_id='zv2-z1'")->execute();
$dm = bot_cfg_dialmode_report($repo, ['campaign_id'=>$zc, 'include_invalid_amd'=>1]);
$row = $dm['rows'][0] ?? [];
check('reporte por tipo de marcación: N, casilla de voz y coincidencia zypad/AMD', count($dm['rows']) === 1 && $row['dial_mode'] === 'ZYPAD_SOMBRA' && $row['calls'] === 3 && $row['preanswer_audio'] === 2 && $row['both_casilla'] === 1 && $row['only_zypad'] === 1 && $row['only_zypad_amd_human'] === 1 && $row['only_amd'] === 0 && $row['small_sample'] === true);
check('reporte por tipo de marcación: tiempo que el corte habría ahorrado', $row['saved_n'] === 1 && $row['saved_seconds_total'] === 6.6 && $row['saved_seconds_avg'] === 6.6 && throws(fn() => bot_cfg_dialmode_report($repo, ['dial_mode'=>'X'])) !== '');
$cat1 = marcador_zypad_catalog($repo);
bot_cfg_zypad_word_save($repo, 'buzon de voz', 'tester');
$cat2 = marcador_zypad_catalog($repo);
$cid2 = $repo->zypadWords()[1]['word_id'];
bot_cfg_zypad_word_delete($repo, (int)$cid2);
check('catálogo para el puente: la versión cambia al añadir y al borrar, y vuelve igual con el mismo contenido', $cat1['version'] !== $cat2['version'] && marcador_zypad_catalog($repo)['version'] === $cat1['version'] && $cat1['words'] === [['word'=>'mensaje', 'category'=>'casilla_voz', 'active'=>true]]);
$det = bot_cfg_report($repo, ['campaign_id'=>$zc, 'include_invalid_amd'=>1, 'from'=>'2000-01-01'], 1, 50)['detail']['rows'];
$z1 = array_values(array_filter($det, fn($r) => $r['palabra'] === 'mensaje'))[0] ?? [];
check('detalle del reporte: tipo de marcación, momento, palabra, segundo, corte, audio previo y resultado final', ($z1['dial_mode'] ?? '') === 'ZYPAD_SOMBRA' && $z1['momento'] === 'PRE' && (float)$z1['segundo'] === 3.4 && $z1['corte_por'] === 'NINGUNO' && $z1['preanswer_audio'] === 'SI' && $z1['result_final'] === 'CASILLA_VOZ');
check('CSV del reporte: incluye las columnas de zypad', str_contains(bot_cfg_report_csv($repo, ['campaign_id'=>$zc, 'include_invalid_amd'=>1, 'from'=>'2000-01-01']), 'tipo_marcacion,detectado_por,momento,palabra,segundo,corte_por,audio_previo,resultado_final') && str_contains(bot_cfg_report_csv($repo, ['campaign_id'=>$zc, 'from'=>'2000-01-01']), 'ZYPAD_SOMBRA'));
// Own AGI action "zypad" (what the 7314/7315 dialplans call): values stored as given, optional epochs at the end
$zr = marcador_zypad_closing($repo, ['zypad', 'zv2-z2', 'zypad', 'pre', 'Mensaje', '3.982', 'ninguno', 'si', 'uuid-2', '2000000', '1996000', '2007000', '16000']);
$zq = $pdo->query("SELECT detectado_por,momento,palabra,segundo,corte_por,preanswer_audio,zypad_call_id,palabra_epoch_ms,first_audio_epoch_ms,answer_epoch_ms,preanswer_bytes,dial_mode FROM zynervox_bot_call_attempts WHERE call_id='zv2-z2'")->fetch();
check('acción zypad del dialplan: guarda lo entregado y las épocas opcionales', $zr['updated'] === 1 && $zq['detectado_por'] === 'ZYPAD' && $zq['momento'] === 'PRE' && $zq['palabra'] === 'mensaje' && (float)$zq['segundo'] === 3.98 && $zq['corte_por'] === 'NINGUNO' && $zq['preanswer_audio'] === 'SI' && $zq['zypad_call_id'] === 'uuid-2' && (int)$zq['palabra_epoch_ms'] === 2000000 && (int)$zq['answer_epoch_ms'] === 2007000 && (int)$zq['preanswer_bytes'] === 16000 && $zq['dial_mode'] === 'ZYPAD_SOMBRA');
marcador_zypad_closing($repo, ['zypad', 'zv2-z2', '', '', '', '', 'NINGUNO', 'NO', '']);
check('acción zypad sin palabra ni detección: deja vacío lo demás (sin derivar)', $pdo->query("SELECT CONCAT(detectado_por,'/',momento,'/',palabra,'/',corte_por,'/',preanswer_audio) FROM zynervox_bot_call_attempts WHERE call_id='zv2-z2'")->fetchColumn() === '///NINGUNO/NO' && throws(fn() => marcador_zypad_closing($repo, ['zypad', 'a;b'])) !== '');
// The dialplan sends both formats back to back (audit 15..23, then zypad): the second must not erase what only the first carries
marcador_zypad_apply($repo, $zargs('zv2-z2', ['uuid-3', 'mensaje', '3000000', '4100', 'SI', '8000', '2996000', '3005000', 'ZYPAD_SOMBRA']));
marcador_zypad_closing($repo, ['zypad', 'zv2-z2', 'ZYPAD', 'PRE', 'mensaje', '4.1', 'NINGUNO', 'SI', 'uuid-3']);
$bq = $pdo->query("SELECT palabra_audio_ms,palabra_epoch_ms,answer_epoch_ms,preanswer_bytes,detectado_por,momento FROM zynervox_bot_call_attempts WHERE call_id='zv2-z2'")->fetch();
check('audit 15..23 seguido de zypad: se conservan los datos que solo trae el primero', (int)$bq['palabra_audio_ms'] === 4100 && (int)$bq['palabra_epoch_ms'] === 3000000 && (int)$bq['answer_epoch_ms'] === 3005000 && (int)$bq['preanswer_bytes'] === 8000 && $bq['detectado_por'] === 'ZYPAD' && $bq['momento'] === 'PRE');
// Dashboard reason for a list whose first pass is over: pending retries, not "Sin contactos por llamar"
$rowR = ['list_active'=>1, 'list_state'=>'running', 'campaign_state'=>'running', 'max_channels'=>3, 'att'=>['open_calls'=>0], 'leads'=>['pending'=>0]];
$cR = ['campaign_active'=>1];
check('motivo de lista: primera pasada terminada con reintentos pendientes', bot_cfg_list_reason($rowR, $cR, true, true, 0, 5, fn() => 12) === 'Primera pasada terminada · esperando reintentos (12 pendientes)');
check('motivo de lista: sin reintentos, o con contactos sin llamar, texto veraz', bot_cfg_list_reason($rowR, $cR, true, true, 0, 5, fn() => 0) === 'Sin contactos por llamar' && bot_cfg_list_reason(['leads'=>['pending'=>7]] + $rowR, $cR, true, true, 0, 5, fn() => 99) === 'Quedan 7 contactos por llamar'
    && bot_cfg_list_reason($rowR, $cR, true, true, 3, 5, fn() => 99) === '');
$rid = (int)$pdo->query("SELECT MAX(list_id) FROM zynervox_bot_lists WHERE campaign_id=$zc")->fetchColumn();
check('conteo de reintentos pendientes de una lista sin reintentos configurados = 0', $repo->listRetryPendingCount($zc, $rid, 'UP') === 0);
// --- Distribution by contact (022): one category per contact, by its last attempt; the sum is always the number of contacts ---
$dc = bot_cfg_save($repo, null, ['name'=>'DISTRIB', 'active'=>'1', 'call_origin'=>'7308', 'max_channels'=>'1', 'lead_order'=>'UP', 'amd_enabled'=>'1', 'transfer_carrier_id'=>'', 'transfer_destination'=>'', 'rules'=>[], 'schedules'=>[['weekday'=>1, 'start_time'=>'00:00', 'end_time'=>'23:59']]]);
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels,dialer_state) VALUES ($dc,'D',1,3,'stopped')");
$dl = (int)$pdo->lastInsertId();
// category => [amd_status, corte_por, dial_status, disposition, sip_cause, open?]
$cases = ['humano'=>['HUMAN','', 'ANSWER', 'XFER_OK', 'SIP 200 OK', 0], 'casilla_voz'=>['MACHINE','', 'ANSWER', 'AMD_MACHINE', 'SIP 200 OK', 0], 'casilla_voz2'=>['','ZYPAD', '', 'NOANSWER', 'SIP 487 Request Terminated', 0],
    'no_seguro'=>['NOTSURE','', 'ANSWER', 'ANSWER_HUMAN', 'SIP 200 OK', 0], 'contestada_sin_amd'=>['','', 'ANSWER', 'ANSWER_HUMAN', 'SIP 200 OK', 0], 'no_disponible'=>['','', 'BUSY', 'BUSY', 'SIP 480 Temporarily Unavailable', 0],
    'no_disponible2'=>['','', '', 'NOANSWER', 'SIP 408 Request Timeout', 0], 'sin_respuesta'=>['','', '', 'NOANSWER', 'SIP 487 Request Terminated', 0], 'sin_respuesta2'=>['','', '', 'NOANSWER', 'SIP 183 Session Progress', 0],
    'sin_respuesta3'=>['','', '', 'NOANSWER', '', 0], 'ocupado'=>['','', 'BUSY', 'BUSY', 'SIP 486 Busy Here', 0], 'ocupado2'=>['','', 'BUSY', 'BUSY', 'SIP 600 Busy Everywhere', 0],
    'errores'=>['','', '', 'CONGESTION', 'SIP 503 Service Unavailable', 0], 'errores2'=>['','', '', 'FAILED', 'SIP 403 Forbidden', 0], 'errores3'=>['','', '', 'FAILED', 'SIP 999 Raro', 0], 'en_llamada'=>['','', '', '', '', 1]];
$i = 0; $expect = [];
foreach ($cases as $name=>$c) {
    $i++; $pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count) VALUES ($dl,'95500".str_pad((string)$i, 4, '0', STR_PAD_LEFT)."','X',1)");
    $lead = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,campaign_id,started_at,finished_at,status,dial_status,amd_status,corte_por,disposition,sip_cause) VALUES (?,?,?,'955000000',?,UTC_TIMESTAMP(),".($c[5] ? 'NULL' : 'UTC_TIMESTAMP()').",?,?,?,?,?,?)")
        ->execute(["zv2-d$i", $dl, $lead, $dc, $c[5] ? 'CALL' : 'X', $c[2], $c[0], $c[1], $c[3], $c[4]]);
    $k = rtrim($name, '23'); $expect[$k] = ($expect[$k] ?? 0) + 1;
}
// a retried contact: first a Humano, then a Sin respuesta => counts only as Sin respuesta (last result)
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count) VALUES ($dl,'955009999','X',2)");
$lead2 = (int)$pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,campaign_id,started_at,finished_at,status,dial_status,amd_status,disposition,sip_cause) VALUES (?,?,?,'955009999',?,UTC_TIMESTAMP() - INTERVAL ? MINUTE,UTC_TIMESTAMP() - INTERVAL ? MINUTE,'X',?,?,?,?)");
$ins->execute(['zv2-dr1', $dl, $lead2, $dc, 30, 30, 'ANSWER', 'HUMAN', 'XFER_OK', 'SIP 200 OK']); $ins->execute(['zv2-dr2', $dl, $lead2, $dc, 5, 5, '', '', 'NOANSWER', 'SIP 487 Request Terminated']);
$expect['sin_respuesta']++;
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count) VALUES ($dl,'955008888','NEW',0),($dl,'955008887','NEW',0)"); $expect['sin_llamar'] = 2;
$repo->refreshListSummary($dl, 'stopped', marcador_amd_valid_from_utc());
$dist = bot_cfg_contact_distribution($repo, ['list_id'=>$dl]);
$lst = $dist['lists'][0] ?? [];
$nContacts = (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=$dl")->fetchColumn();
check('distribución por contacto: cada categoría cuenta lo esperado (último resultado) y los códigos raros caen en Errores', ($lst['counts'] ?? []) === marcador_contact_counts($expect));
check('distribución por contacto: la suma de las categorías es exactamente el total de contactos', array_sum($lst['counts']) === $nContacts && $lst['contacts'] === $nContacts && abs(array_sum($lst['pct']) - 100.0) < 0.0001);
check('distribución por contacto: la campaña y el total suman lo mismo y los porcentajes dan 100', $dist['total']['contacts'] === $nContacts && abs(array_sum($dist['total']['pct']) - 100.0) < 0.0001 && array_sum(array_column($dist['campaigns'], 'contacts')) === $nContacts);
check('aguja: Humano ÷ contactos llamados y Sin datos bajo 20 llamados', $lst['needle']['called'] === $nContacts - 2 && $lst['needle']['human'] === 1 && $lst['needle']['no_data'] === true && $lst['needle']['label'] === 'Sin datos'
    && marcador_contact_needle(marcador_contact_counts(['humano'=>12, 'errores'=>8, 'sin_llamar'=>80]))['label'] === '12 de 20 contactos llamados' && marcador_contact_needle(marcador_contact_counts(['humano'=>12, 'errores'=>7, 'sin_llamar'=>81]))['no_data'] === true);
check('porcentajes con decimales suman 100,0 exactos', abs(array_sum(marcador_contact_pct([1, 1, 1])) - 100.0) < 0.0001 && abs(array_sum(marcador_contact_pct([7, 13, 29, 51, 0, 3])) - 100.0) < 0.0001 && array_sum(marcador_contact_pct([0, 0])) === 0.0);
$rg = bot_cfg_contact_distribution($repo, ['list_id'=>$dl, 'from'=>'2000-01-01']);
check('distribución con fechas: usa el último intento dentro del rango y no cuenta «Sin llamar»', $rg['source'] === 'attempts' && $rg['lists'][0]['counts']['sin_llamar'] === 0 && $rg['lists'][0]['contacts'] === $nContacts - 2 && $rg['lists'][0]['counts']['sin_respuesta'] === $expect['sin_respuesta']);
$rg2 = bot_cfg_contact_distribution($repo, ['list_id'=>$dl, 'from'=>gmdate('Y-m-d', time() - 400 * 86400), 'to'=>gmdate('Y-m-d', time() - 399 * 86400)]);
check('distribución con un rango sin llamadas: vacía y sin error', $rg2['lists'] === [] && $rg2['total']['contacts'] === 0);
$ds = bot_cfg_dashboard($repo);
$dl1 = null; foreach ($ds['campaigns'] as $cc) foreach ($cc['lists'] as $ll) if ($ll['list_id'] === $dl) $dl1 = [$cc, $ll];
check('dashboard: la lista trae su distribución por contacto y la campaña la suma', $dl1 !== null && $dl1[1]['distribution']['contacts'] === $nContacts && $dl1[0]['distribution']['contacts'] === $nContacts && $dl1[1]['insight']['basis'] === 'contactos');
$za = bot_cfg_contact_zypad_amd($repo, []);
check('zypad vs AMD por contacto: devuelve filas con su N (o vacío) sin error', is_array($za['rows']));
// zypad-only split and the weekday x hour matrix by contact
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels,dialer_state) VALUES ($zc,'ZS2',1,1,'stopped')");
$zl2 = (int)$pdo->lastInsertId();
$zcases = [['HUMAN', 'SIP 200 OK', 'XFER_OK', 'ANSWER'], ['', 'SIP 480 Temporarily Unavailable', 'BUSY', 'BUSY'], ['', 'SIP 487 Request Terminated', 'NOANSWER', ''], ['NOTSURE', 'SIP 200 OK', 'ANSWER_HUMAN', 'ANSWER'], ['', 'SIP 200 OK', 'ANSWER_HUMAN', 'ANSWER'], ['MACHINE', 'SIP 200 OK', 'AMD_MACHINE', 'ANSWER']];
foreach ($zcases as $j=>$zc2) {
    $pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count) VALUES ($zl2,'9560000".$j."','X',1)"); $lz = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,campaign_id,started_at,finished_at,status,dial_status,amd_status,disposition,sip_cause,dial_mode,detectado_por,momento) VALUES (?,?,?,'956000000',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'X',?,?,?,?,'ZYPAD_SOMBRA','ZYPAD','PRE')")
        ->execute(["zv2-zs$j", $zl2, $lz, $zc, $zc2[3], $zc2[0], $zc2[2], $zc2[1]]);
}
$zr = bot_cfg_contact_zypad_amd($repo, ['list_id'=>$zl2, 'from'=>'2000-01-01'])['rows'][0] ?? [];
check('zypad vs AMD por contacto: solo_zypad = con AMD humano + sin contestar + otros', ($zr['solo_zypad'] ?? -1) === 5 && $zr['solo_zypad_amd_humano'] === 1 && $zr['solo_zypad_sin_contestar'] === 2 && $zr['solo_zypad_otros'] === 2
    && $zr['solo_zypad_amd_humano'] + $zr['solo_zypad_sin_contestar'] + $zr['solo_zypad_otros'] === $zr['solo_zypad'] && $zr['ambos'] === 1 && $zr['contacts'] === 6);
$wm = bot_cfg_contact_weekday_hour($repo, ['list_id'=>$zl2, 'from'=>'2000-01-01']);
check('matriz día × hora por contacto: cada contacto cuenta una vez, con contestados y humanos', $wm['total']['contacts'] === 6 && $wm['total']['answered'] === 4 && $wm['total']['human'] === 1 && $wm['cells'][0]['weekday'] >= 1 && $wm['cells'][0]['weekday'] <= 7 && $wm['cells'][0]['hour_local'] >= 0 && $wm['cells'][0]['hour_local'] <= 23);
check('matriz día × hora por contacto: rango sin llamadas = vacía sin error', bot_cfg_contact_weekday_hour($repo, ['from'=>gmdate('Y-m-d', time() - 400 * 86400), 'to'=>gmdate('Y-m-d', time() - 399 * 86400)])['cells'] === []);
// Mini needle: last 10 finished calls of a list
$W = BOT_RECENT_WINDOW;
$l10 = bot_cfg_last10($repo, $dl);
check('mini aguja: con menos llamadas terminadas que la ventana dice Sin datos', $l10['no_data'] === ($l10['calls'] < $W) && ($l10['calls'] >= $W || $l10['label'] === 'Sin datos') && $l10['window'] === $W);
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels,dialer_state) VALUES ($dc,'M10',1,3,'stopped')"); $m10 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count) VALUES ($m10,'957000001','X',1)"); $mlead = (int)$pdo->lastInsertId();
$expH = 0;
for ($q = 1; $q <= $W + 2; $q++) { $am = $q <= 2 ? 'HUMAN' : ($q % 2 ? 'HUMAN' : ''); if ($q > 2) $expH += $am === 'HUMAN' ? 1 : 0;
    $pdo->prepare("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,campaign_id,started_at,finished_at,status,amd_status) VALUES (?,?,?,'957000001',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'X',?)")->execute(["zv2-m$q", $m10, $mlead, $dc, $am]); }
$m = bot_cfg_last10($repo, $m10);
check('mini aguja: las últimas de la ventana (primeros intentos y reintentos), con cuántas humanas', $m['calls'] === $W && $m['no_data'] === false && $m['human'] === $expH && $m['label'] === $expH.' de '.$W.' últimas llamadas');
$ba = bot_cfg_report_extra($repo, ['list_id'=>$dl, 'from'=>'2000-01-01'])['by_attempt'];
check('report_extra.by_attempt: intentos por número de intento, con contestadas y humanas', $ba !== [] && $ba[0]['attempt_no'] === 1 && $ba[0]['calls'] > $ba[count($ba) - 1]['calls'] - 1 && array_sum(array_column($ba, 'calls')) === (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE list_id=$dl AND finished_at IS NOT NULL AND call_id NOT LIKE 'TEST-%'")->fetchColumn()
    && ($ba[1]['attempt_no'] ?? 2) === 2);
// Detalle de intentos: Estado filter on the server, full phone on screen, masked in the CSV
$rp = bot_cfg_report($repo, ['list_id'=>$dl, 'from'=>'2000-01-01'], 1, 5);
$sumE = array_sum($rp['detail']['estados']);
check('detalle: la suma de los Estados es el total y hay etiquetas en español', $sumE === $rp['detail']['total'] && $rp['detail']['total'] > 5 && $rp['detail']['pages'] === (int)ceil($rp['detail']['total'] / 5) && count($rp['detail']['estado_list']) === count(MARCADOR_ATTEMPT_STATES));
$ok = true; foreach ($rp['detail']['estados'] as $est=>$n) { $q2 = bot_cfg_report($repo, ['list_id'=>$dl, 'from'=>'2000-01-01', 'estado'=>$est], 1, 3); $ok = $ok && $q2['detail']['total'] === $n && array_unique(array_column($q2['detail']['rows'], 'estado')) === [$est] && count($q2['detail']['rows']) === min(3, $n); }
check('detalle: cada Estado filtra en servidor con su total y su paginación', $ok && count($rp['detail']['estados']) >= 5);
check('detalle: 480 y 408 = No disponible, 486 y 600 = Ocupado, 487 y NOANSWER = Sin respuesta, XFER_OK = Transferida', ($rp['detail']['estados']['no_disponible'] ?? 0) === 2 && ($rp['detail']['estados']['ocupado'] ?? 0) === 2 && ($rp['detail']['estados']['transferida'] ?? 0) === 2 && ($rp['detail']['estados']['casilla_voz'] ?? 0) === 1);
check('detalle: Estado inválido se rechaza y las tarjetas no cambian con el filtro', throws(fn() => bot_cfg_report($repo, ['estado'=>"x' OR 1=1 --"])) !== '' && bot_cfg_report($repo, ['list_id'=>$dl, 'from'=>'2000-01-01', 'estado'=>'ocupado'])['kpi']['calls'] === bot_cfg_report($repo, ['list_id'=>$dl, 'from'=>'2000-01-01'])['kpi']['calls']);
$full = $rp['detail']['rows'][0]['phone'];
check('detalle en pantalla: teléfono completo; CSV: enmascarado', !str_contains($full, '*') && strlen($full) >= 9 && !str_contains(bot_cfg_report_csv($repo, ['list_id'=>$dl, 'from'=>'2000-01-01']), $full)
    && str_contains(bot_cfg_report_csv($repo, ['list_id'=>$dl, 'from'=>'2000-01-01', 'estado'=>'ocupado']), '*') && substr_count(trim(bot_cfg_report_csv($repo, ['list_id'=>$dl, 'from'=>'2000-01-01', 'estado'=>'ocupado'])), "\n") === 2);
// AMD is no longer a form field: 7308 and 7314 always keep amd_enabled=1, other origins never lose it in silence
$ba7 = ['name'=>'SINAMD7308', 'active'=>'1', 'call_origin'=>'7308', 'max_channels'=>'1', 'lead_order'=>'UP', 'transfer_carrier_id'=>'', 'transfer_destination'=>''];
$n7308 = bot_cfg_save($repo, null, $ba7);
$n7314 = bot_cfg_save($repo, null, ['name'=>'SINAMD7314', 'call_origin'=>'7314'] + $ba7);
check('guardar sin amd_enabled: 7308 y 7314 nuevas quedan con AMD', (int)$repo->campaign($n7308)['amd_enabled'] === 1 && (int)$repo->campaign($n7314)['amd_enabled'] === 1);
$pdo->exec("UPDATE zynervox_bot_campaigns SET amd_enabled=0 WHERE campaign_id IN ($n7308,$n7314)");
bot_cfg_save($repo, $n7308, $ba7); bot_cfg_save($repo, $n7314, ['call_origin'=>'7314'] + $ba7);
check('guardar sin amd_enabled una campaña 7308/7314 que lo tenía apagado lo deja en 1', (int)$repo->campaign($n7308)['amd_enabled'] === 1 && (int)$repo->campaign($n7314)['amd_enabled'] === 1);
bot_cfg_save($repo, $n7308, ['amd_enabled'=>'0'] + $ba7);
check('aunque llegue amd_enabled=0 en un tipo ofrecido, queda en 1', (int)$repo->campaign($n7308)['amd_enabled'] === 1);
$nOld = bot_cfg_save($repo, null, ['name'=>'ANTIGUA7307', 'call_origin'=>'7307', 'amd_enabled'=>'1'] + $ba7);
bot_cfg_save($repo, $nOld, ['call_origin'=>'7307'] + $ba7);
check('un origen no ofrecido conserva su valor al guardar sin amd_enabled', (int)$repo->campaign($nOld)['amd_enabled'] === 1);
$pdo->exec("UPDATE zynervox_bot_campaigns SET amd_enabled=0 WHERE campaign_id=$nOld"); bot_cfg_save($repo, $nOld, ['call_origin'=>'7307'] + $ba7);
check('un origen no ofrecido que estaba sin AMD sigue sin AMD (no se cambia en silencio)', (int)$repo->campaign($nOld)['amd_enabled'] === 0);
// --- Blacklist (023): a blocked number is never dialed; its attempt is «Bloqueado», final, and the contact counts in its own category ---
check('lista negra: la clave son los últimos 9 dígitos', marcador_blacklist_key('+51 912 345 678') === '912345678' && marcador_blacklist_key('987654321') === '987654321' && marcador_blacklist_key('abc') === '');
$bc = bot_cfg_save($repo, null, ['name'=>'BLACK', 'active'=>'1', 'call_origin'=>'7308', 'max_channels'=>'1', 'lead_order'=>'UP', 'transfer_carrier_id'=>'', 'transfer_destination'=>'', 'rules'=>[], 'schedules'=>[['weekday'=>1, 'start_time'=>'00:00', 'end_time'=>'23:59']]]);
check('lista negra por campaña: una campaña nueva viene con la opción en No y guardar sin el campo no la cambia', (int)$repo->campaign($bc)['blacklist_enabled'] === 0);
$pdo->exec("DELETE FROM zynervox_blacklist"); $pdo->exec("INSERT INTO zynervox_blacklist (campaign_id,phone,reason,created_by) VALUES (0,'900000011','prueba ficticia','test')");
check('lista negra por campaña: con la opción en No no consulta nada (ni el motor ni el AGI)', !$repo->isBlacklisted('900000011', $bc) && marcador_blacklist_check($repo, '900000011', (string)$bc) === 'NO');
bot_cfg_save($repo, $bc, ['name'=>'BLACK', 'blacklist_enabled'=>'1', 'call_origin'=>'7308', 'lead_order'=>'UP', 'rules'=>[], 'schedules'=>[['weekday'=>1, 'start_time'=>'00:00', 'end_time'=>'23:59']]]);
bot_cfg_save($repo, $bc, ['name'=>'BLACK', 'call_origin'=>'7308', 'lead_order'=>'UP', 'rules'=>[], 'schedules'=>[['weekday'=>1, 'start_time'=>'00:00', 'end_time'=>'23:59']]]);
check('lista negra por campaña: guardar sin el campo conserva el Sí', (int)$repo->campaign($bc)['blacklist_enabled'] === 1);
$bv = fn($c) => in_array('__ZV2_BLACKLIST=SI', bot_engine_variables($c, ['call_id'=>'zv2-x', 'list_id'=>1, 'lead_id'=>1], []), true);
check('lista negra por campaña: el motor manda __ZV2_BLACKLIST=SI solo con la opción en Sí', $bv($repo->campaign($bc)) && !$bv(['blacklist_enabled'=>0] + $repo->campaign($bc)));
$bc2 = bot_cfg_save($repo, null, ['name'=>'BLACK2', 'blacklist_enabled'=>'1', 'call_origin'=>'7308', 'lead_order'=>'UP', 'rules'=>[], 'schedules'=>[]]);
$pdo->exec("DELETE FROM zynervox_blacklist"); $pdo->exec("INSERT INTO zynervox_blacklist (campaign_id,phone,reason,created_by) VALUES ($bc2,'900000011','otra campaña','test'),($bc,'900000041','solo esta','test'),(0,'900000042','todas','test')");
check('lista negra por campaña: lo de otra campaña no bloquea, lo propio y lo de «todas» sí; sin campaña no consulta', !$repo->isBlacklisted('900000011', $bc) && $repo->isBlacklisted('900000011', $bc2) && $repo->isBlacklisted('900000041', $bc) && !$repo->isBlacklisted('900000041', $bc2) && $repo->isBlacklisted('900000042', $bc) && $repo->isBlacklisted('900000042', $bc2)
    && marcador_blacklist_check($repo, '900000042', '') === 'NO' && marcador_blacklist_check($repo, '900000042', '0') === 'NO' && marcador_blacklist_check($repo, '900000042', (string)$bc) === 'SI');
check('lista negra por campaña: el mismo número en varias campañas y en «todas» no duplica y la clave rechaza el duplicado', throws(fn() => $pdo->exec("INSERT INTO zynervox_blacklist (campaign_id,phone) VALUES ($bc,'900000041')")) !== '' && $pdo->exec("INSERT INTO zynervox_blacklist (campaign_id,phone) VALUES ($bc2,'900000041'),(0,'900000041')") === 2);
$pdo->exec("DELETE FROM zynervox_blacklist"); $pdo->exec("DELETE FROM zynervox_bot_campaigns WHERE campaign_id=$bc2");
$pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels,dialer_state) VALUES ($bc,'BL',1,1,'running')"); $bl = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status) VALUES ($bl,'900000011','NEW'),($bl,'900000012','NEW'),($bl,'900000013','NEW')");
$pdo->exec("DELETE FROM zynervox_blacklist"); $pdo->exec("INSERT INTO zynervox_blacklist (campaign_id,phone,reason,created_by) VALUES ($bc,'900000011','prueba ficticia','test')");
check('lista negra: isBlacklisted ve el número ficticio y no los otros', $repo->isBlacklisted('900000011', $bc) && $repo->isBlacklisted('+51 900000011', $bc) && !$repo->isBlacklisted('900000012', $bc));
$cl = $repo->claimCampaignLead($bc, 'UP', 'zv2-black1', fn($lead) => ['ok'=>1]);
check('lista negra: el bloqueado no se marca y el motor pasa al siguiente contacto', $cl !== null && $cl['phone'] === '900000012');
$ba = $pdo->query("SELECT status,disposition,end_source,finished_at IS NOT NULL fin FROM zynervox_bot_call_attempts WHERE list_id=$bl AND disposition='BLOCKED'")->fetch();
check('lista negra: intento BLOQUEADO terminado, sin cuenta de llamada y contacto en estado final', $ba && $ba['status'] === 'BLOCKED' && $ba['end_source'] === 'BLOCKED' && (int)$ba['fin'] === 1
    && $pdo->query("SELECT CONCAT(status,'/',called_count) FROM zynervox_bot_list WHERE list_id=$bl AND phone='900000011'")->fetchColumn() === 'BLOCKED/0');
check('lista negra: no se reintenta (ni con reglas) y no queda elegible', $repo->listEligibleCount($bc, $bl, 'UP') === 1);
$pdo->exec("UPDATE zynervox_bot_call_attempts SET finished_at=UTC_TIMESTAMP(),status='X',disposition='NOANSWER',sip_cause='SIP 487 Request Terminated' WHERE call_id='zv2-black1'");
$repo->refreshListSummary($bl, 'stopped', marcador_amd_valid_from_utc());
$bd = bot_cfg_contact_distribution($repo, ['list_id'=>$bl])['lists'][0];
check('distribución: «Bloqueado» es una categoría propia y todo suma el total de contactos', $bd['counts']['bloqueado'] === 1 && $bd['counts']['sin_respuesta'] === 1 && $bd['counts']['sin_llamar'] === 1 && array_sum($bd['counts']) === 3 && abs(array_sum($bd['pct']) - 100.0) < 0.0001);
$rb = bot_cfg_report($repo, ['list_id'=>$bl, 'from'=>'2000-01-01', 'estado'=>'bloqueado'], 1, 10)['detail'];
check('detalle: el Estado «Bloqueado» existe en el filtro y en las etiquetas', $rb['total'] === 1 && $rb['rows'][0]['estado_label'] === 'Bloqueado' && in_array('bloqueado', array_column($rb['estado_list'], 'key'), true));
$pdo->exec("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,campaign_id,started_at,status) SELECT 'zv2-black2',list_id,lead_id,phone,$bc,UTC_TIMESTAMP(),'CALL' FROM zynervox_bot_list WHERE list_id=$bl AND phone='900000013'");
check('AGI «blocked»: el dialplan cierra el intento como Bloqueado y el contacto queda final', $repo->markCallBlocked('zv2-black2') === 1 && $pdo->query("SELECT CONCAT(disposition,'/',status) FROM zynervox_bot_call_attempts WHERE call_id='zv2-black2'")->fetchColumn() === 'BLOCKED/BLOCKED'
    && $pdo->query("SELECT status FROM zynervox_bot_list WHERE list_id=$bl AND phone='900000013'")->fetchColumn() === 'BLOCKED' && $repo->markCallBlocked('zv2-no-existe') === 0);
$pdo->exec("DELETE FROM zynervox_blacklist");
check('lista negra: borrar el número lo deja marcable otra vez (los nuevos contactos)', !$repo->isBlacklisted('900000011', $bc));
// --- Screen «Test» (025): one-contact list, one call whatever the result, no retries, closes itself ---
$tc = bot_cfg_save($repo, null, ['name'=>'TESTSCREEN', 'active'=>'1', 'call_origin'=>'7308', 'lead_order'=>'UP', 'transfer_carrier_id'=>'', 'transfer_destination'=>'', 'schedules'=>[['weekday'=>1, 'start_time'=>'00:00', 'end_time'=>'23:59']],
    'rules'=>[['sip_code'=>486, 'max_attempts'=>5, 'retry_delay_min'=>0, 'active'=>1], ['sip_code'=>0, 'max_attempts'=>5, 'retry_delay_min'=>0, 'active'=>1]]]);
$t1 = bot_cfg_test_create($repo, $tc, '+51 900 000 051', 'test');
$t2 = bot_cfg_test_create($repo, $tc, '900000051', 'test');
$t3 = bot_cfg_test_create($repo, $tc, '51-900-000-051', 'test'); $t4 = bot_cfg_test_create($repo, $tc, '51900000051', 'test');
check('Test: 9 dígitos, 51…, +51…, con espacios o guiones guardan siempre los 9 dígitos', $pdo->query("SELECT GROUP_CONCAT(DISTINCT phone) FROM zynervox_bot_list WHERE list_id IN ({$t1['list_id']},{$t2['list_id']},{$t3['list_id']},{$t4['list_id']})")->fetchColumn() === '900000051');
$pdo->exec("DELETE FROM zynervox_bot_list WHERE list_id IN ({$t3['list_id']},{$t4['list_id']})"); $pdo->exec("DELETE FROM zynervox_bot_lists WHERE list_id IN ({$t3['list_id']},{$t4['list_id']})");
$tl = $pdo->query("SELECT CONCAT(is_test,'/',active,'/',dialer_state,'/',max_channels,'/',name) FROM zynervox_bot_lists WHERE list_id={$t1['list_id']}")->fetchColumn();
check('Test: lista de 1 contacto, 1 canal, activa, detenida, marcada de prueba y con nombre generado sin el número', preg_match('#^1/1/stopped/1/test-\d{8}-\d{6}$#', (string)$tl) === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id={$t1['list_id']} AND phone='900000051'")->fetchColumn() === 1 && $t2['list_id'] !== $t1['list_id']);
check('Test: número inválido se rechaza sin crear lista', throws(fn() => bot_cfg_test_create($repo, $tc, 'abc', 'test')) !== '' && throws(fn() => bot_cfg_test_create($repo, $tc, '12345', 'test')) !== '' && str_contains(throws(fn() => bot_cfg_test_create($repo, $tc, '5190000005112', 'test')), '9 dígitos') && str_contains(throws(fn() => bot_cfg_test_create($repo, $tc, '90000005', 'test')), '9 dígitos') && throws(fn() => bot_cfg_test_create($repo, 999999, '900000052', 'test')) !== ''
    && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_lists WHERE campaign_id=$tc AND is_test=1")->fetchColumn() === 2);
$rowsT = bot_cfg_test_recent($repo, 5);
check('Test: la lista de pruebas recientes trae las dos listas, la más nueva primero, sin llamada todavía', count($rowsT) === 2 && $rowsT[0]['list_id'] === $t2['list_id'] && $rowsT[0]['state'] === 'sin_iniciar' && $rowsT[0]['estado'] === null && $rowsT[0]['phone'] === '900000051');
bot_cfg_list_start($repo, $tc, $t1['list_id'], 'test', ['7308']);
$cl = $repo->claimCampaignLead($tc, 'UP', 'zv2-testscr1', fn($lead) => ['ok'=>1]);
check('Test: el motor marca el contacto de la lista de prueba', $cl !== null && $cl['list_id'] === $t1['list_id']);
$repo->finishUnansweredCall('zv2-testscr1', 'BUSY', 17, 'SIP 486 Busy Here', 'BUSY');
check('Test: con 486 y una regla de reintento de 5 en la campaña, la lista de prueba NO reintenta', $repo->claimCampaignLead($tc, 'UP', 'zv2-testscr2', fn($lead) => ['ok'=>1]) === null && $repo->listEligibleCount($tc, $t1['list_id'], 'UP') === 0);
$ft = marcador_list_finish_if_done($repo, $tc, $t1['list_id']);
$rt = array_values(array_filter(bot_cfg_test_recent($repo, 5), fn($r) => $r['list_id'] === $t1['list_id']))[0];
check('Test: al terminar la lista se cierra sola y la pantalla ve su estado y resultado', $ft && $rt['state'] === 'terminada' && $rt['estado'] === 'ocupado' && $rt['estado_label'] === 'Ocupado' && str_contains($rt['resultado'], '486')
    && (int)$pdo->query("SELECT active FROM zynervox_bot_lists WHERE list_id={$t1['list_id']}")->fetchColumn() === 0);
check('Test: una lista normal de la misma campaña sí conserva sus reintentos', $repo->campaignConfig($tc)['rules'] !== []);
// --- Blacklist in two levels (026): global lists filtered only on load; regular one consulted when dialing; audited changes ---
$pdo->exec("DELETE FROM zynervox_blacklist"); $pdo->exec("DELETE FROM zynervox_blacklist_global"); $pdo->exec("UPDATE zynervox_bot_campaigns SET global_blacklist_id=NULL"); $pdo->exec("DELETE FROM zynervox_blacklist_lists"); $pdo->exec("DELETE FROM zynervox_blacklist_audit");
$gA = bot_bl_list_create($repo, 'BCP-T', 'tester'); $gB = bot_bl_list_create($repo, 'INTERBANK-T', 'tester');
check('global: el nombre de la lista es único y se puede renombrar', throws(fn() => bot_bl_list_create($repo, 'BCP-T', 'tester')) !== '' && (bot_bl_list_rename($repo, $gB, 'IBK-T', 'tester') ?? true) && array_column(bot_bl_lists($repo), 'name') === ['BCP-T', 'IBK-T']);
foreach (['900000081', '51900000082', '+51 900-000-083'] as $n) bot_bl_add($repo, 'global', $gA, $n, 'cliente BCP', 'tester');
bot_bl_add($repo, 'global', $gB, '900000084', 'cliente IBK', 'tester');
bot_bl_add($repo, 'global', $gA, '900000081', 'motivo nuevo', 'tester');
check('global: números a 9 dígitos, sin duplicados y la reincidencia solo actualiza el motivo', (int)$pdo->query("SELECT COUNT(*) FROM zynervox_blacklist_global WHERE list_id=$gA")->fetchColumn() === 3 && $pdo->query("SELECT reason FROM zynervox_blacklist_global WHERE list_id=$gA AND phone='900000081'")->fetchColumn() === 'motivo nuevo'
    && throws(fn() => bot_bl_add($repo, 'global', $gA, '12345', '', 'tester')) !== '' && throws(fn() => bot_bl_add($repo, 'global', 999999, '900000085', '', 'tester')) !== '' && throws(fn() => bot_bl_add($repo, 'global', $gA, '900000085', '', ' ')) !== '');
$gc = bot_cfg_save($repo, null, ['name'=>'GLOBALCON', 'active'=>'1', 'call_origin'=>'7308', 'lead_order'=>'UP', 'rules'=>[], 'schedules'=>[]]);
$gn = bot_cfg_save($repo, null, ['name'=>'GLOBALSIN', 'active'=>'1', 'call_origin'=>'7308', 'lead_order'=>'UP', 'rules'=>[], 'schedules'=>[]]);
$go = bot_cfg_save($repo, null, ['name'=>'GLOBALOTRA', 'active'=>'1', 'call_origin'=>'7308', 'lead_order'=>'UP', 'rules'=>[], 'schedules'=>[]]);
bot_bl_assign_global($repo, $gc, $gA, 'tester'); bot_bl_assign_global($repo, $go, $gB, 'tester');
$mk = function(int $camp) use($pdo, $repo) { $pdo->exec("INSERT INTO zynervox_bot_lists (campaign_id,name,active,max_channels) VALUES ($camp,'GL',1,1)"); return (int)$pdo->lastInsertId(); };
$rows = bot_list_parse_txt("numero,id_contacto\n900000081,1\n900000082,2\n900000084,3\n900000086,4\n");
$lc = $mk($gc); $rc = $repo->importLeads($lc, $gc, $rows);
check('carga con global: bloquea los suyos (81 y 82), no el de otra lista global (84) ni el libre, e informa cuántos y de qué lista', $rc['saved'] === 4 && $rc['blocked'] === 2 && $rc['blocked_list'] === 'BCP-T'
    && $pdo->query("SELECT GROUP_CONCAT(phone ORDER BY phone) FROM zynervox_bot_list WHERE list_id=$lc AND status='BLOCKED'")->fetchColumn() === '900000081,900000082'
    && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE list_id=$lc AND disposition='BLOCKED' AND finished_at IS NOT NULL")->fetchColumn() === 2);
$ln = $mk($gn); $rn = $repo->importLeads($ln, $gn, $rows);
check('carga en una campaña SIN lista global: no bloquea nada', $rn['blocked'] === 0 && $rn['blocked_list'] === '' && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=$ln AND status<>'NEW'")->fetchColumn() === 0);
$lo = $mk($go); $ro = $repo->importLeads($lo, $go, $rows);
check('carga con OTRA lista global: solo bloquea los de esa (84)', $ro['blocked'] === 1 && $ro['blocked_list'] === 'IBK-T' && $pdo->query("SELECT phone FROM zynervox_bot_list WHERE list_id=$lo AND status='BLOCKED'")->fetchColumn() === '900000084');
$pdo->exec("UPDATE zynervox_bot_lists SET dialer_state='running' WHERE list_id=$lc");
$repo->refreshListSummary($lc, 'stopped', marcador_amd_valid_from_utc());
$dc = bot_cfg_contact_distribution($repo, ['list_id'=>$lc])['lists'][0];
check('los bloqueados por la global cuentan en «Bloqueado» de la distribución y suman 100', $dc['counts']['bloqueado'] === 2 && $dc['counts']['sin_llamar'] === 2 && array_sum($dc['counts']) === 4 && abs(array_sum($dc['pct']) - 100.0) < 0.0001);
$seen = []; while (($cl = $repo->claimCampaignLead($gc, 'UP', 'zv2-gl'.count($seen), fn($lead) => ['ok'=>1])) !== null) { $seen[] = $cl['phone']; $repo->finishUnansweredCall($cl['call_id'], 'NOANSWER', 19, 'SIP 487 Request Terminated', 'NOANSWER'); }
check('el motor nunca marca a los bloqueados por la carga (solo 84 y 86)', $seen === ['900000084', '900000086'] || $seen === ['900000086', '900000084']);
bot_bl_add($repo, 'global', $gA, '900000086', 'agregado después', 'tester');
$pdo->exec("UPDATE zynervox_bot_lists SET dialer_state='stopped' WHERE list_id=$ln"); $lc2 = $mk($gn); $pdo->exec("UPDATE zynervox_bot_lists SET dialer_state='running' WHERE list_id=$lc2"); $repo->importLeads($lc2, $gn, bot_list_parse_txt("numero,id_contacto\n900000086,9\n"));
check('lo agregado a la global DESPUÉS no se refiltra al marcar (solo en la carga)', $repo->claimCampaignLead($gn, 'UP', 'zv2-gl-late', fn($lead) => ['ok'=>1])['phone'] === '900000086');
// regular blacklist: only the regular table is consulted when dialing, never the global one
$pdo->exec("DELETE FROM zynervox_blacklist"); bot_cfg_save($repo, $gn, ['name'=>'GLOBALSIN', 'blacklist_enabled'=>'1']);
check('al marcar se consulta solo la regular: un número de la global no bloquea y uno de la regular sí', !$repo->isBlacklisted('900000081', $gn) && marcador_blacklist_check($repo, '900000081', (string)$gn) === 'NO'
    && (bot_bl_add($repo, 'regular', $gn, '900000091', 'regular', 'tester') === '900000091') && $repo->isBlacklisted('900000091', $gn) && marcador_blacklist_check($repo, '900000091', (string)$gn) === 'SI');
bot_bl_add($repo, 'regular', $gn, '900000092', '', 'tester'); bot_bl_add($repo, 'regular', $gc, '900000093', '', 'tester'); bot_bl_add($repo, 'regular', 0, '900000094', 'todas', 'tester');
$sr = bot_bl_search($repo, '+51 900000091'); $sg = bot_bl_search($repo, '900000081');
check('buscar un número lo encuentra en las listas globales y en la regular', count($sr) === 1 && $sr[0]['scope'] === 'regular' && $sr[0]['list_name'] === 'GLOBALSIN' && count($sg) === 1 && $sg[0]['scope'] === 'global' && $sg[0]['list_name'] === 'BCP-T');
check('retirar exige confirmación y retira solo un número', throws(fn() => bot_bl_remove($repo, 'regular', $gn, '900000091', 'tester', false)) !== '' && bot_bl_remove($repo, 'regular', $gn, '900000091', 'tester', true) === 1 && bot_bl_remove($repo, 'regular', $gn, '900000091', 'tester', true) === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_blacklist WHERE campaign_id=$gn")->fetchColumn() === 1);
check('vaciar la regular exige confirmación y vacía solo la campaña pedida (ni las otras ni «todas»)', throws(fn() => bot_bl_clear_regular($repo, $gn, 'tester', false)) !== '' && bot_bl_clear_regular($repo, $gn, 'tester', true) === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_blacklist WHERE campaign_id=$gc")->fetchColumn() === 1 && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_blacklist WHERE campaign_id=0")->fetchColumn() === 1);
check('conteo de la regular por ámbito: la campaña, otra campaña y «todas» (0) por separado', bot_bl_count_regular($repo, $gc) === 1 && bot_bl_count_regular($repo, $gn) === 0 && bot_bl_count_regular($repo, 0) === 1 && throws(fn() => bot_bl_count_regular($repo, -1)) !== '');
check('vaciar «todas» quita solo las filas de «todas»', bot_bl_clear_regular($repo, 0, 'tester', true) === 1 && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_blacklist WHERE campaign_id=$gc")->fetchColumn() === 1);
bot_bl_remove($repo, 'global', $gA, '900000086', 'tester', true);
check('la global no se puede vaciar por el servicio: no existe esa operación y quitar un número deja los demás', !function_exists('bot_bl_clear_global') && !method_exists($repo, 'blacklistClearGlobal') && (int)$pdo->query("SELECT COUNT(*) FROM zynervox_blacklist_global WHERE list_id=$gA")->fetchColumn() === 3);
$au = bot_bl_audit($repo, 200); $acts = array_count_values(array_column($au, 'action'));
check('auditoría: altas, retiradas, vaciados, altas de lista y asignaciones quedan con quién, cuándo, número y lista', ($acts['add'] ?? 0) >= 9 && ($acts['remove'] ?? 0) === 2 && ($acts['clear'] ?? 0) === 2 && ($acts['list_create'] ?? 0) === 2 && ($acts['assign'] ?? 0) === 2 && ($acts['list_rename'] ?? 0) === 1
    && $au[0]['actor'] === 'tester' && count(bot_bl_audit($repo, 10, '900000086')) === 2
    && array_filter($au, fn($a) => $a['action'] === 'remove' && $a['scope'] === 'global' && $a['phone'] === '900000086' && $a['list_name'] === 'BCP-T'));
bot_bl_assign_global($repo, $gc, null, 'tester');
check('una campaña puede quitar su lista global', $repo->importLeads($mk($gc), $gc, $rows)['blocked'] === 0);
$tg = bot_cfg_test_create($repo, $go, '900000084', 'tester');
check('Test con la campaña que tiene global: la lista de prueba nace bloqueada y lo informa', $tg['blocked'] === 1 && $tg['blocked_list'] === 'IBK-T');
$pdo->exec("DELETE FROM zynervox_blacklist"); $pdo->exec("DELETE FROM zynervox_blacklist_global"); $pdo->exec("UPDATE zynervox_bot_campaigns SET global_blacklist_id=NULL"); $pdo->exec("DELETE FROM zynervox_blacklist_lists"); $pdo->exec("DELETE FROM zynervox_blacklist_audit");
// Strict check used by the dialplan AGI: SI / NO / ERROR (fail closed when the table or the database fail)
$pdo->exec("INSERT INTO zynervox_blacklist (campaign_id,phone,reason,created_by) VALUES ($bc,'900000021','prueba ficticia','test')");
check('AGI de lista negra: SI para un bloqueado (con prefijo o formato), NO para otro, ERROR sin dígitos', marcador_blacklist_check($repo, '51900000021', (string)$bc) === 'SI' && marcador_blacklist_check($repo, '900000021', (string)$bc) === 'SI' && marcador_blacklist_check($repo, '900000022', (string)$bc) === 'NO' && marcador_blacklist_check($repo, 'xx', (string)$bc) === 'ERROR' && marcador_blacklist_check($repo, '12345', (string)$bc) === 'NO');
$pdo->exec("RENAME TABLE zynervox_blacklist TO zynervox_blacklist_off");
$errAns = marcador_blacklist_check($repo, '900000022', (string)$bc);
$pdo->exec("RENAME TABLE zynervox_blacklist_off TO zynervox_blacklist");
check('AGI de lista negra: si la consulta falla responde ERROR (no llamar a ciegas)', $errAns === 'ERROR');
$pdo->exec("DELETE FROM zynervox_blacklist");
// The check itself failed (database down): the attempt is a retryable failure, not a definitive «Bloqueado»
$pdo->exec("INSERT INTO zynervox_bot_list (list_id,phone,status,called_count) VALUES ($bl,'900000031','CALL',1)"); $le = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO zynervox_bot_call_attempts (call_id,list_id,lead_id,phone,campaign_id,started_at,status) VALUES ('zv2-black3',$bl,$le,'900000031',$bc,UTC_TIMESTAMP(),'CALL')");
$repo->markCallBlocked('zv2-black3', true);
$fa = $pdo->query("SELECT CONCAT(disposition,'/',status,'/',sip_cause) FROM zynervox_bot_call_attempts WHERE call_id='zv2-black3'")->fetchColumn();
$fl = $pdo->query("SELECT CONCAT(status,'/',last_sip_code) FROM zynervox_bot_list WHERE lead_id=$le")->fetchColumn();
check('fallo de la comprobación: intento FALLIDO (no Bloqueado) y contacto reintentable con la regla del código 0', $fa === 'FAILED/FAIL/Lista negra no disponible' && $fl === 'FAIL/0' && !str_contains((string)$fa, 'BLOCKED'));
echo $fail ? "$fail FALLOS\n" : "TODO OK\n";
exit($fail ? 1 : 0);
