<?php
declare(strict_types=1);

function bot_list_audio_variables(array $lead, int $listId, int $campaignId): array {
    try {
        $extra = json_decode((string)($lead['extra_json'] ?? '{}'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Las variables del lead contienen JSON inválido.');
    }
    if (!is_object($extra)) throw new RuntimeException('Las variables del lead deben ser un objeto JSON.');
    $variables = [];
    foreach (get_object_vars($extra) as $key => $value) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string)$key)) continue;
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new RuntimeException('La variable '.$key.' debe contener texto o un número.');
        }
        $variables[$key] = trim((string)$value);
    }
    // Names imported with alternate headers still expose the canonical nombre.
    if (!isset($variables['nombre']) || $variables['nombre'] === '') $variables['nombre'] = trim((string)($lead['customer_name'] ?? ''));
    // System identifiers cannot be overridden by uploaded columns.
    return array_merge($variables, [
        'numero'=>(string)$lead['phone'], 'lead_id'=>(string)$lead['lead_id'],
        'list_id'=>(string)$listId, 'campaign_id'=>(string)$campaignId,
    ]);
}

function bot_list_audio_response_variables(array $flow): array {
    $variables=[];
    foreach ($flow['nodes'] ?? [] as $node) if (($node['type'] ?? '')==='capture_stt' && !empty($node['variable'])) {
        foreach (['','_raw','_spoken'] as $suffix) $variables[]=$node['variable'].$suffix;
    }
    return array_unique($variables);
}

function bot_list_audio_templates(array $flow, bool $prebuildOnly=false): array {
    $nodes = $flow['nodes'] ?? [];
    $pending = [$flow['start'] ?? ''];
    $seen = [];
    $templates = [];
    while ($pending) {
        $id = array_pop($pending);
        if ($id === '' || isset($seen[$id])) continue;
        if (!isset($nodes[$id]) || !is_array($nodes[$id])) throw new RuntimeException('El flujo referencia un nodo inexistente.');
        $seen[$id] = true;
        $node = $nodes[$id];
        $type = $node['type'] ?? '';
        $texts = [];
        if (in_array($type, ['create_audio','create_audio_dynamic','capture_stt','menu','menu_ari','playback','hangup'], true)) {
            $texts['audio'] = $node['audio_text'] ?? '';
            if ($type === 'create_audio_dynamic' && trim((string)$texts['audio']) === '') $texts['audio'] = $node['message'] ?? '';
        }
        if ($type === 'bridge') $texts['bridge'] = $node['bridge_text'] ?? '';
        $texts['retry'] = $node['retry_text'] ?? '';
        if ($type === 'create_audio_composite') {
            foreach ($node['segments'] ?? [] as $index => $segment) $texts['segment_'.$index] = $segment['text'] ?? '';
        }
        foreach ($texts as $kind => $text) {
            if (!is_string($text)) throw new RuntimeException('El flujo contiene un texto de audio inválido.');
            $text = trim($text);
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',$text,$matches);
            if ($text !== '' && (!$prebuildOnly || !array_intersect(bot_list_audio_response_variables($flow),$matches[1]))) $templates[$id.'.'.$kind] = $text;
        }
        $targets = [$node['next'] ?? '', $node['fallback'] ?? ''];
        foreach ($node['edges'] ?? [] as $target) $targets[] = $target;
        foreach ($node['stt_intents'] ?? [] as $intent) $targets[] = $intent['target'] ?? '';
        foreach ($node['ranges'] ?? [] as $range) $targets[] = $range['target'] ?? '';
        foreach ($targets as $target) {
            if (!is_string($target) && !is_int($target)) throw new RuntimeException('El flujo contiene una transición inválida.');
            if ((string)$target !== '') $pending[] = (string)$target;
        }
    }
    if (!$templates) throw new RuntimeException('El flujo publicado no contiene textos para generar.');
    return $templates;
}

function bot_list_audio_render(string $template, array $variables): string {
    preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $matches);
    $missing = [];
    foreach (array_unique($matches[1]) as $key) {
        if (!array_key_exists($key, $variables) || trim((string)$variables[$key]) === '') $missing[] = $key;
    }
    if ($missing) throw new RuntimeException('Variables ausentes o vacías: '.implode(', ', $missing).'.');
    $text = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', static function ($match) use ($variables) {
        return (string)$variables[$match[1]];
    }, $template);
    if ($text === null || trim($text) === '' || mb_strlen($text, 'UTF-8') > 1000
        || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text)) {
        throw new RuntimeException('El texto resultante debe tener de 1 a 1000 caracteres válidos.');
    }
    // Substitutions must not leave another unresolved template in the payload.
    if (strpos($text, '{') !== false || strpos($text, '}') !== false) throw new RuntimeException('El texto resultante contiene variables sin resolver.');
    return $text;
}

function bot_list_audio_preflight(array $flow, array $leads, int $listId, int $campaignId): array {
    if (!$leads) throw new RuntimeException('Carga leads en la lista antes de generar audios.');
    $templates = bot_list_audio_templates($flow,true);
    $result = ['leads'=>count($leads), 'ready'=>0, 'rejected'=>0, 'texts'=>0, 'errors'=>[]];
    foreach ($leads as $lead) {
        try {
            if ((int)$lead['list_id'] !== $listId) throw new RuntimeException('El lead no pertenece a esta lista.');
            $variables = bot_list_audio_variables($lead, $listId, $campaignId);
            foreach ($templates as $node => $template) {
                try { bot_list_audio_render($template, $variables); }
                catch (RuntimeException $e) { throw new RuntimeException('Nodo '.$node.': '.$e->getMessage()); }
            }
            $result['ready']++;
            $result['texts'] += count($templates);
        } catch (RuntimeException $e) {
            $result['rejected']++;
            if (count($result['errors']) < 5) $result['errors'][] = 'Lead #'.(int)$lead['lead_id'].': '.$e->getMessage();
        }
    }
    return $result;
}
