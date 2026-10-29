#!/usr/bin/env python3
"""Private, bounded adapters for interactive IVR nodes. No CARSA imports."""
import datetime as dt
import json
import os
from pathlib import Path
import re
import sys
import time
import subprocess
import tempfile
import unicodedata
import urllib.request
import urllib.parse
import urllib.error
import wave
import uuid


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def http_json(url, headers, body, timeout):
    if url.startswith('https://'):
        # Provider edges reject the legacy Python TLS/client fingerprint on this host.
        # Credentials travel through stdin config, never command-line arguments.
        with tempfile.TemporaryDirectory(prefix='zv2_stt_') as directory:
            source, target = Path(directory) / 'input', Path(directory) / 'response'
            source.write_bytes(body)
            os.chmod(str(source), 0o600)
            config = []
            for key, value in headers.items():
                header = key + ': ' + value
                if '\r' in header or '\n' in header:
                    raise ValueError('invalid_provider_header')
                config.append('header = "' + header.replace('\\', '\\\\').replace('"', '\\"') + '"')
            process = subprocess.run(['/usr/bin/curl', '--silent', '--show-error', '--max-time', str(timeout), '--max-filesize', '65536', '--config', '-', '--data-binary', '@' + str(source), '--output', str(target), '--write-out', '%{http_code}', url],
                                     input='\n'.join(config), universal_newlines=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout + 2)
            code = int(process.stdout or '0')
            if process.returncode or not 200 <= code < 300:
                raise urllib.error.HTTPError(url, code, 'provider_request_failed', None, None)
            with target.open('rb') as handle:
                data = handle.read(65537)
            if len(data) > 65536:
                raise ValueError('provider_response_too_large')
            return json.loads(data)
    request = urllib.request.Request(url, data=body, headers=headers)
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=timeout) as response:
        data = response.read(65537)
        if len(data) > 65536:
            raise ValueError('provider_response_too_large')
        return json.loads(data)


def normalize(text):
    text = re.sub(r'[\[(][^\])]*[\])]', ' ', text)
    text = ''.join(c for c in unicodedata.normalize('NFD', text.lower()) if unicodedata.category(c) != 'Mn')
    return re.sub(r'\s+', ' ', re.sub(r'[^a-z0-9/ -]', ' ', text)).strip()


WEEKDAYS = ('lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo')
MONTHS = ('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre')
SQL_COLUMNS = set(('ID FECHA TELEFONO_BOT ESTADO RESULTADO CLIENTE NUMERO_CLIENTE DIRECCION TIENDA MONTO FECHA_AGENDADA RESPUESTA_REGISTRADA FECHA_INDICADA HORA_VISITA MINUTO_VISITA ID_CONTACTO CAMPAÑA COLA CALL_ID DURACION_LLAMADA PROVEEDOR ID_CLASE ID_USUARIO FECHA_DE_GESTION HORA_GESTION HORA_INICIA_GESTION HORA_FIN_GESTION NUM_DOC PRI_NOMBRE SEG_NOMBRE APE_PATERNO APE_MATERNO ID_TIPIFICACION_01 ID_TIPIFICACION_02 ID_TIPIFICACION_03 ID_AGENCIA').split())


def spoken_date(value):
    return f'{WEEKDAYS[value.weekday()]} {value.day} de {MONTHS[value.month - 1]}'


def local_date(text, today):
    value = normalize(text)
    for phrase, delta in [('pasado manana', 2), ('manana', 1), ('hoy', 0)]:
        if re.search(r'\b' + phrase + r'\b', value):
            return today + dt.timedelta(days=delta)
    match = re.search(r'\b(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?\b', value)
    if match:
        year = int(match[3] or today.year)
        if year < 100:
            year += 2000
        try:
            return dt.date(year, int(match[2]), int(match[1]))
        except ValueError:
            return None
    for index, day in enumerate(WEEKDAYS):
        if normalize(day) in value.split():
            delta = (index - today.weekday()) % 7
            if delta == 0 and any(word in value.split() for word in ('proximo', 'siguiente')):
                delta = 7
            return today + dt.timedelta(days=delta)
    return None


