<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
// Dialer v1 campaign configuration and engine queries (migration 014). Validation lives in bot_ivr/campaign_config_service.php.
trait CampaignConfigQueries {
    private const CONFIG_COLUMNS = ['name','active','call_origin','max_channels','amd_enabled','transfer_carrier_id','transfer_destination','timezone','lead_order','dial_timeout','campaign_cid'];
    public function campaignConfig(int $id): array {
        $config = ['campaign'=>$this->campaign($id)];
        foreach (['headers'=>['campaign_headers','position','position,header_name,lead_field'],
                  'rules'=>['campaign_sip_rules','sip_code','sip_code,max_attempts,retry_delay_min,active'],
                  'amd_actions'=>['campaign_amd_actions','result','result,action,max_attempts,retry_delay_min'],
                  'schedules'=>['campaign_schedules','weekday,start_time','weekday,TIME_FORMAT(start_time,\'%H:%i\') start_time,TIME_FORMAT(end_time,\'%H:%i\') end_time']] as $key=>[$table,$order,$columns]) {
            $s = $this->db->prepare("SELECT $columns FROM zynervox_bot_$table WHERE campaign_id=? ORDER BY $order");
            $s->execute([$id]);
            $config[$key] = $s->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $config;
    }
    /** Replaces the whole configuration atomically. $campaign holds CONFIG_COLUMNS; $rules = ['sip'=>[...], 'amd'=>[...]] (the contract keeps one array); returns the campaign id. */
    public function saveCampaignConfig(?int $id, array $campaign, array $headers, array $rules, array $schedules): int {
        return $this->callAtomic(function() use($id,$campaign,$headers,$rules,$schedules) {
            if ($id === null) {
                $this->db->prepare('INSERT INTO zynervox_bot_campaigns (name,active) VALUES (?,0)')->execute([$campaign['name']]);
                $id = (int)$this->db->lastInsertId();
            } else {
                $s = $this->db->prepare('SELECT campaign_id FROM zynervox_bot_campaigns WHERE campaign_id=? FOR UPDATE');
                $s->execute([$id]);
                if (!$s->fetchColumn()) throw new \RuntimeException('Campaña inexistente.');
            }
            $assignments = [];
            $bindings = [];
            foreach (self::CONFIG_COLUMNS as $column) {
                if (!array_key_exists($column, $campaign)) throw new \RuntimeException('Falta un campo de campaña validado.');
                $assignments[] = $column.'=?';
                $bindings[] = $campaign[$column];
            }
            $bindings[] = $id;
            $this->db->prepare('UPDATE zynervox_bot_campaigns SET '.implode(',', $assignments).' WHERE campaign_id=?')->execute($bindings);
            foreach (['headers','sip_rules','amd_actions','schedules'] as $table) $this->db->prepare("DELETE FROM zynervox_bot_campaign_$table WHERE campaign_id=?")->execute([$id]);
            foreach (array_values($headers) as $position=>$h) $this->db->prepare('INSERT INTO zynervox_bot_campaign_headers (campaign_id,position,header_name,lead_field) VALUES (?,?,?,?)')->execute([$id,$position,$h['name'],$h['field']]);
            foreach ($rules['sip'] ?? [] as $r) $this->db->prepare('INSERT INTO zynervox_bot_campaign_sip_rules (campaign_id,sip_code,max_attempts,retry_delay_min,active) VALUES (?,?,?,?,?)')->execute([$id,$r['sip_code'],$r['max_attempts'],$r['retry_delay_min'],$r['active']]);
            foreach ($rules['amd'] ?? [] as $r) $this->db->prepare('INSERT INTO zynervox_bot_campaign_amd_actions (campaign_id,result,action,max_attempts,retry_delay_min) VALUES (?,?,?,?,?)')->execute([$id,$r['result'],$r['action'],$r['max_attempts'],$r['retry_delay_min']]);
            foreach ($schedules as $w) $this->db->prepare('INSERT INTO zynervox_bot_campaign_schedules (campaign_id,weekday,start_time,end_time) VALUES (?,?,?,?)')->execute([$id,$w['weekday'],$w['start_time'].':00',$w['end_time'].':00']);
            return $id;
        });
    }
    public function campaignAttemptsReport(?int $campaignId, int $limit): array {
        $s = $this->db->prepare('SELECT attempt_id,campaign_id,lead_id,phone,started_at,status,disposition,dial_status,amd_status,amd_cause,sip_cause,hangup_cause AS q850,cause_source,answered_at,billsec
            FROM zynervox_bot_call_attempts WHERE (? IS NULL OR campaign_id=?) ORDER BY attempt_id DESC LIMIT '.$limit);
        $s->execute([$campaignId,$campaignId]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    /** Deletes the campaign and its configuration (headers, schedules, AMD actions, SIP and legacy rules cascade). Attempts, events and dial runs stay as history. */
    public function deleteCampaign(int $id): void {
        $this->callAtomic(function() use($id) {
            $s = $this->db->prepare('SELECT dialer_state FROM zynervox_bot_campaigns WHERE campaign_id=? FOR UPDATE');
            $s->execute([$id]);
            $state = $s->fetchColumn();
            if ($state === false) throw new \RuntimeException('Campaña inexistente.');
            if ($state === 'running') throw new \RuntimeException('Detén la campaña antes de eliminarla.');
            $l = $this->db->prepare('SELECT COUNT(*) FROM zynervox_bot_lists WHERE campaign_id=?');
            $l->execute([$id]);
            if ((int)$l->fetchColumn()) throw new \RuntimeException('Elimina primero las listas de la campaña.');
            $open = $this->db->prepare("SELECT 1 FROM zynervox_bot_call_attempts WHERE campaign_id=? AND finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST' LIMIT 1");
            $open->execute([$id]);
            if ($open->fetchColumn()) throw new \RuntimeException('Espera a que terminen las llamadas antes de eliminar la campaña.');
            $this->db->prepare('DELETE FROM zynervox_bot_campaigns WHERE campaign_id=?')->execute([$id]);
        });
    }
    /** Starts (active lists only) or stops (all lists) every list of a campaign. */
    public function setCampaignListsState(int $campaignId, string $state, string $actor): void {
        if (!in_array($state, ['running','stopped'], true)) throw new \RuntimeException('Estado de lista inválido.');
        $this->db->prepare('UPDATE zynervox_bot_lists SET dialer_state=?,dialer_changed_at=UTC_TIMESTAMP(),dialer_changed_by=? WHERE campaign_id=? AND dialer_state<>? '.($state === 'running' ? 'AND active=1' : ''))->execute([$state,substr($actor,0,80),$campaignId,$state]);
    }
    public function setListDialerState(int $campaignId, int $listId, string $state, string $actor): void {
        if (!in_array($state, ['running','stopped'], true)) throw new \RuntimeException('Estado de lista inválido.');
        $s = $this->db->prepare('UPDATE zynervox_bot_lists SET dialer_state=?,dialer_changed_at=UTC_TIMESTAMP(),dialer_changed_by=? WHERE list_id=? AND campaign_id=?');
        $s->execute([$state,substr($actor,0,80),$listId,$campaignId]);
        $e = $this->db->prepare('SELECT COUNT(*) FROM zynervox_bot_lists WHERE list_id=? AND campaign_id=?');
        $e->execute([$listId,$campaignId]);
        if (!(int)$e->fetchColumn()) throw new \RuntimeException('Lista inexistente en la campaña.');
    }
    public function setListActive(int $campaignId, int $listId, bool $active): void {
        $this->setListDialerState($campaignId, $listId, 'stopped', 'sistema'); // validates the pair
        $this->db->prepare('UPDATE zynervox_bot_lists SET active=? WHERE list_id=? AND campaign_id=?')->execute([$active ? 1 : 0,$listId,$campaignId]);
    }
    public function runningListsCount(int $campaignId): int {
        $s = $this->db->prepare("SELECT COUNT(*) FROM zynervox_bot_lists WHERE campaign_id=? AND active=1 AND dialer_state='running'");
        $s->execute([$campaignId]);
        return (int)$s->fetchColumn();
    }
    public function listState(int $campaignId, int $listId): array {
        $s = $this->db->prepare('SELECT list_id,campaign_id,name,active,dialer_state,max_channels FROM zynervox_bot_lists WHERE list_id=? AND campaign_id=?');
        $s->execute([$listId,$campaignId]);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Lista inexistente en la campaña.');
        return $row;
    }
    public function listEligibleCount(int $campaignId, int $listId, string $order): int {
        $s = $this->db->prepare(preg_replace('/ORDER BY (RAND\(\)|l\.lead_id (DESC|ASC))\s*$/', '', $this->campaignCandidateSql($order, 'COUNT(*)', '', false, $listId)));
        $s->execute([$campaignId]);
        return (int)$s->fetchColumn();
    }
    public function touchDialerHeartbeat(): void {
        $this->db->exec("INSERT INTO zynervox_bot_dialer_settings (name,value) VALUES ('engine_heartbeat',UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=UNIX_TIMESTAMP()");
    }
    public function dialerHeartbeatAge(): ?int {
        $v = $this->db->query("SELECT UNIX_TIMESTAMP()-value FROM zynervox_bot_dialer_settings WHERE name='engine_heartbeat'")->fetchColumn();
        return $v === false ? null : (int)$v;
    }
    /** One row per list with leads and attempt counters, grouped in SQL (light enough for a 5 s refresh). */
    public function dashboardRows(): array {
        $rows = $this->db->query("SELECT c.campaign_id,c.name campaign_name,c.active campaign_active,c.dialer_state campaign_state,c.timezone,c.scheduled,c.start_time,c.end_time,c.lead_order,
                p.list_id,p.name list_name,p.active list_active,p.dialer_state list_state,p.max_channels
            FROM zynervox_bot_campaigns c LEFT JOIN zynervox_bot_lists p ON p.campaign_id=c.campaign_id ORDER BY c.campaign_id,p.list_id")->fetchAll(\PDO::FETCH_ASSOC);
        $leads = []; foreach ($this->db->query("SELECT list_id,COUNT(*) total,SUM(status='NEW') pending,SUM(called_count>0) called FROM zynervox_bot_list GROUP BY list_id")->fetchAll(\PDO::FETCH_ASSOC) as $r) $leads[$r['list_id']] = $r;
        $att = []; foreach ($this->db->query("SELECT list_id,COUNT(*) calls,
                SUM(finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST') open_calls,
                SUM(finished_at IS NOT NULL AND (amd_status='HUMAN' OR (amd_status='' AND disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL')))) human,
                SUM(amd_status='MACHINE') machine, SUM(amd_status='NOTSURE') notsure,
                SUM(amd_status IN ('HUMAN','MACHINE','NOTSURE') OR disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL')) answered,
                SUM(started_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE) calls15,
                SUM(disposition='BUSY') unavailable, SUM(disposition='NOANSWER') noanswer,
                SUM(disposition IN ('FAILED','CONGESTION','CANCELLED')) failed
            FROM zynervox_bot_call_attempts WHERE list_id IS NOT NULL GROUP BY list_id")->fetchAll(\PDO::FETCH_ASSOC) as $r) $att[$r['list_id']] = $r;
        foreach ($rows as &$r) { $r['leads'] = $leads[$r['list_id']] ?? null; $r['att'] = $att[$r['list_id']] ?? null; }
        return $rows;
    }
    private const REPORT_ANSWERED = "(a.amd_status IN ('HUMAN','MACHINE','NOTSURE') OR a.disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL'))";
    private const REPORT_HUMAN = "(a.amd_status='HUMAN' OR (a.amd_status='' AND a.disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL')))";
    /** Normalized filters: from_utc/to_utc (Y-m-d H:i:s, nullable), campaign_id/list_id (nullable), include_test, include_invalid_amd, invalid_before_utc, offset_seconds. */
    private function reportWhere(array $f): array {
        $w = ['a.finished_at IS NOT NULL']; $p = [];
        if ($f['from_utc'] !== null) { $w[] = 'a.started_at>=?'; $p[] = $f['from_utc']; }
        if ($f['to_utc'] !== null) { $w[] = 'a.started_at<?'; $p[] = $f['to_utc']; }
        if ($f['campaign_id'] !== null) { $w[] = 'a.campaign_id=?'; $p[] = $f['campaign_id']; }
        if ($f['list_id'] !== null) { $w[] = 'a.list_id=?'; $p[] = $f['list_id']; }
        if (!$f['include_test']) $w[] = "a.call_id NOT LIKE 'TEST-%'";
        return [implode(' AND ', $w), $p];
    }
    private function reportCounters(array $f): string {
        $v = $f['include_invalid_amd'] ? '1=1' : "a.started_at>='".$f['invalid_before_utc']."'";
        $A = self::REPORT_ANSWERED; $H = self::REPORT_HUMAN;
        return "COUNT(*) calls, COALESCE(SUM($A),0) answered, COALESCE(SUM($H AND $v),0) human, COALESCE(SUM(a.amd_status='MACHINE' AND $v),0) machine, COALESCE(SUM(a.amd_status='NOTSURE' AND $v),0) not_sure,
            COALESCE(SUM(a.disposition='BUSY'),0) unavailable, COALESCE(SUM(a.disposition='NOANSWER'),0) no_answer, COALESCE(SUM(a.disposition IN ('FAILED','CONGESTION','CANCELLED')),0) failed,
            COALESCE(SUM(a.disposition='XFER_OK'),0) xfer_ok, COALESCE(SUM(a.disposition='XFER_FAIL'),0) xfer_fail";
    }
    public function reportAggregates(array $f): array {
        [$where, $params] = $this->reportWhere($f);
        $off = (int)$f['offset_seconds']; $C = $this->reportCounters($f);
        $from = "FROM zynervox_bot_call_attempts a LEFT JOIN zynervox_bot_lists p ON p.list_id=a.list_id LEFT JOIN zynervox_bot_campaigns c ON c.campaign_id=a.campaign_id WHERE $where";
        $q = function(string $sql, array $extra = []) use($params) { $s = $this->db->prepare($sql); $s->execute(array_merge($params, $extra)); return $s->fetchAll(\PDO::FETCH_ASSOC); };
        $out = [];
        $out['kpi'] = $q("SELECT $C, COALESCE(ROUND(AVG(a.billsec)),0) avg_billsec, MIN(DATE_ADD(a.started_at, INTERVAL $off SECOND)) first_call, MAX(DATE_ADD(a.started_at, INTERVAL $off SECOND)) last_call $from")[0];
        $out['by_hour'] = $q("SELECT DATE_FORMAT(DATE_ADD(a.started_at, INTERVAL $off SECOND), '%Y-%m-%d %H:00') hour_local, $C $from GROUP BY hour_local ORDER BY hour_local");
        $out['by_list'] = $q("SELECT a.campaign_id, COALESCE(c.name,'campaña eliminada') campaign, a.list_id, COALESCE(p.name,'lista eliminada') list, p.list_id IS NULL list_deleted, $C $from GROUP BY a.campaign_id, c.name, a.list_id, p.name, p.list_id ORDER BY a.campaign_id, a.list_id");
        $valid = $f['include_invalid_amd'] ? '' : " AND a.started_at>='".$f['invalid_before_utc']."'";
        $out['by_amd'] = $q("SELECT IF(a.amd_cause IN ('TOOLONG','LONGGREETING','INITIALSILENCE','MAXWORDS','MAXWORDLENGTH'), a.amd_cause, a.amd_status) result, a.amd_status, a.amd_cause, COUNT(*) calls $from AND a.amd_status<>''$valid GROUP BY result, a.amd_status, a.amd_cause ORDER BY calls DESC");
        $out['by_cause'] = $q("SELECT a.sip_cause, a.hangup_cause q850, a.disposition, COUNT(*) calls $from GROUP BY a.sip_cause, a.hangup_cause, a.disposition ORDER BY calls DESC");
        return $out;
    }
    public function reportDetail(array $f, int $limit, int $offset): array {
        [$where, $params] = $this->reportWhere($f); $off = (int)$f['offset_seconds'];
        $from = "FROM zynervox_bot_call_attempts a LEFT JOIN zynervox_bot_lists p ON p.list_id=a.list_id LEFT JOIN zynervox_bot_campaigns c ON c.campaign_id=a.campaign_id WHERE $where";
        $t = $this->db->prepare("SELECT COUNT(*) $from"); $t->execute($params);
        $s = $this->db->prepare("SELECT a.attempt_id, DATE_FORMAT(DATE_ADD(a.started_at, INTERVAL $off SECOND), '%Y-%m-%d %H:%i:%s') started_at_local, a.started_at>='".$f['invalid_before_utc']."' amd_valid,
                a.campaign_id, COALESCE(c.name,'campaña eliminada') campaign, a.list_id, COALESCE(p.name,'lista eliminada') list, a.lead_id, a.phone, a.status, a.disposition, a.amd_status, a.amd_cause,
                a.sip_cause, a.hangup_cause q850, a.billsec, a.cause_source $from ORDER BY a.attempt_id DESC LIMIT ".(int)$limit." OFFSET ".(int)$offset);
        $s->execute($params);
        return ['total'=>(int)$t->fetchColumn(), 'rows'=>$s->fetchAll(\PDO::FETCH_ASSOC)];
    }
    // --- AMD parameters per campaign and AMD audit (migration 018). Logic and the STT batch live in app/web/marcador/. ---
    private const AMD_PARAM_COLUMNS = ['initial_silence','greeting','after_greeting_silence','total_analysis_time','minimum_word_length','between_words_silence','maximum_number_of_words','silence_threshold','maximum_word_length'];
    /** Stored parameters of the campaign (empty array when it has no row yet: the caller applies the defaults). */
    public function campaignAmdParams(int $id): array {
        try {
            $s = $this->db->prepare('SELECT '.implode(',', self::AMD_PARAM_COLUMNS).' FROM zynervox_bot_campaign_amd_params WHERE campaign_id=?');
            $s->execute([$id]);
            $row = $s->fetch(\PDO::FETCH_ASSOC);
            return $row ? array_map('intval', $row) : [];
        } catch (\PDOException $e) { error_log('ZV2 amd_params (migración 018 pendiente?): '.$e->getMessage()); return []; } // never stops the dialing
    }
    public function setCampaignAmdParams(int $id, array $params): void {
        $this->campaign($id);
        $cols = self::AMD_PARAM_COLUMNS;
        $sql = 'INSERT INTO zynervox_bot_campaign_amd_params (campaign_id,'.implode(',', $cols).') VALUES (?'.str_repeat(',?', count($cols)).') ON DUPLICATE KEY UPDATE '.implode(',', array_map(fn($c) => "$c=VALUES($c)", $cols));
        $this->db->prepare($sql)->execute(array_merge([$id], array_map(fn($c) => $params[$c], $cols)));
    }
    /** Creates the audit row of a launched call with the parameter snapshot. Idempotent per call_id. */
    public function openAmdAudit(array $call, int $campaignId, array $params): void {
        $cols = self::AMD_PARAM_COLUMNS;
        $sql = 'INSERT IGNORE INTO zynervox_bot_amd_audit (call_id,campaign_id,list_id,lead_id,'.implode(',', $cols).') VALUES (?,?,?,?'.str_repeat(',?', count($cols)).')';
        $this->db->prepare($sql)->execute(array_merge([$call['call_id'], $campaignId, $call['list_id'], $call['lead_id']], array_map(fn($c) => $params[$c], $cols)));
    }
    /** Extra facts that arrive with the call (decided_ms, audio_path, audio_seconds). Only the given keys change. */
    public function recordAmdAudit(string $callId, array $data): void {
        $allowed = ['decided_ms','audio_path','audio_seconds','reviewed_class'];
        $set = []; $vals = [];
        foreach ($allowed as $k) if (array_key_exists($k, $data)) { $set[] = "$k=?"; $vals[] = $data[$k]; }
        if (!$set) return;
        $vals[] = $callId;
        $this->db->prepare('UPDATE zynervox_bot_amd_audit SET '.implode(',', $set).' WHERE call_id=?')->execute($vals);
    }
    /** Closes the loop for finished calls: drops rows of calls never answered, copies result, cause, attempt id and recording path for the answered ones. */
    public function syncAmdAudit(): array {
        $answered = "(t.amd_status IN ('HUMAN','MACHINE','NOTSURE') OR t.disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL'))";
        $drop = $this->db->exec("DELETE u FROM zynervox_bot_amd_audit u JOIN zynervox_bot_call_attempts t ON t.call_id=u.call_id WHERE u.audit_state='open' AND t.finished_at IS NOT NULL AND NOT $answered");
        $ready = $this->db->exec("UPDATE zynervox_bot_amd_audit u JOIN zynervox_bot_call_attempts t ON t.call_id=u.call_id
            SET u.audit_state='ready', u.attempt_id=t.attempt_id, u.amd_status=t.amd_status, u.amd_cause=t.amd_cause,
                u.audio_path=COALESCE(NULLIF(t.recording_path,''),u.audio_path),
                u.stt_status=IF(COALESCE(NULLIF(t.recording_path,''),u.audio_path) IS NULL,'no_audio','pending')
            WHERE u.audit_state='open' AND t.finished_at IS NOT NULL AND $answered");
        return ['dropped'=>(int)$drop, 'ready'=>(int)$ready];
    }
    public function pendingSttAudits(int $limit): array {
        $s = $this->db->prepare("SELECT audit_id,call_id,audio_path FROM zynervox_bot_amd_audit WHERE audit_state='ready' AND stt_status='pending' AND audio_path IS NOT NULL ORDER BY audit_id LIMIT ".max(1, min(500, $limit)));
        $s->execute();
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function countPendingSttAudits(): int {
        return (int)$this->db->query("SELECT COUNT(*) FROM zynervox_bot_amd_audit WHERE audit_state='ready' AND stt_status='pending' AND audio_path IS NOT NULL")->fetchColumn();
    }
    public function saveSttResult(int $auditId, string $status, string $text, string $engine, string $error, ?float $seconds): void {
        if (!in_array($status, ['done','error','no_audio'], true)) throw new \RuntimeException('Estado STT inválido.');
        $this->db->prepare('UPDATE zynervox_bot_amd_audit SET stt_status=?,stt_text=?,stt_engine=?,stt_error=?,audio_seconds=COALESCE(?,audio_seconds),stt_at=UTC_TIMESTAMP() WHERE audit_id=?')
            ->execute([$status, $status === 'done' ? $text : null, substr($engine, 0, 60), substr($error, 0, 255), $seconds, $auditId]);
    }
    public function setDialerState(int $id, string $state, string $actor): void {
        if (!in_array($state, ['running','stopped'], true)) throw new \RuntimeException('Estado de marcador inválido.');
        $this->campaign($id);
        $this->db->prepare('UPDATE zynervox_bot_campaigns SET dialer_state=?,dialer_changed_at=UTC_TIMESTAMP(),dialer_changed_by=? WHERE campaign_id=?')->execute([$state,substr($actor,0,80),$id]);
    }
    /** Keys available as header sources: fixed fields plus the extra columns found in the campaign's lists. */
    public function campaignLeadFields(int $id): array {
        $s = $this->db->prepare("SELECT l.extra_json FROM zynervox_bot_list l JOIN zynervox_bot_lists p ON p.list_id=l.list_id WHERE p.campaign_id=? AND l.extra_json IS NOT NULL AND l.extra_json<>'' ORDER BY l.lead_id LIMIT 200");
        $s->execute([$id]);
        $keys = [];
        foreach ($s->fetchAll(\PDO::FETCH_COLUMN) as $json) foreach (array_keys((array)json_decode((string)$json, true)) as $key) $keys[(string)$key] = true;
        unset($keys['numero'], $keys['phone'], $keys['customer_name']);
        return array_merge(['phone','customer_name'], array_keys($keys));
    }
    public function dialerCampaignIds(?int $only = null): array {
        if ($only !== null) return [$only];
        return array_map('intval', $this->db->query("SELECT campaign_id FROM zynervox_bot_campaigns WHERE dialer_state='running' AND active=1 ORDER BY campaign_id")->fetchAll(\PDO::FETCH_COLUMN));
    }
    public function campaignActiveCalls(int $id): int {
        $s = $this->db->prepare("SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE campaign_id=? AND finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST'");
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    }
    private const OPEN_ATTEMPT = "finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST'";
    public function dialerMaxTotalChannels(): int {
        $v = $this->db->query("SELECT value FROM zynervox_bot_dialer_settings WHERE name='max_total_channels'")->fetchColumn();
        return $v === false ? 10 : (int)$v;
    }
    public function setDialerMaxTotalChannels(int $n): void {
        $this->db->prepare("INSERT INTO zynervox_bot_dialer_settings (name,value) VALUES ('max_total_channels',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$n]);
    }
    /** Hot change: takes effect on the engine's next pass, the campaign keeps running. */
    public function setListChannels(int $campaignId, int $listId, int $n): void {
        $s = $this->db->prepare('UPDATE zynervox_bot_lists SET max_channels=? WHERE list_id=? AND campaign_id=?');
        $s->execute([$n,$listId,$campaignId]);
        $e = $this->db->prepare('SELECT COUNT(*) FROM zynervox_bot_lists WHERE list_id=? AND campaign_id=?');
        $e->execute([$listId,$campaignId]);
        if (!(int)$e->fetchColumn()) throw new \RuntimeException('Lista inexistente en la campaña.');
    }
    /** Every list with its channel limit and the calls open right now. */
    public function listsChannels(): array {
        return $this->db->query('SELECT p.list_id,p.campaign_id,p.name,p.active,p.dial_prefix,p.max_channels,
            (SELECT COUNT(*) FROM zynervox_bot_list l WHERE l.list_id=p.list_id) leads_count,
            (SELECT COUNT(*) FROM zynervox_bot_call_attempts a WHERE a.list_id=p.list_id AND a.'.str_replace(' AND ',' AND a.',self::OPEN_ATTEMPT).') open_calls
            FROM zynervox_bot_lists p ORDER BY p.campaign_id,p.list_id')->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function openCallsTotal(): int {
        return (int)$this->db->query('SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE '.self::OPEN_ATTEMPT)->fetchColumn();
    }
    private function campaignCandidateSql(string $order, string $select, string $tail, bool $onlyStarted = true, ?int $listId = null): string {
        $orderBy = $order === 'RANDOM' ? 'RAND()' : 'l.lead_id '.($order === 'DOWN' ? 'DESC' : 'ASC');
        $OPEN = "a2.finished_at IS NULL AND a2.end_source<>'RECOVERY' AND a2.status<>'LOST'";
        return "SELECT $select FROM zynervox_bot_list l JOIN zynervox_bot_lists p ON p.list_id=l.list_id AND p.campaign_id=? AND p.active=1".($onlyStarted ? " AND p.dialer_state='running'" : '').($listId !== null ? ' AND p.list_id='.$listId : '')."
            LEFT JOIN zynervox_bot_campaign_sip_rules sr ON sr.campaign_id=p.campaign_id AND sr.active=1 AND l.status IN ('B','NA','UNAV','CONG','CANCEL','FAIL')
                AND sr.sip_code=IF(l.last_sip_code BETWEEN 100 AND 199,487,l.last_sip_code)
            LEFT JOIN zynervox_bot_campaign_amd_actions aa ON aa.campaign_id=p.campaign_id AND aa.action='retry' AND l.status IN ('AA','ANSWER') AND aa.result=(
                SELECT IF(x.amd_cause IN ('TOOLONG','LONGGREETING','INITIALSILENCE','MAXWORDS','MAXWORDLENGTH'),x.amd_cause,x.amd_status)
                FROM zynervox_bot_call_attempts x WHERE x.lead_id=l.lead_id AND x.finished_at IS NOT NULL ORDER BY x.attempt_id DESC LIMIT 1)
            WHERE (l.status='NEW'
                OR (sr.sip_code IS NOT NULL AND l.called_count<sr.max_attempts AND l.last_call_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL sr.retry_delay_min MINUTE))
                OR (aa.result IS NOT NULL AND l.called_count<aa.max_attempts AND l.last_call_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL aa.retry_delay_min MINUTE)))
              ".($listId === null ? "AND (SELECT COUNT(*) FROM zynervox_bot_call_attempts a2 WHERE a2.list_id=l.list_id AND $OPEN) < p.max_channels" : '')."
              AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts a WHERE a.lead_id=l.lead_id AND a.finished_at IS NULL AND a.end_source<>'RECOVERY' AND a.status<>'LOST')
            ORDER BY $orderBy $tail";
    }
    /** Read-only: next eligible leads (for --dry-run), no locks and no state change. */
    public function previewCampaignLeads(int $id, string $order, int $limit): array {
        $s = $this->db->prepare($this->campaignCandidateSql($order, 'l.lead_id,l.list_id,l.phone,l.customer_name,l.extra_json,l.status,l.called_count,p.max_channels-(SELECT COUNT(*) FROM zynervox_bot_call_attempts a3 WHERE a3.list_id=l.list_id AND a3.finished_at IS NULL AND a3.end_source<>\'RECOVERY\' AND a3.status<>\'LOST\') list_free', 'LIMIT '.max(1, min(500, $limit))));
        $s->execute([$id]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function countEligibleLeads(int $id, string $order): int {
        $s = $this->db->prepare(preg_replace('/ORDER BY (RAND\(\)|l\.lead_id (DESC|ASC))\s*$/', '', $this->campaignCandidateSql($order, 'COUNT(*)', '', false)));
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    }
    /**
     * Claims the next eligible lead: creates the attempt (status CALL) in one transaction.
     * $resolve(lead) returns the payload for the call or throws to reject the lead: it is then marked $rejectStatus
     * (final: no retry rule) so it is never picked again silently, and the next candidate is tried.
     * @return array{call_id:string,lead_id:int,list_id:int,phone:string,payload:mixed}|null
     */
    public function claimCampaignLead(int $id, string $order, string $callId, callable $resolve, string $rejectStatus = 'HDR_ERR'): ?array {
        for ($tries = 0; $tries < 20; $tries++) {
            $claimed = $this->callAtomic(function() use($id,$order,$callId,$resolve,$rejectStatus) {
                $s = $this->db->prepare($this->campaignCandidateSql($order, 'l.lead_id,l.list_id,l.phone,l.customer_name,l.extra_json', 'LIMIT 1 FOR UPDATE'));
                $s->execute([$id]);
                $lead = $s->fetch(\PDO::FETCH_ASSOC);
                if (!$lead) return null;
                try { $payload = $resolve($lead); }
                catch (\InvalidArgumentException|\RuntimeException $e) {
                    $this->db->prepare('UPDATE zynervox_bot_list SET status=? WHERE lead_id=?')->execute([$rejectStatus,$lead['lead_id']]);
                    error_log('ZV2 dialer lead '.$lead['lead_id'].' rejected: '.$e->getMessage());
                    return false;
                }
                if ($this->startCall((int)$lead['list_id'], $lead['phone'], $callId) !== (int)$lead['lead_id']) throw new \RuntimeException('Identidad de lead ambigua.');
                return ['call_id'=>$callId,'lead_id'=>(int)$lead['lead_id'],'list_id'=>(int)$lead['list_id'],'phone'=>$lead['phone'],'payload'=>$payload];
            });
            if ($claimed !== false) return $claimed;
        }
        return null;
    }
    public function rejectCampaignClaim(string $callId): void {
        $this->callAtomic(function() use($callId) {
            $s = $this->db->prepare('SELECT caller_channel,finished_at FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE');
            $s->execute([$callId]);
            $call = $s->fetch();
            if (!$call || $call['caller_channel'] !== '' || $call['finished_at'] !== null) return;
            $this->finishCall($callId, 'CHANUNAVAIL', '', '', 0, false, ['disposition'=>'FAILED','cause_source'=>'agi','sip_cause'=>'AMI Originate rechazado']);
            $this->db->prepare("UPDATE zynervox_bot_call_attempts SET end_source='AMI_REJECTED',duration_seconds=GREATEST(0,TIMESTAMPDIFF(SECOND,started_at,finished_at)),billsec=0 WHERE call_id=?")->execute([$callId]);
            $this->recordCallEvent($callId, 'DIAL_REJECTED', hash('sha256', $callId.'|DIAL_REJECTED'), time(), ['reason'=>'AMI rejected originate']);
        });
    }
    /** Closes an attempt that never ran dialplan (no answer, busy, congestion, failure) with the cause reported by AMI. */
    public function finishUnansweredCall(string $callId, string $dialStatus, int $q850, string $sipText, string $disposition): void {
        $this->callAtomic(function() use($callId,$dialStatus,$q850,$sipText,$disposition) {
            $s = $this->db->prepare('SELECT finished_at FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE');
            $s->execute([$callId]);
            $call = $s->fetch();
            if (!$call || $call['finished_at'] !== null) return;
            $this->finishCall($callId, $dialStatus, '', '', $q850, false, ['sip_cause'=>$sipText, 'cause_source'=>'ami', 'disposition'=>$disposition]);
            $this->db->prepare("UPDATE zynervox_bot_call_attempts SET end_source='AMI',duration_seconds=GREATEST(0,TIMESTAMPDIFF(SECOND,started_at,finished_at)),billsec=0 WHERE call_id=?")->execute([$callId]);
        });
    }
}
