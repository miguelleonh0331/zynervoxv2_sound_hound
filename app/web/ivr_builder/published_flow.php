<?php
declare(strict_types=1);
require_once __DIR__.'/../config/Config.php';

// Public read-only boundary for consumers of an IVR Builder publication.
function ivr_builder_published_flow(int $flowId): array {
    if ($flowId < 1 || $flowId > 99) throw new RuntimeException('El flujo debe tener un código de 01 a 99.');
    $code = str_pad((string)$flowId, 2, '0', STR_PAD_LEFT);
    $root = (string)\Config\Config::deployment('runtime', '/etc/asterisk/synervox');
    $path = $root.'/modules/flows/published/'.$code.'.json';
    if (!is_readable($path)) throw new RuntimeException('El flujo asignado no está publicado o no puede leerse.');
    if (filesize($path) > 10 * 1024 * 1024) throw new RuntimeException('La publicación del flujo supera el límite permitido.');
    try {
        $source = file_get_contents($path);
        if ($source === false) throw new RuntimeException('No se pudo leer la publicación.');
        $flow = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('La publicación del flujo contiene JSON inválido.');
    }
    if (!is_array($flow) || (string)($flow['flow_code'] ?? '') !== $code
        || !is_array($flow['nodes'] ?? null) || !is_string($flow['start'] ?? null)
        || !isset($flow['nodes'][$flow['start']])) {
        throw new RuntimeException('La publicación del flujo no es válida.');
    }
    return $flow;
}
