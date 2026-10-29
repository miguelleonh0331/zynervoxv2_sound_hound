#!/usr/bin/env python3
"""Isolated gTTS list prebuild. No database, CARSA or call-engine access."""
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import fcntl
import hashlib
import inspect
import json
import os
import signal
import sqlite3
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import wave

PROFILE = {'provider': 'gtts', 'voice': 'google-es-com', 'language': 'es',
           'speed': '1.3', 'format': 'pcm_s16le-mono-8000', 'pipeline': 'v1'}
WORKERS = (1, 3, 10, 25, 60, 100)


def remove_file(path):
    try:
        path.unlink()
    except FileNotFoundError:
        pass


def normalize(text):
    if not isinstance(text, str):
        raise ValueError('invalid_text')
    text = ' '.join(text.split())
    if not text or len(text) > 1000 or any(ord(c) < 32 or ord(c) == 127 for c in text):
        raise ValueError('invalid_text')
    if '{' in text or '}' in text:
        raise ValueError('unresolved_variables')
    return text


def audio_hash(text):
    payload = dict(PROFILE, text=normalize(text))
    canonical = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
    return hashlib.sha256(canonical.encode('utf-8')).hexdigest()


def valid_wav(path):
    try:
        size = path.stat().st_size
        if not 100 <= size <= 10 * 1024 * 1024:
            return False
        with wave.open(str(path), 'rb') as audio:
            if (audio.getnchannels(), audio.getframerate(), audio.getsampwidth(), audio.getcomptype()) != (1, 8000, 2, 'NONE'):
                return False
            frames = audio.getnframes()
            return frames > 0 and len(audio.readframes(frames)) == frames * 2
    except (OSError, wave.Error, EOFError):
        return False


