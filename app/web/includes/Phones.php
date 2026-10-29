<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Phones {
    private static function database() {
        if (!\Config\Config::deployment('isolated', false)) return Database::getInstance();
        if (\Config\Config::get('CORE_DB_database') !== 'zynervox_core') throw new \RuntimeException('Phones requiere zynervox_core');
        return Database::getCoreInstance();
    }
    private static function table() {
        return \Config\Config::deployment('isolated', false) ? 'v2_phones' : 'phones';
    }
    private static function audit($action, $extension) {
        if (\Config\Config::deployment('isolated', false)) return Audit::logAccess($_SESSION['user'] ?? 'system', 'PHONE_'.$action, $extension);
        return Audit::logTableChange($_SESSION['user'] ?? 'system', $action, 'phones', 'extension', $extension);
    }
    public static function recentChanges() {
        if (!\Config\Config::deployment('isolated', false)) return Audit::getRecent('phones',20);
        return self::database()->query("SELECT created_at AS audit_timestamp, user AS audit_user, action AS audit_action, details AS extension, '' AS fullname FROM v2_access_log WHERE action IN ('PHONE_CREATE','PHONE_UPDATE','PHONE_DELETE') ORDER BY id DESC LIMIT 20")->fetchAll();
    }
    public static function generateConfiguration() {
        if (!\Config\Config::deployment('isolated', false)) return true;
        $directory = rtrim((string)\Config\Config::deployment('runtime'), '/').'/modules/asterisk';
        if (strpos($directory, '/var/lib/zynervoxv2/') !== 0 || realpath($directory) !== $directory || !is_writable($directory)) throw new \RuntimeException('Directorio de anexos no disponible');
        $sip = "; Anexos SIP v2 - generado automaticamente\n\n";
        $pjsip = "; Anexos PJSIP v2 - generado automaticamente\n\n";
        $rows = self::database()->query("SELECT * FROM v2_phones WHERE active='Y' ORDER BY extension")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            self::validate(array_intersect_key($row, array_flip(['extension','login','pass','fullname','outbound_cid','active','protocol'])));
            $id = $row['extension'];
            $login = $row['login'];
            $secret = $row['pass'];
            $cid = preg_replace('/[^0-9+]/', '', $row['outbound_cid']);
            if ($row['protocol'] === 'SIP') {
                $sip .= "[$id]\ntype=friend\nhost=dynamic\ndefaultuser=$login\nsecret=$secret\ncontext=zynervoxv2\ndisallow=all\nallow=ulaw\nallow=alaw\ndtmfmode=rfc2833\nqualify=yes\n";
                if ($cid !== '') $sip .= "callerid=$cid\n";
                $sip .= "\n";
            } else {
                $pjsip .= "[$id]\ntype=endpoint\ncontext=zynervoxv2\ndisallow=all\nallow=ulaw,alaw\nauth=$id-auth\naors=$id\ndtmf_mode=rfc4733\n";
                if ($cid !== '') $pjsip .= "callerid=$cid\n";
                $pjsip .= "\n[$id-auth]\ntype=auth\nauth_type=userpass\nusername=$login\npassword=$secret\n\n[$id]\ntype=aor\nmax_contacts=1\nremove_existing=yes\nqualify_frequency=60\n\n";
            }
        }
        foreach (['sip-zynervoxv2-annexos.conf'=>$sip, 'pjsip-zynervoxv2-annexos.conf'=>$pjsip] as $name=>$contents) {
            $path = $directory.'/'.$name;
            if (is_link($path)) throw new \RuntimeException('Destino de anexos invalido');
            $temporary = tempnam($directory, '.phones-');
            if ($temporary === false) throw new \RuntimeException('No se pudo preparar el archivo');
            try {
                if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0640) || !rename($temporary, $path)) throw new \RuntimeException('Datos guardados; no se pudo generar la configuracion de anexos');
            } finally { if (is_file($temporary)) unlink($temporary); }
        }
        return true;
    }
    private static function validate($data) {
        if (!\Config\Config::deployment('isolated', false)) return;
        if (!preg_match('/^[0-9]{1,20}$/D', (string)($data['extension'] ?? ''))) throw new \InvalidArgumentException('Extension invalida');
        if (isset($data['protocol']) && !in_array($data['protocol'], ['SIP', 'PJSIP'], true)) throw new \InvalidArgumentException('Protocolo invalido');
        if (isset($data['active']) && !in_array($data['active'],['Y','N'],true)) throw new \InvalidArgumentException('Estado invalido');
        foreach ($data as $key=>$value) {
            if (!in_array($key,['extension','login','pass','fullname','outbound_cid','active','protocol'],true) || !is_string($value) || strlen($value)>160 || preg_match('/[\r\n]/',$value)) throw new \InvalidArgumentException('Campo invalido');
        }
        if (isset($data['login']) && !preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $data['login'])) throw new \InvalidArgumentException('Login invalido');
        if (isset($data['pass']) && preg_match('/[;#\x00-\x20\x7f]/', $data['pass'])) throw new \InvalidArgumentException('Password contiene caracteres no admitidos en Asterisk');
    }

    
    public static function getTemplateValues() {
        if (\Config\Config::deployment('isolated', false)) return ['status'=>'ACTIVE','user_group'=>'---ALL---'];
        $headers = explode(';', 'extension;dialplan_number;voicemail_id;phone_ip;computer_ip;server_ip;login;pass;status;active;phone_type;fullname;company;picture;messages;old_messages;protocol;local_gmt;ASTmgrUSERNAME;ASTmgrSECRET;login_user;login_pass;login_campaign;park_on_extension;conf_on_extension;VICIDIAL_park_on_extension;VICIDIAL_park_on_filename;monitor_prefix;recording_exten;voicemail_exten;voicemail_dump_exten;ext_context;dtmf_send_extension;call_out_number_group;client_browser;install_directory;local_web_callerID_URL;VICIDIAL_web_URL;AGI_call_logging_enabled;user_switching_enabled;conferencing_enabled;admin_hangup_enabled;admin_hijack_enabled;admin_monitor_enabled;call_parking_enabled;updater_check_enabled;AFLogging_enabled;QUEUE_ACTION_enabled;CallerID_popup_enabled;voicemail_button_enabled;enable_fast_refresh;fast_refresh_rate;enable_persistant_mysql;auto_dial_next_number;VDstop_rec_after_each_call;DBX_server;DBX_database;DBX_user;DBX_pass;DBX_port;DBY_server;DBY_database;DBY_user;DBY_pass;DBY_port;outbound_cid;enable_sipsak_messages;email;template_id;conf_override;phone_context;phone_ring_timeout;conf_secret;delete_vm_after_email;is_webphone;use_external_server_ip;codecs_list;codecs_with_template;webphone_dialpad;on_hook_agent;webphone_auto_answer;voicemail_timezone;voicemail_options;user_group;voicemail_greeting;voicemail_dump_exten_no_inst;voicemail_instructions;on_login_report;unavail_dialplan_fwd_exten;unavail_dialplan_fwd_context;nva_call_url;nva_search_method;nva_error_filename;nva_new_list_id;nva_new_phone_code;nva_new_status;webphone_dialbox;webphone_mute;webphone_volume;webphone_debug;outbound_alt_cid;conf_qualify;webphone_layout;mohsuggest;peer_status;ping_time;webphone_settings');
        $values = explode(';', '70553559;70553559;70553559;;;192.168.1.192;70553559;70553559;ACTIVE;Y;;;;;0;0;SIP;-5.00;cron;1234;\N;\N;\N;8301;8302;8301;park;8612;8309;8501;85026666666666;default;local/8500998@default;Zap/g2/;/usr/bin/mozilla;/usr/local/perl_TK;http://www.vicidial.org/test_callerid_output.php;http://www.vicidial.org/test_VICIDIAL_output.php;1;1;1;0;0;1;1;1;1;1;1;1;0;1000;0;1;1;\N;asterisk;cron;1234;3306;\N;asterisk;cron;1234;3306;70553559;0;\N;;\N;default;60;ngFoavIybh37nM8;N;N;N;;0;Y;N;Y;eastern;;---ALL---;;85026666666667;Y;N;;;\N;NONE;;995;1;NVAINS;Y;Y;Y;N;;Y;;;UNKNOWN;\N;VICIPHONE_SETTINGS');
        
        $template = [];
        foreach ($headers as $i => $header) {
            $val = $values[$i] ?? '';
            if ($val === '\N') $val = null;
            $template[$header] = $val;
        }
        return $template;
    }

    public static function createFromUser($user, $fullName) {
        if (\Config\Config::deployment('isolated',false)) return self::getById($user) ? true : self::create(['extension'=>$user,'login'=>$user,'pass'=>bin2hex(random_bytes(20)),'fullname'=>$fullName,'outbound_cid'=>$user,'active'=>'Y','protocol'=>'PJSIP']);
        $db = self::database();
        $table = self::table();
        try {
            // Verificar si ya existe
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM $table WHERE login = :u OR extension = :e");
            $stmtCheck->execute(['u' => $user, 'e' => $user]);
            if ($stmtCheck->fetchColumn() > 0) return true; // Ya existe, no hacemos nada

            $template = self::getTemplateValues();
            
            // Personalizar con los datos del usuario
            $data = $template;
            $data['extension'] = $user;
            $data['dialplan_number'] = $user;
            $data['voicemail_id'] = $user;
            $data['login'] = $user;
            $data['pass'] = $user;
            $data['fullname'] = $fullName;
            $data['outbound_cid'] = $user;

            $columns = implode(", ", array_keys($data));
            $placeholders = ":" . implode(", :", array_keys($data));
            
            $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($data)) {
                self::audit('CREATE', $user);
                return true;
            }
        } catch (\PDOException $e) { 
            error_log("Error creando phone para $user: " . $e->getMessage());
            return false; 
        }
        return false;
    }

    public static function delete($extension) {
        $db = self::database();
        $table = self::table();
        try {
            self::audit('DELETE', $extension);
            $stmt = $db->prepare("DELETE FROM $table WHERE extension = :id");
            return $stmt->execute(['id' => $extension]) && self::generateConfiguration();
        } catch (\PDOException $e) { return false; }
    }

    public static function getAll($search = null) {
        $db = self::database();
        $table = self::table();
        $sql = "SELECT extension, login, fullname, protocol, active, status, user_group, outbound_cid FROM $table";
        $params = [];
        if ($search) {
            $sql .= " WHERE extension LIKE :s1 OR login LIKE :s2 OR fullname LIKE :s3";
            $params = ['s1' => "%$search%", 's2' => "%$search%", 's3' => "%$search%"];
        }
        $sql .= " ORDER BY extension ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById($extension) {
        $db = self::database();
        $table = self::table();
        $stmt = $db->prepare("SELECT * FROM $table WHERE extension = :id LIMIT 1");
        $stmt->execute(['id' => $extension]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Creacion manual de un anexo (no ligado a un Usuario), para telefonos
    // fisicos/softphones que necesiten extension propia sin pasar por Users.php.
    public static function create($data) {
        self::validate($data);
        if (\Config\Config::deployment('isolated',false) && empty($data['pass'])) throw new \InvalidArgumentException('Password requerido');
        $db = self::database();
        $table = self::table();
        try {
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM $table WHERE extension = :e");
            $stmtCheck->execute(['e' => $data['extension']]);
            if ($stmtCheck->fetchColumn() > 0) return false; // Ya existe ese anexo

            $template = self::getTemplateValues();
            $finalData = array_merge($template, $data);

            $columns = implode(", ", array_keys($finalData));
            $placeholders = ":" . implode(", :", array_keys($finalData));

            $stmt = $db->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
            if ($stmt->execute($finalData)) {
                self::audit('CREATE', $data['extension']);
                return self::generateConfiguration();
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }

    public static function update($extension, $data) {
        $data['extension'] = $extension;
        self::validate($data);
        if (\Config\Config::deployment('isolated',false) && ($data['pass'] ?? '') === '') unset($data['pass']);
        $db = self::database();
        $table = self::table();
        try {
            $setParts = [];
            foreach (array_keys($data) as $key) {
                $setParts[] = "$key = :$key";
            }
            $setSql = implode(", ", $setParts);
            $data['extensionKey'] = $extension;

            $stmt = $db->prepare("UPDATE $table SET $setSql WHERE extension = :extensionKey");
            if ($stmt->execute($data)) {
                self::audit('UPDATE', $extension);
                return self::generateConfiguration();
            }
        } catch (\PDOException $e) { return false; }
        return false;
    }
}
