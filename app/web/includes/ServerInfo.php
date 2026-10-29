<?php

namespace Includes;

// Zynervox se despliega en varios servidores/labs con el MISMO repo, asi
// que un admin que salta entre pestanas no siempre sabe en cual esta
// parado. Este helper calcula hostname + IP LAN SOLO (nada que el usuario
// tenga que teclear) y ademas persiste una nota libre en disco, LOCAL a
// ESTE servidor (runtime/ es la unica carpeta escribible por www-data bajo
// el webroot, mismo patron que DevChecklist.php), para que el admin pueda
// anotar "este es produccion" / "este es el lab de pruebas X".
//
// La nota se guarda bajo la clave del hostname (no solo como texto suelto)
// como defensa extra: si alguna vez runtime/ se copia entre servidores por
// costumbre (rsync de un deploy), la nota de un servidor no se hace pasar
// por la de otro.
class ServerInfo {

    const NOTES_FILE = __DIR__ . '/../runtime/server_info/notes.json';

    public static function hostname(): string {
        $h = @gethostname();
        return ($h !== false && $h !== '') ? $h : 'desconocido';
    }

    // IP LAN real (no 127.0.0.1). SERVER_ADDR es la via estandar de PHP
    // cuando Apache escucha en la LAN; si no esta disponible (CLI, bind
    // raro) se cae a `hostname -I` (Linux, mismo SO que corre Zynervox).
    public static function lanIp(): string {
        $addr = $_SERVER['SERVER_ADDR'] ?? '';
        if ($addr !== '' && $addr !== '127.0.0.1' && $addr !== '::1') {
            return $addr;
        }
        $out = @shell_exec('hostname -I 2>/dev/null');
        if ($out) {
            foreach (preg_split('/\s+/', trim($out)) as $ip) {
                if ($ip !== '' && $ip !== '127.0.0.1') {
                    return $ip;
                }
            }
        }
        return $addr !== '' ? $addr : 'desconocida';
    }

    public static function getNotes(): string {
        $state = self::readFile();
        $key = self::hostname();
        return (string)($state[$key]['text'] ?? '');
    }

    public static function saveNotes(string $text): array {
        $text = trim($text);
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 2000); // tope defensivo, esto es una nota, no un documento
        } else {
            $text = substr($text, 0, 2000);
        }

        $state = self::readFile();
        $key = self::hostname();
        $state[$key] = [
            'text'       => $text,
            'updated_at' => date('Y-m-d H:i:s'),
            'by'         => $_SESSION['user'] ?? '',
        ];
        self::writeFile($state);
        return $state[$key];
    }

    private static function readFile(): array {
        if (!file_exists(self::NOTES_FILE)) {
            return [];
        }
        $raw = @file_get_contents(self::NOTES_FILE);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function writeFile(array $state): void {
        $dir = dirname(self::NOTES_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fp = fopen(self::NOTES_FILE, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
