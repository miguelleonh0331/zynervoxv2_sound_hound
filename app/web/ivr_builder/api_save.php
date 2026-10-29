<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/flow_store.php';
ivr_builder_require_login(true);

// Legacy catalog optional: isolated v2 can embed its own bridge phrase.
if (!function_exists('bridges_read_catalog')) {
    function bridges_read_catalog(): array { return []; }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método inválido', 405);
$flow = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($flow)) fail('JSON inválido');
$flowCode = (string) ($flow['flow_code'] ?? '');
if (!preg_match('/^\d{2}$/', $flowCode)) fail('Código de flujo inválido');
$name = trim((string) ($flow['name'] ?? ''));
$start = (string) ($flow['start'] ?? '');
$nodes = $flow['nodes'] ?? null;
$listIdRaw = $flow['list_id'] ?? null;
$listId = ($listIdRaw === null || $listIdRaw === '') ? null : (int) $listIdRaw;
if ($listId !== null && $listId < 1) fail('Lista inválida');
if ($name === '' || strlen($name) > 100) fail('Nombre inválido');
if (!is_array($nodes) || !$nodes) fail('Debe existir al menos un nodo');
if (!isset($nodes[$start])) fail('Nodo inicial inexistente');

$types = ['noop', 'playback', 'create_audio', 'create_audio_dynamic', 'capture_stt', 'bridge', 'execute', 'inject_sql', 'menu', 'goto', 'hangup', 'decision_range', 'create_audio_composite', 'amd', 'menu_ari', 'capture_stt_ari', 'amd_ari', 'reenviar'];
// reenviar: transfiere el canal en vivo (AMI Redirect) a un anexo creado
// manualmente en Asterisk. Nodo terminal, no usa next/fallback.
// menu_ari/capture_stt_ari/amd_ari: experimento "doble canal" 4006.
$allowedComposeProviders = ['macelioai', 'macelioai_remote', 'deepgram', 'deepgram_pool', 'voicescloning', 'deepgram_pod'];
$injectSqlColumns = [
    'ID', 'FECHA', 'TELEFONO_BOT', 'ESTADO', 'RESULTADO', 'CLIENTE',
    'NUMERO_CLIENTE', 'DIRECCION', 'TIENDA', 'MONTO', 'FECHA_AGENDADA',
    'RESPUESTA_REGISTRADA', 'FECHA_INDICADA', 'HORA_VISITA', 'MINUTO_VISITA',
    'ID_CONTACTO', 'CAMPAÑA', 'COLA', 'CALL_ID', 'DURACION_LLAMADA',
    'PROVEEDOR', 'ID_CLASE', 'ID_USUARIO', 'FECHA_DE_GESTION', 'HORA_GESTION',
    'HORA_INICIA_GESTION', 'HORA_FIN_GESTION', 'NUM_DOC', 'PRI_NOMBRE',
    'SEG_NOMBRE', 'APE_PATERNO', 'APE_MATERNO', 'ID_TIPIFICACION_01',
    'ID_TIPIFICACION_02', 'ID_TIPIFICACION_03', 'ID_AGENCIA',
];
$clean = [];
foreach ($nodes as $id => $node) {
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $id)) fail("ID inválido: $id");
    if (!is_array($node)) fail("Nodo inválido: $id");
    $type = (string) ($node['type'] ?? '');
    if (!in_array($type, $types, true)) fail("Tipo inválido en $id");
    $item = [
        'type' => $type,
        'message' => mb_substr(trim((string) ($node['message'] ?? '')), 0, 240),
        'audio' => preg_replace('/[^A-Za-z0-9_\/-]/', '', (string) ($node['audio'] ?? '')),
        'audio_text' => mb_substr(trim((string) ($node['audio_text'] ?? '')), 0, 2000),
        'audio_hash' => preg_replace('/[^a-f0-9]/', '', (string) ($node['audio_hash'] ?? '')),
        'audio_status' => in_array((string) ($node['audio_status'] ?? ''), ['ready', 'pending', 'error'], true) ? (string) $node['audio_status'] : 'pending',
        'capture_mode' => in_array((string) ($node['capture_mode'] ?? 'date'), ['date', 'text', 'number', 'name'], true) ? (string) $node['capture_mode'] : 'date',
        'variable' => mb_substr(trim((string) ($node['variable'] ?? '')), 0, 64),
        'bridge_id' => mb_substr(trim((string) ($node['bridge_id'] ?? '')), 0, 64),
        'bridge_category' => mb_substr(trim((string) ($node['bridge_category'] ?? '')), 0, 64),
        'bridge_text' => mb_substr(trim((string) ($node['bridge_text'] ?? '')), 0, 240),
        'execute_url' => mb_substr(trim((string) ($node['execute_url'] ?? '')), 0, 4000),
        'execute_method' => 'GET',
        'anexo' => preg_replace('/[^A-Za-z0-9_*#.-]/', '', (string) ($node['anexo'] ?? '')),
        'contexto' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($node['contexto'] ?? '')),
        'prioridad' => max(1, min(99, (int) ($node['prioridad'] ?? 1))),
        'inject_sql_fields' => [],
        'next' => (string) ($node['next'] ?? ''),
        'fallback' => (string) ($node['fallback'] ?? ''),
        'timeout_ms' => max(500, min(30000, (int) ($node['timeout_ms'] ?? 6000))),
        'retry_count' => max(0, min(3, (int) ($node['retry_count'] ?? 0))),
        'retry_text' => mb_substr(trim((string) ($node['retry_text'] ?? '')), 0, 500),
        'retry_audio' => preg_replace('/[^A-Za-z0-9_\/-]/', '', (string) ($node['retry_audio'] ?? '')),
        'retry_audio_hash' => preg_replace('/[^a-f0-9]/', '', (string) ($node['retry_audio_hash'] ?? '')),
        'position' => [
            'x' => max(0, min(6000, (int) ($node['position']['x'] ?? 40))),
            'y' => max(0, min(4000, (int) ($node['position']['y'] ?? 40))),
        ],
        'edges' => [],
        'stt_intents' => [],
        'ranges' => [],
        'compose_provider' => 'deepgram',
        'segments' => [],
        'composite_hash' => preg_replace('/[^a-f0-9]/', '', (string) ($node['composite_hash'] ?? '')),
        'composite_status' => in_array((string) ($node['composite_status'] ?? ''), ['ready', 'pending', 'error'], true) ? (string) $node['composite_status'] : 'pending',
    ];
    if (in_array($type, ['capture_stt', 'capture_stt_ari', 'decision_range'], true)) {
        if ($item['variable'] === '') fail("Variable vacia en $id");
    } else {
        $item['capture_mode'] = 'date';
        $item['variable'] = '';
    }
    if ($type === 'decision_range') {
        $ranges = [];
        foreach (($node['ranges'] ?? []) as $range) {
            if (!is_array($range)) fail("Rango inválido en $id");
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            $min = ($min === null || $min === '') ? null : (int) $min;
            $max = ($max === null || $max === '') ? null : (int) $max;
            if ($min !== null && $max !== null && $min > $max) fail("Rango invertido en $id");
            $target = (string) ($range['target'] ?? '');
            if ($target === '') fail("Destino vacio en rango de $id");
            $ranges[] = [
                'min' => $min,
                'max' => $max,
                'label' => mb_substr(trim((string) ($range['label'] ?? '')), 0, 120),
                'target' => $target,
            ];
        }
        if (!$ranges) fail("Debe existir al menos un rango en $id");
        $item['ranges'] = $ranges;
    }
    if ($type === 'create_audio_dynamic' && $item['audio_text'] === '') fail("Plantilla vacia en $id");
    if ($type === 'create_audio_composite') {
        $provider = (string) ($node['compose_provider'] ?? 'deepgram');
        if (!in_array($provider, $allowedComposeProviders, true)) fail("Proveedor inválido en $id");
        $item['compose_provider'] = $provider;
        $segmentsIn = $node['segments'] ?? null;
        if (!is_array($segmentsIn) || !$segmentsIn) fail("Debe existir al menos un segmento en $id");
        if (count($segmentsIn) > 10) fail("Máximo 10 segmentos en $id");
        $segmentsOut = [];
        foreach ($segmentsIn as $segIndex => $segment) {
            if (!is_array($segment)) fail("Segmento inválido en $id:$segIndex");
            $segId = mb_substr(trim((string) ($segment['id'] ?? '')), 0, 40);
            $segText = mb_substr(trim((string) ($segment['text'] ?? '')), 0, 500);
            $segPause = max(0, min(3000, (int) ($segment['pause_after_ms'] ?? 0)));
            if ($segText === '') fail("Texto vacío en segmento " . ($segIndex + 1) . " de $id");
            $segmentsOut[] = [
                'id' => $segId !== '' ? $segId : ('seg_' . ($segIndex + 1)),
                'text' => $segText,
                'pause_after_ms' => $segPause,
                'hash' => preg_replace('/[^a-f0-9]/', '', (string) ($segment['hash'] ?? '')),
                'cached' => (bool) ($segment['cached'] ?? false),
            ];
        }
        $item['segments'] = $segmentsOut;
        if ($item['next'] === '') fail("Destino next requerido en $id (composite)");
    } else {
        $item['compose_provider'] = 'deepgram';
        $item['segments'] = [];
        $item['composite_hash'] = '';
        $item['composite_status'] = 'pending';
    }
    if ($type === 'bridge') {
        $bridgeId = $item['bridge_id'];
        if ($bridgeId === '') fail("Puente vacio en $id");
        $catalog = bridges_read_catalog();
        $bridgeFound = null;
        foreach ($catalog as $bridgeItem) {
            if (($bridgeItem['id'] ?? '') === $bridgeId) {
                $bridgeFound = $bridgeItem;
                break;
            }
        }
        // Isolated v2 embeds the bridge phrase and prebuilds its own hashed audio.
        if (!$bridgeFound && (!\Config\Config::deployment('isolated',false) || $item['bridge_text']==='')) fail("Puente inexistente en $id");
        if ($bridgeFound && !empty($bridgeFound['audio_hash'])) {
            $item['bridge_category'] = (string) ($bridgeFound['category'] ?? '');
            $item['bridge_text'] = (string) ($bridgeFound['text'] ?? '');
        }
    } else {
        $item['bridge_id'] = '';
        $item['bridge_category'] = '';
        $item['bridge_text'] = '';
    }
    if ($type === 'execute') {
        if ($item['execute_url'] === '') fail("URL vacía en $id");
        if (!preg_match('#^https?://#i', $item['execute_url'])) fail("URL debe usar http o https en $id");
    } else {
        $item['execute_url'] = '';
        $item['execute_method'] = 'GET';
    }
    if ($type === 'reenviar') {
        if ($item['anexo'] === '') fail("Anexo vacío en $id");
    } else {
        $item['anexo'] = '';
        $item['contexto'] = '';
        $item['prioridad'] = 1;
    }
    if ($type === 'inject_sql') {
        $fields = $node['inject_sql_fields'] ?? null;
        if (!is_array($fields) || !$fields) fail("Mapeo SQL vacío en $id");
        foreach ($fields as $column => $template) {
            $column = strtoupper(trim((string) $column));
            if (!in_array($column, $injectSqlColumns, true)) fail("Columna SQL inválida en $id: $column");
            $template = mb_substr(trim((string) $template), 0, 4000);
            if ($template === '') fail("Plantilla SQL vacía en $id: $column");
            $item['inject_sql_fields'][$column] = $template;
        }
    }
    if (!in_array($type, ['menu', 'capture_stt', 'menu_ari', 'capture_stt_ari'], true)) {
        $item['retry_count'] = 0;
        $item['retry_text'] = '';
        $item['retry_audio'] = '';
        $item['retry_audio_hash'] = '';
    } elseif ($item['retry_count'] > 0 && ($item['retry_text'] === '' || $item['retry_audio'] === '')) {
        fail("Retry STT incompleto en $id");
    }
    foreach (($node['edges'] ?? []) as $digit => $target) {
        if (!preg_match('/^[0-9*#]$/', (string) $digit)) fail("DTMF inválido en $id");
        if ((string) $target === '') continue;
        $item['edges'][(string) $digit] = (string) $target;
    }
    foreach (($node['stt_intents'] ?? []) as $intent) {
        if (!is_array($intent)) fail("Intent STT invalido en $id");
        $intentId = trim((string) ($intent['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $intentId)) fail("ID STT invalido en $id");
        $phrases = [];
        foreach (($intent['phrases'] ?? []) as $phrase) {
            $phrase = mb_substr(trim((string) $phrase), 0, 120);
            if ($phrase !== '') $phrases[] = $phrase;
        }
        if (!$phrases) fail("Frases STT vacias en $id:$intentId");
        $item['stt_intents'][] = [
            'id' => $intentId,
            'match' => 'contains_any',
            'phrases' => array_values(array_unique($phrases)),
            'priority' => max(1, min(9999, (int) ($intent['priority'] ?? 100))),
            'target' => (string) ($intent['target'] ?? ''),
            'fuzzy' => (bool) ($intent['fuzzy'] ?? false),
        ];
    }
    $clean[(string) $id] = $item;
}
foreach ($clean as $id => $node) {
    foreach (['next', 'fallback'] as $field) {
        if ($node[$field] !== '' && !isset($clean[$node[$field]])) fail("Destino $field inexistente en $id");
    }
    foreach ($node['edges'] as $target) if (!isset($clean[$target])) fail("Rama inexistente en $id");
    foreach ($node['stt_intents'] as $intent) if (!isset($clean[$intent['target']])) fail("Destino STT inexistente en $id");
    foreach ($node['ranges'] as $range) if (!isset($clean[$range['target']])) fail("Destino de rango inexistente en $id");
    if (($node['type'] ?? '') === 'bridge' && $node['next'] === '') fail("Destino next inexistente en $id");
    if (($node['type'] ?? '') === 'execute' && ($node['next'] === '' || $node['fallback'] === '')) fail("Destinos ok/error requeridos en $id");
    if (($node['type'] ?? '') === 'inject_sql' && ($node['next'] === '' || $node['fallback'] === '')) fail("Destinos ok/error SQL requeridos en $id");
    if (in_array(($node['type'] ?? ''), ['amd', 'amd_ari'], true) && $node['fallback'] === '') fail("Destino continuar (fallback) requerido en $id");
}

$result = ['flow_code' => $flowCode, 'name' => $name, 'start' => $start, 'list_id' => $listId, 'nodes' => $clean, 'updated_at' => gmdate('c')];
$db = bot_ivr_db();
try {
    flow_store($db, $result);
    $published = flow_publish($db, $result);
} catch (Throwable $e) {
    error_log('IVR save/publish failed: ' . $e->getMessage());
    fail('No se pudo guardar y publicar el flujo', 500);
}
echo json_encode(['ok'=>true,'updated_at'=>$result['updated_at'],'published_sha256'=>$published['sha256']], JSON_UNESCAPED_UNICODE);
