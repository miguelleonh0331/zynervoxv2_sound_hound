<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Phones.php';

class Users {
    
    public static function getTemplateValues() {
        // NOTA: igual que en UserGroups.php, el template original traia
        // columnas 'modify_stamp' y 'manual_dial_lead_id' que no existen en
        // el schema real de vicidial_users en esta version de VICIdial 2.14
        // (la tabla termina en 'hci_enabled'). Se quitaron de headers/values.
        $headers = explode(';', 'user_id;user;pass;full_name;user_level;user_group;phone_login;phone_pass;delete_users;delete_user_groups;delete_lists;delete_campaigns;delete_ingroups;delete_remote_agents;load_leads;campaign_detail;ast_admin_access;ast_delete_phones;delete_scripts;modify_leads;hotkeys_active;change_agent_campaign;agent_choose_ingroups;closer_campaigns;scheduled_callbacks;agentonly_callbacks;agentcall_manual;vicidial_recording;vicidial_transfers;delete_filters;alter_agent_interface_options;closer_default_blended;delete_call_times;modify_call_times;modify_users;modify_campaigns;modify_lists;modify_scripts;modify_filters;modify_ingroups;modify_usergroups;modify_remoteagents;modify_servers;view_reports;vicidial_recording_override;alter_custdata_override;qc_enabled;qc_user_level;qc_pass;qc_finish;qc_commit;add_timeclock_log;modify_timeclock_log;delete_timeclock_log;alter_custphone_override;vdc_agent_api_access;modify_inbound_dids;delete_inbound_dids;active;alert_enabled;download_lists;agent_shift_enforcement_override;manager_shift_enforcement_override;shift_override_flag;export_reports;delete_from_dnc;email;user_code;territory;allow_alerts;agent_choose_territories;custom_one;custom_two;custom_three;custom_four;custom_five;voicemail_id;agent_call_log_view_override;callcard_admin;agent_choose_blended;realtime_block_user_info;custom_fields_modify;force_change_password;agent_lead_search_override;modify_shifts;modify_phones;modify_carriers;modify_labels;modify_statuses;modify_voicemail;modify_audiostore;modify_moh;modify_tts;preset_contact_search;modify_contacts;modify_same_user_level;admin_hide_lead_data;admin_hide_phone_data;agentcall_email;modify_email_accounts;failed_login_count;last_login_date;last_ip;pass_hash;alter_admin_interface_options;max_inbound_calls;modify_custom_dialplans;wrapup_seconds_override;modify_languages;selected_language;user_choose_language;ignore_group_on_search;api_list_restrict;api_allowed_functions;lead_filter_id;admin_cf_show_hidden;agentcall_chat;user_hide_realtime;access_recordings;modify_colors;user_nickname;user_new_lead_limit;api_only_user;modify_auto_reports;modify_ip_lists;ignore_ip_list;ready_max_logout;export_gdpr_leads;pause_code_approval;max_hopper_calls;max_hopper_calls_hour;mute_recordings;hide_call_log_info;next_dial_my_callbacks;user_admin_redirect_url;max_inbound_filter_enabled;max_inbound_filter_statuses;max_inbound_filter_ingroups;max_inbound_filter_min_sec;status_group_id;mobile_number;two_factor_override;manual_dial_filter;user_location;download_invalid_files;user_group_two;failed_login_attempts_today;failed_login_count_today;failed_last_ip_today;failed_last_type_today;modify_dial_prefix;inbound_credits;hci_enabled');
        $values = explode(';', '8;PLUSER001;PLUSER001;PLUSER001;1;SINASIGNAR;PLUSER001;PLUSER001;0;0;0;0;0;0;0;0;0;0;0;0;0;0;1;;1;1;1;1;1;0;0;0;0;0;0;0;0;0;0;0;0;0;0;0;DISABLED;NOT_ACTIVE;0;1;0;0;0;0;0;0;NOT_ACTIVE;0;0;0;Y;0;0;DISABLED;0;0;0;0;;;;0;0;;;;;;;DISABLED;0;1;0;0;N;NOT_ACTIVE;0;0;0;0;0;0;0;0;0;NOT_ACTIVE;0;1;0;0;0;0;0;2001-01-01 00:00:01;;;1;0;0;-1;0;default English;0;0;0; ALL_FUNCTIONS ;NONE;0;0;0;0;0;;-1;0;0;0;0;-1;0;0;0;0;DISABLED;DISABLED;NOT_ACTIVE;;0;;;-1;;;NOT_ACTIVE;DISABLED;;0;;0;0;;;0;-1;0');
        
        $template = [];
        foreach ($headers as $i => $header) {
            $template[$header] = $values[$i] ?? '';
        }
        return $template;
    }

