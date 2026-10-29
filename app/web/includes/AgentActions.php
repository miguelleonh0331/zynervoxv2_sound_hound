<?php
namespace Includes;

use PDO;
use Exception;

class AgentActions {
    private $db;
    private $server_ip;

    public function __construct($db, $server_ip) {
        $this->db = $db;
        $this->server_ip = $server_ip;
    }

    /**
     * Alterna entre PAUSED y READY
     */
    public function togglePause($user, $current_status) {
        $new_status = ($current_status === 'PAUSED') ? 'READY' : 'PAUSED';
        $stmt = $this->db->prepare("UPDATE vicidial_live_agents SET status = ?, last_update_time = NOW() WHERE user = ?");
        return $stmt->execute([$new_status, $user]);
    }

    /**
     * Colgar llamada activa
     */
    public function hangupCall($user) {
        // En ViciDial, para colgar enviamos una acción al manager sobre el canal del agente/cliente
        $stmt = $this->db->prepare("SELECT conf_exten, extension FROM vicidial_live_agents WHERE user = ?");
        $stmt->execute([$user]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return false;

        $extension = $row['extension'];
        
        // Buscamos el canal activo en vicidial_manager o Asterisk (vía vicidial_live_agents no es directo)
        // El método más seguro es insertar un Hangup para esa extensión/canal si lo conocemos
        $mgr_sql = "INSERT INTO vicidial_manager (
                        server_ip, status, action, channel, entry_date
                    ) 
                    VALUES (?, 'NEW', 'Hangup', ?, NOW())";
        
        $mgr_stmt = $this->db->prepare($mgr_sql);
        return $mgr_stmt->execute([$this->server_ip, "SIP/$extension"]);
    }

    /**
     * Obtiene las tipificaciones (dispositions) de la campaña
     */
    public function getDispositions($campaign_id) {
        // Tipificaciones de campaña
        $stmt = $this->db->prepare("SELECT status, status_name FROM vicidial_campaign_statuses WHERE campaign_id = ?");
        $stmt->execute([$campaign_id]);
        $camp_statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tipificaciones del sistema (las más comunes)
        $stmt_sys = $this->db->query("SELECT status, status_name FROM vicidial_statuses WHERE selectable = 'Y'");
        $sys_statuses = $stmt_sys->fetchAll(PDO::FETCH_ASSOC);

        return array_merge($camp_statuses, $sys_statuses);
    }

    /**
     * Obtiene los datos del lead actual
     */
    public function getCurrentLeadData($user) {
        $stmt = $this->db->prepare("SELECT lead_id FROM vicidial_live_agents WHERE user = ?");
        $stmt->execute([$user]);
        $lead_id = $stmt->fetchColumn();

        if (!$lead_id || $lead_id <= 0) return null;

        $stmt_lead = $this->db->prepare("SELECT * FROM vicidial_list WHERE lead_id = ?");
        $stmt_lead->execute([$lead_id]);
        return $stmt_lead->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Guarda la tipificación y libera al agente
     */
    public function setDisposition($user, $lead_id, $status) {
        // 1. Loggear en vicidial_log (simplificado)
        // 2. Actualizar estado del lead
        $upd = $this->db->prepare("UPDATE vicidial_list SET status = ? WHERE lead_id = ?");
        $upd->execute([$status, $lead_id]);

        // 3. Poner al agente en READY si estaba INCALL
        $stmt = $this->db->prepare("UPDATE vicidial_live_agents SET status = 'READY', lead_id = 0 WHERE user = ?");
        return $stmt->execute([$user]);
    }
}
