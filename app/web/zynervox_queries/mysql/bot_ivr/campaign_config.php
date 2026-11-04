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
    /**
     * Dashboard rows: ACTIVE lists only, one per list, from the incremental summary plus the calls open right now (indexed, tiny) and the last 15 minutes.
     * Lists that have no summary row yet get their totals from the leads table.
     */
    public function dashboardRows(): array {
        $rows = $this->db->query("SELECT c.campaign_id,c.name campaign_name,c.active campaign_active,c.dialer_state campaign_state,c.timezone,c.scheduled,c.start_time,c.end_time,c.lead_order,
                p.list_id,p.name list_name,p.active list_active,p.dialer_state list_state,p.max_channels,
                u.contacts,u.not_called,u.calls,u.answered,u.human,u.machine,u.not_sure,u.unavailable,u.no_answer,u.failed,u.xfer_ok,u.xfer_fail,u.counted_attempt_id,u.updated_at summary_at
            FROM zynervox_bot_campaigns c JOIN zynervox_bot_lists p ON p.campaign_id=c.campaign_id AND p.active=1
            LEFT JOIN zynervox_bot_list_summary u ON u.list_id=p.list_id ORDER BY c.campaign_id,p.list_id")->fetchAll(\PDO::FETCH_ASSOC);
        $open = []; foreach ($this->db->query("SELECT list_id,COUNT(*) n FROM zynervox_bot_call_attempts WHERE finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST' GROUP BY list_id")->fetchAll(\PDO::FETCH_ASSOC) as $r) $open[$r['list_id']] = (int)$r['n'];
        $recent = []; foreach ($this->db->query("SELECT list_id,COUNT(*) n FROM zynervox_bot_call_attempts WHERE started_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE GROUP BY list_id")->fetchAll(\PDO::FETCH_ASSOC) as $r) $recent[$r['list_id']] = (int)$r['n'];
        $missing = array_values(array_unique(array_map(fn($r) => (int)$r['list_id'], array_filter($rows, fn($r) => $r['contacts'] === null))));
        $counts = [];
        if ($missing) {
            $in = implode(',', $missing);
            foreach ($this->db->query("SELECT list_id,COUNT(*) total,SUM(status='NEW') pending FROM zynervox_bot_list WHERE list_id IN ($in) GROUP BY list_id")->fetchAll(\PDO::FETCH_ASSOC) as $r) $counts[$r['list_id']] = $r;
        }
        foreach ($rows as &$r) {
            $id = $r['list_id'];
            $total = $r['contacts'] !== null ? (int)$r['contacts'] : (int)($counts[$id]['total'] ?? 0);
            $pending = $r['contacts'] !== null ? (int)$r['not_called'] : (int)($counts[$id]['pending'] ?? 0);
            $r['leads'] = ['total'=>$total, 'pending'=>$pending, 'called'=>$total - $pending];
            $r['att'] = ['calls'=>(int)$r['calls'], 'open_calls'=>$open[$id] ?? 0, 'human'=>(int)$r['human'], 'machine'=>(int)$r['machine'], 'notsure'=>(int)$r['not_sure'], 'answered'=>(int)$r['answered'],
                'unavailable'=>(int)$r['unavailable'], 'noanswer'=>(int)$r['no_answer'], 'failed'=>(int)$r['failed'], 'calls15'=>$recent[$id] ?? 0];
        }
        unset($r);
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
        if (empty($f['skip_totals'])) $out['kpi'] = $q("SELECT $C, COALESCE(ROUND(AVG(a.billsec)),0) avg_billsec, MIN(DATE_ADD(a.started_at, INTERVAL $off SECOND)) first_call, MAX(DATE_ADD(a.started_at, INTERVAL $off SECOND)) last_call $from")[0];
        $out['by_hour'] = $q("SELECT DATE_FORMAT(DATE_ADD(a.started_at, INTERVAL $off SECOND), '%Y-%m-%d %H:00') hour_local, $C $from GROUP BY hour_local ORDER BY hour_local");
        if (empty($f['skip_totals'])) $out['by_list'] = $q("SELECT a.campaign_id, COALESCE(c.name,'campaña eliminada') campaign, a.list_id, COALESCE(p.name,'lista eliminada') list, p.list_id IS NULL list_deleted, $C $from GROUP BY a.campaign_id, c.name, a.list_id, p.name, p.list_id ORDER BY a.campaign_id, a.list_id");
        $valid = $f['include_invalid_amd'] ? '' : " AND a.started_at>='".$f['invalid_before_utc']."'";
        $out['by_amd'] = $q("SELECT IF(a.amd_cause IN ('TOOLONG','LONGGREETING','INITIALSILENCE','MAXWORDS','MAXWORDLENGTH'), a.amd_cause, a.amd_status) result, a.amd_status, a.amd_cause, COUNT(*) calls $from AND a.amd_status<>''$valid GROUP BY result, a.amd_status, a.amd_cause ORDER BY calls DESC");
        $out['by_cause'] = $q("SELECT a.sip_cause, a.hangup_cause q850, a.disposition, COUNT(*) calls $from GROUP BY a.sip_cause, a.hangup_cause, a.disposition ORDER BY calls DESC");
        return $out;
    }
    /** Extra report series (same filters as reportAggregates): daily, weekday x hour, per-lead attempts, SIP codes, AMD by cause, per carrier. */
    public function reportExtra(array $f): array {
        [$where, $params] = $this->reportWhere($f);
        $off = (int)$f['offset_seconds']; $C = $this->reportCounters($f);
        $loc = "DATE_ADD(a.started_at, INTERVAL $off SECOND)";
        $from = "FROM zynervox_bot_call_attempts a WHERE $where";
        $q = function(string $sql) use($params) { $s = $this->db->prepare($sql); $s->execute($params); return $s->fetchAll(\PDO::FETCH_ASSOC); };
        $valid = $f['include_invalid_amd'] ? '' : " AND a.started_at>='".$f['invalid_before_utc']."'";
        $out = [];
        $out['by_day'] = $q("SELECT DATE_FORMAT($loc, '%Y-%m-%d') day_local, $C $from GROUP BY day_local ORDER BY day_local");
        $out['by_weekday_hour'] = $q("SELECT WEEKDAY($loc)+1 weekday, HOUR($loc) hour_local, $C $from GROUP BY weekday, hour_local ORDER BY weekday, hour_local");
        $out['funnel'] = $q("SELECT $C $from")[0];
        $out['attempts_per_lead'] = $q("SELECT t.list_id, LEAST(t.c,4) attempts, COUNT(*) leads FROM (SELECT a.list_id, a.lead_id, COUNT(*) c $from GROUP BY a.list_id, a.lead_id) t GROUP BY t.list_id, attempts ORDER BY t.list_id, attempts");
        $A = self::REPORT_ANSWERED;
        $out['first_contact_attempt'] = $q("SELECT m.n attempt_no, COUNT(*) leads FROM (SELECT x.lead_id, MIN(x.n) n FROM (SELECT a.lead_id, ROW_NUMBER() OVER (PARTITION BY a.lead_id ORDER BY a.attempt_id) n, ($A) ans $from) x WHERE x.ans=1 GROUP BY x.lead_id) m GROUP BY m.n ORDER BY m.n");
        $out['by_sip_code'] = $q("SELECT COALESCE(REGEXP_SUBSTR(a.sip_cause, '[0-9]{3}'), '') code, a.disposition, COUNT(*) calls $from GROUP BY code, a.disposition ORDER BY calls DESC");
        $out['amd_by_cause'] = $q("SELECT a.amd_status, a.amd_cause, COUNT(*) calls $from AND a.amd_status<>''$valid GROUP BY a.amd_status, a.amd_cause ORDER BY calls DESC");
        $out['by_carrier'] = $q("SELECT a.carrier, $C $from GROUP BY a.carrier ORDER BY calls DESC");
        return $out;
    }
    // --- zypad (migration 021): word catalog, per-attempt zypad facts and the report by dial mode ---
    public function zypadWords(): array {
        return $this->db->query('SELECT word_id,word,category,active,updated_at,updated_by FROM zynervox_bot_zypad_words ORDER BY word_id')->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function zypadWordSave(string $word, string $category, bool $active, string $actor): int {
        $this->db->prepare('INSERT INTO zynervox_bot_zypad_words (word,category,active,updated_at,updated_by) VALUES (?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE category=VALUES(category),active=VALUES(active),updated_at=UTC_TIMESTAMP(),updated_by=VALUES(updated_by)')
            ->execute([$word, $category, $active ? 1 : 0, $actor]);
        $s = $this->db->prepare('SELECT word_id FROM zynervox_bot_zypad_words WHERE word=?'); $s->execute([$word]);
        return (int)$s->fetchColumn();
    }
    public function zypadWordDelete(int $wordId): bool {
        $s = $this->db->prepare('DELETE FROM zynervox_bot_zypad_words WHERE word_id=? AND word_id<>1'); $s->execute([$wordId]);
        return $s->rowCount() > 0;
    }
    /** Highest updated_at of the catalog (UTC string): the bridge can compare it to know when to reload. */
    public function zypadCatalogVersion(): string {
        return (string)$this->db->query('SELECT COALESCE(MAX(updated_at),"") FROM zynervox_bot_zypad_words')->fetchColumn();
    }
    /** Zypad facts delivered at closing; returns the attempt count found (0 = unknown call_id). detectado_por/momento/corte_por are derived here from the attempt itself. */
    public function recordZypad(string $callId, array $d): int {
        // detectado_por / momento / corte_por: the given value wins (even ''), null derives it from the attempt.
        $s = $this->db->prepare("UPDATE zynervox_bot_call_attempts SET zypad_call_id=?,palabra=?,palabra_epoch_ms=COALESCE(?,palabra_epoch_ms),palabra_audio_ms=COALESCE(?,palabra_audio_ms),segundo=COALESCE(?,segundo),preanswer_audio=?,preanswer_bytes=COALESCE(?,preanswer_bytes),first_audio_epoch_ms=COALESCE(?,first_audio_epoch_ms),answer_epoch_ms=COALESCE(?,answer_epoch_ms),
            detectado_por=COALESCE(?,IF(?<>'','ZYPAD',IF(amd_status='MACHINE','AMD','NINGUNO'))), momento=COALESCE(?,IF(?<>'',?,IF(amd_status='MACHINE','POST',''))),
            corte_por=COALESCE(?,IF(dial_mode='ZYPAD_SOMBRA','NINGUNO',corte_por)) WHERE call_id=?");
        $s->execute([$d['zypad_call_id'], $d['palabra'], $d['palabra_epoch_ms'], $d['palabra_audio_ms'], $d['segundo'], $d['preanswer_audio'], $d['preanswer_bytes'], $d['first_audio_epoch_ms'], $d['answer_epoch_ms'],
            $d['detectado_por'] ?? null, $d['palabra'], $d['momento'] ?? null, $d['momento_palabra'] ?? '', $d['momento_palabra'] ?? '', $d['corte_por'] ?? null, $callId]);
        if ($s->rowCount() > 0) return $s->rowCount();
        $e = $this->db->prepare('SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE call_id=?'); $e->execute([$callId]); // unchanged values also give 0 rows
        return (int)$e->fetchColumn();
    }
    /** dial_mode from the campaign origin for attempts not stamped yet. $map: origin => mode. */
    public function stampDialModes(array $map): int {
        $case = 'CASE c.call_origin'; $p = [];
        foreach ($map as $origin=>$mode) { $case .= ' WHEN ? THEN ?'; $p[] = (string)$origin; $p[] = $mode; }
        $case .= " ELSE 'AMD' END";
        $s = $this->db->prepare("UPDATE zynervox_bot_call_attempts t JOIN zynervox_bot_campaigns c ON c.campaign_id=t.campaign_id SET t.dial_mode=$case WHERE t.dial_mode=''");
        $s->execute($p);
        return $s->rowCount();
    }
    /** Comparison by dial mode (same filters as the report, plus optional dial_mode). */
    public function dialModeReport(array $f): array {
        [$where, $params] = $this->reportWhere($f);
        if (!empty($f['dial_mode'])) { $where .= ' AND a.dial_mode=?'; $params[] = $f['dial_mode']; }
        $v = $f['include_invalid_amd'] ? '1=1' : "a.started_at>='".$f['invalid_before_utc']."'";
        $zy = "(a.detectado_por='ZYPAD' OR a.corte_por='ZYPAD')"; $am = "(a.amd_status='MACHINE' AND $v)";
        $sql = "SELECT IF(a.dial_mode='','AMD',a.dial_mode) dial_mode, COUNT(*) calls,
            COALESCE(SUM(a.preanswer_audio='SI'),0) preanswer_audio,
            COALESCE(SUM(a.amd_status<>'' AND $v),0) amd_decided,
            COALESCE(SUM($am),0) amd_machine, COALESCE(SUM($zy),0) zypad_hits,
            COALESCE(SUM(a.result_final='CASILLA_VOZ'),0) casilla_voz,
            COALESCE(SUM($zy AND $am),0) both_casilla, COALESCE(SUM($zy AND NOT $am),0) only_zypad, COALESCE(SUM(NOT $zy AND $am),0) only_amd,
            COALESCE(SUM($zy AND NOT $am AND a.amd_status='HUMAN' AND $v),0) only_zypad_amd_human,
            COALESCE(SUM(a.detectado_por='ZYPAD' AND a.momento='PRE' AND a.palabra_epoch_ms IS NOT NULL AND a.answer_epoch_ms IS NOT NULL),0) saved_n,
            COALESCE(SUM(IF(a.detectado_por='ZYPAD' AND a.momento='PRE' AND a.palabra_epoch_ms IS NOT NULL AND a.answer_epoch_ms IS NOT NULL, GREATEST(0, CAST(a.answer_epoch_ms AS SIGNED)-CAST(a.palabra_epoch_ms AS SIGNED))/1000, 0)),0) saved_seconds
            FROM zynervox_bot_call_attempts a WHERE $where GROUP BY dial_mode ORDER BY dial_mode";
        $s = $this->db->prepare($sql); $s->execute($params);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    // --- Distribution by contact (migration 022). The category rules are marcador_contact_category_sql(). ---
    /** Contacts of one list by category of their last attempt (only contacts with attempts; TEST-% excluded). */
    public function contactDistributionOfList(int $listId): array {
        $cat = marcador_contact_category_sql();
        $s = $this->db->prepare("SELECT t.cat, COUNT(*) n FROM (SELECT $cat cat FROM zynervox_bot_call_attempts a JOIN (SELECT MAX(attempt_id) id FROM zynervox_bot_call_attempts WHERE list_id=? AND call_id NOT LIKE 'TEST-%' GROUP BY lead_id) m ON m.id=a.attempt_id) t GROUP BY t.cat");
        $s->execute([$listId]);
        $o = []; foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) $o[$r['cat']] = (int)$r['n'];
        return $o;
    }
    private function contactWhere(array $f): array {
        $w = ["a.call_id NOT LIKE 'TEST-%'"]; $p = [];
        if ($f['from_utc'] !== null) { $w[] = 'a.started_at>=?'; $p[] = $f['from_utc']; }
        if ($f['to_utc'] !== null) { $w[] = 'a.started_at<?'; $p[] = $f['to_utc']; }
        if ($f['campaign_id'] !== null) { $w[] = 'a.campaign_id=?'; $p[] = $f['campaign_id']; }
        if ($f['list_id'] !== null) { $w[] = 'a.list_id=?'; $p[] = $f['list_id']; }
        return [implode(' AND ', $w), $p];
    }
    /** With a date range: per list, contacts by category of their last attempt INSIDE the range. Rows: list_id, cat, n. */
    public function contactDistributionRange(array $f): array {
        [$w, $p] = $this->contactWhere($f);
        $cat = marcador_contact_category_sql();
        $s = $this->db->prepare("SELECT t.list_id, t.cat, COUNT(*) n FROM (SELECT a.list_id, $cat cat FROM zynervox_bot_call_attempts a JOIN (SELECT MAX(a.attempt_id) id FROM zynervox_bot_call_attempts a WHERE $w GROUP BY a.list_id, a.lead_id) m ON m.id=a.attempt_id) t GROUP BY t.list_id, t.cat");
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    /**
     * Zypad vs AMD per CONTACT (last attempt in range, zypad dial modes only): casilla by zypad only, AMD only or both, plus zypad hits with AMD HUMAN (possible false cut).
     * Rows per dial_mode: contacts (N), casilla_zypad, casilla_amd, ambos, solo_zypad, solo_amd, solo_zypad_amd_humano.
     */
    public function zypadAmdByContact(array $f): array {
        [$w, $p] = $this->contactWhere($f);
        $cat = marcador_contact_category_sql();
        $zy = "(a.detectado_por='ZYPAD' OR a.corte_por='ZYPAD')"; $am = "a.amd_status='MACHINE'";
        // solo_zypad = zypad heard the word and the AMD did not say MACHINE. It splits in three, always adding up: AMD said HUMAN, the call was never answered, and the rest
        // (NOTSURE, answered without AMD result, call still in progress, or cut by zypad with no AMD machine).
        $s = $this->db->prepare("SELECT t.dial_mode, COUNT(*) contacts, COALESCE(SUM(t.zy),0) casilla_zypad, COALESCE(SUM(t.am),0) casilla_amd, COALESCE(SUM(t.zy AND t.am),0) ambos,
                COALESCE(SUM(t.zy AND NOT t.am),0) solo_zypad, COALESCE(SUM(NOT t.zy AND t.am),0) solo_amd,
                COALESCE(SUM(t.zy AND NOT t.am AND t.cat='humano'),0) solo_zypad_amd_humano,
                COALESCE(SUM(t.zy AND NOT t.am AND t.cat IN ('no_disponible','sin_respuesta','ocupado','errores')),0) solo_zypad_sin_contestar,
                COALESCE(SUM(t.zy AND NOT t.am AND t.cat NOT IN ('humano','no_disponible','sin_respuesta','ocupado','errores')),0) solo_zypad_otros
            FROM (SELECT a.dial_mode, $zy zy, $am am, $cat cat FROM zynervox_bot_call_attempts a JOIN (SELECT MAX(a.attempt_id) id FROM zynervox_bot_call_attempts a WHERE $w AND a.dial_mode LIKE 'ZYPAD%' GROUP BY a.list_id, a.lead_id) m ON m.id=a.attempt_id) t
            GROUP BY t.dial_mode ORDER BY t.dial_mode");
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    /** Contacts by weekday and hour (Lima) of their LAST attempt in the filters: contacts, answered (a person, a machine, not sure or answered without AMD), human, casilla. */
    public function contactWeekdayHour(array $f): array {
        [$w, $p] = $this->contactWhere($f);
        $off = (int)$f['offset_seconds']; $cat = marcador_contact_category_sql();
        $loc = "DATE_ADD(a.started_at, INTERVAL $off SECOND)";
        $s = $this->db->prepare("SELECT WEEKDAY($loc)+1 weekday, HOUR($loc) hour_local, COUNT(*) contacts,
                SUM(t.cat IN ('humano','casilla_voz','no_seguro','contestada_sin_amd')) answered, SUM(t.cat='humano') human, SUM(t.cat='casilla_voz') casilla
            FROM (SELECT a.attempt_id, $cat cat FROM zynervox_bot_call_attempts a JOIN (SELECT MAX(a.attempt_id) id FROM zynervox_bot_call_attempts a WHERE $w GROUP BY a.list_id, a.lead_id) m ON m.id=a.attempt_id) t
            JOIN zynervox_bot_call_attempts a ON a.attempt_id=t.attempt_id GROUP BY weekday, hour_local ORDER BY weekday, hour_local");
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function reportDetail(array $f, int $limit, int $offset): array {
        [$where, $params] = $this->reportWhere($f); $off = (int)$f['offset_seconds'];
        $from = "FROM zynervox_bot_call_attempts a LEFT JOIN zynervox_bot_lists p ON p.list_id=a.list_id LEFT JOIN zynervox_bot_campaigns c ON c.campaign_id=a.campaign_id WHERE $where";
        $t = $this->db->prepare("SELECT COUNT(*) $from"); $t->execute($params);
        $s = $this->db->prepare("SELECT a.attempt_id, DATE_FORMAT(DATE_ADD(a.started_at, INTERVAL $off SECOND), '%Y-%m-%d %H:%i:%s') started_at_local, a.started_at>='".$f['invalid_before_utc']."' amd_valid,
                a.campaign_id, COALESCE(c.name,'campaña eliminada') campaign, a.list_id, COALESCE(p.name,'lista eliminada') list, a.lead_id, a.phone, a.status, a.disposition, a.amd_status, a.amd_cause,
                a.sip_cause, a.hangup_cause q850, a.billsec, a.cause_source, IF(a.dial_mode='','AMD',a.dial_mode) dial_mode, a.detectado_por, a.momento, a.palabra, a.segundo, a.corte_por, a.preanswer_audio, a.result_final $from ORDER BY a.attempt_id DESC LIMIT ".(int)$limit." OFFSET ".(int)$offset);
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
    public function openAmdAudit(array $call, int $campaignId, array $params, ?int $versionId = null): void {
        $cols = self::AMD_PARAM_COLUMNS;
        $sql = 'INSERT IGNORE INTO zynervox_bot_amd_audit (call_id,campaign_id,list_id,lead_id,amd_version_id,'.implode(',', $cols).') VALUES (?,?,?,?,?'.str_repeat(',?', count($cols)).')';
        $this->db->prepare($sql)->execute(array_merge([$call['call_id'], $campaignId, $call['list_id'], $call['lead_id'], $versionId], array_map(fn($c) => $params[$c], $cols)));
    }
    /** Extra facts that arrive with the call (decided_ms, audio_path, audio_seconds). Only the given keys change. */
    public function recordAmdAudit(string $callId, array $data): void {
        $allowed = array_merge(['decided_ms','audio_path','contact_path','audio_seconds','reviewed_class'], self::AMD_PARAM_COLUMNS);
        $set = []; $vals = [];
        foreach ($allowed as $k) if (array_key_exists($k, $data)) { $set[] = "$k=?"; $vals[] = $data[$k]; }
        if (!$set) return;
        $vals[] = $callId;
        $this->db->prepare('UPDATE zynervox_bot_amd_audit SET '.implode(',', $set).' WHERE call_id=?')->execute($vals);
        // The AGI can deliver the recording after the sync already marked the call 'no_audio': give it a chance to be transcribed.
        if (isset($data['audio_path'])) $this->db->prepare("UPDATE zynervox_bot_amd_audit SET stt_status='pending' WHERE call_id=? AND stt_status='no_audio' AND audit_state='ready'")->execute([$callId]);
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
    // --- Global AMD parameters with versions (migration 019) ---
    /** Current global version (version_id + the 9 parameters + date and author), or null when migration 019 is not applied. */
    public function amdGlobalCurrent(): ?array {
        try {
            $s = $this->db->query("SELECT v.* FROM zynervox_bot_amd_global_versions v JOIN zynervox_bot_dialer_settings d ON d.name='amd_global_version' AND d.value=v.version_id");
            $row = $s ? $s->fetch(\PDO::FETCH_ASSOC) : false;
            return $row ?: null;
        } catch (\PDOException $e) { error_log('ZV2 amd_global (migración 019 pendiente?): '.$e->getMessage()); return null; } // never stops the dialing
    }
    public function amdGlobalVersions(int $limit): array {
        $s = $this->db->query('SELECT * FROM zynervox_bot_amd_global_versions ORDER BY version_id DESC LIMIT '.max(1, min(200, $limit)));
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function amdGlobalVersion(int $id): ?array {
        $s = $this->db->prepare('SELECT * FROM zynervox_bot_amd_global_versions WHERE version_id=?'); $s->execute([$id]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    /** Inserts a new version and makes it the current one, atomically. Returns its id. */
    public function amdGlobalAddVersion(array $params, string $author, string $note, ?int $fromVersion): int {
        return $this->callAtomic(function() use($params, $author, $note, $fromVersion) {
            $cols = self::AMD_PARAM_COLUMNS;
            $this->db->prepare('INSERT INTO zynervox_bot_amd_global_versions (created_by,note,from_version_id,'.implode(',', $cols).') VALUES (?,?,?'.str_repeat(',?', count($cols)).')')
                ->execute(array_merge([substr($author, 0, 80), substr($note, 0, 255), $fromVersion], array_map(fn($c) => $params[$c], $cols)));
            $id = (int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO zynervox_bot_dialer_settings (name,value) VALUES ('amd_global_version',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$id]);
            return $id;
        });
    }

    // --- Summary per list (migration 019): incremental counters, survives deleting the list ---
    /**
     * Adds to the summary the attempts finished since the last pass (watermark counted_attempt_id) and refreshes contacts and rates.
     * $state: running | stopped | finished | deleted. TEST-% attempts are not counted; AMD figures count only attempts from $amdValidFromUtc.
     */
    public function refreshListSummary(int $listId, string $state, string $amdValidFromUtc): void {
        if (!in_array($state, ['running','stopped','finished','deleted'], true)) throw new \RuntimeException('Estado de resumen inválido.');
        $this->callAtomic(function() use($listId, $state, $amdValidFromUtc) {
            $this->db->prepare('INSERT IGNORE INTO zynervox_bot_list_summary (list_id) VALUES (?)')->execute([$listId]);
            $cur = $this->db->prepare('SELECT * FROM zynervox_bot_list_summary WHERE list_id=? FOR UPDATE'); $cur->execute([$listId]);
            $u = $cur->fetch(\PDO::FETCH_ASSOC);
            $info = $this->db->prepare('SELECT p.list_id,p.campaign_id,p.name list_name,c.name campaign_name FROM zynervox_bot_lists p LEFT JOIN zynervox_bot_campaigns c ON c.campaign_id=p.campaign_id WHERE p.list_id=?'); $info->execute([$listId]);
            $i = $info->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($i === null && ($u['campaign_id'] === null || $u['campaign_name'] === '')) { // deleted list: campaign and name come from the attempts and the campaign, if any
                $ci = $this->db->prepare('SELECT a.campaign_id,c.name FROM zynervox_bot_call_attempts a LEFT JOIN zynervox_bot_campaigns c ON c.campaign_id=a.campaign_id WHERE a.list_id=? AND a.campaign_id IS NOT NULL ORDER BY a.attempt_id DESC LIMIT 1');
                $ci->execute([$listId]);
                if ($row = $ci->fetch(\PDO::FETCH_ASSOC)) { $u['campaign_id'] = $row['campaign_id']; $u['campaign_name'] = $row['name'] ?? 'campaña eliminada'; }
            }
            $openMin = $this->db->prepare("SELECT MIN(attempt_id) FROM zynervox_bot_call_attempts WHERE list_id=? AND finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST'"); $openMin->execute([$listId]);
            $om = $openMin->fetchColumn();
            $maxId = $this->db->prepare('SELECT MAX(attempt_id) FROM zynervox_bot_call_attempts WHERE list_id=?'); $maxId->execute([$listId]);
            $mark = $om !== null && $om !== false ? (int)$om - 1 : (int)$maxId->fetchColumn();
            $from = (int)$u['counted_attempt_id']; $mark = max($mark, $from);
            $A = "(amd_status IN ('HUMAN','MACHINE','NOTSURE') OR disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL'))";
            $v = 'started_at>=?';
            $agg = $this->db->prepare("SELECT COUNT(*) calls, COALESCE(SUM($A),0) answered,
                    COALESCE(SUM((amd_status='HUMAN' OR (amd_status='' AND disposition IN ('ANSWER_HUMAN','XFER_OK','XFER_FAIL'))) AND $v),0) human,
                    COALESCE(SUM(amd_status='MACHINE' AND $v),0) machine, COALESCE(SUM(amd_status='NOTSURE' AND $v),0) not_sure,
                    COALESCE(SUM(disposition='BUSY'),0) unavailable, COALESCE(SUM(disposition='NOANSWER'),0) no_answer,
                    COALESCE(SUM(disposition IN ('FAILED','CONGESTION','CANCELLED')),0) failed, COALESCE(SUM(disposition='XFER_OK'),0) xfer_ok, COALESCE(SUM(disposition='XFER_FAIL'),0) xfer_fail,
                    COALESCE(SUM(NOT $v AND amd_status<>''),0) amd_invalid, MIN(started_at) first_at, MAX(started_at) last_at
                FROM zynervox_bot_call_attempts WHERE list_id=? AND attempt_id>? AND attempt_id<=? AND finished_at IS NOT NULL AND call_id NOT LIKE 'TEST-%'");
            $agg->execute([$amdValidFromUtc, $amdValidFromUtc, $amdValidFromUtc, $amdValidFromUtc, $listId, $from, $mark]);
            $n = $agg->fetch(\PDO::FETCH_ASSOC);
            $leads = $this->db->prepare("SELECT COUNT(*) total, COALESCE(SUM(status='NEW'),0) pending FROM zynervox_bot_list WHERE list_id=?"); $leads->execute([$listId]);
            $l = $leads->fetch(\PDO::FETCH_ASSOC);
            $exists = $i !== null;
            $contacts = $exists ? (int)$l['total'] : (int)$u['contacts']; $notCalled = $exists ? (int)$l['pending'] : (int)$u['not_called'];
            $t = []; foreach (['calls','answered','human','machine','not_sure','unavailable','no_answer','failed','xfer_ok','xfer_fail'] as $k) $t[$k] = (int)$u[$k] + (int)$n[$k === 'not_sure' ? 'not_sure' : $k];
            $t['amd_invalid_calls'] = (int)$u['amd_invalid_calls'] + (int)$n['amd_invalid'];
            $pct = fn(int $x) => $t['calls'] > 0 ? round(100 * $x / $t['calls'], 1) : 0;
            $first = $u['first_call_at'] === null ? $n['first_at'] : ($n['first_at'] === null ? $u['first_call_at'] : min($u['first_call_at'], $n['first_at']));
            $last = $u['last_call_at'] === null ? $n['last_at'] : ($n['last_at'] === null ? $u['last_call_at'] : max($u['last_call_at'], $n['last_at']));
            $dur = ($first !== null && $last !== null) ? max(0, strtotime($last.' UTC') - strtotime($first.' UTC')) : 0;
            $finishedAt = $state === 'finished' ? ($u['state'] === 'finished' && $u['finished_at'] ? $u['finished_at'] : gmdate('Y-m-d H:i:s')) : null;
            $deletedAt = $state === 'deleted' ? ($u['deleted_at'] ?: gmdate('Y-m-d H:i:s')) : null;
            $this->db->prepare('UPDATE zynervox_bot_list_summary SET campaign_id=?,campaign_name=?,list_name=?,state=?,contacts=?,not_called=?,calls=?,answered=?,human=?,machine=?,not_sure=?,unavailable=?,no_answer=?,failed=?,xfer_ok=?,xfer_fail=?,amd_invalid_calls=?,
                    contact_pct=?,human_pct=?,machine_pct=?,unavailable_pct=?,no_answer_pct=?,first_call_at=?,last_call_at=?,duration_seconds=?,finished_at=?,deleted_at=?,counted_attempt_id=? WHERE list_id=?')
                ->execute([$exists ? $i['campaign_id'] : $u['campaign_id'], $exists ? ($i['campaign_name'] ?? 'campaña eliminada') : $u['campaign_name'], $exists ? $i['list_name'] : ($u['list_name'] ?: 'lista eliminada'), $state, $contacts, $notCalled,
                    $t['calls'], $t['answered'], $t['human'], $t['machine'], $t['not_sure'], $t['unavailable'], $t['no_answer'], $t['failed'], $t['xfer_ok'], $t['xfer_fail'], $t['amd_invalid_calls'],
                    $pct($t['answered']), $pct($t['human']), $pct($t['machine']), $pct($t['unavailable']), $pct($t['no_answer']), $first, $last, $dur, $finishedAt, $deletedAt, $mark, $listId]);
            // Distribution by contact (migration 022): last attempt of each contact; never-dialed ones are contacts - dialed. Survives deleting the list.
            try {
                $cnt = $this->contactDistributionOfList($listId);
                $dialed = array_sum($cnt); $total = max($contacts, $dialed);
                $cnt['sin_llamar'] = $total - $dialed;
                $sets = []; $vals = [];
                foreach (['humano','casilla_voz','no_seguro','contestada_sin_amd','no_disponible','sin_respuesta','ocupado','errores','en_llamada','sin_llamar'] as $k) { $sets[] = "dist_$k=?"; $vals[] = (int)($cnt[$k] ?? 0); }
                $vals[] = $total; $vals[] = $listId;
                $this->db->prepare('UPDATE zynervox_bot_list_summary SET '.implode(',', $sets).',dist_at=UTC_TIMESTAMP(),contacts=? WHERE list_id=?')->execute($vals);
            } catch (\PDOException $e) { error_log('ZV2 distribución por contacto (migración 022 pendiente?): '.$e->getMessage()); } // never stops the dialing
        });
    }
    public function listSummary(int $listId): ?array {
        $s = $this->db->prepare('SELECT * FROM zynervox_bot_list_summary WHERE list_id=?'); $s->execute([$listId]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    public function listSummaries(?int $campaignId = null, ?int $listId = null): array {
        $w = []; $p = [];
        if ($campaignId !== null) { $w[] = 'campaign_id=?'; $p[] = $campaignId; }
        if ($listId !== null) { $w[] = 'list_id=?'; $p[] = $listId; }
        $s = $this->db->prepare('SELECT * FROM zynervox_bot_list_summary'.($w ? ' WHERE '.implode(' AND ', $w) : '').' ORDER BY campaign_id,list_id'); $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    /** Lists to keep fresh: started ones, ones with calls still open, and every list id seen in the attempts or lists but without a summary row. */
    public function listsToMaintain(): array {
        $rows = $this->db->query("SELECT p.list_id,p.campaign_id,p.dialer_state,p.active FROM zynervox_bot_lists p WHERE p.dialer_state='running' OR p.list_id IN (SELECT DISTINCT list_id FROM zynervox_bot_call_attempts WHERE finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST')")->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }
    /** Summary rows whose list no longer exists and are not marked deleted yet. */
    public function summariesOfDeletedLists(): array {
        return array_map('intval', $this->db->query("SELECT u.list_id FROM zynervox_bot_list_summary u LEFT JOIN zynervox_bot_lists p ON p.list_id=u.list_id WHERE p.list_id IS NULL AND u.state<>'deleted'")->fetchAll(\PDO::FETCH_COLUMN));
    }
    /** Every list id that exists or has attempts but still has no summary row (for the backfill). */
    public function listIdsWithoutSummary(): array {
        return array_map('intval', $this->db->query("SELECT x.list_id FROM (SELECT list_id FROM zynervox_bot_lists UNION SELECT DISTINCT list_id FROM zynervox_bot_call_attempts WHERE list_id IS NOT NULL) x LEFT JOIN zynervox_bot_list_summary u ON u.list_id=x.list_id WHERE u.list_id IS NULL")->fetchAll(\PDO::FETCH_COLUMN));
    }
    /** True when the list has no open calls and nothing left to dial now or later (new contacts or retries pending). */
    public function listHasNoPendingWork(int $campaignId, int $listId, string $order): bool {
        $o = $this->db->prepare("SELECT 1 FROM zynervox_bot_call_attempts WHERE list_id=? AND finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST' LIMIT 1"); $o->execute([$listId]);
        if ($o->fetchColumn()) return false;
        $s = $this->db->prepare(preg_replace('/ORDER BY (RAND\(\)|l\.lead_id (DESC|ASC))\s*$/', '', $this->campaignCandidateSql($order, 'COUNT(*)', '', false, $listId, true)));
        $s->execute([$campaignId]);
        return (int)$s->fetchColumn() === 0;
    }
    /** Contacts of the list waiting for a retry, ignoring the wait time (same eligibility as listHasNoPendingWork, minus the never-dialed ones). */
    public function listRetryPendingCount(int $campaignId, int $listId, string $order): int {
        $s = $this->db->prepare(preg_replace('/ORDER BY (RAND\(\)|l\.lead_id (DESC|ASC))\s*$/', '', $this->campaignCandidateSql($order, 'COUNT(*)', '', false, $listId, true)));
        $s->execute([$campaignId]);
        $all = (int)$s->fetchColumn();
        $n = $this->db->prepare("SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=? AND status='NEW'"); $n->execute([$listId]);
        return max(0, $all - (int)$n->fetchColumn());
    }
    /** Last N finished attempts of a list (first attempts and retries alike), newest first, by index (list_id, attempt_id): [calls, human]. TEST-% excluded. */
    public function lastFinishedAttempts(int $listId, int $n = 10): array {
        $s = $this->db->prepare("SELECT COUNT(*) calls, COALESCE(SUM(t.amd_status='HUMAN'),0) human FROM (SELECT amd_status FROM zynervox_bot_call_attempts WHERE list_id=? AND finished_at IS NOT NULL AND call_id NOT LIKE 'TEST-%' ORDER BY attempt_id DESC LIMIT ".max(1, min(100, $n)).") t");
        $s->execute([$listId]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        return ['calls'=>(int)$r['calls'], 'human'=>(int)$r['human']];
    }
    public function deactivateList(int $campaignId, int $listId): void {
        $this->db->prepare('UPDATE zynervox_bot_lists SET active=0 WHERE list_id=? AND campaign_id=?')->execute([$listId, $campaignId]);
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
        return $this->db->query("SELECT p.list_id,p.campaign_id,p.name,p.active,p.dial_prefix,p.max_channels,p.dialer_state,
            (SELECT COUNT(*) FROM zynervox_bot_list l WHERE l.list_id=p.list_id) leads_count,
            COALESCE(o.n,0) open_calls
            FROM zynervox_bot_lists p LEFT JOIN (SELECT list_id,COUNT(*) n FROM zynervox_bot_call_attempts WHERE finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST' GROUP BY list_id) o ON o.list_id=p.list_id
            ORDER BY p.campaign_id,p.list_id")->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function openCallsTotal(): int {
        return (int)$this->db->query('SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE '.self::OPEN_ATTEMPT)->fetchColumn();
    }
    private function campaignCandidateSql(string $order, string $select, string $tail, bool $onlyStarted = true, ?int $listId = null, bool $ignoreTime = false): string {
        $orderBy = $order === 'RANDOM' ? 'RAND()' : 'l.lead_id '.($order === 'DOWN' ? 'DESC' : 'ASC');
        $OPEN = "a2.finished_at IS NULL AND a2.end_source<>'RECOVERY' AND a2.status<>'LOST'";
        return "SELECT $select FROM zynervox_bot_list l JOIN zynervox_bot_lists p ON p.list_id=l.list_id AND p.campaign_id=? AND p.active=1".($onlyStarted ? " AND p.dialer_state='running'" : '').($listId !== null ? ' AND p.list_id='.$listId : '')."
            LEFT JOIN zynervox_bot_campaign_sip_rules sr ON sr.campaign_id=p.campaign_id AND sr.active=1 AND l.status IN ('B','NA','UNAV','CONG','CANCEL','FAIL')
                AND sr.sip_code=IF(l.last_sip_code BETWEEN 100 AND 199,487,l.last_sip_code)
            LEFT JOIN zynervox_bot_campaign_amd_actions aa ON aa.campaign_id=p.campaign_id AND aa.action='retry' AND aa.result IN (
                (SELECT IF(x.corte_por='ZYPAD','MACHINE',NULL) FROM zynervox_bot_call_attempts x WHERE x.lead_id=l.lead_id AND x.finished_at IS NOT NULL ORDER BY x.attempt_id DESC LIMIT 1),
                IF(l.status IN ('AA','ANSWER'), (SELECT x.amd_status FROM zynervox_bot_call_attempts x WHERE x.lead_id=l.lead_id AND x.finished_at IS NOT NULL ORDER BY x.attempt_id DESC LIMIT 1), NULL),
                IF(l.status IN ('AA','ANSWER'), (SELECT x.amd_cause FROM zynervox_bot_call_attempts x WHERE x.lead_id=l.lead_id AND x.finished_at IS NOT NULL ORDER BY x.attempt_id DESC LIMIT 1), NULL))
            WHERE (l.status='NEW'
                OR (sr.sip_code IS NOT NULL AND l.called_count<sr.max_attempts".($ignoreTime ? '' : " AND l.last_call_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL sr.retry_delay_min MINUTE)").")
                OR (aa.result IS NOT NULL AND l.called_count<aa.max_attempts".($ignoreTime ? '' : " AND l.last_call_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL aa.retry_delay_min MINUTE)")."))
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
