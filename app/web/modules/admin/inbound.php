<?php
require_once __DIR__ . '/../../includes/Auth.php';
use Includes\Auth;

Auth::checkAccess(9);
$pageTitle = 'Inbound (Entrantes / DIDs)';
$activeKey = 'inbound';
require __DIR__ . '/_stub.php';
