<?php
declare(strict_types=1);

const BOT_AUDIO_LAB_DIR = '/var/lib/asterisk/synervox/bot_ivr/audio_lab';
const BOT_AUDIO_LAB_PYTHON = '/etc/asterisk/synervox/venvs/audio_lab/bin/python';
const BOT_AUDIO_LAB_SCRIPT = '/etc/asterisk/synervox/modules/bot_ivr/audio_lab_generate.py';

function bot_audio_lab_providers(): array {
    // Add future network providers here and in the executor, never from request URLs.
    return ['gtts'=>'gTTS (gratis, voz básica)', 'rga'=>'RGA (Remote Generation Audio)'];
}
function bot_audio_lab_validate(string $text, string $provider): string {
    $text = trim($text);
    if (!mb_check_encoding($text, 'UTF-8') || $text === '' || mb_strlen($text, 'UTF-8') > 1000
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text)) {
        throw new RuntimeException('Escribe un texto válido de 1 a 1000 caracteres.');
    }
    if (!array_key_exists($provider, bot_audio_lab_providers())) throw new RuntimeException('Proveedor no disponible.');
    return $text;
}
function bot_audio_lab_file(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !isset($_SESSION['bot_audio_lab'][$id])) {
        throw new RuntimeException('Audio no disponible en esta sesión.');
    }
    $path = BOT_AUDIO_LAB_DIR.'/'.$id.'.wav';
    if (!is_file($path)) throw new RuntimeException('El archivo de audio ya no está disponible.');
    return $path;
}
function bot_audio_lab_generate(string $text, string $provider): string {
    require_once __DIR__ . '/../config/Config.php';
    if (\Config\Config::deployment('isolated', false)) throw new RuntimeException('Laboratorio legacy deshabilitado en v2 aislado');
    $text = bot_audio_lab_validate($text, $provider);
    if (!is_executable(BOT_AUDIO_LAB_PYTHON) || !is_file(BOT_AUDIO_LAB_SCRIPT) || !is_writable(BOT_AUDIO_LAB_DIR)) {
        throw new RuntimeException('El generador de prueba no está instalado o no tiene permisos.');
    }
    $id = bin2hex(random_bytes(16));
    $path = BOT_AUDIO_LAB_DIR.'/'.$id.'.wav';
    $process = proc_open([BOT_AUDIO_LAB_PYTHON, BOT_AUDIO_LAB_SCRIPT],
        [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el generador.');
    $success = false;
    try {
        fwrite($pipes[0], json_encode(['text'=>$text, 'provider'=>$provider, 'output'=>$path], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + ($provider === 'rga' ? 180 : 90);
        $response = '';
        do {
            $response .= stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('La generación tardó demasiado. Intenta con un texto más corto.');
            }
            usleep(100000);
        } while (true);
        $response .= stream_get_contents($pipes[1]);
        $result = json_decode($response, true);
        if (empty($result['ok']) && $provider === 'rga') {
            $messages = [
                'rga_config'=>'RGA no está configurado en este servidor.',
                'rga_auth'=>'RGA rechazó la autenticación. Revisa el token del servidor.',
                'rga_bad_request'=>'RGA rechazó el texto o los parámetros enviados.',
                'rga_unavailable'=>'RGA no tiene proxies disponibles o alcanzó su límite de generación. Intenta más tarde.',
                'rga_proxy_failed'=>'Los proxies de RGA no pudieron generar el audio. Intenta más tarde.',
                'rga_connection'=>'No se pudo conectar con RGA o el servidor tardó demasiado.',
                'rga_invalid_audio'=>'RGA devolvió un audio inválido o de formato distinto al esperado.',
                'rga_error'=>'RGA no pudo completar la generación.',
            ];
            throw new RuntimeException($messages[$result['code'] ?? 'rga_error'] ?? $messages['rga_error']);
        }
        if (empty($result['ok']) || !is_file($path) || filesize($path) < 100 || filesize($path) > 10*1024*1024) {
            throw new RuntimeException('No se pudo generar el audio. Intenta nuevamente.');
        }
        $handle = fopen($path, 'rb');
        $header = fread($handle, 12);
        fclose($handle);
        if (substr($header,0,4) !== 'RIFF' || substr($header,8,4) !== 'WAVE') throw new RuntimeException('El proveedor produjo un audio inválido.');
        $_SESSION['bot_audio_lab'][$id] = ['text'=>$text, 'provider'=>$provider, 'created_at'=>date('Y-m-d H:i:s')];
        while (count($_SESSION['bot_audio_lab']) > 5) {
            $old = array_key_first($_SESSION['bot_audio_lab']);
            @unlink(BOT_AUDIO_LAB_DIR.'/'.$old.'.wav');
            unset($_SESSION['bot_audio_lab'][$old]);
        }
        $success = true;
        return $id;
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
        if (!$success) @unlink($path);
    }
}
