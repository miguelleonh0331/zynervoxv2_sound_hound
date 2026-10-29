<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait ListQueries {
    public function listStatistics(int $listId,int $campaignId): array {
        $this->list($listId,$campaignId);
        // Latest attempt per current contact: retries never inflate lead counters.
        $s=$this->db->prepare("SELECT l.status,COUNT(*) total,COALESCE(SUM(a.attempt_id IS NOT NULL AND a.finished_at IS NULL AND a.end_source<>'RECOVERY' AND a.status<>'LOST'),0) calling,COALESCE(SUM(a.finished_at IS NOT NULL OR a.end_source='RECOVERY' OR a.status='LOST'),0) finished FROM zynervox_bot_list l LEFT JOIN zynervox_bot_call_attempts a ON a.attempt_id=(SELECT MAX(x.attempt_id) FROM zynervox_bot_call_attempts x WHERE x.lead_id=l.lead_id AND x.list_id=l.list_id) WHERE l.list_id=? GROUP BY l.status ORDER BY total DESC,l.status");
        $s->execute([$listId]);$stats=['total'=>0,'calling'=>0,'finished'=>0,'states'=>[],'errors'=>[]];
        foreach($s->fetchAll(\PDO::FETCH_ASSOC) as $row){foreach(['total','calling','finished'] as $key)$stats[$key]+=(int)$row[$key];$stats['states'][]=['status'=>$row['status'],'count'=>(int)$row['total']];}
        $s=$this->db->prepare("SELECT call_id,phone,status,dial_status,hangup_cause,ivr_result,end_source FROM zynervox_bot_call_attempts WHERE list_id=? AND (status IN ('FAIL','UNAV','CONG','LOST') OR dial_status IN ('CHANUNAVAIL','CONGESTION') OR ivr_result='ERROR' OR end_source IN ('RECOVERY','AMI_REJECTED')) ORDER BY started_at DESC,attempt_id DESC LIMIT 30");
        $s->execute([$listId]);$stats['errors']=$s->fetchAll(\PDO::FETCH_ASSOC);return $stats;
    }
    public function updateListDialPrefix(int $listId, int $campaignId, string $prefix): void {
        if (!preg_match('/^[0-9]{0,20}$/D', $prefix)) throw new \RuntimeException('Prefijo invalido.');
        $this->list($listId, $campaignId);
        $this->dialMutationGuard($listId);
        $this->db->prepare('UPDATE zynervox_bot_lists SET dial_prefix=:prefix WHERE list_id=:list AND campaign_id=:campaign')->execute([':prefix'=>$prefix, ':list'=>$listId, ':campaign'=>$campaignId]);
    }
    public function lists(int $campaignId): array {
        $stmt = $this->db->prepare('SELECT l.*, (SELECT COUNT(*) FROM zynervox_bot_list d WHERE d.list_id=l.list_id) leads_count FROM zynervox_bot_lists l WHERE l.campaign_id=:id ORDER BY l.list_id');
        $stmt->execute([':id'=>$campaignId]);
        return $stmt->fetchAll();
    }
    public function list(int $listId, int $campaignId): array {
        $stmt = $this->db->prepare('SELECT l.*,c.name campaign_name FROM zynervox_bot_lists l JOIN zynervox_bot_campaigns c ON c.campaign_id=l.campaign_id WHERE l.list_id=:list AND l.campaign_id=:campaign');
        $stmt->execute([':list'=>$listId, ':campaign'=>$campaignId]);
        $row = $stmt->fetch();
        if (!$row) throw new \RuntimeException('La lista no existe o no pertenece a esta campaña.');
        return $row;
    }
    public function createList(int $campaignId, string $name, bool $active, ?int $flowId = null): int {
        $this->campaign($campaignId);
        if ($flowId !== null && $flowId < 1) throw new \RuntimeException('ID de flujo inválido.');
        $this->db->prepare('INSERT INTO zynervox_bot_lists (campaign_id,name,active,id_flujo) VALUES (:campaign,:name,:active,:flow)')->execute([':campaign'=>$campaignId, ':name'=>$name, ':active'=>(int)$active, ':flow'=>$flowId]);
        return (int)$this->db->lastInsertId();
    }
    public function updateList(int $campaignId, int $listId, string $name, bool $active, ?int $flowId = null): void {
        $this->list($listId, $campaignId);
        $this->dialMutationGuard($listId);
        if ($flowId !== null && $flowId < 1) throw new \RuntimeException('ID de flujo inválido.');
        $this->db->prepare('UPDATE zynervox_bot_lists SET name=:name,active=:active,id_flujo=COALESCE(:flow,id_flujo) WHERE list_id=:list AND campaign_id=:campaign')->execute([':name'=>$name, ':active'=>(int)$active, ':list'=>$listId, ':campaign'=>$campaignId, ':flow'=>$flowId]);
    }
}
