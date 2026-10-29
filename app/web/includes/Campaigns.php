<?php

namespace Includes;

use Includes\Database;
use PDO;
use Exception;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Campaigns {
    
    public static function getAll() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT campaign_id, campaign_name, active, dial_method, auto_dial_level, adaptive_maximum_level, adaptive_dropped_percentage, available_only_ratio_tally, campaign_description FROM vicidial_campaigns WHERE campaign_id NOT LIKE 'PL%' ORDER BY campaign_id ASC");
        return $stmt->fetchAll();
    }

    public static function getTemplates() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT campaign_id, campaign_name, campaign_description FROM vicidial_campaigns WHERE campaign_id LIKE 'PL%' ORDER BY campaign_id ASC");
        return $stmt->fetchAll();
    }

    public static function clone($templateID, $newID, $newName, $newDesc = '') {
        $db = Database::getInstance();

        // 1. Verificar si el nuevo ID ya existe
        $check = $db->prepare("SELECT count(*) FROM vicidial_campaigns WHERE campaign_id = :id");
        $check->execute(['id' => $newID]);
        if ($check->fetchColumn() > 0) {
            throw new Exception("El ID de campaña '$newID' ya existe.");
        }

        // 2. Obtener los datos de la plantilla
        $stmt = $db->prepare("SELECT * FROM vicidial_campaigns WHERE campaign_id = :template");
        $stmt->execute(['template' => $templateID]);
        $templateData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$templateData) {
            throw new Exception("La plantilla '$templateID' no existe.");
        }

        // 3. Preparar los datos para la nueva campaña
        $newData = $templateData;
        $newData['campaign_id'] = $newID;
        $newData['campaign_name'] = $newName;
        $newData['campaign_description'] = $newDesc;
        $newData['active'] = 'Y';

        // 4. Construir la consulta de inserción dinámica
        $columns = implode(", ", array_keys($newData));
        $placeholders = ":" . implode(", :", array_keys($newData));
        
        $sql = "INSERT INTO vicidial_campaigns ($columns) VALUES ($placeholders)";
        $insert = $db->prepare($sql);
        
        if ($insert->execute($newData)) {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'CLONE', 'vicidial_campaigns', 'campaign_id', $newID);
            return true;
        }

        return false;
    }

    public static function toggleStatus($id, $currentStatus) {
        $db = Database::getInstance();
        $newStatus = ($currentStatus == 'Y' ? 'N' : 'Y');
        
        $stmt = $db->prepare("UPDATE vicidial_campaigns SET active = :status WHERE campaign_id = :id");
        if ($stmt->execute(['status' => $newStatus, 'id' => $id])) {
            $action = ($newStatus == 'Y' ? 'ACTIVATE' : 'DEACTIVATE');
            Audit::logTableChange($_SESSION['user'] ?? 'system', $action, 'vicidial_campaigns', 'campaign_id', $id);
            return true;
        }
        return false;
    }

    public static function delete($id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM vicidial_campaigns WHERE campaign_id = :id");
        if ($stmt->execute(['id' => $id])) {
            // Nota: En eliminación, el log debe hacerse ANTES de borrar para capturar el estado final
            // pero como el usuario pide auditoría, aquí se registra el hecho.
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'DELETE', 'vicidial_campaigns', 'campaign_id', $id);
            return true;
        }
        return false;
    }
}
