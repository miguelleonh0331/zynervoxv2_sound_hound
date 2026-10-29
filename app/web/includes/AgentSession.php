<?php
namespace Includes;

use PDO;
use Exception;

class AgentSession {
    private $db;
    private $server_ip;

    public function __construct($db, $server_ip) {
        $this->db = $db;
        $this->server_ip = $server_ip;
    }

    /**
     * Obtiene las campañas disponibles para un agente según sus permisos
     */
    public function getAvailableCampaigns($user) {
        // 1. Obtener el grupo del usuario
        $stmt_user = $this->db->prepare("SELECT user_group FROM vicidial_users WHERE user = ?");
        $stmt_user->execute([$user]);
        $user_group = $stmt_user->fetchColumn();

        if (!$user_group) return [];

        // 2. Obtener campañas permitidas para ese grupo
        $stmt_group = $this->db->prepare("SELECT allowed_campaigns FROM vicidial_user_groups WHERE user_group = ?");
        $stmt_group->execute([$user_group]);
        $allowed = $stmt_group->fetchColumn();

        // 3. Consultar las campañas activas
        if ($allowed === '-ALL-CAMPAIGNS-') {
            $stmt = $this->db->query("SELECT campaign_id, campaign_name FROM vicidial_campaigns WHERE active='Y' ORDER BY campaign_id");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Limpiar y convertir el string " CAMP1 CAMP2 " en un array
            $allowed_list = array_values(array_filter(explode(' ', trim($allowed))));
            if (empty($allowed_list)) return [];

            $placeholders = implode(',', array_fill(0, count($allowed_list), '?'));
            $sql = "SELECT campaign_id, campaign_name FROM vicidial_campaigns 
                    WHERE active='Y' AND campaign_id IN ($placeholders) 
                    ORDER BY campaign_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($allowed_list);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /**
     * Inicia una sesión de agente en vicidial_live_agents
     */
    public function startSession($user, $campaign_id, $extension) {
        // 1. Buscar una conferencia libre (NULL o vacía)
        // Intentamos primero con la IP del servidor configurada
        $sql = "SELECT conf_exten FROM vicidial_conferences 
                WHERE (extension IS NULL OR extension = '') 
                AND server_ip = ? 
                LIMIT 1";
        
        $conf_stmt = $this->db->prepare($sql);
        $conf_stmt->execute([$this->server_ip]);
        $conference = $conf_stmt->fetchColumn();

        // Fallback: Si no hay en esta IP, buscamos cualquier conferencia libre en el sistema
        // Esto ayuda si hay discrepancias de IP entre la DB y la config
        if (!$conference) {
            $fallback_sql = "SELECT conf_exten, server_ip FROM vicidial_conferences 
                             WHERE (extension IS NULL OR extension = '') 
                             LIMIT 1";
            $fb_stmt = $this->db->query($fallback_sql);
            $fb_row = $fb_stmt->fetch(PDO::FETCH_ASSOC);
            if ($fb_row) {
                $conference = $fb_row['conf_exten'];
                $this->server_ip = $fb_row['server_ip']; // Actualizamos la IP para que coincida con la DB
            }
        }

        if (!$conference) {
            throw new Exception("ERROR CRÍTICO: No hay conferencias libres (MeetMe) en la base de datos.");
        }

        // 2. Limpiar sesiones previas (por seguridad)
        $clean = $this->db->prepare("DELETE FROM vicidial_live_agents WHERE user = ?");
        $clean->execute([$user]);

        // 3. Insertar en vicidial_live_agents
        $sql = "INSERT INTO vicidial_live_agents 
                (user, server_ip, conf_exten, extension, status, campaign_id, last_update_time) 
                VALUES (?, ?, ?, ?, 'PAUSED', ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$user, $this->server_ip, $conference, $extension, $campaign_id]);

        // 4. Marcar conferencia como ocupada
        $upd_conf = $this->db->prepare("UPDATE vicidial_conferences SET extension = ? WHERE conf_exten = ? AND server_ip = ?");
        $upd_conf->execute([$extension, $conference, $this->server_ip]);

        return $conference;
    }

    /**
     * Dispara la llamada de conexión a la conferencia
     */
    public function triggerCall($user, $extension, $conference) {
        // Adaptado al esquema fragmentado: action, cmd_line_b, c, d, e, f
        $mgr_sql = "INSERT INTO vicidial_manager (
                        server_ip, status, action, channel, callerid,
                        cmd_line_b, cmd_line_c, cmd_line_d, cmd_line_e, cmd_line_f,
                        entry_date
                    ) 
                    VALUES (?, 'NEW', 'Originate', ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $mgr_stmt = $this->db->prepare($mgr_sql);
        return $mgr_stmt->execute([
            $this->server_ip, 
            "SIP/$extension",
            $extension,
            "Channel: SIP/$extension",
            "Exten: $conference",
            "Context: default",
            "Priority: 1",
            "Callerid: $extension"
        ]);
    }

    /**
     * Actualiza el heartbeat del agente
     */
    public function heartbeat($user) {
        $stmt = $this->db->prepare("UPDATE vicidial_live_agents SET last_update_time = NOW() WHERE user = ?");
        return $stmt->execute([$user]);
    }

    /**
     * Finaliza la sesión
     */
    public function stopSession($user) {
        // Liberar conferencia
        $get_conf = $this->db->prepare("SELECT conf_exten FROM vicidial_live_agents WHERE user = ?");
        $get_conf->execute([$user]);
        $conf = $get_conf->fetchColumn();

        if ($conf) {
            $liberar = $this->db->prepare("UPDATE vicidial_conferences SET extension = NULL WHERE conf_exten = ? AND server_ip = ?");
            $liberar->execute([$conf, $this->server_ip]);
        }

        $stmt = $this->db->prepare("DELETE FROM vicidial_live_agents WHERE user = ?");
        return $stmt->execute([$user]);
    }
}
