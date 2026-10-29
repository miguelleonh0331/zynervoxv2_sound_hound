#!/usr/bin/python3
"""Persistencia SQL compartida del motor Bot IVR; no crea estado en disco."""
from __future__ import annotations

import json
import uuid
from pathlib import Path
from typing import Mapping, Optional

import pymysql
from pymysql.cursors import DictCursor

BOT_IVR_DB_CONFIG = Path('/etc/asterisk/synervox/secrets/bot_ivr_db.json')


def _config() -> dict[str, str]:
    if not BOT_IVR_DB_CONFIG.exists():
        raise RuntimeError('Configura la conexión desde el botón Configurar conexión a base de datos.')
    config = json.loads(BOT_IVR_DB_CONFIG.read_text(encoding='utf-8'))
    if not isinstance(config, dict) or config.get('database') != 'zynervox':
        raise RuntimeError('Bot IVR requiere la base de datos zynervox.')
    keys = {'server': 'VARDB_server', 'port': 'VARDB_port',
            'database': 'VARDB_database', 'user': 'VARDB_user', 'password': 'VARDB_pass'}
    if any(key not in config for key in keys):
        raise RuntimeError('Configuración DB incompleta.')
    return {target: str(config[source]) for source, target in keys.items()}


def connect():
    cfg = _config()
    return pymysql.connect(
        host=cfg['VARDB_server'], port=int(cfg.get('VARDB_port') or 3306),
        user=cfg['VARDB_user'], password=cfg['VARDB_pass'],
        database=cfg['VARDB_database'], charset='utf8mb4', autocommit=False,
        cursorclass=DictCursor,
    )


def create_call(conn, campaign_id: int, queue: Mapping, flow_code: str,
                origin: str, variables: Mapping[str, object]) -> dict:
    call_uuid = str(uuid.uuid4())
    phone = str(queue.get('phone') or '')
    dial_number = str(queue.get('dial_number') or phone)
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO synervox_call_runs "
            "(call_uuid,campaign_id,queue_id,flow_code,phone,dial_number,origin,status) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,'reserved')",
            (call_uuid, campaign_id, int(queue['id']), flow_code, phone, dial_number, origin),
        )
        call_id = int(cur.lastrowid)
        rows = [(call_id, str(key), str(value), 'campaign') for key, value in variables.items()]
        if rows:
            cur.executemany(
                "INSERT INTO synervox_call_variables "
                "(call_id,variable_key,variable_value,source) VALUES (%s,%s,%s,%s)", rows,
            )
        cur.execute(
            "INSERT INTO synervox_call_node_events (call_id,event_type,event_value) "
            "VALUES (%s,'reserved','Contexto creado en SQL')", (call_id,),
        )
    conn.commit()
    return {'id': call_id, 'call_uuid': call_uuid}


def load_call(conn, call_uuid: str) -> Optional[dict]:
    with conn.cursor() as cur:
        cur.execute("SELECT * FROM synervox_call_runs WHERE call_uuid=%s", (call_uuid,))
        call = cur.fetchone()
        if not call:
            return None
        cur.execute(
            "SELECT variable_key,variable_value FROM synervox_call_variables WHERE call_id=%s",
            (call['id'],),
        )
        call['variables'] = {row['variable_key']: row['variable_value'] for row in cur.fetchall()}
        return call


def set_variable(conn, call_id: int, key: str, value: object, source: str = 'runtime') -> None:
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO synervox_call_variables (call_id,variable_key,variable_value,source) "
            "VALUES (%s,%s,%s,%s) ON DUPLICATE KEY UPDATE "
            "variable_value=VALUES(variable_value),source=VALUES(source)",
            (call_id, key, str(value), source),
        )
    conn.commit()


def node_event(conn, call_id: int, node_id: Optional[str], event_type: str,
               value: Optional[str] = None) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO synervox_call_node_events (call_id,node_id,event_type,event_value) "
            "VALUES (%s,%s,%s,%s)", (call_id, node_id, event_type, value),
        )
        cur.execute(
            "UPDATE synervox_call_runs SET current_node_id=%s,heartbeat_at=NOW() WHERE id=%s",
            (node_id, call_id),
        )
    conn.commit()


def finish_call(conn, call_id: int, status: str, end_reason: Optional[str] = None,
                error: Optional[str] = None) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE synervox_call_runs SET status=%s,end_reason=%s,last_error=%s,"
            "heartbeat_at=NOW(),completed_at=NOW() WHERE id=%s",
            (status, end_reason, error[:500] if error else None, call_id),
        )
    conn.commit()


def process_stop_requested(conn, campaign_id: int, process_type: str = 'dialer') -> bool:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT state,stop_requested_at FROM synervox_campaign_processes "
            "WHERE campaign_id=%s AND process_type=%s", (campaign_id, process_type),
        )
        row = cur.fetchone()
    return bool(row and (row['stop_requested_at'] is not None or row['state'] == 'stopping'))


def process_heartbeat(conn, campaign_id: int, process_type: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE synervox_campaign_processes SET heartbeat_at=NOW() "
            "WHERE campaign_id=%s AND process_type=%s", (campaign_id, process_type),
        )
    conn.commit()
