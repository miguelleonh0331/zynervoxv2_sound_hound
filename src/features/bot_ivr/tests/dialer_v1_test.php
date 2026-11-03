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
foreach (['call_events','ivr_results','call_attempts','list','lists','campaign_headers','campaign_retry_rules','campaign_sip_rules','campaign_amd_actions','campaign_schedules','campaigns'] as $t) $pdo->exec("DELETE FROM zynervox_bot_$t");

$base = ['name'=>'T', 'active'=>'1', 'call_origin'=>'7307', 'max_channels'=>'2', 'lead_order'=>'UP', 'amd_enabled'=>'1', 'transfer_carrier_id'=>'dest', 'transfer_destination'=>'5551000',
    'headers'=>[['name'=>'X-Monto', 'field'=>'monto'], ['name'=>'X-Cliente', 'field'=>'customer_name']],
    'rules'=>[['sip_code'=>'486', 'max_attempts'=>'2', 'retry_delay_min'=>'0'], ['sip_code'=>'600', 'max_attempts'=>'2', 'retry_delay_min'=>'0']],
    'amd_actions'=>[['result'=>'MACHINE', 'action'=>'retry', 'max_attempts'=>'2', 'retry_delay_min'=>'0'], ['result'=>'HUMAN', 'action'=>'hangup', 'max_attempts'=>'9']], 'schedules'=>[['weekday'=>'1', 'start_time'=>'08:00', 'end_time'=>'18:00']]];
$id = bot_cfg_save($repo, null, $base);
$cfg = bot_cfg_get($repo, $id);
check('guarda headers, reintentos y franjas', count($cfg['headers']) === 2 && count($cfg['rules']) === 2 && $cfg['rules'][0]['sip_code'] === 486 && $cfg['rules'][1]['sip_code'] === 600 && $cfg['schedules'][0]['start_time'] === '08:00');
check('canales libres 1..500', bot_cfg_save($repo, $id, ['max_channels'=>'137'] + $base) === $id && (int)$repo->campaign($id)['max_channels'] === 137);
check('rechaza 501 canales', throws(fn() => bot_cfg_save($repo, $id, ['max_channels'=>'501'] + $base)) !== '');
check('rechaza header sin X-', str_contains(throws(fn() => bot_cfg_save($repo, $id, ['headers'=>[['name'=>'Via', 'field'=>'a']]] + $base)), 'Headers'));
check('rechaza header repetido', throws(fn() => bot_cfg_save($repo, $id, ['headers'=>[['name'=>'X-A', 'field'=>'a'], ['name'=>'x-a', 'field'=>'b']]] + $base)) !== '');
check('rechaza regla del 200 y códigos repetidos', throws(fn() => bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>200, 'max_attempts'=>1, 'retry_delay_min'=>0]]] + $base)) !== '' && throws(fn() => bot_cfg_save($repo, $id, ['rules'=>[['sip_code'=>486, 'max_attempts'=>1, 'retry_delay_min'=>0], ['sip_code'=>486, 'max_attempts'=>1, 'retry_delay_min'=>0]]] + $base)) !== '');
$am = array_column($cfg['amd_actions'], null, 'result');
check('acciones AMD: retry guarda intentos, hangup los pone a 0 y NOTSURE toma su default', $am['MACHINE']['action'] === 'retry' && $am['MACHINE']['max_attempts'] === 2 && $am['HUMAN']['action'] === 'hangup' && $am['HUMAN']['max_attempts'] === 0 && $am['NOTSURE']['action'] === 'transfer');
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
check('dashboard por lista sin mezclar', $dl[$la]['started'] && $dl[$la]['open_calls'] === 2 && !$dl[$lb]['started'] && $dl[$lb]['reason'] === 'Lista detenida' && $dl[$lcc]['reason'] === 'Lista inactiva' && $dl[$la]['totals']['contacts'] === 2);
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
echo $fail ? "$fail FALLOS\n" : "TODO OK\n";
exit($fail ? 1 : 0);
