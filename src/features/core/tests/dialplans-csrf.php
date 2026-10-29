<?php
session_start();
$_SESSION['user']='admin';
$_SESSION['user_level']=9;
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['save_dialplan'=>'1','name'=>'CSRF fixture','dialplan_entry'=>'exten => _999X.,1,Hangup()','active'=>'Y'];
register_shutdown_function(function () {
    if (http_response_code()!==403) throw new RuntimeException('Dialplan CSRF accepted');
    echo " CSRF_REJECTED\n";
});
require $argv[1].'/modules/admin/dialplan.php';
