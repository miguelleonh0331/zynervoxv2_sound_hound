<?php
declare(strict_types=1);

/** Actualiza una fila pequeña de resumen sin consultar ni recorrer la cola. */
function campaign_summary_upsert(PDO $db, int $campaignId, array $values): void {
    $allowed = [
        'audio_status', 'campaign_status', 'last_zypad', 'total_contacts', 'pending_contacts',
        'active_contacts', 'completed_contacts', 'failed_contacts', 'last_error',
    ];
    $columns = [];
    $params = [':campaign_id' => $campaignId];
    foreach ($allowed as $column) {
        if (!array_key_exists($column, $values)) continue;
        $columns[] = $column;
        $params[':' . $column] = $values[$column];
    }
    if (!$columns) return;

    $insertColumns = implode(',', array_merge(['campaign_id'], $columns));
    $placeholders = implode(',', array_map(static function (string $column): string {
        return ':' . $column;
    }, array_merge(['campaign_id'], $columns)));
    $updates = implode(',', array_map(static function (string $column): string {
        return $column . '=VALUES(' . $column . ')';
    }, $columns));
    $sql = 'INSERT INTO carsa_campaign_state_summary (' . $insertColumns . ') VALUES ('
         . $placeholders . ') ON DUPLICATE KEY UPDATE ' . $updates . ',updated_at=CURRENT_TIMESTAMP';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

/** Evita escrituras cada tres segundos: solo persiste si el resumen cambió. */
function campaign_summary_sync_if_changed(PDO $db, int $campaignId, array $values): void {
    $stmt = $db->prepare('SELECT * FROM carsa_campaign_state_summary WHERE campaign_id=:id');
    $stmt->execute([':id' => $campaignId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($current) {
        $changed = false;
        foreach ($values as $key => $value) {
            if ((string)($current[$key] ?? '') !== (string)($value ?? '')) {
                $changed = true;
                break;
            }
        }
        if (!$changed) return;
    }
    campaign_summary_upsert($db, $campaignId, $values);
}
