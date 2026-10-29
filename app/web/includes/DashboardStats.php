<?php

namespace Includes;

use Includes\Database;
use PDO;

require_once __DIR__ . '/Database.php';

class DashboardStats {

    // Conteos activos/inactivos por tipo de registro, para la tabla
    // "Resumen del Sistema" del dashboard.
    public static function getTenantSummary() {
        // $db no se usa aca -- cada countByFlag() pide su propia instancia
        // (y ya esta protegida con try/catch). No duplicar la conexion sin
        // protegerla: sin astguiclient.conf esto reventaba antes de llegar
        // a countByFlag().
        $rows = [];

        $rows['users'] = self::countByFlag(
            "SELECT active, COUNT(*) c FROM vicidial_users WHERE user NOT IN ('VDAD','VDCL') GROUP BY active"
        );
        $rows['campaigns'] = self::countByFlag(
            "SELECT active, COUNT(*) c FROM vicidial_campaigns GROUP BY active"
        );
        $rows['lists'] = self::countByFlag(
            "SELECT active, COUNT(*) c FROM vicidial_lists GROUP BY active"
        );
        $rows['ingroups'] = self::countByFlag(
            "SELECT active, COUNT(*) c FROM vicidial_inbound_groups GROUP BY active"
        );
        $rows['dids'] = self::countByFlag(
            "SELECT active, COUNT(*) c FROM vicidial_inbound_dids GROUP BY active"
        );

        return $rows;
    }

    private static function countByFlag($sql) {
        $active = 0; $inactive = 0;
        try {
            $db = Database::getInstance();
            $stmt = $db->query($sql);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (($r['active'] ?? 'N') === 'Y') { $active = (int)$r['c']; }
                else { $inactive += (int)$r['c']; }
            }
        } catch (\PDOException $e) {
            return ['active' => 0, 'inactive' => 0, 'total' => 0];
        }
        return ['active' => $active, 'inactive' => $inactive, 'total' => $active + $inactive];
    }

    // Conteos en vivo de agentes/llamadas para las 4 tarjetas superiores.
    // Basado en la misma tabla que usa el Monitor (vicidial_live_agents).
    public static function getLiveCounts() {
        $out = [
            'connected' => 0, 'incall' => 0, 'ready' => 0, 'paused' => 0,
            'queue' => 0, 'ringing' => 0,
        ];
        try {
            $db = Database::getInstance();
        } catch (\PDOException $e) {
            // Sin astguiclient.conf (ej. labs sin VICIdial): conteos en 0
            // en vez de reventar el dashboard completo.
            return $out;
        }
        try {
            $stmt = $db->query("SELECT status, COUNT(*) c FROM vicidial_live_agents GROUP BY status");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['connected'] += (int)$r['c'];
                switch ($r['status']) {
                    case 'INCALL': $out['incall'] = (int)$r['c']; break;
                    case 'READY':  $out['ready']  = (int)$r['c']; break;
                    case 'PAUSED': $out['paused'] = (int)$r['c']; break;
                    case 'QUEUE':
                    case 'MQUEUE': $out['queue'] += (int)$r['c']; break;
                }
            }
        } catch (\PDOException $e) {}

        try {
            $stmt = $db->query("SELECT COUNT(*) c FROM vicidial_auto_calls");
            $out['ringing'] = (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            $out['ringing'] = null; // tabla no disponible / sin permisos
        }

        return $out;
    }
}
