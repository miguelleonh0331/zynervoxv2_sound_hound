<?php
/**
 * VOX SPHERE - Lead Status Updater
 * Actualiza el campo 'status' de un lead específico en la tabla vicidial_list.
 */

header('Content-Type: application/json');
require_once('dbconnect_mysqli.php');
require_once(__DIR__ . "/../../includes/Reporting.php");

$lead_id = isset($_REQUEST['lead_id']) ? $_REQUEST['lead_id'] : '';
$status = isset($_REQUEST['status']) ? $_REQUEST['status'] : '';

if (empty($lead_id) || empty($status)) {
    die(json_encode(['status' => 'error', 'message' => 'Faltan parámetros (lead_id o status)']));
}

try {
    // Sanitizar
    $lead_id = preg_replace('/[^0-9]/', '', $lead_id);
    $status = $link->real_escape_string($status);

    $query = "UPDATE vicidial_list SET status = ? WHERE lead_id = ?";
    $stmt = $link->prepare($query);
    $stmt->bind_param("si", $status, $lead_id);

    if ($stmt->execute()) {
        // 1. Guardar en el historial de interacciones local (Vox Sphere Log)
        try {
            // Asegurar que la tabla existe
            $link->query("CREATE TABLE IF NOT EXISTS vox_sphere_vicidial_log (
                log_id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT(10) UNSIGNED NOT NULL,
                phone_number VARCHAR(18),
                status VARCHAR(10),
                user VARCHAR(20),
                event_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (lead_id),
                INDEX (phone_number)
            ) ENGINE=InnoDB;");

            $phone_query = "SELECT phone_number, user FROM vicidial_list WHERE lead_id = ?";
            $ps = $link->prepare($phone_query);
            $ps->bind_param("i", $lead_id);
            $ps->execute();
            $res = $ps->get_result();
            $row = $res->fetch_assoc();
            $phone = $row['phone_number'] ?? '';
            $user = $row['user'] ?? 'AGENT';
            $ps->close();

            $log_query = "INSERT INTO vox_sphere_vicidial_log (lead_id, phone_number, status, user) VALUES (?, ?, ?, ?)";
            $ls = $link->prepare($log_query);
            $ls->bind_param("isss", $lead_id, $phone, $status, $user);
            $ls->execute();
            $ls->close();
        } catch (Exception $logEx) {
            // Error en el log local no debe detener el flujo principal
        }

        // 2. Sincronización al Espejo (Opcional - Graceful failure)
        try {
            if (class_exists('\Includes\Reporting')) {
                \Includes\Reporting::syncLeadById($lead_id);
            }
        } catch (Exception $syncEx) {
            // Un fallo en el espejo no debe tirar el agente
        }

        echo json_encode(['status' => 'success', 'message' => 'Status actualizado localmente']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error SQL: ' . $stmt->error]);
    }
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
}

$link->close();
?>
