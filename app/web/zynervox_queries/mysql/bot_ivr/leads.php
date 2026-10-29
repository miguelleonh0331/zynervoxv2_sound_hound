<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait LeadQueries {
    public function audioLeads(int $listId, int $campaignId): array {
        $this->list($listId, $campaignId);
        $stmt = $this->db->prepare('SELECT l.lead_id,l.list_id,l.phone,l.customer_name,l.extra_json FROM zynervox_bot_list l JOIN zynervox_bot_lists p ON p.list_id=l.list_id WHERE l.list_id=:list AND p.campaign_id=:campaign ORDER BY l.lead_id');
        $stmt->execute([':list'=>$listId, ':campaign'=>$campaignId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function leadCount(int $listId): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=:id');
        $stmt->execute([':id'=>$listId]);
        return (int)$stmt->fetchColumn();
    }
    public function importLeads(int $listId, int $campaignId, array $parsed): array {
        if (empty($parsed['rows'])) throw new \RuntimeException('El archivo no contiene contactos válidos. Se conserva la base actual.');
        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = 'bot_import_'.bin2hex(random_bytes(8));
        if ($ownsTransaction) $this->db->beginTransaction();
        else $this->db->exec('SAVEPOINT '.$savepoint);
        try {
            // Replace only this list, atomically and serialized with other uploads.
            $lock = $this->db->prepare('SELECT list_id FROM zynervox_bot_lists WHERE list_id=:list AND campaign_id=:campaign FOR UPDATE');
            $lock->execute([':list'=>$listId, ':campaign'=>$campaignId]);
            if (!$lock->fetch()) throw new \RuntimeException('La lista no pertenece a esta campaña.');
            $this->dialMutationGuard($listId);
            $active=$this->db->prepare("SELECT call_id FROM zynervox_bot_call_attempts WHERE list_id=? AND finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST' LIMIT 1");$active->execute([$listId]);if($active->fetchColumn())throw new \RuntimeException('Espera a que terminen las llamadas antes de reemplazar la base.');
            $this->db->prepare('DELETE FROM zynervox_bot_list WHERE list_id=:list')->execute([':list'=>$listId]);
            $existing = [];
            $saved = 0;
            $duplicates = (int)$parsed['duplicates'];
            foreach (array_chunk($parsed['rows'], 100) as $batch) {
                $values = [];
                $bindings = [];
                foreach ($batch as $row) {
                    if (isset($existing[$row['phone']])) { $duplicates++; continue; }
                    $existing[$row['phone']] = true;
                    $values[] = "(?,?,?,?,'NEW')";
                    array_push($bindings, $listId, $row['phone'], $row['customer_name'], json_encode($row['extra'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                }
                if ($values) {
                    $this->db->prepare('INSERT INTO zynervox_bot_list (list_id,phone,customer_name,extra_json,status) VALUES '.implode(',', $values))->execute($bindings);
                    $saved += count($values);
                }
            }
            if ($ownsTransaction) $this->db->commit();
            else $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
            return ['saved'=>$saved, 'duplicates'=>$duplicates, 'rejected'=>(int)$parsed['rejected'], 'errors'=>$parsed['errors']];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            elseif (!$ownsTransaction && $this->db->inTransaction()) {
                $this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
            }
            throw $e;
        }
    }
}
