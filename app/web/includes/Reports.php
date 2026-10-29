<?php

namespace Includes;

use Includes\Database;
use PDO;

require_once __DIR__ . '/Database.php';

// Reportes reales de negocio (distinto de Reporting.php, que solo sincroniza
// un espejo de leads a una BD remota). Fuentes: vicidial_log (todo intento de
// marcacion saliente) y vicidial_closer_log (llamadas que llegaron a un
// agente = contactos reales), igual que los reportes de campaña de stock
// VICIdial (call_report_export.php usa el mismo par de tablas).
class Reports {

    public static function getCampaignReport($startDate, $endDate, $campaignId = null) {
        $db = Database::getInstance();

        // Total de intentos de marcacion por campaña
        $sql1 = "SELECT campaign_id, COUNT(*) as total_calls
                 FROM vicidial_log
                 WHERE call_date >= :start AND call_date <= :end";
        $params1 = ['start' => "$startDate 00:00:00", 'end' => "$endDate 23:59:59"];
        if ($campaignId) { $sql1 .= " AND campaign_id = :cid"; $params1['cid'] = $campaignId; }
        $sql1 .= " GROUP BY campaign_id";
        $stmt1 = $db->prepare($sql1);
        $stmt1->execute($params1);
        $totals = [];
        foreach ($stmt1->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[$row['campaign_id']] = (int)$row['total_calls'];
        }

        // Contactos reales (llegaron a un agente) + ventas + tiempo hablado
        $sql2 = "SELECT cl.campaign_id,
                    COUNT(*) as contacts,
                    SUM(cl.length_in_sec) as talk_seconds,
                    SUM(CASE WHEN cs.sale = 'Y' THEN 1 ELSE 0 END) as sales
                 FROM vicidial_closer_log cl
                 LEFT JOIN vicidial_campaign_statuses cs
                    ON cs.status = cl.status AND cs.campaign_id = cl.campaign_id
                 WHERE cl.call_date >= :start AND cl.call_date <= :end";
        $params2 = ['start' => "$startDate 00:00:00", 'end' => "$endDate 23:59:59"];
        if ($campaignId) { $sql2 .= " AND cl.campaign_id = :cid"; $params2['cid'] = $campaignId; }
        $sql2 .= " GROUP BY cl.campaign_id";
        $stmt2 = $db->prepare($sql2);
        $stmt2->execute($params2);
        $contactRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Nombres de campaña
        $names = [];
        $stmt3 = $db->query("SELECT campaign_id, campaign_name FROM vicidial_campaigns");
        foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $row) { $names[$row['campaign_id']] = $row['campaign_name']; }

        $report = [];
        $allCampaignIds = array_unique(array_merge(array_keys($totals), array_column($contactRows, 'campaign_id')));

        foreach ($allCampaignIds as $cid) {
            $totalCalls = $totals[$cid] ?? 0;
            $contactRow = null;
            foreach ($contactRows as $r) { if ($r['campaign_id'] === $cid) { $contactRow = $r; break; } }

            $contacts = $contactRow ? (int)$contactRow['contacts'] : 0;
            $sales = $contactRow ? (int)$contactRow['sales'] : 0;
            $talkSeconds = $contactRow ? (int)$contactRow['talk_seconds'] : 0;

            $report[] = [
                'campaign_id' => $cid,
                'campaign_name' => $names[$cid] ?? $cid,
                'total_calls' => $totalCalls,
                'contacts' => $contacts,
                'sales' => $sales,
                'talk_seconds' => $talkSeconds,
                'avg_duration' => $contacts > 0 ? round($talkSeconds / $contacts) : 0,
                'contact_rate' => $totalCalls > 0 ? round(($contacts / $totalCalls) * 100, 1) : 0,
                'sale_rate' => $contacts > 0 ? round(($sales / $contacts) * 100, 1) : 0,
            ];
        }

        usort($report, fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);
        return $report;
    }
}
