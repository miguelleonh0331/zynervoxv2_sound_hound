<?php
session_start();
$_SESSION['user'] = 'admin';
$_SESSION['user_level'] = 9;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['save_carrier' => '1', 'carrier_id' => 'CSRF_INVALID'];
register_shutdown_function(function () {
    if (http_response_code() !== 403) throw new RuntimeException('CSRF accepted');
    echo " CSRF_REJECTED\n";
});
require $argv[1] . '/modules/admin/carriers.php';
