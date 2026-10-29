<?php

namespace Includes;

use Includes\Database;
use PDO;
use Exception;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Motor {
    
    public static function getPresets() {
        $db = Database::getInstance();
        try {
            $stmt = $db->query("SELECT * FROM vox_sphere_motor_presets ORDER BY preset_name ASC");
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    public static function createPreset($data) {
        $db = Database::getInstance();
        try {
            $columns = implode(", ", array_keys($data));
            $placeholders = ":" . implode(", :", array_keys($data));
            
            $sql = "INSERT INTO vox_sphere_motor_presets ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                $id = $db->lastInsertId();
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'CREATE', 'vox_sphere_motor_presets', 'id', $id);
                return $id;
            }
        } catch (\PDOException $e) {
            return false;
        }
        return false;
    }

    public static function getPresetById($id) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("SELECT * FROM vox_sphere_motor_presets WHERE id = :id");
            $stmt->execute(['id' => $id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null;
        }
    }

    public static function updatePreset($id, $data) {
        $db = Database::getInstance();
        try {
            $setParts = [];
            foreach (array_keys($data) as $key) {
                $setParts[] = "$key = :$key";
            }
            $setSql = implode(", ", $setParts);
            
            $sql = "UPDATE vox_sphere_motor_presets SET $setSql WHERE id = :id";
            $data['id'] = $id;
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'UPDATE', 'vox_sphere_motor_presets', 'id', $id);
                return true;
            }
        } catch (\PDOException $e) {
            return false;
        }
        return false;
    }

    public static function deletePreset($id) {
        $db = Database::getInstance();
        try {
            // Log snapshot before delete
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'DELETE', 'vox_sphere_motor_presets', 'id', $id);
            
            $stmt = $db->prepare("DELETE FROM vox_sphere_motor_presets WHERE id = :id");
            return $stmt->execute(['id' => $id]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function applyToCampaign($presetID, $campaignID) {
        $db = Database::getInstance();
        
        try {
            // 1. Obtener datos del preset
            $stmt = $db->prepare("SELECT * FROM vox_sphere_motor_presets WHERE id = :id");
            $stmt->execute(['id' => $presetID]);
            $preset = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$preset) return false;

            // 2. Actualizar campaña
            $sql = "UPDATE vicidial_campaigns SET 
                    dial_method = :dm,
                    auto_dial_level = :adl,
                    adaptive_maximum_level = :aml,
                    adaptive_dropped_percentage = :adp,
                    available_only_ratio_tally = :aort
                    WHERE campaign_id = :cid";
            
            $update = $db->prepare($sql);
            $res = $update->execute([
                'dm'   => $preset['dial_method'],
                'adl'  => $preset['auto_dial_level'],
                'aml'  => $preset['adaptive_maximum_level'],
                'adp'  => $preset['adaptive_dropped_percentage'],
                'aort' => $preset['available_only_ratio_tally'],
                'cid'  => $campaignID
            ]);

            if ($res) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'MOTOR_APPLY', 'vicidial_campaigns', 'campaign_id', $campaignID);
                return true;
            }
        } catch (\PDOException $e) {
            return false;
        }
        return false;
    }

    public static function identifyPreset($campaign, $presets) {
        foreach ($presets as $p) {
            if ($campaign['dial_method'] == $p['dial_method'] &&
                (float)$campaign['auto_dial_level'] == (float)$p['auto_dial_level'] &&
                (float)$campaign['adaptive_maximum_level'] == (float)$p['adaptive_maximum_level'] &&
                (float)$campaign['adaptive_dropped_percentage'] == (float)$p['adaptive_dropped_percentage'] &&
                $campaign['available_only_ratio_tally'] == $p['available_only_ratio_tally']) {
                return $p['preset_name'];
            }
        }
        return "Manual / Personalizado";
    }
}
