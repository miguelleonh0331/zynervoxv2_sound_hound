<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
\Config\Config::requireLegacyRuntime();

const TTS_JOBS_TOKEN_FILE = '/etc/asterisk/synervox/secrets/tts_jobs_token';
const TTS_JOBS_BLOB_DIR = '/var/lib/asterisk/sounds/voicebot/runtime/tts_jobs';
const TTS_JOBS_CLAIM_STALE_SECONDS = 300;

function tts_jobs_reply(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tts_jobs_authenticate(): void {
    $expected = trim((string) @file_get_contents(TTS_JOBS_TOKEN_FILE));
    $given = trim((string) ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        tts_jobs_reply(['ok' => false, 'error' => 'No autorizado'], 401);
    }
}

function tts_jobs_hash(string $text, string $lang, string $speed): string {
    return hash('sha256', $text . '|' . $lang . '|' . $speed);
}

function tts_jobs_valid_hash(string $hash): bool {
    return preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
}

function tts_jobs_valid_wav(string $body): bool {
    return strlen($body) >= 100 && substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WAVE';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLoopback = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$isHttps = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on';
if (!$isLoopback && !$isHttps) {
    tts_jobs_reply(['ok' => false, 'error' => 'HTTPS requerido'], 426);
}
tts_jobs_authenticate();

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$db = carsa_db();
if (!is_dir(TTS_JOBS_BLOB_DIR) && !mkdir(TTS_JOBS_BLOB_DIR, 0770, true) && !is_dir(TTS_JOBS_BLOB_DIR)) {
    tts_jobs_reply(['ok' => false, 'error' => 'Almacenamiento TTS no disponible'], 500);
}

if ($action === 'enqueue') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') tts_jobs_reply(['ok' => false, 'error' => 'Método inválido'], 405);
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) tts_jobs_reply(['ok' => false, 'error' => 'JSON inválido'], 400);
    $text = trim((string) ($data['text'] ?? ''));
    $lang = strtolower(trim((string) ($data['lang'] ?? 'es')));
    $speed = number_format((float) ($data['speed'] ?? 1.3), 1, '.', '');
    if ($text === '' || mb_strlen($text, 'UTF-8') > 5000) tts_jobs_reply(['ok' => false, 'error' => 'Texto requerido; máximo 5000 caracteres'], 400);
    if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $lang)) tts_jobs_reply(['ok' => false, 'error' => 'Idioma inválido'], 400);
    if ((float) $speed < 0.5 || (float) $speed > 2.0) tts_jobs_reply(['ok' => false, 'error' => 'Velocidad inválida'], 400);
    $hash = tts_jobs_hash($text, $lang, $speed);
    $stmt = $db->prepare('SELECT status FROM tts_jobs WHERE job_hash=:hash');
    $stmt->execute([':hash' => $hash]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        $stmt = $db->prepare('INSERT IGNORE INTO tts_jobs (job_hash,text_content,lang,speed,status) VALUES (:hash,:text,:lang,:speed,"pending")');
        $stmt->execute([':hash' => $hash, ':text' => $text, ':lang' => $lang, ':speed' => $speed]);
        $status = 'pending';
    } elseif ($status === 'failed') {
        $stmt = $db->prepare('UPDATE tts_jobs SET status="pending",claimed_by=NULL,claimed_at=NULL,completed_at=NULL,error=NULL WHERE job_hash=:hash AND status="failed"');
        $stmt->execute([':hash' => $hash]);
        $status = 'pending';
    }
    tts_jobs_reply(['ok' => true, 'job_hash' => $hash, 'status' => $status]);
}

if ($action === 'pull') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') tts_jobs_reply(['ok' => false, 'error' => 'Método inválido'], 405);
    $take = max(1, min(10, (int) ($_GET['take'] ?? 3)));
    $worker = trim((string) ($_GET['worker'] ?? ''));
    if ($worker === '' || mb_strlen($worker, 'UTF-8') > 80) tts_jobs_reply(['ok' => false, 'error' => 'Worker inválido'], 400);
    $db->beginTransaction();
    try {
        $sql = 'SELECT id,job_hash,text_content,lang,speed FROM tts_jobs '
             . 'WHERE status="pending" OR (status="claimed" AND claimed_at < (NOW() - INTERVAL '
             . TTS_JOBS_CLAIM_STALE_SECONDS . ' SECOND)) ORDER BY id LIMIT ' . $take . ' FOR UPDATE';
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $db->exec('UPDATE tts_jobs SET status="claimed",claimed_by=' . $db->quote($worker)
                . ',claimed_at=NOW(),completed_at=NULL,error=NULL WHERE id IN (' . implode(',', $ids) . ')');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        tts_jobs_reply(['ok' => false, 'error' => 'No se pudo reclamar trabajo'], 500);
    }
    $jobs = array_map(static fn(array $row): array => [
        'job_hash' => $row['job_hash'], 'text' => $row['text_content'],
        'lang' => $row['lang'], 'speed' => (float) $row['speed'],
    ], $rows);
    tts_jobs_reply(['ok' => true, 'jobs' => $jobs]);
}

