<?php
declare(strict_types=1);
// Datos del informe de llamadas (JSON) y exportación CSV. Solo nivel 9. Sin SQL propio: usa bot_cfg_report de la capa de campañas.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../bot_ivr/campaign_config_service.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function rep_json(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (empty($_SESSION['user']) || (int)($_SESSION['user_level'] ?? 0) !== 9) rep_json(401, ['ok' => false, 'error' => 'Sesión requerida.']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') rep_json(405, ['ok' => false, 'error' => 'Método no permitido.']);

$filters = [];
foreach (['from', 'to', 'campaign_id', 'list_id'] as $k) $filters[$k] = isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : '';
$filters['include_test'] = !empty($_GET['include_test']);
$filters['include_invalid_amd'] = !empty($_GET['include_invalid_amd']);
try {
    $db = bot_ivr_repository();
    if (($_GET['export'] ?? '') === 'csv') {
        $csv = bot_cfg_report_csv($db, $filters);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="informe-llamadas-' . gmdate('Ymd-His') . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $report = bot_cfg_report($db, $filters, $page, 50);
    $options = [];
    foreach (bot_cfg_list($db) as $c) $options[(int)$c['campaign_id']] = ['campaign_id' => (int)$c['campaign_id'], 'name' => $c['name'], 'lists' => []];
    foreach (bot_cfg_lists_channels($db) as $l) if (isset($options[(int)$l['campaign_id']])) $options[(int)$l['campaign_id']]['lists'][] = ['list_id' => (int)$l['list_id'], 'name' => $l['name']];
    $extra = null; $summary = null; $warnings = [];
    try { $extra = bot_cfg_report_extra($db, $filters); } catch (RuntimeException $e) { $warnings[] = $e->getMessage(); } catch (Throwable $e) { $warnings[] = 'No se pudieron calcular los gráficos de diagnóstico.'; }
    $contacts = null; $zypad = null;
    try { $contacts = bot_cfg_contact_distribution($db, $filters); } catch (RuntimeException $e) { $warnings[] = $e->getMessage(); } catch (Throwable $e) { $warnings[] = 'No se pudo calcular el resultado por contacto.'; }
    try { $zypad = bot_cfg_contact_zypad_amd($db, $filters); } catch (Throwable $e) { $warnings[] = 'No se pudo calcular Zypad frente a AMD.'; }
    $dialmodes = null;
    try { $dialmodes = bot_cfg_dialmode_report($db, $filters); } catch (RuntimeException $e) { $warnings[] = $e->getMessage(); } catch (Throwable $e) { $warnings[] = 'No se pudo calcular la comparación por tipo de marcación.'; }
    $sf = ['campaign_id' => $filters['campaign_id'] !== '' ? $filters['campaign_id'] : null, 'list_id' => $filters['list_id'] !== '' ? $filters['list_id'] : null];
    $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
    if (in_array($status, ['running', 'stopped', 'finished', 'deleted'], true)) $sf['status'] = $status;
    try { $summary = bot_cfg_summary($db, $sf); } catch (Throwable $e) { $warnings[] = 'No se pudo leer el resumen por lista.'; }
    rep_json(200, ['ok' => true, 'report' => $report, 'extra' => $extra, 'contacts' => $contacts, 'zypad_amd' => $zypad, 'dialmodes' => $dialmodes, 'summary' => $summary, 'warnings' => $warnings, 'options' => array_values($options)]);
} catch (RuntimeException $e) {
    rep_json(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    rep_json(503, ['ok' => false, 'error' => 'No se pudo consultar el informe.']);
}
