#!/usr/bin/python3
"""Pregenera los audios de una campaña usando solo SQL y multimedia en disco."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

from runtime_store import connect, process_heartbeat, process_stop_requested
from tts_providers import PROVIDERS, audio_hash, normalize_provider, provider_spec

FLOWS = Path('/etc/asterisk/synervox/modules/flows/published')
PLACEHOLDER = re.compile(r'\{([A-Za-z_][A-Za-z0-9_]*)\}')


def worker_count(value: str) -> int:
    count = int(value)
    if count not in (1, 3, 10, 25, 60, 100):
        raise argparse.ArgumentTypeError('workers debe ser 1, 3, 10, 25, 60 o 100')
    return count


def valid_wav(path: Path) -> bool:
    if not path.is_file() or path.stat().st_size < 100:
        return False
    with path.open('rb') as handle:
        header = handle.read(12)
    return header[:4] == b'RIFF' and header[8:12] == b'WAVE'


def queue_signature(rows: list[dict]) -> str:
    digest = hashlib.sha256()
    for row in rows:
        for key in ('id', 'phone', 'customer_name', 'amount', 'store_address'):
            digest.update(str(row.get(key) or '').encode('utf-8'))
            digest.update(b'\0')
        digest.update(b'\n')
    return digest.hexdigest()


def build_variables(campaign_id: int, row: dict) -> dict[str, str]:
    variables = {'campaign_id': str(campaign_id), 'queue_id': str(row['id'])}
    for name, column in (('nombre', 'customer_name'), ('monto', 'amount'),
                         ('direccion', 'store_address')):
        value = row.get(column)
        if value not in (None, ''):
            variables[name] = str(value)
    raw = row.get('extra_json')
    if raw:
        try:
            extra = json.loads(raw)
            if isinstance(extra, dict):
                for key, value in extra.items():
                    if isinstance(value, (str, int, float, bool)):
                        variables[str(key)] = str(value)
        except (TypeError, ValueError):
            pass
    return variables


def render(text: str, variables: dict[str, str]) -> str:
    return PLACEHOLDER.sub(lambda match: variables.get(match.group(1), ''), text or '')


def reachable_nodes(flow: dict) -> dict:
    nodes = flow.get('nodes') or {}
    start = flow.get('start') or flow.get('start_node') or flow.get('entry')
    if not start or start not in nodes:
        return nodes
    seen, pending = set(), [start]
    while pending:
        node_id = pending.pop()
        if node_id in seen or node_id not in nodes:
            continue
        seen.add(node_id)
        stack = [nodes[node_id]]
        while stack:
            value = stack.pop()
            if isinstance(value, dict):
                for key, child in value.items():
                    if key not in ('audio_text', 'message', 'text', 'retry_text'):
                        stack.append(child)
            elif isinstance(value, list):
                stack.extend(value)
            elif isinstance(value, str) and value in nodes and value not in seen:
                pending.append(value)
    return {node_id: nodes[node_id] for node_id in seen}


def runtime_keys(nodes: dict) -> set[str]:
    keys: set[str] = set()
    for node in nodes.values():
        for field in ('variable', 'output_variable', 'save_as'):
            value = str(node.get(field) or '').strip()
            if value:
                keys.add(value)
                if node.get('capture_mode') == 'date':
                    keys.add(value + '_spoken')
    return keys


def composite_hash(chain: list[tuple[str, int]], token: str) -> str:
    parts = ['composite-v1', token, 'wav8k']
    for digest, pause_ms in chain:
        parts.extend((digest, str(pause_ms)))
    return hashlib.sha256('|'.join(parts).encode('utf-8')).hexdigest()


def ensure_audio(item: tuple[str, str], provider: str) -> tuple[str, str, str | None]:
    digest, text = item
    spec = provider_spec(provider)
    cache: Path = spec['cache']
    cache.mkdir(parents=True, exist_ok=True)
    target = cache / f'{digest}.wav'
    if valid_wav(target):
        return digest, 'reused', None
    temporary = cache / f'.{digest}.tmp.{os.getpid()}.{uuid.uuid4().hex}.wav'
    last_error = ''
    for attempt, delay in enumerate((0, 2, 5), 1):
        if delay:
            time.sleep(delay)
        try:
            subprocess.run([
                str(spec['python']), str(spec['script']), '--text', text,
                '--output', str(temporary), '--lang', 'es', '--speed', '1.3',
            ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
               text=True, timeout=int(spec['timeout']))
            if not valid_wav(temporary):
                raise RuntimeError('el proveedor produjo un WAV inválido')
            temporary.chmod(0o640)
            temporary.replace(target)
            return digest, 'generated', None
        except Exception as exc:
            stderr = getattr(exc, 'stderr', None)
            last_error = (str(stderr).strip() if stderr else str(exc))[:500]
            temporary.unlink(missing_ok=True)
            if attempt == 3:
                break
    return digest, 'failed', last_error


def ensure_composite(digest: str, chain: list[tuple[str, int]], provider: str) -> tuple[str, str, str | None]:
    cache: Path = provider_spec(provider)['cache']
    target = cache / f'{digest}.wav'
    if valid_wav(target):
        return digest, 'reused', None
    try:
        pcm = bytearray()
        for segment_hash, pause_ms in chain:
            segment = cache / f'{segment_hash}.wav'
            if not valid_wav(segment):
                raise RuntimeError(f'segmento {segment_hash} no disponible')
            data = segment.read_bytes()
            if len(data) < 44 or data[12:16] != b'fmt ' or data[36:40] != b'data':
                raise RuntimeError(f'segmento {segment_hash} no es WAV PCM canónico')
            pcm.extend(data[44:])
            pcm.extend(b'\x00\x00' * int(8000 * pause_ms / 1000))
        size = len(pcm)
        header = (b'RIFF' + (36 + size).to_bytes(4, 'little') + b'WAVEfmt '
                  + (16).to_bytes(4, 'little') + (1).to_bytes(2, 'little')
                  + (1).to_bytes(2, 'little') + (8000).to_bytes(4, 'little')
                  + (16000).to_bytes(4, 'little') + (2).to_bytes(2, 'little')
                  + (16).to_bytes(2, 'little') + b'data' + size.to_bytes(4, 'little'))
        temporary = cache / f'.{digest}.tmp.{os.getpid()}.{uuid.uuid4().hex}.wav'
        temporary.write_bytes(header + bytes(pcm))
        if not valid_wav(temporary):
            raise RuntimeError('WAV compuesto inválido')
        temporary.chmod(0o640)
        temporary.replace(target)
        return digest, 'generated', None
    except Exception as exc:
        return digest, 'failed', str(exc)[:500]


def requirements(campaign_id: int, flow: dict, rows: list[dict], provider: str):
    nodes = reachable_nodes(flow)
    known_runtime = runtime_keys(nodes)
    texts: dict[str, str] = {}
    dynamic: list[tuple[str, str]] = []
    composites: list[tuple[str, dict]] = []
    unknown: set[str] = set()

    for node_id, node in nodes.items():
        node_type = str(node.get('type') or '')
        audio_text = str(node.get('audio_text') or '').strip()
        if node_type in ('create_audio_dynamic', 'menu_ari', 'capture_stt_ari') and audio_text:
            dynamic.append((node_id, audio_text))
        elif node_type in ('create_audio', 'capture_stt', 'menu', 'playback', 'hangup') and audio_text:
            placeholders = set(PLACEHOLDER.findall(audio_text))
            if placeholders:
                unknown.update(f'{node_id}:{key}' for key in placeholders)
            else:
                digest, normalized = audio_hash(audio_text, provider)
                texts[digest] = normalized
        retry_text = str(node.get('retry_text') or '').strip()
        if retry_text:
            placeholders = set(PLACEHOLDER.findall(retry_text))
            if placeholders:
                dynamic.append((f'{node_id}.retry', retry_text))
            else:
                digest, normalized = audio_hash(retry_text, provider)
                texts[digest] = normalized
        if node_type == 'bridge':
            bridge_text = str(node.get('bridge_text') or '').strip()
            if bridge_text:
                digest, normalized = audio_hash(bridge_text, provider)
                texts[digest] = normalized
        if node_type == 'create_audio_composite':
            composites.append((node_id, node))

    base_hashes = set(texts)
    row_hashes: dict[int, set[str]] = {}
    composite_items: dict[str, list[tuple[str, int]]] = {}
    runtime_only: set[str] = set()
    for row in rows:
        variables = build_variables(campaign_id, row)
        required = set(base_hashes)
        for node_id, template in dynamic:
            missing = set(PLACEHOLDER.findall(template)) - set(variables)
            bad = missing - known_runtime
            if bad:
                unknown.update(f'{node_id}:{key}' for key in bad)
            elif missing:
                runtime_only.add(node_id)
            else:
                digest, normalized = audio_hash(render(template, variables), provider)
                texts[digest] = normalized
                required.add(digest)
        for node_id, node in composites:
            chain: list[tuple[str, int]] = []
            complete = True
            for segment in node.get('segments') or []:
                template = str(segment.get('text') or '')
                missing = set(PLACEHOLDER.findall(template)) - set(variables)
                bad = missing - known_runtime
                if bad:
                    unknown.update(f'{node_id}:{key}' for key in bad)
                    complete = False
                    break
                if missing:
                    runtime_only.add(node_id)
                    complete = False
                    break
                digest, normalized = audio_hash(render(template, variables), provider)
                texts[digest] = normalized
                required.add(digest)
                pause = max(0, min(3000, int(segment.get('pause_after_ms') or 0)))
                chain.append((digest, pause))
            if complete and chain:
                digest = composite_hash(chain, provider_spec(provider)['token'])
                composite_items[digest] = chain
                required.add(digest)
        row_hashes[int(row['id'])] = required
    return texts, composite_items, row_hashes, runtime_only, unknown


def record_event(conn, campaign_id: int, level: str, event_type: str, message: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO synervox_campaign_events "
            "(campaign_id,process_type,level,event_type,message) VALUES (%s,'audio_build',%s,%s,%s)",
            (campaign_id, level, event_type, message[:5000]),
        )


def finish_error(conn, campaign_id: int, provider: str, message: str) -> None:
    error = message[:500]
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE carsa_initial_survey SET audio_status='failed',audio_error=%s,tts_provider=%s "
            "WHERE campaign_id=%s AND status='pending' AND audio_status='building'",
            (error, provider, campaign_id),
        )
        cur.execute(
            "UPDATE synervox_campaign_audio_builds SET status='failed',error_message=%s,completed_at=NOW() "
            "WHERE campaign_id=%s", (error, campaign_id),
        )
        cur.execute(
            "UPDATE synervox_campaign_processes SET state='failed',heartbeat_at=NOW(),finished_at=NOW(),last_error=%s "
            "WHERE campaign_id=%s AND process_type='audio_build'", (error, campaign_id),
        )
        cur.execute(
            "INSERT INTO carsa_campaign_state_summary (campaign_id,audio_status,last_error) "
            "VALUES (%s,'error',%s) ON DUPLICATE KEY UPDATE audio_status='error',last_error=VALUES(last_error)",
            (campaign_id, error),
        )
    record_event(conn, campaign_id, 'error', 'audio_build_failed', error)
    conn.commit()


def self_test(conn) -> int:
    required = ('tts_jobs', 'synervox_campaigns', 'carsa_initial_survey',
                'synervox_campaign_processes', 'synervox_campaign_audio_builds',
                'synervox_campaign_audio_files', 'synervox_campaign_events')
    placeholders = ','.join(['%s'] * len(required))
    with conn.cursor() as cur:
        cur.execute(
            f'SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() '
            f'AND table_name IN ({placeholders})', required,
        )
        count = int(cur.fetchone()['total'])
    token = Path('/etc/asterisk/synervox/secrets/tts_jobs_token').is_file()
    script = provider_spec('macelioai_remote')['script'].is_file()
    print(f'self-test tables={count}/{len(required)} token={int(token)} generator={int(script)}')
    return 0 if count == len(required) and token and script and FLOWS.is_dir() else 1


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--campaign-id', type=int)
    parser.add_argument('--workers', type=worker_count, default=3)
    parser.add_argument('--provider', default='macelioai_remote', choices=sorted(PROVIDERS))
    parser.add_argument('--analyze-only', action='store_true')
    parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()
    provider = normalize_provider(args.provider)
    conn = connect()
    if args.self_test:
        try:
            return self_test(conn)
        finally:
            conn.close()
    if not args.campaign_id or args.campaign_id <= 0:
        conn.close()
        raise SystemExit('--campaign-id es obligatorio')

    campaign_id = args.campaign_id
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT c.id,c.flow_code,f.published_sha256 FROM synervox_campaigns c "
                "LEFT JOIN bot_ivr_flows f ON BINARY f.flow_code=BINARY c.flow_code WHERE c.id=%s",
                (campaign_id,),
            )
            campaign = cur.fetchone()
            if not campaign:
                raise RuntimeError('Campaña inexistente')
            cur.execute(
                "SELECT id,phone,customer_name,amount,store_address,extra_json "
                "FROM carsa_initial_survey WHERE campaign_id=%s AND status='pending' ORDER BY id",
                (campaign_id,),
            )
            rows = cur.fetchall()
        if not rows:
            raise RuntimeError('La campaña no tiene clientes pendientes')
        flow_path = FLOWS / f"{campaign['flow_code']}.json"
        flow_bytes = flow_path.read_bytes()
        flow_sha = hashlib.sha256(flow_bytes).hexdigest()
        published_sha = str(campaign.get('published_sha256') or '')
        if not published_sha or flow_sha != published_sha:
            raise RuntimeError('El flujo publicado no coincide con el hash registrado')
        flow = json.loads(flow_bytes.decode('utf-8'))
        texts, composites, row_hashes, runtime_only, unknown = requirements(
            campaign_id, flow, rows, provider,
        )
        queue_sha = queue_signature(rows)
        total = len(texts) + len(composites)
        if args.analyze_only:
            cache: Path = provider_spec(provider)['cache']
            print(json.dumps({
                'ok': not unknown and total > 0, 'campaign_id': campaign_id, 'contacts': len(rows),
                'required_audios': total, 'cached': sum(valid_wav(cache / f'{key}.wav') for key in texts),
                'runtime_only_nodes': sorted(runtime_only), 'unknown_variables': sorted(unknown),
            }, ensure_ascii=False))
            return 0 if not unknown and total > 0 else 1
        if total == 0:
            raise RuntimeError('El flujo no contiene audios TTS pregenerables')
        if unknown:
            raise RuntimeError('Variables no definidas: ' + ', '.join(sorted(unknown)))

        with conn.cursor() as cur:
            cur.execute(
                "UPDATE carsa_initial_survey SET audio_status='building',audio_name=NULL,"
                "audio_error=NULL,tts_provider=%s WHERE campaign_id=%s AND status='pending'",
                (provider, campaign_id),
            )
            cur.execute(
                "INSERT INTO synervox_campaign_audio_builds "
                "(campaign_id,provider,status,flow_sha256,queue_sha256,total,generated,reused,runtime_only,error_message,started_at,completed_at) "
                "VALUES (%s,%s,'building',%s,%s,%s,0,0,%s,NULL,NOW(),NULL) "
                "ON DUPLICATE KEY UPDATE provider=VALUES(provider),status='building',flow_sha256=VALUES(flow_sha256),"
                "queue_sha256=VALUES(queue_sha256),total=VALUES(total),generated=0,reused=0,"
                "runtime_only=VALUES(runtime_only),error_message=NULL,started_at=NOW(),completed_at=NULL",
                (campaign_id, provider, flow_sha, queue_sha, total, len(runtime_only)),
            )
        record_event(conn, campaign_id, 'info', 'audio_build_started',
                     f'Pregeneración iniciada: {len(rows)} contactos, {total} audios únicos.')
        conn.commit()
        process_heartbeat(conn, campaign_id, 'audio_build')

        outcomes: dict[str, tuple[str, str | None]] = {}
        with ThreadPoolExecutor(max_workers=args.workers) as pool:
            futures = {pool.submit(ensure_audio, item, provider): item[0] for item in texts.items()}
            for future in as_completed(futures):
                digest, status, error = future.result()
                outcomes[digest] = (status, error)
                process_heartbeat(conn, campaign_id, 'audio_build')
        for digest, chain in composites.items():
            failed = [item for item, _ in chain if outcomes.get(item, ('failed', None))[0] == 'failed']
            if failed:
                outcomes[digest] = ('failed', 'Segmentos fallidos: ' + ', '.join(failed))
            else:
                _, status, error = ensure_composite(digest, chain, provider)
                outcomes[digest] = (status, error)

        if process_stop_requested(conn, campaign_id, 'audio_build'):
            raise RuntimeError('Generación detenida por solicitud')
        failures: list[str] = []
        generated = sum(status == 'generated' for status, _ in outcomes.values())
        reused = sum(status == 'reused' for status, _ in outcomes.values())
        cache: Path = provider_spec(provider)['cache']
        with conn.cursor() as cur:
            ready_ids: list[int] = []
            for row in rows:
                errors = [outcomes.get(digest, ('failed', 'Resultado ausente'))[1]
                          for digest in row_hashes[int(row['id'])]
                          if outcomes.get(digest, ('failed', None))[0] == 'failed']
                if errors:
                    message = '; '.join(str(item) for item in errors if item)[:500]
                    failures.append(f"{row['id']}: {message}")
                    cur.execute(
                        "UPDATE carsa_initial_survey SET audio_status='failed',audio_error=%s "
                        "WHERE id=%s", (message, row['id']),
                    )
                else:
                    ready_ids.append(int(row['id']))
            # Un solo UPDATE por lote para todos los contactos listos (antes:
            # una sentencia por contacto -- miles de round-trips en campañas
            # grandes). audio_name sigue el patron fijo 'ivr_<campaign>_<id>',
            # calculable en SQL con CONCAT. Se trocea por si acaso para no
            # armar un IN(...) descomunal en campanas muy grandes.
            CHUNK = 1000
            for start in range(0, len(ready_ids), CHUNK):
                chunk = ready_ids[start:start + CHUNK]
                placeholders = ','.join(['%s'] * len(chunk))
                cur.execute(
                    "UPDATE carsa_initial_survey "
                    "SET audio_status='ready',audio_name=CONCAT('ivr_',%s,'_',id),audio_error=NULL "
                    f"WHERE id IN ({placeholders})",
                    (campaign_id, *chunk),
                )
            if failures:
                raise RuntimeError('; '.join(failures)[:500])
            cur.execute(
                "DELETE FROM synervox_campaign_audio_files WHERE campaign_id=%s "
                "AND audio_kind IN ('tts','composite')", (campaign_id,),
            )
            for digest, (status, error) in outcomes.items():
                kind = 'composite' if digest in composites else 'tts'
                path = cache / f'{digest}.wav'
                cur.execute(
                    "INSERT INTO synervox_campaign_audio_files "
                    "(campaign_id,queue_id,audio_kind,provider,audio_hash,file_path,byte_size,status,error_message) "
                    "VALUES (%s,NULL,%s,%s,%s,%s,%s,'ready',NULL)",
                    (campaign_id, kind, provider, digest, str(path), path.stat().st_size),
                )
            cur.execute(
                "UPDATE synervox_campaign_audio_builds SET status='ready',generated=%s,reused=%s,"
                "error_message=NULL,completed_at=NOW() WHERE campaign_id=%s",
                (generated, reused, campaign_id),
            )
            cur.execute(
                "UPDATE synervox_campaign_processes SET state='completed',heartbeat_at=NOW(),finished_at=NOW(),last_error=NULL "
                "WHERE campaign_id=%s AND process_type='audio_build'", (campaign_id,),
            )
            cur.execute(
                "INSERT INTO carsa_campaign_state_summary (campaign_id,audio_status,last_error) "
                "VALUES (%s,'ready',NULL) ON DUPLICATE KEY UPDATE audio_status='ready',last_error=NULL",
                (campaign_id,),
            )
        record_event(conn, campaign_id, 'info', 'audio_build_completed',
                     f'Pregeneración lista: {generated} generados, {reused} reutilizados.')
        conn.commit()
        print(f'DONE campaign={campaign_id} generated={generated} reused={reused}')
        return 0
    except Exception as exc:
        conn.rollback()
        if not args.analyze_only:
            try:
                finish_error(conn, campaign_id, provider, str(exc))
            except Exception:
                conn.rollback()
        print(f'ERROR {exc}', file=sys.stderr)
        return 1
    finally:
        conn.close()


if __name__ == '__main__':
    raise SystemExit(main())
