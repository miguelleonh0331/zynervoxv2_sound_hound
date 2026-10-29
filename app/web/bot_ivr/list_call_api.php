<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require __DIR__.'/list_call_service.php';
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(!initial_survey_is_authenticated()){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sesion de administrador requerida.']);exit;}
try {
    $list=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$campaign=filter_var($_GET['campaign_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$list || !$campaign)throw new RuntimeException('Lista o campana invalida.');bot_ivr_repository()->list($list,$campaign);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='GET')$run=bot_list_call_status($list,$campaign);
    elseif($method==='POST'){
        $source=file_get_contents('php://input');if(strlen($source)>4096)throw new RuntimeException('Solicitud demasiado grande.');$data=json_decode($source,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Solicitud invalida.');
        if(!is_string($data['csrf']??null) || empty($_SESSION['bot_campaign_csrf']) || !hash_equals($_SESSION['bot_campaign_csrf'],$data['csrf'])){http_response_code(403);throw new RuntimeException('Sesion invalida. Recarga la pagina.');}
        if(($data['action']??'')==='play'){$channels=filter_var($data['channels']??null,FILTER_VALIDATE_INT);if($channels===false || !in_array($channels,[1,3,5,10,20,25,50,100],true))throw new RuntimeException('Cantidad de canales invalida.');$run=bot_list_call_start($list,$campaign,$channels,(string)$_SESSION['user']);}
        elseif(($data['action']??'')==='stop'){if(!is_string($data['run_id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$data['run_id']))throw new RuntimeException('Marcacion invalida.');$run=bot_list_call_stop($list,$campaign,$data['run_id']);}
        else throw new RuntimeException('Accion invalida.');
    }else{http_response_code(405);throw new RuntimeException('Metodo invalido.');}
    echo json_encode(['ok'=>true,'run'=>bot_list_call_public($run),'statistics'=>bot_ivr_repository()->listStatistics($list,$campaign)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['ok'=>false,'error'=>get_class($e)==='RuntimeException' || $e instanceof InvalidArgumentException?$e->getMessage():'No se pudo completar la operacion de llamadas.']);}
