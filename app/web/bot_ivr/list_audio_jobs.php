<?php
declare(strict_types=1);
require_once __DIR__.'/../config/Config.php';
require_once __DIR__.'/list_audio_service.php';

function bot_list_audio_providers(): array { return ['gtts'=>'gTTS (gratis, voz básica)']; }

function bot_list_audio_root(): string {
    return rtrim((string)\Config\Config::deployment('runtime', '/var/lib/zynervoxv2/asterisk'), '/');
}

function bot_list_audio_active(int $listId): bool {
    $file = bot_list_audio_root().'/bot_ivr/audio_jobs/list_'.$listId.'.lock';
    if (!is_file($file)) return false;
    $lock = fopen($file, 'a');
    if ($lock === false) throw new RuntimeException('No se puede comprobar la generación activa.');
    $available = flock($lock, LOCK_EX | LOCK_NB);
    fclose($lock);
    return !$available;
}

function bot_list_audio_job_status(int $listId): ?array {
    $path = bot_list_audio_root().'/bot_ivr/audio_jobs/list_'.$listId.'.json';
    if (!is_file($path)) return null;
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('No se puede leer el estado de generación.');
    $status = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($status) || (int)($status['list_id'] ?? 0) !== $listId) throw new RuntimeException('Estado de generación inválido.');
    if (in_array($status['state'] ?? '', ['starting','running'], true) && !bot_list_audio_active($listId)) {
        $status['state'] = 'failed';
        $status['errors'] = ['El generador se detuvo. Puedes volver a generar.'];
    }
    // Never return texts, absolute paths or the full lead/audio mapping to the UI.
    return array_intersect_key($status, array_flip(['state','signature','generated','reused','failed','completed','total','errors']));
}

function bot_list_audio_ready(?array $job, ?array $payload): bool {
    if (!$job || !$payload || ($job['state'] ?? '') !== 'ready'
        || ($job['signature'] ?? '') !== $payload['signature']
        || (int)($job['failed'] ?? 1) !== 0 || !empty($job['errors'])
        || (int)($job['total'] ?? 0) < 1
        || (int)($job['completed'] ?? 0) !== (int)$job['total']) return false;
    $path = bot_list_audio_root().'/bot_ivr/audio_jobs/list_'.$payload['list_id'].'.json';
    $manifest = json_decode((string)@file_get_contents($path), true);
    if (!is_array($manifest) || ($manifest['signature'] ?? '') !== $payload['signature']
        || ($manifest['state'] ?? '') !== 'ready'
        || (int)($manifest['campaign_id'] ?? 0) !== $payload['campaign_id']
        || count($manifest['audio'] ?? []) !== count($payload['prompts'])) return false;
    $index = bot_list_audio_root().'/sounds/cache/ivr_builder/gtts/audio_registry.sqlite';
    if (!is_file($index)) return false;
    $registry = new PDO('sqlite:'.$index, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $registry->exec('PRAGMA query_only=ON');
    $lookup = $registry->prepare('SELECT 1 FROM published_audio WHERE hash=?');
    $checked = [];
    foreach ($manifest['audio'] as $audio) {
        $hash = $audio['hash'] ?? '';
        if (empty($audio['ready']) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) return false;
        if (isset($checked[$hash])) continue;
        $lookup->execute([$hash]);
        if (!$lookup->fetchColumn()) return false;
        $checked[$hash] = true;
    }
    return !bot_list_audio_active((int)$payload['list_id']);
}

function bot_list_audio_payload(array $flow, array $leads, int $listId, int $campaignId, int $workers): array {
    if (!in_array($workers, [1,3,10,25,60,100], true)) throw new RuntimeException('Velocidad de generación inválida.');
    $check = bot_list_audio_preflight($flow, $leads, $listId, $campaignId);
    if ($check['rejected']) throw new RuntimeException('Corrige las variables de los leads antes de generar. '.implode(' ', $check['errors']));
    $templates = bot_list_audio_templates($flow,true);
    // Composite output needs concatenation/pauses; this first provider only builds individual prompts.
    foreach (array_keys($templates) as $node) {
        if (strpos($node, '.segment_') !== false) throw new RuntimeException('La generación de audios compuestos aún no está disponible.');
    }
    $prompts = [];
    foreach ($leads as $lead) {
        $variables = bot_list_audio_variables($lead, $listId, $campaignId);
        foreach ($templates as $node => $template) {
            $text = bot_list_audio_render($template, $variables);
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            $prompts[] = ['lead_id'=>(int)$lead['lead_id'], 'node'=>$node, 'text'=>$text];
        }
    }
    $payload = ['list_id'=>$listId, 'campaign_id'=>$campaignId, 'flow'=>$flow,
                'provider'=>'gtts', 'profile'=>'gtts-es-com-1.3-pcm16-mono8000-v1', 'prompts'=>$prompts];
    $signature = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    unset($payload['flow']);
    $payload['signature'] = $signature;
    $payload['workers'] = $workers;
    return $payload;
}

function bot_list_audio_start(array $payload): void {
    $root = bot_list_audio_root();
    $jobs = $root.'/bot_ivr/audio_jobs';
    $python = __DIR__.'/../venvs/gtts_env/bin/python';
    $worker = $root.'/modules/bot_ivr/list_audio_worker.py';
    if (!is_executable($python) || !is_readable($worker) || !is_writable($jobs)
        || !is_writable($root.'/sounds/cache/ivr_builder/gtts')) {
        throw new RuntimeException('El proveedor gTTS local no está preparado en este servidor.');
    }
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($encoded) > 64 * 1024 * 1024) throw new RuntimeException('El trabajo supera 64 MB. Divide la lista.');
    $path = $jobs.'/'.bin2hex(random_bytes(16)).'.input.json';
    $created = fopen($path, 'x');
    if ($created === false) throw new RuntimeException('No se pudo preparar la generación.');
    chmod($path, 0600);
    try {
        if (fwrite($created, $encoded) !== strlen($encoded)) throw new RuntimeException('No se pudo guardar el trabajo completo.');
    } catch (Throwable $e) {
        unlink($path);
        throw $e;
    } finally { fclose($created); }
    $started = false;
    try {
        $process = proc_open([$python, $worker, '--root', $root, '--job', $path],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el generador.');
        $response = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $result = json_decode((string)$response, true);
        if ($code !== 0 || empty($result['ok'])) throw new RuntimeException('No se pudo iniciar la generación. Comprueba si ya está activa.');
        $started = true;
    } finally { if (!$started && is_file($path)) unlink($path); }
}