def convert_gtts(text, output):
    from gtts import gTTS
    with tempfile.TemporaryDirectory(prefix='zynervox_gtts_') as directory:
        mp3, wav = Path(directory) / 'speech.mp3', Path(directory) / 'speech.wav'
        options = {'text': text, 'lang': 'es', 'tld': 'com', 'slow': False}
        if 'timeout' in inspect.signature(gTTS).parameters:
            options['timeout'] = 25
        gTTS(**options).save(str(mp3))
        subprocess.run(['/usr/bin/ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                        '-i', str(mp3), '-ar', '8000', '-ac', '1', '-sample_fmt', 's16', str(wav)],
                       check=True, timeout=30, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        subprocess.run(['/usr/bin/sox', str(wav), str(output), 'tempo', '1.3'],
                       check=True, timeout=30, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def generate_gtts(text, output):
    # gTTS 2.2.x lacks its own timeout; bound the entire conversion externally.
    with subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--generate-wav', str(output)],
                          stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          universal_newlines=True, start_new_session=True) as process:
        try:
            process.communicate(json.dumps({'text': text}), timeout=100)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.communicate()
            raise RuntimeError('generation_timeout') from None
        if process.returncode:
            raise RuntimeError('generation_failed')


def audio_registry(cache):
    path = cache / 'audio_registry.sqlite'
    connection = sqlite3.connect(str(path), timeout=30)
    connection.execute('PRAGMA journal_mode=WAL')
    connection.execute('CREATE TABLE IF NOT EXISTS published_audio (hash TEXT PRIMARY KEY, published_at INTEGER NOT NULL)')
    connection.commit()
    path.chmod(0o660)
    return connection


def delete_audio(digest, cache):
    # Unpublish first, under the same hash lease used by generation.
    if len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest):
        raise ValueError('invalid_hash')
    with (cache / (digest + '.lock')).open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        with audio_registry(cache) as registry:
            registry.execute('DELETE FROM published_audio WHERE hash=?', (digest,))
        registry.close()
        remove_file(cache / (digest + '.wav'))


def ensure_audio(text, cache, generator=generate_gtts):
    text = normalize(text)
    digest = audio_hash(text)
    target = cache / (digest + '.wav')
    # Keep lock files: unlinking them would allow a second lock inode for one hash.
    with (cache / (digest + '.lock')).open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        registry = audio_registry(cache)
        published = registry.execute('SELECT 1 FROM published_audio WHERE hash=?', (digest,)).fetchone()
        registry.close()
        if published:
            return digest, 'reused'
        fd, temporary = tempfile.mkstemp(prefix='.' + digest + '.', suffix='.wav', dir=str(cache))
        os.close(fd)
        temporary = Path(temporary)
        try:
            generator(text, temporary)
            if not valid_wav(temporary):
                raise ValueError('invalid_audio')
            temporary.chmod(0o640)
            os.replace(str(temporary), str(target))
            with audio_registry(cache) as registry:
                registry.execute('INSERT OR REPLACE INTO published_audio VALUES (?, ?)', (digest, int(time.time())))
            registry.close()
        finally:
            remove_file(temporary)
    return digest, 'generated'


def atomic_json(path, value):
    fd, temporary = tempfile.mkstemp(prefix='.' + path.name, dir=str(path.parent))
    temporary = Path(temporary)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            json.dump(value, handle, ensure_ascii=False)
        temporary.chmod(0o640)
        os.replace(str(temporary), str(path))
    finally:
        remove_file(temporary)


def read_job(path):
    if path.stat().st_size > 64 * 1024 * 1024:
        raise ValueError('job_too_large')
    job = json.loads(path.read_text(encoding='utf-8'))
    if job.get('provider') != 'gtts' or job.get('workers') not in WORKERS:
        raise ValueError('invalid_provider_or_workers')
    if not isinstance(job.get('list_id'), int) or job['list_id'] < 1:
        raise ValueError('invalid_list')
    if not isinstance(job.get('prompts'), list) or not job['prompts']:
        raise ValueError('empty_prompts')
    for prompt in job['prompts']:
        normalize(prompt['text'])
        if not isinstance(prompt.get('lead_id'), int) or prompt['lead_id'] < 1 or not isinstance(prompt.get('node'), str):
            raise ValueError('invalid_prompt')
    return job


def paths(root, list_id):
    return root / 'bot_ivr' / 'audio_jobs' / ('list_' + str(list_id) + '.json')


def run_job(root, path, lease_fd):
    # The inherited descriptor holds the per-list lock until this process exits.
    lease = os.fdopen(lease_fd, 'a')
    job = read_job(path)
    status_path = paths(root, job['list_id'])
    cache = root / 'sounds' / 'cache' / 'ivr_builder' / 'gtts'
    state = {'state': 'running', 'list_id': job['list_id'], 'campaign_id': job['campaign_id'],
             'signature': job['signature'], 'provider': 'gtts', 'workers': job['workers'],
             'pid': os.getpid(), 'generated': 0, 'reused': 0, 'failed': 0,
             'total': 0, 'completed': 0, 'audio': [], 'errors': [], 'updated_at': int(time.time())}
    try:
        unique = {audio_hash(p['text']): normalize(p['text']) for p in job['prompts']}
        state['total'] = len(unique)
        atomic_json(status_path, state)
        outcomes = {}
        with ThreadPoolExecutor(max_workers=job['workers']) as pool:
            futures = {pool.submit(ensure_audio, text, cache): digest for digest, text in unique.items()}
            for future in as_completed(futures):
                digest = futures[future]
                try:
                    _, outcome = future.result()
                    state[outcome] += 1
                    outcomes[digest] = True
                except Exception:
                    state['failed'] += 1
                    outcomes[digest] = False
                    if len(state['errors']) < 5:
                        state['errors'].append('No se pudo generar el audio ' + digest[:12] + '.')
                state['completed'] += 1
                state['updated_at'] = int(time.time())
                atomic_json(status_path, state)
        state['audio'] = [{'lead_id': p['lead_id'], 'node': p['node'], 'hash': audio_hash(p['text']),
                           'ready': outcomes[audio_hash(p['text'])]} for p in job['prompts']]
        state['state'] = 'failed' if state['failed'] else 'ready'
    except Exception:
        state['state'] = 'failed'
        state['errors'] = ['No se pudo completar la generación de audios.']
    finally:
        state['updated_at'] = int(time.time())
        atomic_json(status_path, state)
        remove_file(path)
        lease.close()


def launch(root, path):
    job = read_job(path)
    lock = (path.parent / ('list_' + str(job['list_id']) + '.lock')).open('a')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        lock.close()
        raise ValueError('already_running') from None
    try:
        atomic_json(paths(root, job['list_id']), {'state': 'starting', 'list_id': job['list_id'],
                    'signature': job['signature'], 'pid': 0, 'updated_at': int(time.time())})
        with open(os.devnull, 'rb') as stdin, open(os.devnull, 'wb') as output:
            process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--root', str(root),
                         '--job', str(path), '--lease-fd', str(lock.fileno())],
                        stdin=stdin, stdout=output, stderr=output, start_new_session=True,
                        pass_fds=(lock.fileno(),))
        return process.pid
    finally:
        lock.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root')
    parser.add_argument('--job')
    parser.add_argument('--generate-wav')
    parser.add_argument('--lease-fd', type=int)
    args = parser.parse_args()
    try:
        if args.generate_wav:
            convert_gtts(normalize(json.load(sys.stdin)['text']), Path(args.generate_wav))
            sys.exit(0)
        if not args.root or not args.job:
            raise ValueError('missing_job')
        root, job = Path(args.root).resolve(), Path(args.job).resolve()
        if job.parent != root / 'bot_ivr' / 'audio_jobs' or not job.name.endswith('.input.json'):
            raise ValueError('invalid_job_path')
        if args.lease_fd is None:
            print(json.dumps({'ok': True, 'pid': launch(root, job)}))
        else:
            run_job(root, job, args.lease_fd)
    except Exception:
        print(json.dumps({'ok': False, 'error': 'No se pudo iniciar la generación; comprueba si ya está activa.'}))
        sys.exit(1)
