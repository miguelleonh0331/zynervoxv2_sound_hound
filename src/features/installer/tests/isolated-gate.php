<?php
declare(strict_types=1);
$_SERVER['SCRIPT_FILENAME'] = $argv[1] . '/' . $argv[2];
register_shutdown_function(function (): void { echo 'STATUS=' . (http_response_code() ?: 200); });
require $argv[1] . '/includes/IsolatedGate.php';
echo 'ALLOWED ';
