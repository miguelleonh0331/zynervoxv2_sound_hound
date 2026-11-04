<?php
// php recording_metadata_test.php  (uses a temporary directory, no database)
declare(strict_types=1);
require_once __DIR__.'/../../../../app/web/bot_ivr/call_audit_service.php';
$fail = 0;
function check(string $what, bool $ok): void { global $fail; echo ($ok ? 'ok   ' : 'FAIL ').$what."\n"; if (!$ok) $fail++; }
$root = sys_get_temp_dir().'/zv_rec_'.bin2hex(random_bytes(4));
mkdir($root.'/recordings/bot_ivr', 0777, true); mkdir($root.'/recordings/marcador', 0777, true);
file_put_contents($root.'/recordings/bot_ivr/zv2-a.wav', str_repeat('x', 100));
file_put_contents($root.'/recordings/marcador/zv2-b.wav', str_repeat('x', 100));
file_put_contents($root.'/recordings/marcador/zv2-c.wav', str_repeat('x', 40));
symlink($root.'/recordings/bot_ivr/zv2-a.wav', $root.'/recordings/marcador/zv2-d.wav');
$a = call_recording_metadata('zv2-a', $root); $b = call_recording_metadata('zv2-b', $root);
check('bot_ivr sigue funcionando', $a['recording_status'] === 'ready' && str_ends_with((string)$a['recording_path'], '/bot_ivr/zv2-a.wav'));
check('encuentra la grabación del marcador', $b['recording_status'] === 'ready' && str_ends_with((string)$b['recording_path'], '/marcador/zv2-b.wav'));
check('fichero de solo cabecera es empty', call_recording_metadata('zv2-c', $root)['recording_status'] === 'empty');
check('enlace simbólico se ignora', call_recording_metadata('zv2-d', $root)['recording_status'] === 'none');
check('sin fichero es none', call_recording_metadata('zv2-z', $root)['recording_path'] === null);
check('rechaza ID inválido', (function () use ($root) { try { call_recording_metadata('../x', $root); } catch (RuntimeException $e) { return true; } return false; })());
exec('rm -rf '.escapeshellarg($root));
echo $fail ? "FALLOS: $fail\n" : "TODO OK\n";
exit($fail ? 1 : 0);
