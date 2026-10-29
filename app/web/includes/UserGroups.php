<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class UserGroups {
    
    public static function getTemplateValues() {
        // NOTA: el template original traia una columna 'modify_stamp' que no
        // existe en el schema de VICIdial 2.14 (INSERT fallaba con
        // "Unknown column 'modify_stamp'"). Se quito de headers/values.
        $headers = explode(';', 'user_group;group_name;allowed_campaigns;qc_allowed_campaigns;qc_allowed_inbound_groups;group_shifts;forced_timeclock_login;shift_enforcement;agent_status_viewable_groups;agent_status_view_time;agent_call_log_view;agent_xfer_consultative;agent_xfer_dial_override;agent_xfer_vm_transfer;agent_xfer_blind_transfer;agent_xfer_dial_with_customer;agent_xfer_park_customer_dial;agent_fullscreen;allowed_reports;webphone_url_override;webphone_systemkey_override;webphone_dialpad_override;admin_viewable_groups;admin_viewable_call_times;allowed_custom_reports;agent_allowed_chat_groups;agent_xfer_park_3way;admin_ip_list;agent_ip_list;api_ip_list;webphone_layout;allowed_queue_groups;reports_header_override;admin_home_url;script_id');
        $values = explode(';', 'ADMIN;VICIDIAL ADMINISTRATORS; -ALL-CAMPAIGNS- - -;\N;\N;\N;N;OFF; --ALL-GROUPS-- ;N;N;Y;Y;Y;Y;Y;Y;N;ALL REPORTS;;;DISABLED; ---ALL--- ; ---ALL--- ;; --ALL-GROUPS-- ;Y;;;;;\N;DISABLED;;');

        $template = [];
        foreach ($headers as $i => $header) {
            $template[$header] = ($values[$i] === '\N') ? null : $values[$i];
        }
        return $template;
    }

    public static function getAll() {
        $db = Database::getInstance();
        try {
            $stmt = $db->query("SELECT user_group, group_name FROM vicidial_user_groups ORDER BY user_group ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { return []; }
    }

    public static function getById($groupId) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("SELECT * FROM vicidial_user_groups WHERE user_group = :id");
            $stmt->execute(['id' => $groupId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { return null; }
    }

    public static function create($data) {
        $db = Database::getInstance();
        try {
            $template = self::getTemplateValues();
            $finalData = array_merge($template, $data);

            $columns = implode(", ", array_keys($finalData));
            $placeholders = ":" . implode(", :", array_keys($finalData));
            
            $sql = "INSERT INTO vicidial_user_groups ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($finalData)) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'CREATE', 'vicidial_user_groups', 'user_group', $data['user_group']);
                return true;
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }

    public static function update($groupId, $data) {
        $db = Database::getInstance();
        try {
            $setParts = [];
            foreach (array_keys($data) as $key) {
                $setParts[] = "$key = :$key";
            }
            $setSql = implode(", ", $setParts);
            
            $sql = "UPDATE vicidial_user_groups SET $setSql WHERE user_group = :groupId";
            $data['groupId'] = $groupId;
            
            $stmt = $db->prepare($sql);
            if ($stmt->execute($data)) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'UPDATE', 'vicidial_user_groups', 'user_group', $groupId);
                return true;
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }

    public static function delete($groupId) {
        $db = Database::getInstance();
        try {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'DELETE', 'vicidial_user_groups', 'user_group', $groupId);
            $stmt = $db->prepare("DELETE FROM vicidial_user_groups WHERE user_group = :id");
            return $stmt->execute(['id' => $groupId]);
        } catch (\PDOException $e) { return false; }
    }
}
