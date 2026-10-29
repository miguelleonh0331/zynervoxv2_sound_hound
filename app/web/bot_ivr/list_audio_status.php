<?php
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__.'/auth.php';
require __DIR__.'/list_audio_jobs.php';
require_once __DIR__.'/../ivr_builder/published_flow.php';
initial_survey_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new RuntimeException('Método inválido.');
    $listId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$listId || !$campaignId) throw new RuntimeException('Lista o campaña inválida.');
    $db = bot_ivr_repository();
    $list = $db->list($listId, $campaignId);
    $job = bot_list_audio_job_status($listId);
    $ready = false;
    try {
        $payload = bot_list_audio_payload(ivr_builder_published_flow((int)$list['id_flujo']), $db->audioLeads($listId, $campaignId), $listId, $campaignId, 25);
        $ready = bot_list_audio_ready($job, $payload);
    } catch (Throwable $e) { /* Invalid or changed inputs must keep Play blocked. */ }
    echo json_encode(['ok'=>true, 'job'=>$job, 'ready'=>$ready], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'No se pudo consultar la generación.']);
}
