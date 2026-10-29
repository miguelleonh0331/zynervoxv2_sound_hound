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
    // SIPAddHeader (chan_sip) answers result=0 on success; -1 means the header was not added.
    if (str_starts_with($command,'EXEC SIPAddHeader ') && !preg_match('/^200 result=0(?:\s|$)/',trim($reply))) throw new RuntimeException('Forward header failed');
    return trim($reply);
}
try {
    $reply=forward_command('GET VARIABLE ZV2_FORWARD_HEADERS');
    if (!preg_match('/^200 result=1 \(([A-Za-z0-9+\/=]{1,32768})\)$/D',$reply,$match)) throw new RuntimeException('Missing forwarding headers');
    $headers=json_decode(base64_decode($match[1],true),true,32,JSON_THROW_ON_ERROR);
    $config=ivr_forwarding_render(['forwarding'=>['mode'=>'anexo','protocol'=>'SIP','endpoint'=>'validated','headers'=>$headers]],[]);
    foreach ($config['headers'] as $header) forward_command(ivr_forwarding_sip_add_header_command($header));
} catch (Throwable $e) {
    // A missing/invalid header must prevent sending the outgoing INVITE.
    echo "EXEC Hangup\n";fflush(STDOUT);fgets(STDIN);exit(1);
}
