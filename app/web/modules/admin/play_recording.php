<?php
// Sirve/descarga el archivo de audio de una grabacion (recording_log).
// "location" en recording_log puede ser:
//   - una URL remota (si el archivado remoto por FTP/HTTP esta configurado)
//     -> se redirige al navegador.
//   - una ruta local en disco (caso normal en un solo servidor: PATHmonitor /
//     PATHDONEmonitor de astguiclient.conf) -> se transmite con readfile().
// No se confia en ningun path que venga del usuario: solo se usa el valor
// guardado en la base de datos para el recording_id solicitado.
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Leads.php';

use Includes\Auth;
use Includes\Leads;

Auth::checkAccess(9);

$recordingId = $_GET['id'] ?? null;
if (!$recordingId) {
    http_response_code(400);
    echo 'Falta id de grabacion.';
    exit;
}

$rec = Leads::getRecordingById($recordingId);
if (!$rec) {
    http_response_code(404);
    echo 'Grabacion no encontrada.';
    exit;
}

$location = trim($rec['location'] ?? '');

// Caso remoto: redirigir directo a la URL de archivo.
if (preg_match('/^https?:\/\//i', $location)) {
    header('Location: ' . $location);
    exit;
}

// Caso local: usar location si es una ruta absoluta valida, si no, intentar
// reconstruirla con el filename en los directorios estandar de Asterisk.
$candidates = [];
if ($location !== '' && $location[0] === '/') {
    $candidates[] = $location;
}
if (!empty($rec['filename'])) {
    $candidates[] = '/var/spool/asterisk/monitor/' . $rec['filename'];
    $candidates[] = '/var/spool/asterisk/monitor/MIX/' . $rec['filename'];
    $candidates[] = '/var/spool/asterisk/monitorDONE/' . $rec['filename'];
}

$filePath = null;
foreach ($candidates as $c) {
    if (is_file($c)) { $filePath = $c; break; }
}

if (!$filePath) {
    http_response_code(404);
    echo 'Archivo de audio no encontrado en disco.';
    exit;
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mime = [
    'wav' => 'audio/wav',
    'gsm' => 'audio/gsm',
    'mp3' => 'audio/mpeg',
    'ogg' => 'audio/ogg',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Cache-Control: no-store');
readfile($filePath);
