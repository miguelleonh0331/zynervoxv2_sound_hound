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
    public function leadSipHeaders(int $leadId): array {
        $stmt = $this->db->prepare('SELECT header_name `name`, header_value `value` FROM zynervox_bot_lead_headers WHERE lead_id=? ORDER BY position LIMIT 16');
        $stmt->execute([$leadId]);
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
            // Migration 013 is optional until applied: only touch its objects when the upload defines headers
            // (error if missing) or when the column exists (to clear a stale map).
            $schema = $this->db->query("SELECT SUM(table_name='zynervox_bot_lists' AND column_name='sip_header_map') c, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='zynervox_bot_lead_headers') t FROM information_schema.columns WHERE table_schema=DATABASE()")->fetch(\PDO::FETCH_ASSOC);
            $hasMap = (int)($schema['c'] ?? 0) > 0;
            if (!empty($parsed['header_map']) && (!$hasMap || (int)($schema['t'] ?? 0) === 0)) throw new \RuntimeException('Migración 013 pendiente: no se pueden guardar headers SIP. Aplica src/features/bot_ivr/models/013-lead-sip-headers.sql o carga sin columnas de header.');
            if ($hasMap) $this->db->prepare('UPDATE zynervox_bot_lists SET sip_header_map=? WHERE list_id=?')->execute([empty($parsed['header_map']) ? null : json_encode($parsed['header_map'], JSON_THROW_ON_ERROR), $listId]);
            if (!empty($parsed['header_map'])) {
                $ids = $this->db->prepare('SELECT phone,lead_id FROM zynervox_bot_list WHERE list_id=?');
                $ids->execute([$listId]);
                $leadByPhone = $ids->fetchAll(\PDO::FETCH_KEY_PAIR);
                $rows = [];
                foreach ($parsed['rows'] as $row) {
                    if (!isset($leadByPhone[$row['phone']])) continue;
                    foreach (($row['headers'] ?? []) as $position=>$header) array_push($rows, (int)$leadByPhone[$row['phone']], $position, $header['name'], $header['value']);
                }
                foreach (array_chunk($rows, 4 * 500) as $chunk) {
                    $this->db->prepare('INSERT INTO zynervox_bot_lead_headers (lead_id,position,header_name,header_value) VALUES '.implode(',', array_fill(0, intdiv(count($chunk), 4), '(?,?,?,?)')))->execute($chunk);
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
