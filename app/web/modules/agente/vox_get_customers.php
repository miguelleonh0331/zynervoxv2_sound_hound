<?php
/**
 * VOX SPHERE - Customer List Fetcher (Extended)
 * Obtiene el listado de clientes con detalles completos de vicidial_list.
 */

header('Content-Type: application/json');
require_once('dbconnect_mysqli.php');

$query = "SELECT lead_id, entry_date, modify_date, status, user, vendor_lead_code, 
                 source_id, list_id, gmt_offset_now, called_since_last_reset, 
                 phone_code, phone_number, title, first_name, middle_initial, 
                 last_name, address1, address2, address3, city, state, province, 
                 postal_code, country_code, gender, date_of_birth, alt_phone, 
                 email, security_phrase, comments, called_count, 
                 last_local_call_time, rank, owner, entry_list_id 
          FROM vicidial_list 
          ORDER BY entry_date DESC LIMIT 50";

$result = $link->query($query);

$customers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
}

echo json_encode(['status' => 'success', 'data' => $customers]);

$link->close();
?>
