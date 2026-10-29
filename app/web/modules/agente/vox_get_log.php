<?php
/**
 * VOX SPHERE - Log Retriever
 * Obtiene las últimas 3 interacciones registradas para un lead.
 */

header('Content-Type: application/json');
require_once('dbconnect_mysqli.php');

$lead_id = isset($_REQUEST['lead_id']) ? preg_replace('/[^0-9]/', '', $_REQUEST['lead_id']) : '';

if (empty($lead_id)) {
    die(json_encode(['status' => 'error', 'message' => 'Faltan parámetros']));
}

// Consultar los últimos 3 registros
$query = "SELECT status, event_date, user FROM vox_sphere_vicidial_log WHERE lead_id = ? ORDER BY log_id DESC LIMIT 3";
$stmt = $link->prepare($query);
$stmt->bind_param("i", $lead_id);
$stmt->execute();
$res = $stmt->get_result();
$logs = [];
while ($row = $res->fetch_assoc()) {
    $logs[] = $row;
}

echo json_encode(['status' => 'success', 'data' => $logs]);

$stmt->close();
$link->close();
?>
