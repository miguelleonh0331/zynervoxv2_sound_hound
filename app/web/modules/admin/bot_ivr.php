<?php
require_once __DIR__ . '/../../includes/Auth.php';
use Includes\Auth;

Auth::checkAccess(9);
$pageTitle = 'Bot IVR';
$activeKey = 'bot_ivr';
require __DIR__ . '/_stub.php';
