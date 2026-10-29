<?php
namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;
use Exception;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Lists {
    
    public static function getAll() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT l.*, (SELECT count(*) FROM vicidial_list WHERE list_id = l.list_id) as lead_count FROM vicidial_lists l ORDER BY l.list_id ASC");
        return $stmt->fetchAll();
    }

    public static function getById($id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM vicidial_lists WHERE list_id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($data) {
        $db = Database::getInstance();

        $defaults = [
            'list_name' => '',
            'campaign_id' => 'TESTCAMP',
            'active' => 'N',
            'list_description' => '',
            'list_changedate' => date('Y-m-d H:i:s'),
            'reset_time' => '',
            'agent_script_override' => '',
            'campaign_cid_override' => '',
            'am_message_exten_override' => '',
            'drop_inbound_group_override' => '',
            'xferconf_a_number' => '',
            'xferconf_b_number' => '',
            'xferconf_c_number' => '',
            'xferconf_d_number' => '',
            'xferconf_e_number' => '',
            'web_form_address' => '',
            'web_form_address_two' => '',
            'time_zone_setting' => 'COUNTRY_AND_AREA_CODE',
            'inventory_report' => 'Y',
            'expiration_date' => '2099-12-31',
            'na_call_url' => '',
            'local_call_time' => 'campaign',
            'web_form_address_three' => '',
            'status_group_id' => '',
            'user_new_lead_limit' => -1,
            'inbound_list_script_override' => '',
            'default_xfer_group' => '---NONE---',
            'daily_reset_limit' => -1,
            'resets_today' => 0,
            'auto_active_list_rank' => 0,
            'cache_count' => 0,
            'cache_count_new' => 0,
            'cache_count_dialable_new' => 0,
            'inbound_drop_voicemail' => '',
            'inbound_after_hours_voicemail' => '',
            'qc_scorecard_id' => '',
            'qc_statuses_id' => '',
            'qc_web_form_address' => '',
            'auto_alt_threshold' => -1,
            'cid_group_id' => '---DISABLED---',
            'dial_prefix' => '',
            'weekday_resets_container' => 'DISABLED'
        ];

        $finalData = array_merge($defaults, $data);
        
        $columns = implode(", ", array_keys($finalData));
        $placeholders = ":" . implode(", :", array_keys($finalData));
        
        $sql = "INSERT INTO vicidial_lists ($columns) VALUES ($placeholders)";
        $stmt = $db->prepare($sql);
        
        if ($stmt->execute($finalData)) {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'CREATE', 'vicidial_lists', 'list_id', $finalData['list_id']);
            return true;
        }
        return false;
    }

    public static function updateCampaign($id, $campaign_id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE vicidial_lists SET campaign_id = :camp WHERE list_id = :id");
        if ($stmt->execute(['camp' => $campaign_id, 'id' => $id])) {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'UPDATE_CAMPAIGN', 'vicidial_lists', 'list_id', $id);
            return true;
        }
        return false;
    }

    public static function toggleStatus($id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE vicidial_lists SET active = CASE WHEN active = 'Y' THEN 'N' ELSE 'Y' END WHERE list_id = :id");
        if ($stmt->execute(['id' => $id])) {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'TOGGLE_STATUS', 'vicidial_lists', 'list_id', $id);
            return true;
        }
        return false;
    }

    public static function delete($id, $deleteLeads = true) {
        $db = Database::getInstance();
        
        if ($deleteLeads) {
            $stmtLeads = $db->prepare("DELETE FROM vicidial_list WHERE list_id = :id");
            $stmtLeads->execute(['id' => $id]);
        }

        $stmt = $db->prepare("DELETE FROM vicidial_lists WHERE list_id = :id");
        if ($stmt->execute(['id' => $id])) {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'DELETE', 'vicidial_lists', 'list_id', $id);
            return true;
        }
        return false;
    }
}
