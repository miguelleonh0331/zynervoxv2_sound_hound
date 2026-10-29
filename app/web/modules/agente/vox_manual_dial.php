<?php
/**
 * VOX SPHERE - Manual Dialer Bridge
 * Crea/Actualiza un lead y dispara la sincronización al espejo antes de la llamada.
 */

header('Content-Type: application/json');

$use_slave_server = 0; // Evitar warning en dbconnect_mysqli.php
require_once('dbconnect_mysqli.php');
require_once(__DIR__ . "/../../includes/Database.php");
require_once(__DIR__ . "/../../includes/Reporting.php");

$phone = isset($_REQUEST['phone']) ? $_REQUEST['phone'] : '';
$list_id = isset($_REQUEST['list_id']) ? $_REQUEST['list_id'] : '998'; // Lista por defecto para manuales
$user = isset($_REQUEST['user']) ? $_REQUEST['user'] : 'VOX_AGENT';

if (empty($phone)) {
    die(json_encode(['status' => 'error', 'message' => 'Falta el número de teléfono']));
}

// Limpiar el teléfono
$phone = preg_replace('/[^0-9]/', '', $phone);

try {
    // 1. Verificar si el lead existe
    $query = "SELECT lead_id FROM vicidial_list WHERE phone_number = ? LIMIT 1";
    $ps = $link->prepare($query);
    $ps->bind_param("s", $phone);
    $ps->execute();
    $res = $ps->get_result();
    $lead_id = 0;

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $lead_id = $row['lead_id'];
        
        // Actualizar estado a INCALL y usuario actual
        $upd = "UPDATE vicidial_list SET status='INCALL', user=?, last_local_call_time=NOW() WHERE lead_id=?";
        $ps_upd = $link->prepare($upd);
        $ps_upd->bind_param("si", $user, $lead_id);
        $ps_upd->execute();
        $ps_upd->close();
    } else {
        // 2. Crear un nuevo lead para este número manual
        $ins = "INSERT INTO vicidial_list (entry_date, status, user, list_id, phone_code, phone_number, called_since_last_reset) 
                VALUES (NOW(), 'INCALL', ?, ?, '1', ?, 'N')";
        $ps_ins = $link->prepare($ins);
        $ps_ins->bind_param("sss", $user, $list_id, $phone);
        if ($ps_ins->execute()) {
            $lead_id = $ps_ins->insert_id;
        }
        $ps_ins->close();
    }

    if ($lead_id > 0) {
        // 3. Sincronizar al espejo en tiempo real (Opcional - Graceful)
        try {
            if (class_exists('\Includes\Reporting')) {
                \Includes\Reporting::syncLeadById($lead_id);
            }
        } catch (Exception $syncEx) {
            // Error en espejo no bloquea el marcado local
        }

        echo json_encode([
            'status' => 'success', 
            'lead_id' => $lead_id, 
            'message' => 'Lead listo localmente'
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo crear/obtener el ID del cliente']);
    }

    $ps->close();
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
}

$link->close();