if ($action === 'status') {
    $hash = (string) ($_GET['job_hash'] ?? '');
    if (!tts_jobs_valid_hash($hash)) tts_jobs_reply(['ok' => false, 'error' => 'Hash inválido'], 400);
    $stmt = $db->prepare('SELECT status,error FROM tts_jobs WHERE job_hash=:hash');
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) tts_jobs_reply(['ok' => false, 'error' => 'Trabajo inexistente'], 404);
    tts_jobs_reply(['ok' => true, 'status' => $row['status'], 'error' => $row['error']]);
}

if ($action === 'download') {
    $hash = (string) ($_GET['job_hash'] ?? '');
    if (!tts_jobs_valid_hash($hash)) tts_jobs_reply(['ok' => false, 'error' => 'Hash inválido'], 400);
    $stmt = $db->prepare('SELECT status FROM tts_jobs WHERE job_hash=:hash');
    $stmt->execute([':hash' => $hash]);
    $file = TTS_JOBS_BLOB_DIR . '/' . $hash . '.wav';
    if ($stmt->fetchColumn() !== 'done' || !is_file($file) || filesize($file) < 100) {
        tts_jobs_reply(['ok' => false, 'error' => 'Audio no disponible'], 404);
    }
    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($file));
    $sent = readfile($file);
    if ($sent !== false && !connection_aborted()) {
        @unlink($file);
    }
    exit;
}

if ($action === 'submit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') tts_jobs_reply(['ok' => false, 'error' => 'Método inválido'], 405);
    $hash = (string) ($_GET['job_hash'] ?? '');
    $worker = trim((string) ($_GET['worker'] ?? ''));
    $body = (string) file_get_contents('php://input');
    if (!tts_jobs_valid_hash($hash) || $worker === '' || !tts_jobs_valid_wav($body)) {
        tts_jobs_reply(['ok' => false, 'error' => 'Entrega WAV inválida'], 400);
    }
    $stmt = $db->prepare('SELECT status,claimed_by FROM tts_jobs WHERE job_hash=:hash');
    $stmt->execute([':hash' => $hash]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job || $job['status'] !== 'claimed' || !hash_equals((string) $job['claimed_by'], $worker)) {
        tts_jobs_reply(['ok' => false, 'error' => 'El trabajo no pertenece a este worker'], 409);
    }
    $file = TTS_JOBS_BLOB_DIR . '/' . $hash . '.wav';
    $temporary = $file . '.tmp.' . getmypid();
    if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body) || !rename($temporary, $file)) {
        @unlink($temporary);
        tts_jobs_reply(['ok' => false, 'error' => 'No se pudo guardar el WAV'], 500);
    }
    chmod($file, 0660);
    $stmt = $db->prepare('UPDATE tts_jobs SET status="done",completed_at=NOW(),error=NULL WHERE job_hash=:hash AND status="claimed" AND claimed_by=:worker');
    $stmt->execute([':hash' => $hash, ':worker' => $worker]);
    tts_jobs_reply(['ok' => true]);
}

if ($action === 'fail') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') tts_jobs_reply(['ok' => false, 'error' => 'Método inválido'], 405);
    $hash = (string) ($_GET['job_hash'] ?? '');
    $worker = trim((string) ($_GET['worker'] ?? ''));
    $error = mb_substr(trim((string) ($_GET['error'] ?? 'Error remoto')), 0, 500, 'UTF-8');
    if (!tts_jobs_valid_hash($hash) || $worker === '') tts_jobs_reply(['ok' => false, 'error' => 'Solicitud inválida'], 400);
    $stmt = $db->prepare('UPDATE tts_jobs SET status="failed",completed_at=NOW(),error=:error WHERE job_hash=:hash AND status="claimed" AND claimed_by=:worker');
    $stmt->execute([':error' => $error, ':hash' => $hash, ':worker' => $worker]);
    if ($stmt->rowCount() !== 1) tts_jobs_reply(['ok' => false, 'error' => 'El trabajo no pertenece a este worker'], 409);
    tts_jobs_reply(['ok' => true]);
}

if ($action === 'stats') {
    $rows = $db->query('SELECT status,COUNT(*) amount FROM tts_jobs GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
    $queue = ['total' => 0, 'pending' => 0, 'claimed' => 0, 'done' => 0, 'failed' => 0];
    foreach ($rows as $row) {
        $amount = (int) $row['amount'];
        if (array_key_exists((string) $row['status'], $queue)) $queue[(string) $row['status']] = $amount;
        $queue['total'] += $amount;
    }
    $queue['remaining'] = $queue['pending'] + $queue['claimed'];
    tts_jobs_reply(['ok' => true, 'queue' => $queue]);
}

tts_jobs_reply(['ok' => false, 'error' => 'Acción inválida'], 400);
