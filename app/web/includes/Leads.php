<?php
namespace Includes;

use Includes\Database;
use PDO;
use Exception;

require_once __DIR__ . '/Database.php';

class Leads {
    
    public static function bulkInsertMapped($list_id, $leads, $mapping) {
        $db = Database::getInstance();
        
        $validFields = [
            'vendor_lead_code', 'source_id', 'phone_number', 'title', 
            'first_name', 'middle_initial', 'last_name', 'address1', 
            'address2', 'address3', 'city', 'state', 'province', 
            'postal_code', 'country_code', 'gender', 'date_of_birth', 
            'alt_phone', 'email', 'security_phrase', 'comments', 'rank', 'owner', 'phone_code',
            'status', 'user', 'gmt_offset_now', 'called_since_last_reset', 'called_count',
            'last_local_call_time', 'entry_list_id'
        ];

        $list_id = (float)$list_id; // Asegurar que sea numérico para BIGINT

        $columns = ["entry_date", "list_id"];
        $placeholders = [":entry_date", ":list_id"];
        
        // El status y user pueden venir del mapeo o usar defaults
        $hasStatus = false; $hasUser = false;
        
        foreach ($mapping as $csvHeader => $dbField) {
            if (in_array($dbField, $validFields)) {
                $columns[] = $dbField;
                $placeholders[] = ":$dbField";
                if ($dbField === 'status') $hasStatus = true;
                if ($dbField === 'user') $hasUser = true;
            }
        }

        if (!$hasStatus) { $columns[] = "status"; $placeholders[] = "'NEW'"; }
        if (!$hasUser) { $columns[] = "user"; $placeholders[] = "'admin'"; }

        $sql = "INSERT INTO vicidial_list (".implode(", ", $columns).") VALUES (".implode(", ", $placeholders).")";
        $stmt = $db->prepare($sql);
        
        $entry_date = date('Y-m-d H:i:s');
        $success = 0;
        $allParams = [];
        
        foreach ($leads as $lead) {
            $params = [
                'entry_date' => $entry_date,
                'list_id' => $list_id
            ];
            
            foreach ($mapping as $csvHeader => $dbField) {
                if (in_array($dbField, $validFields)) {
                    $val = $lead[$csvHeader] ?? '';
                    
                    // --- VALIDACIÓN DE TIPOS ---
                    if ($dbField == 'phone_number' || $dbField == 'alt_phone') {
                        $val = preg_replace('/[^0-9]/', '', $val);
                    } elseif ($dbField == 'rank' || $dbField == 'called_count') {
                        $val = (int)$val;
                    } elseif ($dbField == 'gmt_offset_now') {
                        $val = (float)$val;
                    } elseif ($dbField == 'entry_list_id') {
                        $val = (float)$val;
                    }
                    
                    $params[$dbField] = $val;
                }
            }
            
            if ($stmt->execute($params)) {
                // Capturar ID local para el espejo
                $params['lead_id'] = $db->lastInsertId();
                $allParams[] = $params;
                $success++;
            }
        }

        // --- HOOK DE SINCRONIZACIÓN ESPEJO ---
        if ($success > 0) {
            self::syncToMirror($allParams);
        }
        
        return $success;
    }

    private static function syncToMirror($allParams) {
        $reportingFile = __DIR__ . '/Reporting.php';
        if (file_exists($reportingFile)) {
            require_once $reportingFile;
            $config = \Includes\Reporting::getConfig();
            if ($config && $config['is_active'] == 1) {
                foreach ($allParams as $params) {
                    \Includes\Reporting::syncLead($params);
                }
            }
        }
    }

    public static function bulkInsert($list_id, $leads) {
        $mapping = [
            'phone_number' => 'phone_number',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'address1' => 'address1',
            'vendor_lead_code' => 'vendor_lead_code'
        ];
        return self::bulkInsertMapped($list_id, $leads, $mapping);
    }

    public static function search($query) {
        $db = Database::getInstance();
        // NOTA: placeholders nombrados repetidos (:q usado 3 veces) fallan con
        // "Invalid parameter number" cuando ATTR_EMULATE_PREPARES esta en false;
        // cada ocurrencia necesita su propio nombre aunque el valor sea igual.
        $stmt = $db->prepare("SELECT lead_id, phone_number, first_name, last_name, list_id, status FROM vicidial_list WHERE phone_number LIKE :q1 OR first_name LIKE :q2 OR last_name LIKE :q3 OR lead_id = :qi LIMIT 50");
        $q = "%$query%";
        $qi = is_numeric($query) ? (int)$query : 0;
        $stmt->execute(['q1' => $q, 'q2' => $q, 'q3' => $q, 'qi' => $qi]);
        return $stmt->fetchAll();
    }

    public static function getByList($list_id, $limit = 50) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT lead_id, phone_number, first_name, last_name, status, entry_date FROM vicidial_list WHERE list_id = :id LIMIT :limit");
        $stmt->bindValue(':id', $list_id);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function getById($lead_id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT lead_id, phone_number, first_name, last_name, list_id, status FROM vicidial_list WHERE lead_id = :id LIMIT 1");
        $stmt->execute(['id' => $lead_id]);
        return $stmt->fetch();
    }

    // Historial de llamadas de un lead (equivalente a "Search Lead & Recording"
    // de VICIdial stock: vicidial_log guarda una fila por cada intento de
    // llamada saliente/entrante asociado a ese lead_id).
    public static function getCallHistory($lead_id, $limit = 100) {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT call_date, campaign_id, user, status, length_in_sec, term_reason, phone_number, comments
             FROM vicidial_log WHERE lead_id = :id ORDER BY call_date DESC LIMIT :limit"
        );
        $stmt->bindValue(':id', (int)$lead_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Grabaciones asociadas a un lead (tabla recording_log, poblada por el
    // proceso de archivado de Asterisk/Monitor). "location" puede ser una
    // ruta local en disco o una URL remota segun este configurado el archivo
    // remoto (VARFTP_*/VARHTTP_path en astguiclient.conf).
    public static function getRecordings($lead_id, $limit = 50) {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT recording_id, start_time, end_time, length_in_sec, filename, location, user, vicidial_id
             FROM recording_log WHERE lead_id = :id ORDER BY start_time DESC LIMIT :limit"
        );
        $stmt->bindValue(':id', (int)$lead_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function getRecordingById($recording_id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT recording_id, filename, location, lead_id FROM recording_log WHERE recording_id = :id LIMIT 1");
        $stmt->execute(['id' => $recording_id]);
        return $stmt->fetch();
    }
}
