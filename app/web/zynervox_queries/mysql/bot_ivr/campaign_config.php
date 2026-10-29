<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
// Dialer v1 campaign configuration and engine queries (migration 014). Validation lives in bot_ivr/campaign_config_service.php.
trait CampaignConfigQueries {
    private const CONFIG_COLUMNS = ['name','active','call_origin','max_channels','amd_enabled','transfer_carrier_id','transfer_destination','timezone','lead_order','dial_timeout','campaign_cid'];
    public function campaignConfig(int $id): array {
        $config = ['campaign'=>$this->campaign($id)];
        foreach (['headers'=>['campaign_headers','position','position,header_name,lead_field'],
                  'retry_rules'=>['campaign_retry_rules','status','status,max_attempts,retry_delay_min,active'],
                  'schedules'=>['campaign_schedules','weekday,start_time','weekday,TIME_FORMAT(start_time,\'%H:%i\') start_time,TIME_FORMAT(end_time,\'%H:%i\') end_time']] as $key=>[$table,$order,$columns]) {
            $s = $this->db->prepare("SELECT $columns FROM zynervox_bot_$table WHERE campaign_id=? ORDER BY $order");
            $s->execute([$id]);
            $config[$key] = $s->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $config;
    }
    /** Replaces the whole configuration atomically. $campaign holds CONFIG_COLUMNS; returns the campaign id. */
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
            foreach (['headers','retry_rules','schedules'] as $table) $this->db->prepare("DELETE FROM zynervox_bot_campaign_$table WHERE campaign_id=?")->execute([$id]);
            foreach (array_values($headers) as $position=>$h) $this->db->prepare('INSERT INTO zynervox_bot_campaign_headers (campaign_id,position,header_name,lead_field) VALUES (?,?,?,?)')->execute([$id,$position,$h['name'],$h['field']]);
            foreach ($rules as $r) $this->db->prepare('INSERT INTO zynervox_bot_campaign_retry_rules (campaign_id,status,max_attempts,retry_delay_min,active) VALUES (?,?,?,?,?)')->execute([$id,$r['status'],$r['max_attempts'],$r['retry_delay_min'],$r['active']]);
            foreach ($schedules as $w) $this->db->prepare('INSERT INTO zynervox_bot_campaign_schedules (campaign_id,weekday,start_time,end_time) VALUES (?,?,?,?)')->execute([$id,$w['weekday'],$w['start_time'].':00',$w['end_time'].':00']);
            return $id;
        });
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
    private function campaignCandidateSql(string $order, string $select, string $tail): string {
        $orderBy = $order === 'RANDOM' ? 'RAND()' : 'l.lead_id '.($order === 'DOWN' ? 'DESC' : 'ASC');
        return "SELECT $select FROM zynervox_bot_list l JOIN zynervox_bot_lists p ON p.list_id=l.list_id AND p.campaign_id=? AND p.active=1
            LEFT JOIN zynervox_bot_campaign_retry_rules r ON r.campaign_id=p.campaign_id AND r.status=l.status AND r.active=1
            WHERE (l.status='NEW' OR (r.status IS NOT NULL AND l.called_count<r.max_attempts))
              AND (l.next_call_at IS NULL OR l.next_call_at<=UTC_TIMESTAMP())
              AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts a WHERE a.lead_id=l.lead_id AND a.finished_at IS NULL AND a.end_source<>'RECOVERY' AND a.status<>'LOST')
            ORDER BY $orderBy $tail";
    }
    /** Read-only: next eligible leads (for --dry-run), no locks and no state change. */
    public function previewCampaignLeads(int $id, string $order, int $limit): array {
        $s = $this->db->prepare($this->campaignCandidateSql($order, 'l.lead_id,l.list_id,l.phone,l.customer_name,l.extra_json,l.status,l.called_count', 'LIMIT '.max(1, min(500, $limit))));
        $s->execute([$id]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function countEligibleLeads(int $id, string $order): int {
        $s = $this->db->prepare(str_replace('ORDER BY RAND()', '', preg_replace('/ORDER BY .*$/s', '', $this->campaignCandidateSql($order, 'COUNT(*)', ''))));
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