def capture_value(text, mode, config, today=None):
    clean = normalize(text)
    if not clean:
        return {'ok': False, 'error': 'no_audio'}
    if mode in ('text', 'name'):
        return {'ok': True, 'value': text, 'spoken': text}
    if mode == 'number':
        number = ''.join(re.findall(r'\d+', text))
        return {'ok': bool(number), 'value': number, 'spoken': number}
    if mode != 'date':
        raise ValueError('invalid_capture_mode')
    if today is None:
        os.environ['TZ'] = config.get('timezone', 'America/Lima')
        time.tzset()
        today = dt.date.today()
    parsed = local_date(text, today)
    if parsed is None and config.get('date_keys'):
        payload = {'model': 'openai/gpt-oss-20b', 'temperature': 0, 'response_format': {'type': 'json_object'},
                   'messages': [{'role': 'system', 'content': 'Extrae una fecha de visita en Peru. Devuelve JSON has_date boolean, date_iso YYYY-MM-DD. No inventes fechas si no hay una fecha clara.'},
                                {'role': 'user', 'content': f'Fecha actual: {today.isoformat()}. Respuesta: {text}'}]}
        for key in config['date_keys'][:3]:
            try:
                reply = http_json('https://api.groq.com/openai/v1/chat/completions',
                                  {'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'}, json.dumps(payload).encode(), 8)
                data = json.loads(reply['choices'][0]['message']['content'])
                if data.get('has_date'):
                    parsed = dt.datetime.strptime(data['date_iso'], '%Y-%m-%d').date()
                break
            except (ValueError, KeyError, IndexError, urllib.error.URLError):
                continue
    if parsed is None:
        return {'ok': False, 'error': 'no_date'}
    return {'ok': True, 'value': parsed.isoformat(), 'spoken': spoken_date(parsed)}


def transcribe(path, role, config, runtime):
    path = Path(path).resolve()
    call_root = (runtime / 'bot_ivr/ivr_calls').resolve()
    if call_root not in path.parents or not path.is_file() or path.stat().st_size > 5 * 1024 * 1024:
        return {'ok': False, 'error': 'no_audio', 'text': ''}
    with wave.open(str(path), 'rb') as audio:
        if not audio.getnframes():
            return {'ok': False, 'error': 'no_audio', 'text': ''}
    body = path.read_bytes()
    if role == 'amd':
        provider = config.get('amd', {})
        key = provider.get('key', '')
        if not key:
            return {'ok': False, 'text': '', 'error': 'vosk_not_configured'}
        data = http_json(provider['endpoint'], {'Authorization': 'Bearer ' + key, 'Content-Type': 'audio/wav'}, body, 5)
        text = data.get('transcript', '') if data.get('ok') else ''
        if not isinstance(text, str) or len(text) > 4000:
            raise ValueError('invalid_transcript')
        return {'ok': bool(text), 'text': text, 'provider': 'vosk', 'error': '' if text else 'no_audio'}
    if config.get('menu', {}).get('backend') == 'gateway':
        boundary = 'zv2-' + uuid.uuid4().hex
        parts = []
        for key, value in [('language', 'es'), ('call_id', path.parent.name)]:
            parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + value + '\r\n').encode())
        parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="audio"; filename="capture.wav"\r\nContent-Type: audio/wav\r\n\r\n').encode() + body + b'\r\n')
        parts.append(('--' + boundary + '--\r\n').encode())
        data = http_json(config['menu']['endpoint'], {'Content-Type': 'multipart/form-data; boundary=' + boundary}, b''.join(parts), 20)
        text = str(data.get('text') or '').strip()
        if len(text) > 4000:
            raise ValueError('invalid_transcript')
        return {'ok': bool(text), 'text': text, 'provider': 'gateway', 'error': '' if text else 'no_audio'}
    keys = config.get('menu', {}).get('keys', [])
    if not keys:
        return {'ok': False, 'text': '', 'error': 'deepgram_not_configured'}
    offset = os.getpid() % len(keys)
    keys = keys[offset:] + keys[:offset]
    for key in keys[:3]:
        try:
            data = http_json('https://api.deepgram.com/v1/listen?model=nova-3&language=es&smart_format=true&punctuate=true',
                             {'Authorization': 'Token ' + key, 'Content-Type': 'audio/wav'}, body, 8)
            channels = data.get('results', {}).get('channels', [])
            alt = channels[0]['alternatives'][0] if channels else {}
            text = alt.get('transcript', '')
            if not isinstance(text, str) or len(text) > 4000:
                raise ValueError('invalid_transcript')
            if float(alt.get('confidence', 0)) < float(config.get('menu', {}).get('min_confidence', 0.35)):
                text = ''
            return {'ok': bool(text), 'text': text, 'provider': 'deepgram', 'error': '' if text else 'no_audio'}
        except urllib.error.HTTPError as error:
            if error.code not in (401, 403, 408, 409, 429) and error.code < 500:
                break
        except (urllib.error.URLError, TimeoutError, ValueError, KeyError, IndexError):
            continue
    return {'ok': False, 'text': '', 'error': 'stt_unavailable', 'provider': 'deepgram'}


