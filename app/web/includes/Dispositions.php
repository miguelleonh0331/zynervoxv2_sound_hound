<?php

namespace Includes;

use Includes\Database;
use PDO;
use Exception;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Dispositions {

    public static function getAll($campaignId = null) {
        $db = Database::getInstance();
        try {
            if ($campaignId) {
                $stmt = $db->prepare("SELECT * FROM vicidial_campaign_statuses WHERE campaign_id = :cid ORDER BY status ASC");
                $stmt->execute(['cid' => $campaignId]);
            } else {
                $stmt = $db->query("SELECT * FROM vicidial_campaign_statuses ORDER BY campaign_id, status ASC");
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    public static function getById($status, $campaignId) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("SELECT * FROM vicidial_campaign_statuses WHERE status = :status AND campaign_id = :cid");
            $stmt->execute(['status' => $status, 'cid' => $campaignId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null;
        }
    }

    public static function create($data) {
        $db = Database::getInstance();
        try {
            $columns = implode(", ", array_keys($data));
            $placeholders = ":" . implode(", :", array_keys($data));
            
            $sql = "INSERT INTO vicidial_campaign_statuses ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                Audit::logTableChange(
                    $_SESSION['user'] ?? 'system', 
                    'CREATE', 
                    'vicidial_campaign_statuses', 
                    'status', 
                    $data['status'],
                    ['campaign_id' => $data['campaign_id']]
                );
                return true;
            }
        } catch (\PDOException $e) {
            return false;
        }
        return false;
    }

    public static function update($oldStatus, $oldCampaignId, $data) {
        $db = Database::getInstance();
        try {
            $setParts = [];
            foreach (array_keys($data) as $key) {
                $setParts[] = "$key = :$key";
            }
            $setSql = implode(", ", $setParts);
            
            $sql = "UPDATE vicidial_campaign_statuses SET $setSql WHERE status = :old_status AND campaign_id = :old_cid";
            $data['old_status'] = $oldStatus;
            $data['old_cid'] = $oldCampaignId;
            
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                Audit::logTableChange(
                    $_SESSION['user'] ?? 'system', 
                    'UPDATE', 
                    'vicidial_campaign_statuses', 
                    'status', 
                    $oldStatus,
                    ['campaign_id' => $oldCampaignId]
                );
                return true;
            }
        } catch (\PDOException $e) {
            return false;
        }
        return false;
    }

    public static function delete($status, $campaignId) {
        $db = Database::getInstance();
        try {
            // Log snapshot before delete
            Audit::logTableChange(
                $_SESSION['user'] ?? 'system', 
                'DELETE', 
                'vicidial_campaign_statuses', 
                'status', 
                $status,
                ['campaign_id' => $campaignId]
            );
            
            $stmt = $db->prepare("DELETE FROM vicidial_campaign_statuses WHERE status = :status AND campaign_id = :cid");
            return $stmt->execute(['status' => $status, 'cid' => $campaignId]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function copyFromCampaign($sourceId, $targetId) {
        $db = Database::getInstance();
        try {
            // 1. Obtener estados de la fuente
            $stmt = $db->prepare("SELECT * FROM vicidial_campaign_statuses WHERE campaign_id = :sid");
            $stmt->execute(['sid' => $sourceId]);
            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($statuses as $s) {
                // Preparar para el destino
                $data = $s;
                $data['campaign_id'] = $targetId;
                
                // Verificar si ya existe en el destino
                $check = $db->prepare("SELECT count(*) FROM vicidial_campaign_statuses WHERE status = :status AND campaign_id = :cid");
                $check->execute(['status' => $s['status'], 'cid' => $targetId]);
                
                if ($check->fetchColumn() == 0) {
                    self::create($data);
                }
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
