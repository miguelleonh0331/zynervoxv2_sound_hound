<?php

namespace Includes;

use Includes\Database;
use PDO;

require_once __DIR__ . '/Database.php';

class Audit {
    
    public static function logAccess($user, $action, $details = '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        try {
            // getInstance() adentro del try: sin astguiclient.conf (hosts sin
            // VICIdial, ej. login via zynervox_users) esto no debe propagar
            // y romper al que llama (Auth::login ya tuvo exito en ese caso).
            $isolated = \Config\Config::deployment('isolated', false);
            $db = $isolated ? Database::getCoreInstance() : Database::getInstance();
            $table = $isolated ? 'v2_access_log' : 'vox_sphere_access_log';
            $stmt = $db->prepare("INSERT INTO $table (user, action, ip_address, details) VALUES (:user, :action, :ip, :details)");
            return $stmt->execute(['user' => $user, 'action' => $action, 'ip' => $ip, 'details' => $details]);
        } catch (\Throwable $e) { return false; }
    }

    public static function logTableChange($user, $action, $table, $idField, $idValue, $extraCriteria = []) {
        $logTable = "vox_sphere_{$table}_log";

        try {
            $db = Database::getInstance();
            // Construir WHERE dinámico para identificar el registro único
            $where = "$idField = :id_val";
            $params = ['id_val' => $idValue];
            
            foreach ($extraCriteria as $field => $val) {
                $where .= " AND $field = :$field";
                $params[$field] = $val;
            }

            // Obtener el registro actual para el snapshot
            $stmt = $db->prepare("SELECT * FROM $table WHERE $where");
            $stmt->execute($params);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$data) return false;

            // Añadir campos de auditoría al snapshot
            $data['audit_user'] = $user;
            $data['audit_action'] = $action;

            $columns = implode(", ", array_keys($data));
            $placeholders = ":" . implode(", :", array_keys($data));
            
            $sql = "INSERT INTO $logTable ($columns) VALUES ($placeholders)";
            return $db->prepare($sql)->execute($data);
        } catch (\Throwable $e) { return false; }
    }

    public static function getRecent($table = 'access', $limit = 50) {
        $tableName = ($table == 'access') ? 'vox_sphere_access_log' : "vox_sphere_{$table}_log";
        $orderField = ($table == 'access') ? 'event_time' : 'audit_timestamp';

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM $tableName ORDER BY $orderField DESC LIMIT :limit");
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) { return []; }
    }
}
