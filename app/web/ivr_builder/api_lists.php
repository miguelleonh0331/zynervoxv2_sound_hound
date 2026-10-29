<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/db.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Solo lectura de zynervox_bot_lists/zynervox_bot_list: son tablas del
// modulo bot_ivr (campanas/listas de contactos), no del IVR Builder. Este
// endpoint nunca escribe ahi -- evita el mismo tipo de colision que ya
// tuvimos con la cuenta MySQL compartida entre modulos.

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function slug_variable(string $value): string {
    $value = trim($value);
    if (function_exists('transliterator_transliterate')) {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        if ($ascii !== false) $value = $ascii;
    } else {
        $value = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $value);
    }
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
    $value = preg_replace('/_+/', '_', $value) ?? '';
    return trim($value, '_');
}

$db = bot_ivr_db();
$action = (string) ($_GET['action'] ?? 'list');

if ($action === 'list') {
    $rows = $db->query(
        "SELECT list_id, name FROM zynervox_bot_lists WHERE active = 1 ORDER BY name ASC"
    )->fetchAll();
    $items = [];
    foreach ($rows as $row) {
        $items[] = ['id' => (int) $row['list_id'], 'name' => (string) $row['name']];
    }
    echo json_encode(['ok' => true, 'lists' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'variables') {
    $listId = filter_var($_GET['list_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($listId === false) fail('list_id inválido');

    $exists = $db->prepare('SELECT name FROM zynervox_bot_lists WHERE list_id = :l');
    $exists->execute([':l' => $listId]);
    $list = $exists->fetch();
    if (!$list) fail('Lista inexistente', 404);

    $stmt = $db->prepare('SELECT extra_json FROM zynervox_bot_list WHERE list_id = :l AND extra_json IS NOT NULL LIMIT 25');
    $stmt->execute([':l' => $listId]);

    $variables = [];
    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode((string) $row['extra_json'], true);
        if (!is_array($data)) continue;
        foreach (array_keys($data) as $key) {
            $slug = slug_variable((string) $key);
            if ($slug !== '' && $slug !== 'numero') $variables[$slug] = true;
        }
    }
    $variables = array_keys($variables);
    sort($variables);

    echo json_encode([
        'ok' => true,
        'list_id' => $listId,
        'list_name' => (string) $list['name'],
        'variables' => $variables,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

fail('Acción inválida');
