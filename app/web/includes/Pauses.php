<?php

namespace Includes;

use Includes\Database;
use PDO;
use Exception;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Pauses {

    public static function getAll($campaignId = null) {
        $db = Database::getInstance();
        try {
            if ($campaignId) {
                $stmt = $db->prepare("SELECT * FROM vicidial_pause_codes WHERE campaign_id = :cid ORDER BY pause_code ASC");
                $stmt->execute(['cid' => $campaignId]);
            } else {
                $stmt = $db->query("SELECT * FROM vicidial_pause_codes ORDER BY campaign_id, pause_code ASC");
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    public static function getById($code, $campaignId) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("SELECT * FROM vicidial_pause_codes WHERE pause_code = :code AND campaign_id = :cid");
            $stmt->execute(['code' => $code, 'cid' => $campaignId]);
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
            
            $sql = "INSERT INTO vicidial_pause_codes ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                Audit::logTableChange(
                    $_SESSION['user'] ?? 'system', 
                    'CREATE', 
                    'vicidial_pause_codes', 
                    'pause_code', 
                    $data['pause_code'],
                    ['campaign_id' => $data['campaign_id']]
                );
                return true;
            }
        } catch (\PDOException $e) {
            return false;
        }
        return false;
    }

    public static function update($oldCode, $oldCampaignId, $data) {
        $db = Database::getInstance();
        try {
            $setParts = [];
            foreach (array_keys($data) as $key) {
                $setParts[] = "$key = :$key";
            }
            $setSql = implode(", ", $setParts);
            
            $sql = "UPDATE vicidial_pause_codes SET $setSql WHERE pause_code = :old_code AND campaign_id = :old_cid";
            $data['old_code'] = $oldCode;
            $data['old_cid'] = $oldCampaignId;
            
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                Audit::logTableChange(
                    $_SESSION['user'] ?? 'system', 
                    'UPDATE', 
                    'vicidial_pause_codes', 
                    'pause_code', 
                    $oldCode,
                    ['campaign_id' => $oldCampaignId]
                );
                return true;
            }
        } catch (\PDOException $e) {
            return false;
        }
        return false;
    }

    public static function delete($code, $campaignId) {
        $db = Database::getInstance();
        try {
            Audit::logTableChange(
                $_SESSION['user'] ?? 'system', 
                'DELETE', 
                'vicidial_pause_codes', 
                'pause_code', 
                $code,
                ['campaign_id' => $campaignId]
            );
            
            $stmt = $db->prepare("DELETE FROM vicidial_pause_codes WHERE pause_code = :code AND campaign_id = :cid");
            return $stmt->execute(['code' => $code, 'cid' => $campaignId]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function copyFromCampaign($sourceId, $targetId) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("SELECT * FROM vicidial_pause_codes WHERE campaign_id = :sid");
            $stmt->execute(['sid' => $sourceId]);
            $pauses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pauses as $p) {
                $data = $p;
                $data['campaign_id'] = $targetId;
                
                $check = $db->prepare("SELECT count(*) FROM vicidial_pause_codes WHERE pause_code = :code AND campaign_id = :cid");
                $check->execute(['code' => $p['pause_code'], 'cid' => $targetId]);
                
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
