<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait CallQueries {
    private function ivrLeadStatus(string $callId, string $status): void {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D',$status)) throw new \RuntimeException('Invalid IVR status');
        $this->db->prepare('UPDATE zynervox_bot_call_attempts SET status=? WHERE call_id=?')->execute([$status,$callId]);
        $this->db->prepare('UPDATE zynervox_bot_list l JOIN zynervox_bot_call_attempts a ON a.lead_id=l.lead_id SET l.status=? WHERE a.call_id=? AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts newer WHERE newer.lead_id=l.lead_id AND newer.attempt_id>a.attempt_id)')->execute([$status,$callId]);
    }
    public function saveIvrResult(string $callId, string $nodeId, array $fields): void {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$callId) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$nodeId) || !$fields) throw new \RuntimeException('Invalid IVR result');
        $json=json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if (strlen($json)>65536) throw new \RuntimeException('IVR result too large');
        $this->callAtomic(function() use($callId,$nodeId,$json,$fields) {
            $s=$this->db->prepare('SELECT list_id,lead_id,finished_at FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE');$s->execute([$callId]);$call=$s->fetch(\PDO::FETCH_ASSOC);
            if (!$call || $call['finished_at']!==null) throw new \RuntimeException('Invalid IVR result context');
            $s=$this->db->prepare('SELECT fields_json FROM zynervox_bot_ivr_results WHERE call_id=? AND node_id=?');$s->execute([$callId,$nodeId]);$old=$s->fetchColumn();
            if ($old!==false) {if (json_decode($old,true)!==$fields) throw new \RuntimeException('IVR result already differs');return;}
            $this->db->prepare('INSERT INTO zynervox_bot_ivr_results(call_id,node_id,list_id,lead_id,result,fields_json,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$callId,$nodeId,$call['list_id'],$call['lead_id'],substr((string)($fields['RESULTADO'] ?? ''),0,80),$json]);
            if (!empty($fields['RESULTADO'])) $this->ivrLeadStatus($callId,strtoupper(trim((string)$fields['RESULTADO'])));
        });
    }
    public function ivrCallContext(int $listId, int $leadId, string $callId): array {
        $s=$this->db->prepare('SELECT l.lead_id,l.list_id,l.phone,l.customer_name,l.extra_json,p.campaign_id,p.id_flujo FROM zynervox_bot_call_attempts a JOIN zynervox_bot_list l ON l.lead_id=a.lead_id AND l.list_id=a.list_id JOIN zynervox_bot_lists p ON p.list_id=a.list_id WHERE a.call_id=? AND a.list_id=? AND a.lead_id=? AND a.finished_at IS NULL AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_events e WHERE e.call_id=a.call_id AND e.event_type=\'IVR_END\')');
        $s->execute([$callId,$listId,$leadId]);
        $row=$s->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !$row['id_flujo']) throw new \RuntimeException('Invalid IVR call context');
        return $row;
    }
    private function callAtomic(callable $operation) {
        $outer=$this->db->inTransaction();
        $savepoint='call_tracking_'.bin2hex(random_bytes(6));
        if ($outer) $this->db->exec('SAVEPOINT '.$savepoint); else $this->db->beginTransaction();
        try {
            $result=$operation();
            if ($outer) $this->db->exec('RELEASE SAVEPOINT '.$savepoint); else $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($outer) $this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint); elseif ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
    public function recordCallEvent(string $callId, string $type, string $eventId, int $epoch, array $data): void {
        if (!in_array($type,['START','OUTBOUND','ANSWER','AMD','FINISH','RECOVERY','IVR_START','IVR_NODE','IVR_END','DIAL_REJECTED'],true) || !preg_match('/^[a-f0-9]{64}$/D',$eventId) || $epoch<1) throw new \RuntimeException('Invalid event');
        $this->callAtomic(function() use($callId,$type,$eventId,$epoch,$data) {
            $s=$this->db->prepare('SELECT call_id FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE'); $s->execute([$callId]);
            if (!$s->fetchColumn()) throw new \RuntimeException('Attempt not found');
            $s=$this->db->prepare('SELECT event_id FROM zynervox_bot_call_events WHERE event_id=?'); $s->execute([$eventId]);
            if ($s->fetchColumn()) return;
            $at=gmdate('Y-m-d H:i:s',$epoch);
            $this->db->prepare('INSERT INTO zynervox_bot_call_events(event_id,call_id,event_type,occurred_at,payload_json) VALUES (?,?,?,?,?)')->execute([$eventId,$callId,$type,$at,json_encode($data,JSON_THROW_ON_ERROR)]);
            if ($type==='IVR_NODE' && ($data['action'] ?? '')==='status') {
                $this->ivrLeadStatus($callId,(string)$data['status']);
            } elseif ($type==='IVR_START') {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET campaign_id=?,flow_id=?,flow_code=?,flow_name=?,flow_sha256=? WHERE call_id=?')->execute([$data['campaign_id']??null,$data['flow_id']??null,$data['flow_code']??null,$data['flow_name']??null,$data['flow_sha256']??null,$callId]);
            } elseif ($type==='IVR_END') {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET ivr_result=? WHERE call_id=?')->execute([substr($data['result']??'',0,20),$callId]);
            } elseif ($type==='START') {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET linked_id=?,caller_channel=?,origin_prefix=?,carrier=?,started_at=? WHERE call_id=?')->execute([substr($data['linked_id']??'',0,80),substr($data['channel']??'',0,160),substr($data['prefix']??'',0,20),substr($data['carrier']??'',0,60),$at,$callId]);
            } elseif ($type==='OUTBOUND') {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET callee_channel=?,callee_unique_id=? WHERE call_id=?')->execute([substr($data['channel']??'',0,160),substr($data['unique_id']??'',0,80),$callId]);
            } elseif ($type==='ANSWER') {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET answered_at=COALESCE(answered_at,?) WHERE call_id=?')->execute([$at,$callId]);
            } elseif ($type==='AMD') {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET amd_status=?,amd_cause=? WHERE call_id=?')->execute([substr($data['amd_status']??'',0,20),substr($data['amd_cause']??'',0,160),$callId]);
            } elseif ($type==='FINISH') {
                if (array_key_exists('recording_status',$data)) $this->db->prepare('UPDATE zynervox_bot_call_attempts SET recording_path=?,recording_status=?,recording_bytes=?,recording_finalized_at=? WHERE call_id=?')->execute([$data['recording_path'],$data['recording_status'],$data['recording_bytes'],$at,$callId]);
                $this->db->prepare("UPDATE zynervox_bot_call_attempts SET finished_at=?,end_source='HANGUP_HANDLER' WHERE call_id=?")->execute([$at,$callId]);
            }
            $this->db->prepare('UPDATE zynervox_bot_call_attempts SET duration_seconds=IF(finished_at IS NULL,NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,started_at,finished_at))),billsec=IF(finished_at IS NULL,NULL,IF(answered_at IS NULL,0,GREATEST(0,TIMESTAMPDIFF(SECOND,answered_at,finished_at)))) WHERE call_id=?')->execute([$callId]);
        });
    }
    public function openCallsForRecovery(): array {
        return $this->db->query("SELECT call_id,caller_channel,callee_channel,callee_unique_id,linked_id FROM zynervox_bot_call_attempts WHERE finished_at IS NULL AND status<>'LOST' AND end_source<>'RECOVERY' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) ORDER BY attempt_id LIMIT 1000")->fetchAll();
    }
    public function observeCallPresence(string $callId, bool $active, int $epoch): bool {
        return $this->callAtomic(function() use($callId,$active,$epoch) {
            $s=$this->db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE'); $s->execute([$callId]);$call=$s->fetch();
            if (!$call || $call['finished_at']!==null || $call['status']==='LOST' || $call['end_source']==='RECOVERY') return false;
            if ($active) {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET missing_since=NULL WHERE call_id=?')->execute([$callId]);return false;
            }
            if ($call['missing_since']===null) {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET missing_since=? WHERE call_id=?')->execute([gmdate('Y-m-d H:i:s',$epoch),$callId]);return false;
            }
            if ($epoch-strtotime($call['missing_since'].' UTC')<120) return false;
            $this->db->prepare("UPDATE zynervox_bot_call_attempts SET status=IF(status='CALL','LOST',status),end_source='RECOVERY' WHERE call_id=?")->execute([$callId]);
            $this->db->prepare("UPDATE zynervox_bot_list l SET status=IF(status='CALL','LOST',status) WHERE lead_id=? AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts a WHERE a.lead_id=l.lead_id AND a.attempt_id>?)")->execute([$call['lead_id'],$call['attempt_id']]);
            $this->db->prepare('INSERT INTO zynervox_bot_call_events(event_id,call_id,event_type,occurred_at,payload_json) VALUES (?,?,?,?,?)')->execute([hash('sha256',$callId.'|RECOVERY'),$callId,'RECOVERY',gmdate('Y-m-d H:i:s',$epoch),json_encode(['reason'=>'channel_absent_without_finish','actual_end_time'=>'unknown'],JSON_THROW_ON_ERROR)]);
            return true;
        });
    }
    public function startCall(int $listId, string $phone, string $callId): int {
        if ($listId<1 || !preg_match('/^[0-9]{1,20}$/D',$phone) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$callId)) throw new \RuntimeException('Invalid call identity');
        return $this->callAtomic(function() use($listId,$phone,$callId) {
            $s=$this->db->prepare('SELECT list_id,campaign_id,id_flujo FROM zynervox_bot_lists WHERE list_id=? FOR UPDATE'); $s->execute([$listId]);
            $listSnapshot=$s->fetch(\PDO::FETCH_ASSOC);
            if (!$listSnapshot) throw new \RuntimeException('List not found');
            $s=$this->db->prepare('SELECT lead_id,list_id,phone FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE'); $s->execute([$callId]); $old=$s->fetch();
            if ($old) {
                if ((int)$old['list_id']!==$listId || $old['phone']!==$phone) throw new \RuntimeException('Call ID mismatch');
                return (int)$old['lead_id'];
            }
            $s=$this->db->prepare('SELECT lead_id FROM zynervox_bot_list WHERE list_id=? AND phone=? ORDER BY lead_id LIMIT 1 FOR UPDATE'); $s->execute([$listId,$phone]); $lead=$s->fetchColumn();
            if (!$lead) {
                $this->db->prepare('INSERT INTO zynervox_bot_list(list_id,phone) VALUES (?,?)')->execute([$listId,$phone]);
                $lead=$this->db->lastInsertId();
            }
            $this->db->prepare('INSERT INTO zynervox_bot_call_attempts(call_id,list_id,lead_id,phone,campaign_id,flow_id,started_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$callId,$listId,$lead,$phone,$listSnapshot['campaign_id'],$listSnapshot['id_flujo']]);
            $this->db->prepare("UPDATE zynervox_bot_list SET called_count=called_count+1,last_call_at=UTC_TIMESTAMP(),status='CALL' WHERE lead_id=?")->execute([$lead]);
            return (int)$lead;
        });
    }
    public function finishCall(string $callId, string $dialStatus, string $amdStatus, string $amdCause, int $hangupCause, bool $answered, array $causes = []): void {
        $this->callAtomic(function() use($callId,$dialStatus,$amdStatus,$amdCause,$hangupCause,$answered,$causes) {
            $s=$this->db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE'); $s->execute([$callId]); $call=$s->fetch();
            if (!$call) throw new \RuntimeException('Attempt not found');
            if ($call['finished_at']!==null) return;
            if ($amdStatus==='') { $amdStatus=$call['amd_status']; $amdCause=$call['amd_cause']; }
            $answered=$answered || $call['answered_at']!==null;
            // Disposition: explicit value, else from the dialplan stage (marcador), else from the dial result.
            $stage=(string)($causes['stage'] ?? '');
            $disposition=(string)($causes['disposition'] ?? '');
            if ($disposition==='' && $stage!=='') $disposition=['AMD_MACHINE'=>'AMD_MACHINE','XFER_NODEST'=>'XFER_FAIL','XFER'=>$dialStatus==='ANSWER' ? 'XFER_OK' : 'XFER_FAIL','ANSWER'=>'ANSWER_HUMAN'][$stage] ?? '';
            if (!in_array($disposition,['ANSWER_HUMAN','AMD_MACHINE','NOANSWER','BUSY','CONGESTION','FAILED','XFER_OK','XFER_FAIL','CANCELLED'],true)) {
                $disposition=$amdStatus==='MACHINE' ? 'AMD_MACHINE' : (($answered || $dialStatus==='ANSWER') ? 'ANSWER_HUMAN' : (['BUSY'=>'BUSY','NOANSWER'=>'NOANSWER','CONGESTION'=>'CONGESTION','CANCEL'=>'CANCELLED'][$dialStatus] ?? 'FAILED'));
            }
            $status=$amdStatus==='MACHINE' ? 'AA' : (($answered || $dialStatus==='ANSWER') ? 'ANSWER' : (['BUSY'=>'B','NOANSWER'=>'NA','CHANUNAVAIL'=>'UNAV','CONGESTION'=>'CONG','CANCEL'=>'CANCEL'][ $dialStatus ] ?? 'FAIL'));
            if (in_array($disposition,['XFER_OK','XFER_FAIL'],true) && $amdStatus!=='MACHINE') $status=$disposition;
            if (!in_array($call['status'],['CALL','LOST'],true)) $status=$call['status'];
            $this->db->prepare('UPDATE zynervox_bot_call_attempts SET status=?,dial_status=?,amd_status=?,amd_cause=?,hangup_cause=?,finished_at=UTC_TIMESTAMP() WHERE call_id=?')->execute([$status,substr($dialStatus,0,20),substr($amdStatus,0,20),substr($amdCause,0,160),$hangupCause,$callId]);
            // A late callback cannot overwrite a newer attempt for this lead.
            $this->db->prepare('UPDATE zynervox_bot_list l SET status=? WHERE lead_id=? AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts a WHERE a.lead_id=l.lead_id AND a.attempt_id>?)')->execute([$status,$call['lead_id'],$call['attempt_id']]);
            // Migration 014 columns: failures are logged and must not break the closure of the call.
            $source=(string)($causes['cause_source'] ?? '');
            if (!in_array($source,['dialplan','ami','agi'],true)) $source='dialplan';
            try {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET sip_cause=?,cause_source=?,disposition=? WHERE call_id=?')->execute([substr((string)($causes['sip_cause'] ?? ''),0,120),$source,$disposition,$callId]);
                $this->db->prepare('UPDATE zynervox_bot_list l SET next_call_at=(SELECT DATE_ADD(UTC_TIMESTAMP(),INTERVAL r.retry_delay_min MINUTE) FROM zynervox_bot_campaign_retry_rules r WHERE r.campaign_id=? AND r.status=? AND r.active=1) WHERE lead_id=? AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts a WHERE a.lead_id=l.lead_id AND a.attempt_id>?)')->execute([$call['campaign_id'],$status,$call['lead_id'],$call['attempt_id']]);
            } catch (\PDOException $e) { error_log('ZV2 finishCall: migracion 014 pendiente o fallida: '.$e->getMessage()); }
        });
    }
}