    public static function getAll($group = null, $search = null) {
        $db = Database::getInstance();
        try {
            // Buscamos la fecha de creación en el log si existe
            // VDAD y VDCL son cuentas internas del sistema VICIdial (Outbound Auto Dial /
            // Inbound No Agent), no usuarios reales: se ocultan siempre del listado.
            $sql = "SELECT u.user_id, u.user, u.full_name, u.user_level, u.user_group, u.active, u.last_login_date,
                    (SELECT MIN(audit_timestamp) FROM vox_sphere_vicidial_users_log WHERE user = u.user AND audit_action = 'CREATE') as created_at
                    FROM vicidial_users u WHERE u.user NOT IN ('VDAD', 'VDCL')";
            $params = [];
            
            if ($group) {
                $sql .= " AND u.user_group = :group";
                $params['group'] = $group;
            }
            
            if ($search) {
                $sql .= " AND (u.user LIKE :search OR u.full_name LIKE :search2 OR u.user_id LIKE :search3)";
                $params['search'] = "%$search%";
                $params['search2'] = "%$search%";
                $params['search3'] = "%$search%";
            }

            $sql .= " ORDER BY u.user ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { return []; }
    }

    public static function getUserGroups() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT user_group, group_name FROM vicidial_user_groups ORDER BY user_group ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Agentes Remotos (GSM): usuarios de vicidial_users que NO tienen anexo
    // propio en la tabla "phones". Es la misma tabla/grupos que Usuarios,
    // solo que estos se loguean marcando a un numero externo (celular) en
    // vez de registrar una extension SIP local.
    public static function getAllRemote($group = null, $search = null) {
        $db = Database::getInstance();
        try {
            $sql = "SELECT u.user_id, u.user, u.full_name, u.user_level, u.user_group, u.active, u.phone_login, u.last_login_date,
                    (SELECT MIN(audit_timestamp) FROM vox_sphere_vicidial_users_log WHERE user = u.user AND audit_action = 'CREATE') as created_at
                    FROM vicidial_users u
                    LEFT JOIN phones p ON p.login = u.user
                    WHERE u.user NOT IN ('VDAD', 'VDCL') AND p.login IS NULL";
            $params = [];

            if ($group) {
                $sql .= " AND u.user_group = :group";
                $params['group'] = $group;
            }

            if ($search) {
                $sql .= " AND (u.user LIKE :search OR u.full_name LIKE :search2 OR u.user_id LIKE :search3)";
                $params['search'] = "%$search%";
                $params['search2'] = "%$search%";
                $params['search3'] = "%$search%";
            }

            $sql .= " ORDER BY u.user ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { return []; }
    }

    public static function getById($userId) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("SELECT * FROM vicidial_users WHERE user_id = :id");
            $stmt->execute(['id' => $userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { return null; }
    }

    // $skipPhone = true para Agentes Remotos (GSM): comparten tabla y grupos
    // con Usuarios normales, pero no se les debe crear anexo en "phones"
    // porque se loguean marcando a un numero externo, no con una extension SIP.
    public static function create($data, $skipPhone = false) {
        $db = Database::getInstance();
        try {
            // Resolución inteligente de grupo por ID o Nombre
            $stmtGroup = $db->prepare("SELECT user_group FROM vicidial_user_groups WHERE user_group = :ug OR group_name = :gn LIMIT 1");
            $stmtGroup->execute(['ug' => $data['user_group'], 'gn' => $data['user_group']]);
            $resolvedGroup = $stmtGroup->fetchColumn();

            if (!$resolvedGroup) return false; // El grupo no existe

            // Usar el ID de grupo resuelto (evita fallos por usar el nombre descriptivo)
            $data['user_group'] = $resolvedGroup;

            $template = self::getTemplateValues();
            // Merge actual data with template values
            $finalData = array_merge($template, $data);
            unset($finalData['user_id']);

            $columns = implode(", ", array_keys($finalData));
            $placeholders = ":" . implode(", :", array_keys($finalData));

            $sql = "INSERT INTO vicidial_users ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);

            if ($stmt->execute($finalData)) {
                $newId = $db->lastInsertId();
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'CREATE', 'vicidial_users', 'user_id', $newId);

                // Vincular creación de Phones si nivel = 1 (Agente), salvo
                // que sea un Agente Remoto (GSM) explicitamente marcado.
                if (!$skipPhone && ($data['user_level'] ?? 0) == 1) {
                    Phones::createFromUser($data['user'], $data['full_name']);
                }

                return $newId;
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }

    public static function update($userId, $data) {
        $db = Database::getInstance();
        try {
            $setParts = [];
            foreach (array_keys($data) as $key) {
                $setParts[] = "$key = :$key";
            }
            $setSql = implode(", ", $setParts);
            
            $sql = "UPDATE vicidial_users SET $setSql WHERE user_id = :userId";
            $data['userId'] = $userId;
            
            $stmt = $db->prepare($sql);
            if ($stmt->execute($data)) {
                Audit::logTableChange($_SESSION['user'] ?? 'system', 'UPDATE', 'vicidial_users', 'user_id', $userId);
                return true;
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }

    public static function delete($userId) {
        $db = Database::getInstance();
        try {
            // Obtener el nombre de usuario antes de borrar para borrar su phone
            $user = self::getById($userId);
            if ($user) {
                Phones::delete($user['user']);
            }

            Audit::logTableChange($_SESSION['user'] ?? 'system', 'DELETE', 'vicidial_users', 'user_id', $userId);
            $stmt = $db->prepare("DELETE FROM vicidial_users WHERE user_id = :id");
            return $stmt->execute(['id' => $userId]);
        } catch (\PDOException $e) { return false; }
    }
}
