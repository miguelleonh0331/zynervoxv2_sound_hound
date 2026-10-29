#!/usr/bin/python3
"""Encola un TTS remoto y descarga el WAV terminado al caché local."""
from __future__ import annotations

import argparse
import json
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

API_URL = 'http://127.0.0.1/zynervox/bot_ivr/tts_jobs_api.php'
TOKEN_FILE = Path('/etc/asterisk/synervox/secrets/tts_jobs_token')
POLL_INTERVAL = 2.0
POLL_TIMEOUT = 300.0

SSL_CONTEXT = ssl.create_default_context()


def read_token() -> str:
    token = TOKEN_FILE.read_text(encoding='utf-8').strip() if TOKEN_FILE.is_file() else ''
    if not token:
        raise RuntimeError(f'token TTS no disponible en {TOKEN_FILE}')
    return token


def call(action: str, params: dict, token: str, method: str = 'GET',
         body: bytes | None = None, content_type: str | None = None) -> tuple[int, bytes]:
    query = urllib.parse.urlencode({'action': action, **params})
    headers = {'X-Auth-Token': token}
    if content_type:
        headers['Content-Type'] = content_type
    request = urllib.request.Request(f'{API_URL}?{query}', data=body, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=30, context=SSL_CONTEXT) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read()


def valid_wav(data: bytes) -> bool:
    return len(data) >= 100 and data[:4] == b'RIFF' and data[8:12] == b'WAVE'


def download(job_hash: str, output: Path, token: str) -> None:
    code, body = call('download', {'job_hash': job_hash}, token)
    if code != 200 or not valid_wav(body):
        raise RuntimeError(f'descarga TTS inválida: HTTP {code}, {len(body)} bytes')
    output.parent.mkdir(parents=True, exist_ok=True)
    temporary = output.with_name(output.name + '.tmp')
    temporary.write_bytes(body)
    temporary.chmod(0o644)
    temporary.replace(output)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--text', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--lang', default='es')
    parser.add_argument('--speed', type=float, default=1.3)
    args = parser.parse_args()
    token = read_token()
    payload = json.dumps({'text': args.text, 'lang': args.lang, 'speed': args.speed},
                         ensure_ascii=False).encode('utf-8')
    code, body = call('enqueue', {}, token, 'POST', payload, 'application/json')
    data = json.loads(body.decode('utf-8'))
    if code != 200 or not data.get('ok'):
        raise RuntimeError(f'no se pudo encolar TTS: HTTP {code} {data}')
    job_hash = str(data['job_hash'])
    output = Path(args.output)
    if data.get('status') == 'done':
        download(job_hash, output, token)
        return 0

    deadline = time.monotonic() + POLL_TIMEOUT
    while time.monotonic() < deadline:
        time.sleep(POLL_INTERVAL)
        code, body = call('status', {'job_hash': job_hash}, token)
        try:
            data = json.loads(body.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError):
            continue
        if code == 200 and data.get('status') == 'done':
            download(job_hash, output, token)
            return 0
        if code == 200 and data.get('status') == 'failed':
            raise RuntimeError(f"worker TTS remoto falló: {data.get('error') or 'sin detalle'}")
    raise RuntimeError(f'timeout esperando worker TTS remoto ({POLL_TIMEOUT:.0f}s)')


if __name__ == '__main__':
    raise SystemExit(main())
