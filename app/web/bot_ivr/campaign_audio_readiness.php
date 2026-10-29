<?php
declare(strict_types=1);

function campaign_audio_queue_signature(array $rows): string {
    $ctx=hash_init('sha256');
    foreach($rows as $row){foreach(['id','phone','customer_name','amount','store_address'] as $key){hash_update($ctx,(string)($row[$key]??''));hash_update($ctx,"\0");}hash_update($ctx,"\n");}
    return hash_final($ctx);
}

function campaign_audio_readiness(PDO $db,int $campaignId): array {
    $result=['ready'=>false,'reason'=>'Campaña inválida.','total'=>0,'pending'=>0,'building'=>0,'ready_count'=>0,'failed'=>0,'generated'=>0,'reused'=>0,'runtime_only'=>0,'provider'=>'macelioai','manifest_status'=>'missing'];
    $stmt=$db->prepare('SELECT id,flow_code,tts_provider FROM synervox_campaigns WHERE id=:id');$stmt->execute([':id'=>$campaignId]);$campaign=$stmt->fetch(PDO::FETCH_ASSOC);if(!$campaign)return $result;
    $rowsStmt=$db->prepare('SELECT id,phone,customer_name,amount,store_address,COALESCE(audio_status,"pending") audio_status,audio_error FROM carsa_initial_survey WHERE campaign_id=:id AND status="pending" ORDER BY id');$rowsStmt->execute([':id'=>$campaignId]);$rows=$rowsStmt->fetchAll(PDO::FETCH_ASSOC);
    $result['total']=count($rows);foreach($rows as $row){$s=(string)($row['audio_status']??'pending');if($s==='ready')$result['ready_count']++;elseif($s==='building')$result['building']++;elseif($s==='failed')$result['failed']++;else$result['pending']++;}
    if(!$rows){$result['reason']='La campaña no tiene clientes pendientes.';return $result;}
    $buildStmt=$db->prepare('SELECT * FROM synervox_campaign_audio_builds WHERE campaign_id=:id');$buildStmt->execute([':id'=>$campaignId]);$build=$buildStmt->fetch(PDO::FETCH_ASSOC);
    if(!$build){$result['reason']='Primero genera los audios de la campaña.';return $result;}
    $result['manifest_status']=(string)$build['status'];$result['generated']=(int)$build['generated'];$result['reused']=(int)$build['reused'];$result['runtime_only']=(int)$build['runtime_only'];$result['provider']=(string)$build['provider'];
    $allowed=['macelioai','macelioai_remote','deepgram','deepgram_pool','voicescloning','deepgram_pod'];if(!in_array($result['provider'],$allowed,true)){$result['reason']='La generación contiene un proveedor TTS inválido.';return $result;}
    $assigned=strtolower(trim((string)($campaign['tts_provider']??'')));if($assigned!==''&&$assigned!==$result['provider']){$result['reason']='El proveedor TTS asignado cambió; regenera los audios.';$result['manifest_status']='stale';return $result;}
    if($build['status']!=='ready'){$result['reason']=(string)($build['error_message']?:'La generación no terminó correctamente.');return $result;}
    $flowStmt=$db->prepare('SELECT published_sha256 FROM bot_ivr_flows WHERE flow_code=:c');$flowStmt->execute([':c'=>$campaign['flow_code']]);$flowSha=(string)($flowStmt->fetchColumn()?:'');
    if($flowSha===''||!hash_equals((string)$build['flow_sha256'],$flowSha)){$result['reason']='El flujo cambió; regenera los audios.';$result['manifest_status']='stale';return $result;}
    $queueSha=campaign_audio_queue_signature($rows);if(!hash_equals((string)$build['queue_sha256'],$queueSha)){$result['reason']='Los datos de clientes cambiaron; regenera los audios.';$result['manifest_status']='stale';return $result;}
    if($result['building']>0){$result['reason']='Los audios todavía se están generando.';return $result;}if($result['failed']>0){$result['reason']='Hay audios con error; vuelve a generar.';return $result;}if($result['pending']>0||$result['ready_count']!==$result['total']){$result['reason']='Faltan audios por generar.';return $result;}
    $files=$db->prepare('SELECT file_path,byte_size,status FROM synervox_campaign_audio_files WHERE campaign_id=:id');$files->execute([':id'=>$campaignId]);$required=$files->fetchAll(PDO::FETCH_ASSOC);if(!$required){$result['reason']='No existen archivos multimedia registrados para la campaña.';return $result;}
    foreach($required as $file){$path=(string)$file['file_path'];if((string)$file['status']!=='ready'||strpos($path,'/var/lib/asterisk/sounds/')!==0||!is_file($path)||filesize($path)<500){$result['reason']='Un audio requerido falta o está incompleto; regenera.';return $result;}}
    $result['ready']=true;$result['reason']='Audios preparados.';return $result;
}

