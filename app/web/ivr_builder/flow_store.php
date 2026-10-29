<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/Database.php';

define('IVR_PUBLISHED_DIR', \Config\Config::deployment('runtime', '/etc/asterisk/synervox') . '/modules/flows/published');

function flow_store(PDO $db, array $flow): void {
    $code = (string)$flow['flow_code'];
    $db->beginTransaction();
    try {
        $meta = $db->prepare(
            'INSERT INTO bot_ivr_flows (flow_code,name,data_json,start_node_id,list_id) VALUES (:c,:n,\'\',:s,:l)
             ON DUPLICATE KEY UPDATE name=VALUES(name), start_node_id=VALUES(start_node_id), list_id=VALUES(list_id)'
        );
        $meta->execute([':c'=>$code, ':n'=>$flow['name'], ':s'=>$flow['start'], ':l'=>$flow['list_id'] ?? null]);
        $db->prepare('DELETE FROM bot_ivr_flow_nodes WHERE flow_code=:c')->execute([':c'=>$code]);

        $nodeSql = 'INSERT INTO bot_ivr_flow_nodes
            (flow_code,node_id,type,message,audio,audio_text,audio_hash,audio_status,capture_mode,variable_name,
             bridge_id,bridge_category,bridge_text,execute_url,execute_method,anexo,contexto,prioridad,
             next_node_id,fallback_node_id,timeout_ms,retry_count,retry_text,retry_audio,retry_audio_hash,
             position_x,position_y,compose_provider,composite_hash,composite_status)
             VALUES (:flow_code,:node_id,:type,:message,:audio,:audio_text,:audio_hash,:audio_status,:capture_mode,:variable_name,
             :bridge_id,:bridge_category,:bridge_text,:execute_url,:execute_method,:anexo,:contexto,:prioridad,
             :next_node_id,:fallback_node_id,:timeout_ms,:retry_count,:retry_text,:retry_audio,:retry_audio_hash,
             :position_x,:position_y,:compose_provider,:composite_hash,:composite_status)';
        $nodeStmt = $db->prepare($nodeSql);
        $edgeStmt = $db->prepare('INSERT INTO bot_ivr_flow_edges (flow_code,node_id,edge_key,target_node_id) VALUES (:c,:n,:k,:t)');
        $intentStmt = $db->prepare('INSERT INTO bot_ivr_flow_intents (flow_code,node_id,intent_id,match_type,priority,target_node_id,fuzzy) VALUES (:c,:n,:i,:m,:p,:t,:f)');
        $phraseStmt = $db->prepare('INSERT INTO bot_ivr_flow_intent_phrases (flow_code,node_id,intent_id,phrase_order,phrase) VALUES (:c,:n,:i,:o,:p)');
        $rangeStmt = $db->prepare('INSERT INTO bot_ivr_flow_ranges (flow_code,node_id,range_order,min_value,max_value,label,target_node_id) VALUES (:c,:n,:o,:min,:max,:l,:t)');
        $segmentStmt = $db->prepare('INSERT INTO bot_ivr_flow_segments (flow_code,node_id,segment_order,segment_id,text_value,pause_after_ms,hash_value,cached) VALUES (:c,:n,:o,:i,:t,:p,:h,:cached)');
        $fieldStmt = $db->prepare('INSERT INTO bot_ivr_flow_sql_fields (flow_code,node_id,column_name,template_value) VALUES (:c,:n,:k,:v)');

        foreach ($flow['nodes'] as $id=>$node) {
            $nodeStmt->execute([
                ':flow_code'=>$code, ':node_id'=>$id, ':type'=>$node['type'], ':message'=>$node['message'],
                ':audio'=>$node['audio'], ':audio_text'=>$node['audio_text'], ':audio_hash'=>$node['audio_hash'],
                ':audio_status'=>$node['audio_status'], ':capture_mode'=>$node['capture_mode'], ':variable_name'=>$node['variable'],
                ':bridge_id'=>$node['bridge_id'], ':bridge_category'=>$node['bridge_category'], ':bridge_text'=>$node['bridge_text'],
                ':execute_url'=>$node['execute_url'], ':execute_method'=>$node['execute_method'], ':anexo'=>$node['anexo'],
                ':contexto'=>$node['contexto'], ':prioridad'=>$node['prioridad'], ':next_node_id'=>$node['next'],
                ':fallback_node_id'=>$node['fallback'], ':timeout_ms'=>$node['timeout_ms'], ':retry_count'=>$node['retry_count'],
                ':retry_text'=>$node['retry_text'], ':retry_audio'=>$node['retry_audio'], ':retry_audio_hash'=>$node['retry_audio_hash'],
                ':position_x'=>$node['position']['x'], ':position_y'=>$node['position']['y'], ':compose_provider'=>$node['compose_provider'],
                ':composite_hash'=>$node['composite_hash'], ':composite_status'=>$node['composite_status'],
            ]);
            foreach ($node['edges'] as $key=>$target) $edgeStmt->execute([':c'=>$code,':n'=>$id,':k'=>$key,':t'=>$target]);
            foreach ($node['stt_intents'] as $intent) {
                $intentStmt->execute([':c'=>$code,':n'=>$id,':i'=>$intent['id'],':m'=>$intent['match'],':p'=>$intent['priority'],':t'=>$intent['target'],':f'=>(int)$intent['fuzzy']]);
                foreach ($intent['phrases'] as $order=>$phrase) $phraseStmt->execute([':c'=>$code,':n'=>$id,':i'=>$intent['id'],':o'=>$order,':p'=>$phrase]);
            }
            foreach ($node['ranges'] as $order=>$range) $rangeStmt->execute([':c'=>$code,':n'=>$id,':o'=>$order,':min'=>$range['min'],':max'=>$range['max'],':l'=>$range['label'],':t'=>$range['target']]);
            foreach ($node['segments'] as $order=>$segment) $segmentStmt->execute([':c'=>$code,':n'=>$id,':o'=>$order,':i'=>$segment['id'],':t'=>$segment['text'],':p'=>$segment['pause_after_ms'],':h'=>$segment['hash'],':cached'=>(int)$segment['cached']]);
            foreach ($node['inject_sql_fields'] as $column=>$template) $fieldStmt->execute([':c'=>$code,':n'=>$id,':k'=>$column,':v'=>$template]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function flow_load(PDO $db, string $code): ?array {
    $meta = $db->prepare('SELECT flow_code,name,start_node_id,list_id,updated_at FROM bot_ivr_flows WHERE flow_code=:c');
    $meta->execute([':c'=>$code]);
    $row = $meta->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $nodesStmt = $db->prepare('SELECT * FROM bot_ivr_flow_nodes WHERE flow_code=:c ORDER BY node_id');
    $nodesStmt->execute([':c'=>$code]);
    $nodes = [];
    foreach ($nodesStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $nodes[$n['node_id']] = [
            'type'=>$n['type'],'message'=>$n['message'],'audio'=>$n['audio'],'audio_text'=>$n['audio_text'],
            'audio_hash'=>$n['audio_hash'],'audio_status'=>$n['audio_status'],'capture_mode'=>$n['capture_mode'],
            'variable'=>$n['variable_name'],'bridge_id'=>$n['bridge_id'],'bridge_category'=>$n['bridge_category'],
            'bridge_text'=>$n['bridge_text'],'execute_url'=>$n['execute_url'],'execute_method'=>$n['execute_method'],
            'anexo'=>$n['anexo'],'contexto'=>$n['contexto'],'prioridad'=>(int)$n['prioridad'],
            'inject_sql_fields'=>[],'next'=>$n['next_node_id'],'fallback'=>$n['fallback_node_id'],
            'timeout_ms'=>(int)$n['timeout_ms'],'retry_count'=>(int)$n['retry_count'],'retry_text'=>$n['retry_text'],
            'retry_audio'=>$n['retry_audio'],'retry_audio_hash'=>$n['retry_audio_hash'],
            'position'=>['x'=>(int)$n['position_x'],'y'=>(int)$n['position_y']],
            'edges'=>[],'stt_intents'=>[],'ranges'=>[],'compose_provider'=>$n['compose_provider'],
            'segments'=>[],'composite_hash'=>$n['composite_hash'],'composite_status'=>$n['composite_status'],
        ];
    }
    flow_load_children($db, $code, $nodes);
    return ['flow_code'=>$row['flow_code'],'name'=>$row['name'],'start'=>$row['start_node_id'],'list_id'=>$row['list_id']!==null?(int)$row['list_id']:null,'nodes'=>$nodes,'updated_at'=>gmdate('c', strtotime((string)$row['updated_at']))];
}

function flow_load_children(PDO $db, string $code, array &$nodes): void {
    $queries = [
        'edges'=>'SELECT node_id,edge_key,target_node_id FROM bot_ivr_flow_edges WHERE flow_code=:c ORDER BY node_id,edge_key',
        'intents'=>'SELECT node_id,intent_id,match_type,priority,target_node_id,fuzzy FROM bot_ivr_flow_intents WHERE flow_code=:c ORDER BY node_id,priority,intent_id',
        'phrases'=>'SELECT node_id,intent_id,phrase FROM bot_ivr_flow_intent_phrases WHERE flow_code=:c ORDER BY node_id,intent_id,phrase_order',
        'ranges'=>'SELECT node_id,min_value,max_value,label,target_node_id FROM bot_ivr_flow_ranges WHERE flow_code=:c ORDER BY node_id,range_order',
        'segments'=>'SELECT node_id,segment_id,text_value,pause_after_ms,hash_value,cached FROM bot_ivr_flow_segments WHERE flow_code=:c ORDER BY node_id,segment_order',
        'fields'=>'SELECT node_id,column_name,template_value FROM bot_ivr_flow_sql_fields WHERE flow_code=:c ORDER BY node_id,column_name',
    ];
    $rows=[]; foreach($queries as $key=>$sql){$s=$db->prepare($sql);$s->execute([':c'=>$code]);$rows[$key]=$s->fetchAll(PDO::FETCH_ASSOC);}
    foreach($rows['edges'] as $r) if(isset($nodes[$r['node_id']])) $nodes[$r['node_id']]['edges'][$r['edge_key']]=$r['target_node_id'];
    $intentIndex=[];
    foreach($rows['intents'] as $r){if(!isset($nodes[$r['node_id']]))continue;$i=count($nodes[$r['node_id']]['stt_intents']);$nodes[$r['node_id']]['stt_intents'][]=['id'=>$r['intent_id'],'match'=>$r['match_type'],'phrases'=>[],'priority'=>(int)$r['priority'],'target'=>$r['target_node_id'],'fuzzy'=>(bool)$r['fuzzy']];$intentIndex[$r['node_id']."\0".$r['intent_id']]=$i;}
    foreach($rows['phrases'] as $r){$k=$r['node_id']."\0".$r['intent_id'];if(isset($intentIndex[$k]))$nodes[$r['node_id']]['stt_intents'][$intentIndex[$k]]['phrases'][]=$r['phrase'];}
    foreach($rows['ranges'] as $r) if(isset($nodes[$r['node_id']])) $nodes[$r['node_id']]['ranges'][]=['min'=>$r['min_value']===null?null:(int)$r['min_value'],'max'=>$r['max_value']===null?null:(int)$r['max_value'],'label'=>$r['label'],'target'=>$r['target_node_id']];
    foreach($rows['segments'] as $r) if(isset($nodes[$r['node_id']])) $nodes[$r['node_id']]['segments'][]=['id'=>$r['segment_id'],'text'=>$r['text_value'],'pause_after_ms'=>(int)$r['pause_after_ms'],'hash'=>$r['hash_value'],'cached'=>(bool)$r['cached']];
    foreach($rows['fields'] as $r) if(isset($nodes[$r['node_id']])) $nodes[$r['node_id']]['inject_sql_fields'][$r['column_name']]=$r['template_value'];
}

function flow_publish(PDO $db, array $flow): array {
    $json = json_encode($flow, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('No se pudo serializar el flujo publicado');
    $json .= "\n";

    $cfg = \Includes\Database::loadIvrDeployConfig();
    $apiUrl = trim((string) ($cfg['asterisk_api_url'] ?? ''));
    $apiToken = (string) ($cfg['asterisk_api_token'] ?? '');

    if ($apiUrl !== '' && $apiToken !== '') {
        $result = publish_flow_via_api($apiUrl, $apiToken, (string) $flow['flow_code'], $json);
    } else {
        // Fallback: servidor Asterisk y web todavia en la misma maquina y
        // sin configurar el panel -- escribe directo a disco local, igual
        // que el comportamiento original.
        $result = publish_flow_to_local_disk((string) $flow['flow_code'], $json);
    }

    $stmt = $db->prepare('UPDATE bot_ivr_flows SET published_at=NOW(),published_sha256=:s WHERE flow_code=:c');
    $stmt->execute([':s' => $result['sha256'], ':c' => $flow['flow_code']]);
    return $result;
}

function publish_flow_via_api(string $apiUrl, string $token, string $code, string $json): array {
    if (\Config\Config::deployment('isolated', false)) return publish_flow_to_local_disk($code, $json);
    $ch = curl_init(rtrim($apiUrl, '/') . '/asterisk_receive.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-IVR-Token: ' . $token],
        CURLOPT_POSTFIELDS => json_encode(['flow_code' => $code, 'json' => $json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('No se pudo contactar el API Asterisk: ' . $err);
    $resp = json_decode((string) $body, true);
    if ($httpCode !== 200 || !is_array($resp) || empty($resp['ok'])) {
        $error = is_array($resp) ? ($resp['error'] ?? $body) : $body;
        throw new RuntimeException('El API Asterisk rechazó la publicación: ' . $error);
    }
    return ['path' => (string) ($resp['path'] ?? ''), 'sha256' => (string) ($resp['sha256'] ?? hash('sha256', $json))];
}

function publish_flow_to_local_disk(string $code, string $json): array {
    if (!is_dir(IVR_PUBLISHED_DIR) && !mkdir(IVR_PUBLISHED_DIR, 0770, true) && !is_dir(IVR_PUBLISHED_DIR)) throw new RuntimeException('No se pudo crear el directorio de publicación');
    $path = IVR_PUBLISHED_DIR . '/' . $code . '.json';
    $tmp = tempnam(IVR_PUBLISHED_DIR, '.' . $code . '.tmp.');
    if ($tmp === false) throw new RuntimeException('No se pudo crear el archivo temporal');
    try {
        if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('No se pudo escribir la publicación');
        chmod($tmp, 0640);
        if (!rename($tmp, $path)) throw new RuntimeException('No se pudo publicar atómicamente');
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
    return ['path' => $path, 'sha256' => hash('sha256', $json)];
}
