#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require __DIR__.'/../ivr_builder/forwarding.php';
while (($line=fgets(STDIN))!==false && trim($line)!=='') {}
function forward_command(string $command): string {
    echo $command."\n";fflush(STDOUT);
    $reply=fgets(STDIN);
    if ($reply===false || !preg_match('/^200 result=[0-9]+(?:\s|$)/',trim($reply))) throw new RuntimeException('Forward AGI failed');
    if (str_starts_with($command,'SET VARIABLE ') && !preg_match('/^200 result=1(?:\s|$)/',trim($reply))) throw new RuntimeException('Forward header failed');
    return trim($reply);
}
try {
    $reply=forward_command('GET VARIABLE ZV2_FORWARD_HEADERS');
    if (!preg_match('/^200 result=1 \(([A-Za-z0-9+\/=]{1,32768})\)$/D',$reply,$match)) throw new RuntimeException('Missing forwarding headers');
    $headers=json_decode(base64_decode($match[1],true),true,32,JSON_THROW_ON_ERROR);
    $config=ivr_forwarding_render(['forwarding'=>['mode'=>'anexo','endpoint'=>'validated','headers'=>$headers]],[]);
    foreach ($config['headers'] as $header) forward_command('SET VARIABLE '.ivr_forwarding_agi_quote('PJSIP_HEADER(add,'.$header['name'].')').' '.ivr_forwarding_agi_quote($header['value']));
} catch (Throwable $e) {
    // A missing/invalid header must prevent sending the outgoing INVITE.
    echo "EXEC Hangup\n";fflush(STDOUT);fgets(STDIN);exit(1);
}
