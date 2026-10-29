<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
ivr_builder_require_login();
$hash = (string) ($_GET['hash'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $hash)) { http_response_code(400); exit; }
$file = \Config\Config::audioRoot() . '/cache/ivr_builder/macelioai/' . $hash . '.wav';
if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: audio/wav');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store');
readfile($file);
