#!/usr/bin/env python3
"""Proof of concept: JSON on stdin to a telephony WAV; no database access."""
import json
import subprocess
import sys
import tempfile
import time
import wave
from pathlib import Path

from gtts import gTTS
import requests


RGA_CONFIG = Path(__file__).resolve().parents[2] / 'secrets' / 'audio_lab_rga.json'
MAX_AUDIO_BYTES = 10 * 1024 * 1024


class RgaError(Exception):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def generate_rga(text, output):
    try:
        config = json.loads(RGA_CONFIG.read_text(encoding='utf-8'))
        endpoint, token = config['endpoint'], config['token']
        if not isinstance(endpoint, str) or not endpoint.startswith(('http://', 'https://')) or not isinstance(token, str) or not token.strip():
            raise ValueError('Invalid config')
    except (OSError, ValueError, KeyError, TypeError):
        raise RgaError('rga_config') from None
    deadline = time.monotonic() + 150
    try:
        with requests.post(endpoint, headers={'Authorization': 'Bearer ' + token},
                           json={'input': text, 'language': 'es', 'speed': 1.3, 'format': 'wav'},
                           timeout=(5, 145), stream=True, allow_redirects=False) as response:
            if response.status_code != 200:
                codes = {400: 'rga_bad_request', 401: 'rga_auth',
                         502: 'rga_proxy_failed', 503: 'rga_unavailable'}
                raise RgaError(codes.get(response.status_code, 'rga_error'))
            size = 0
            with output.open('wb') as handle:
                for chunk in response.iter_content(chunk_size=65536):
                    size += len(chunk)
                    if size > MAX_AUDIO_BYTES:
                        raise RgaError('rga_invalid_audio')
                    if time.monotonic() > deadline:
                        raise RgaError('rga_connection')
                    handle.write(chunk)
        try:
            with wave.open(str(output), 'rb') as audio:
                if (audio.getnchannels(), audio.getframerate(), audio.getsampwidth(), audio.getcomptype()) != (1, 8000, 2, 'NONE'):
                    raise RgaError('rga_invalid_audio')
                if audio.getnframes() < 1 or output.stat().st_size < 100:
                    raise RgaError('rga_invalid_audio')
        except (wave.Error, EOFError):
            raise RgaError('rga_invalid_audio') from None
    except requests.RequestException:
        raise RgaError('rga_connection') from None


def generate(payload):
    text = payload.get('text', '')
    if not isinstance(text, str) or not text.strip() or len(text) > 1000:
        raise ValueError('Texto requerido; máximo 1000 caracteres.')
    provider = payload.get('provider')
    if provider not in ('gtts', 'rga'):
        raise ValueError('Proveedor no disponible.')
    output = Path(payload['output'])
    if provider == 'rga':
        generate_rga(text.strip(), output)
        output.chmod(0o600)
        return
    with tempfile.TemporaryDirectory(prefix='zynervox_audio_lab_') as directory:
        mp3 = Path(directory) / 'speech.mp3'
        wav = Path(directory) / 'speech.wav'
        gTTS(text=text.strip(), lang='es', tld='com', slow=False, timeout=25).save(str(mp3))
        subprocess.run(['/usr/bin/ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                        '-i', str(mp3), '-ar', '8000', '-ac', '1', '-sample_fmt', 's16',
                        str(wav)], check=True, timeout=30, capture_output=True)
        subprocess.run(['/usr/bin/sox', str(wav), str(output), 'tempo', '1.3'],
                       check=True, timeout=30, capture_output=True)
    output.chmod(0o600)


if __name__ == '__main__':
    try:
        generate(json.load(sys.stdin))
        print(json.dumps({'ok': True}))
    except RgaError as error:
        print(json.dumps({'ok': False, 'code': error.code}))
        sys.exit(1)
    except Exception:
        # No provider response or supplied text is returned to the browser.
        print(json.dumps({'ok': False, 'error': 'No se pudo generar el audio. Intenta nuevamente.'}))
        sys.exit(1)