def dispatch(action, data, config, runtime):
    if action == 'transcribe':
        try:
            return transcribe(data['path'], data['provider'], config, runtime)
        except Exception:
            return {'ok': False, 'text': '', 'error': 'stt_unavailable'}
    if action == 'capture_value':
        return capture_value(data['text'], data['mode'], config)
    if action == 'audio':
        from list_audio_worker import ensure_audio
        digest, state = ensure_audio(data['text'], runtime / 'sounds/cache/ivr_builder/gtts')
        return {'ok': True, 'hash': digest, 'state': state}
    if action == 'execute':
        parts = urllib.parse.urlsplit(data['url'])
        base = urllib.parse.urlunsplit((parts.scheme, parts.netloc, parts.path, '', ''))
        if base not in config.get('execute_allowlist', []):
            raise ValueError('service_not_allowed')
        timeout = max(0.5, min(30, data.get('timeout_ms', 6000) / 1000))
        try:
            with urllib.request.build_opener(NoRedirect()).open(urllib.request.Request(data['url'], headers={'User-Agent': 'Zynervox-IVR/2'}), timeout=timeout) as response:
                response.read(2048)
                return {'ok': 200 <= response.status < 300}
        except (urllib.error.URLError, TimeoutError):
            return {'ok': False}
    if action == 'sqlserver':
        # This destination is used only when explicitly selected in private config.
        import pymssql
        cfg = config.get('sqlserver', {})
        if not cfg.get('enabled'):
            return {'ok': False, 'error': 'sqlserver_not_configured'}
        fields = data['fields']
        allowed = SQL_COLUMNS.intersection(config.get('sql_columns', SQL_COLUMNS))
        if not fields or not set(fields).issubset(allowed):
            raise ValueError('invalid_sql_fields')
        table = cfg['table']
        if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?', table):
            raise ValueError('invalid_sql_table')
        timeout = max(1, min(30, int(data.get('timeout_ms', 6000) / 1000)))
        with pymssql.connect(server=cfg['host'], user=cfg['user'], password=cfg['password'], database=cfg['database'], port=cfg.get('port', 1433), login_timeout=timeout, timeout=timeout) as connection:
            columns = ','.join('[' + field + ']' for field in fields)
            connection.cursor().execute('INSERT INTO ' + table + ' (' + columns + ') VALUES (' + ','.join(['%s'] * len(fields)) + ')', tuple(fields.values()))
            connection.commit()
        return {'ok': True}
    raise ValueError('invalid_action')


if __name__ == '__main__':
    try:
        config = json.loads(Path(sys.argv[1]).read_text())
        runtime = Path(sys.argv[2]).resolve()
        job = json.load(sys.stdin)
        result = dispatch(job['action'], job['data'], config, runtime)
    except Exception as error:
        result = {'ok': False, 'error': type(error).__name__}
    print(json.dumps(result, ensure_ascii=False))
