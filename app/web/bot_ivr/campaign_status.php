<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/auth.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
initial_survey_require_login();
require __DIR__ . '/db.php';
require __DIR__ . '/campaign_audio_readiness.php';
require __DIR__ . '/campaign_recordings.php';
require __DIR__ . '/campaign_state_summary.php';
require __DIR__ . '/campaign_runtime.php';

$campaignId = (int) ($_GET['campaign_id'] ?? 0);
if ($campaignId <= 0) { echo json_encode(['ok'=>false,'error'=>'Campaña inválida']); exit; }

try {
    $db = carsa_db();
    $c = $db->prepare('SELECT id, name, flow_code, status, desired_channels, tts_provider, call_origin FROM synervox_campaigns WHERE id=:id');
    $c->execute([':id'=>$campaignId]);
    $camp = $c->fetch();
    if (!$camp) { echo json_encode(['ok'=>false,'error'=>'Campaña inexistente']); exit; }

    $zypadStmt = $db->prepare('SELECT last_zypad FROM carsa_campaign_state_summary WHERE campaign_id=:id');
    $zypadStmt->execute([':id'=>$campaignId]);
    $lastZypad = (int) ($zypadStmt->fetchColumn() ?: 0);

    // NOTA: en el original habia una tabla carsa_campaign_snapshot que
    // cachea estos numeros (poblada cada ~3s por un worker en background).
    // Esa tabla/worker no se migraron todavia -- siempre se usa el camino
    // "en vivo" (mismo resultado, un poco mas de carga por consulta directa).
    $s = $db->prepare('SELECT status, COUNT(*) AS n FROM carsa_initial_survey WHERE campaign_id=:id GROUP BY status');
    $s->execute([':id'=>$campaignId]);
    $byStatus = ['pending'=>0,'reserved'=>0,'calling'=>0,'completed'=>0,'failed'=>0];
    $total = 0;
    foreach ($s->fetchAll() as $r) { $byStatus[$r['status']] = (int)$r['n']; $total += (int)$r['n']; }

    $a = $db->prepare(
        'SELECT COUNT(DISTINCT q.id) AS n
         FROM carsa_initial_survey q
         JOIN ivr_call_results r ON r.queue_id = q.id
         WHERE q.campaign_id = :id'
    );
    $a->execute([':id'=>$campaignId]);
    $answered = (int) ($a->fetch()['n'] ?? 0);

    $timingStmt = $db->prepare(
        'SELECT COUNT(*) AS processed,
                COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)),0) AS avg_seconds,
                MIN(started_at) AS campaign_started,
                MAX(completed_at) AS last_completed
         FROM carsa_initial_survey
         WHERE campaign_id=:id AND completed_at IS NOT NULL'
    );
    $timingStmt->execute([':id'=>$campaignId]);
    $timing = $timingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $processed = (int)($timing['processed'] ?? 0);
    $avgSeconds = (int)round((float)($timing['avg_seconds'] ?? 0));
    $remainingRowsTmp = $byStatus['pending'] + $byStatus['reserved'] + $byStatus['calling'];
    $etaSeconds = ($avgSeconds > 0 && $remainingRowsTmp > 0) ? (int)(ceil($remainingRowsTmp) * $avgSeconds) : 0;
    $progressPct = $total > 0 ? (int) round($processed / $total * 100) : 0;

    $resultStmt = $db->prepare(
        'SELECT COALESCE(NULLIF(result_label,""),NULLIF(hangup_node_id,""),"Sin etiqueta") AS resultado,
                COUNT(DISTINCT queue_id) AS cantidad
         FROM ivr_call_results WHERE campaign_id=:id
         GROUP BY resultado ORDER BY cantidad DESC,resultado'
    );
    $resultStmt->execute([':id'=>$campaignId]);
    $resultSummary = $resultStmt->fetchAll(PDO::FETCH_ASSOC);

    $recentStmt = $db->prepare(
        'SELECT r.queue_id,q.phone,q.customer_name,
                COALESCE(NULLIF(r.result_label,""),NULLIF(r.hangup_node_id,""),"Sin etiqueta") AS resultado,
                r.created_at
         FROM ivr_call_results r
         LEFT JOIN carsa_initial_survey q ON q.id=r.queue_id
         WHERE r.campaign_id=:id ORDER BY r.id DESC LIMIT 10'
    );
    $recentStmt->execute([':id'=>$campaignId]);
    $recentResults = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    $clientsLimit = (int) ($_GET['clients_limit'] ?? 200);
    $clientsLimit = max(1, min(1000, $clientsLimit));
    $clientsOffset = max(0, (int) ($_GET['clients_offset'] ?? 0));
    $d = $db->prepare(
        'SELECT q.id, q.phone, q.customer_name, q.status, q.assigned_agent,
                q.started_at, q.completed_at,
                COALESCE(q.audio_status,"pending") AS audio_status, q.audio_error,
                (SELECT r.hangup_node_id FROM ivr_call_results r
                  WHERE r.queue_id = q.id
                  ORDER BY r.id DESC LIMIT 1) AS resultado
         FROM carsa_initial_survey q
         WHERE q.campaign_id = :id
         ORDER BY q.id
         LIMIT ' . $clientsLimit . ' OFFSET ' . $clientsOffset
    );
    $d->execute([':id'=>$campaignId]);
    $clients = $d->fetchAll();
    $recordingFiles = campaign_recordings_list_files($db,$campaignId);
    foreach ($clients as &$client) {
        $client['recordings'] = array_map(
            static function (array $recording) use ($campaignId, $client): array {
                $recording['url'] = 'campaign_recording.php?' . http_build_query([
                    'campaign_id' => $campaignId,
                    'queue_id' => (int)$client['id'],
                    'file' => $recording['file'],
                ]);
                return $recording;
            },
            campaign_recordings_for_client($client, $recordingFiles)
        );
    }
    unset($client);

    $activeStmt = $db->prepare(
        'SELECT id,phone,customer_name,assigned_agent,started_at,
                TIMESTAMPDIFF(SECOND,started_at,NOW()) AS elapsed_seconds
         FROM carsa_initial_survey
         WHERE campaign_id=:id AND status IN ("calling","reserved") ORDER BY id'
    );
    $activeStmt->execute([':id'=>$campaignId]);
    $activeCalls = $activeStmt->fetchAll(PDO::FETCH_ASSOC);
    $remainingRows = $byStatus['pending'] + $byStatus['reserved'] + $byStatus['calling'];

    $running=campaign_process_active($db,$campaignId,'dialer');
    $dialerProcess=campaign_process_get($db,$campaignId,'dialer');
    $pid=(int)($dialerProcess['pid']??0);
    $stopPending=campaign_stop_requested($dialerProcess);
    $prebuildRunning=campaign_process_active($db,$campaignId,'audio_build');
    $prebuildProcess=campaign_process_get($db,$campaignId,'audio_build');
    $prebuildPid=(int)($prebuildProcess['pid']??0);
    $audio = campaign_audio_readiness($db, $campaignId);
    $summaryCampaignStatus = $running
        ? ($stopPending ? 'stopping' : 'running')
        : (($total > 0 && $remainingRows === 0)
            ? 'completed'
            : (((string)$camp['status'] === 'stopped') ? 'stopped' : 'idle'));
    $summaryAudioStatus = $prebuildRunning
        ? 'building'
        : (!empty($audio['ready'])
            ? 'ready'
            : (((int)($audio['failed'] ?? 0) > 0 || (string)($audio['manifest_status'] ?? '') === 'failed') ? 'error' : 'pending'));
    campaign_summary_sync_if_changed($db, $campaignId, [
        'audio_status' => $summaryAudioStatus,
        'campaign_status' => $summaryCampaignStatus,
        'total_contacts' => $total,
        'pending_contacts' => $byStatus['pending'],
        'active_contacts' => $byStatus['calling'] + $byStatus['reserved'],
        'completed_contacts' => $byStatus['completed'] + $byStatus['failed'],
        'failed_contacts' => $byStatus['failed'],
        'last_error' => $summaryAudioStatus === 'error' ? (string)($audio['reason'] ?? 'Error de audios') : null,
    ]);

    echo json_encode([
        'ok'=>true,
        'campaign'=>['id'=>(int)$camp['id'],'name'=>$camp['name'],'flow_code'=>$camp['flow_code'],'status'=>$camp['status'],'desired_channels'=>$camp['desired_channels']!==null?(int)$camp['desired_channels']:null,'tts_provider'=>$camp['tts_provider']!==null?(string)$camp['tts_provider']:null,'call_origin'=>$camp['call_origin']!==null?(string)$camp['call_origin']:($lastZypad ? 'zypad_3006' : 'sipp_2006'),'last_zypad'=>$lastZypad],
        'running'=>$running, 'pid'=>$pid, 'stop_pending'=>$stopPending,
        'prebuild_running'=>$prebuildRunning, 'prebuild_pid'=>$prebuildPid,
        'summary_audio_status'=>$summaryAudioStatus,
        'summary_campaign_status'=>$summaryCampaignStatus,
        'audio'=>$audio, 'audio_ready_to_launch'=>(bool)$audio['ready'],
        'progress'=>[
            'processed'=>$processed, 'total'=>$total, 'percent'=>$progressPct,
            'remaining'=>$remainingRows, 'average_seconds'=>$avgSeconds,
            'eta_seconds'=>$etaSeconds,
            'campaign_started'=>$timing['campaign_started'] ?? null,
            'last_completed'=>$timing['last_completed'] ?? null,
        ],
        'active_calls'=>$activeCalls,
        'result_summary'=>$resultSummary,
        'recent_results'=>$recentResults,
        'stats'=>[
            'cargados'=>$total,
            'pendiente'=>$byStatus['pending'],
            'llamando'=>$byStatus['calling'] + $byStatus['reserved'],
            'terminado'=>$byStatus['completed'] + $byStatus['failed'],
            'contestado'=>$answered,
        ],
        'clients'=>$clients,
        'clients_total'=>$total,
        'clients_limit'=>$clientsLimit,
        'clients_offset'=>$clientsOffset,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

