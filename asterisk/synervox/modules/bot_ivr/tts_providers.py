#!/usr/bin/python3
"""Registro TTS mínimo de Zynerdesk para la pregeneración de campañas."""
from __future__ import annotations

import hashlib
import re
from pathlib import Path

MODULE_DIR = Path(__file__).resolve().parent
CACHE_ROOT = Path('/var/lib/asterisk/sounds/voicebot/cache/ivr_builder')

PROVIDERS = {
    'macelioai_remote': {
        # Comparte voz, hash y caché con macelioai; solo cambia el origen.
        'token': 'macelioai',
        'cache': CACHE_ROOT / 'macelioai',
        'python': Path('/usr/bin/python3'),
        'script': MODULE_DIR / 'generate_macelioai_wav_remote.py',
        'timeout': 360,
    },
}


def normalize_provider(value: object) -> str:
    provider = str(value or '').strip().lower()
    if provider not in PROVIDERS:
        raise ValueError(f'proveedor TTS no disponible en Zynerdesk: {provider or "vacío"}')
    return provider


def provider_spec(value: object) -> dict:
    return PROVIDERS[normalize_provider(value)]


def audio_hash(text: object, provider: object = 'macelioai_remote') -> tuple[str, str]:
    normalized = re.sub(r'\s+', ' ', str(text or '')).strip()
    if not normalized:
        raise ValueError('texto TTS vacío')
    token = provider_spec(provider)['token']
    digest = hashlib.sha256(f'{token}|es|1.3|{normalized}'.encode('utf-8')).hexdigest()
    return digest, normalized

