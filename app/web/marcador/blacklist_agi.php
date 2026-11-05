#!/usr/bin/php
<?php
declare(strict_types=1);
// AGI called by every outbound dialplan (7307 to 7315, 7309 included; not the transfer leg) BEFORE the Dial:
//   AGI(blacklist_agi.php,${ZV2_PHONE})
// One prepared query by the last 9 digits (primary key). Sets the channel variable ZV2_BLOQ = NO | SI | ERROR (ERROR: database down or query failed; a number
// without digits too). The dialplan must not dial on SI or ERROR. It never fails the call: the answer is always the variable.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('default_socket_timeout', '2'); // a dead database must not hold the calls
while (($line = fgets(STDIN)) !== false && trim($line) !== '') { /* AGI environment is not needed */ }
$answer = 'ERROR';
try {
    require_once __DIR__.'/../bot_ivr/db.php';
    require_once __DIR__.'/blacklist.php';
    $answer = marcador_blacklist_check(bot_ivr_repository(), (string)($argv[1] ?? ''));
} catch (Throwable $e) { error_log('ZV2 blacklist_agi: '.get_class($e).': '.$e->getMessage()); }
echo 'SET VARIABLE ZV2_BLOQ '.$answer."\n";
fflush(STDOUT);
fgets(STDIN); // the AGI answer line
exit(0);
