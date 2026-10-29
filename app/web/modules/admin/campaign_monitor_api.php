<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/Auth.php';
require_once __DIR__.'/../../bot_ivr/db.php';
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(empty($_SESSION['user']) || !in_array((int)($_SESSION['user_level']??0),[7,8,9],true)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sesion requerida.']);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
try{echo json_encode(['ok'=>true,'campaigns'=>bot_ivr_repository()->campaignMonitor()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'No se pudo consultar las campanas.']);}
