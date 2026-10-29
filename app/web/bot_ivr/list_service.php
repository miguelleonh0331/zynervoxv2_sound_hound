<?php
declare(strict_types=1);

const BOT_LIST_UPLOAD_BYTES = 10485760;
const BOT_LIST_UPLOAD_ROWS = 50000;

function bot_list_get(\ZynervoxQueries\BotIvrRepository $db, int $listId, int $campaignId): array {
    return $db->list($listId, $campaignId);
}

function bot_list_header(string $value): string {
    $value = mb_strtolower(trim($value, " \t\r\n\xEF\xBB\xBF"), 'UTF-8');
    $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return trim(preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '', '_');
}

const BOT_LIST_MAX_SIP_HEADERS = 16;

// Parses "columna=X-Nombre" lines (or just "columna", which becomes X-Columna) into [csvColumn => headerName].
function bot_list_parse_header_map(string $text): array {
    $map = [];
    $names = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode('=', $line, 2);
        $column = bot_list_header($parts[0]);
        if ($column === '') throw new RuntimeException('Headers SIP: columna inválida en "'.$line.'".');
        $name = count($parts) === 2 ? trim($parts[1]) : 'X-'.implode('-', array_map('ucfirst', explode('_', $column)));
        if (isset($map[$column])) throw new RuntimeException('Headers SIP: la columna '.$column.' está repetida.');
        // RFC 3261 token, but the transfer (ivr_builder/forwarding.php) only emits X-* and User-to-User.
        if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,64}$/D', $name)) throw new RuntimeException('Headers SIP: "'.$name.'" no es un nombre de header válido (RFC 3261: letras, dígitos y !#$%&\'*+-.^_`|~).');
        if (!preg_match('/^(?:X-[A-Za-z0-9-]{1,60}|User-to-User)$/Di', $name)) throw new RuntimeException('Headers SIP: "'.$name.'" no se puede enviar en la transferencia. Usa el prefijo X- (p. ej. X-Cliente) o User-to-User.');
        if (isset($names[strtolower($name)])) throw new RuntimeException('Headers SIP: el header '.$name.' está repetido.');
        $names[strtolower($name)] = true;
        $map[$column] = $name;
    }
    if (count($map) > BOT_LIST_MAX_SIP_HEADERS) throw new RuntimeException('Headers SIP: máximo '.BOT_LIST_MAX_SIP_HEADERS.' columnas por lista.');
    return $map;
}

function bot_list_parse_txt(string $contents, array $headerMap = []): array {
    if (strlen($contents) > BOT_LIST_UPLOAD_BYTES) throw new RuntimeException('El archivo supera 10 MB.');
    if (!mb_check_encoding($contents, 'UTF-8')) throw new RuntimeException('Guarda el archivo en UTF-8 antes de cargarlo.');
    $stream = fopen('php://memory', 'r+');
    if ($stream === false) throw new RuntimeException('No se pudo leer el archivo.');
    try {
        fwrite($stream, $contents);
        rewind($stream);
        $raw = fgetcsv($stream, 0, ',', '"', '');
        $header = array_map('bot_list_header', $raw ?: []);
        if (count($header) < 2 || count($header) > 10) throw new RuntimeException('El TXT debe tener de 2 a 10 columnas separadas por comas.');
        if (in_array('', $header, true) || count(array_unique($header)) !== count($header)) throw new RuntimeException('Las cabeceras no pueden estar vacías ni repetidas.');
        foreach ($header as $key) if (strlen($key) > 60) throw new RuntimeException('Las cabeceras no pueden superar 60 caracteres.');
        $phoneIndex = array_search('numero', $header, true);
        if ($phoneIndex === false) throw new RuntimeException('Falta la columna obligatoria numero.');
        $nameIndex = false;
        foreach (['nombre','name','cliente','nombres'] as $alias) {
            $index = array_search($alias, $header, true);
            if ($index !== false) { $nameIndex = $index; break; }
        }
        foreach ($headerMap as $column=>$headerName) {
            if (!in_array($column, $header, true)) throw new RuntimeException('Headers SIP: la columna "'.$column.'" no existe en el TXT. Columnas: '.implode(', ', $header).'.');
            if ($column === 'numero') throw new RuntimeException('Headers SIP: la columna numero no puede ser un header.');
        }
        $result = ['rows'=>[], 'duplicates'=>0, 'rejected'=>0, 'errors'=>[], 'header_map'=>$headerMap];
        $seen = [];
        $record = 1;
        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $record++;
            if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
            if ($record > BOT_LIST_UPLOAD_ROWS + 1) throw new RuntimeException('El archivo supera 50000 registros. Divídelo en archivos más pequeños.');
            $reason = '';
            if (count($cells) > count($header)) $reason = 'cantidad de columnas incorrecta';
            $cells = array_pad($cells, count($header), '');
            $phone = preg_replace('/\D+/', '', (string)$cells[$phoneIndex]) ?? '';
            if ($phone === '' || strlen($phone) > 20) $reason = 'teléfono vacío o mayor de 20 dígitos';
            $name = $nameIndex === false ? '' : trim((string)$cells[$nameIndex]);
            if (mb_strlen($name, 'UTF-8') > 160) $reason = 'nombre mayor de 160 caracteres';
            $extra = [];
            foreach ($header as $index=>$key) {
                if ($index === $phoneIndex) continue;
                $value = trim((string)$cells[$index]);
                if (mb_strlen($value, 'UTF-8') > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', $value)) $reason = 'valor inválido o mayor de 1000 caracteres';
                $extra[$key] = $value;
            }
            $sipHeaders = [];
            foreach ($headerMap as $column=>$headerName) {
                $value = $extra[$column] ?? '';
                if ($value === '') $reason = 'header '.$headerName.' vacío (columna '.$column.')';
                elseif (strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f$\{\}]/', $value)) $reason = 'header '.$headerName.' con caracteres no permitidos ($, {, } o control) o mayor de 1024 bytes';
                $sipHeaders[] = ['name'=>$headerName, 'value'=>$value];
            }
            if ($reason !== '') {
                $result['rejected']++;
                if (count($result['errors']) < 5) $result['errors'][] = 'Registro '.$record.': '.$reason;
                continue;
            }
            if (isset($seen[$phone])) { $result['duplicates']++; continue; }
            $seen[$phone] = true;
            $result['rows'][] = ['phone'=>$phone, 'customer_name'=>$name, 'extra'=>$extra, 'headers'=>$sipHeaders];
        }
        return $result;
    } finally { fclose($stream); }
}

function bot_list_import(\ZynervoxQueries\BotIvrRepository $db, int $listId, int $campaignId, array $parsed): array {
    return $db->importLeads($listId, $campaignId, $parsed);
}
