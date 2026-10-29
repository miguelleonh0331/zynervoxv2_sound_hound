#!/usr/bin/python3
"""Worker SQL de campañas Bot IVR.

No crea PID, stop, JSON ni logs. En producción se niega a reservar contactos
si no hay carrier PJSIP activo o si el originador SQL/PJSIP aún no está listo.
"""
from __future__ import annotations

import argparse
import json
import os
import time
from typing import Mapping

from runtime_store import connect, process_heartbeat, process_stop_requested


def arguments():
    parser = argparse.ArgumentParser()
    parser.add_argument('campaign_id', type=int, nargs='?')
    parser.add_argument('max_channels', type=int, nargs='?')
    parser.add_argument('--origin', default='sipp_2006',
                        choices=('sipp_2006', 'zypad_3006', 'zypad_whisper_4006'))
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--self-test', action='store_true')
    return parser.parse_args()


def event(conn, campaign_id: int, level: str, kind: str, message: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO synervox_campaign_events "
            "(campaign_id,process_type,level,event_type,message) VALUES (%s,'dialer',%s,%s,%s)",
            (campaign_id, level, kind, message[:5000]),
        )
    conn.commit()


def campaign(conn, campaign_id: int):
    with conn.cursor() as cur:
        cur.execute("SELECT id,name,flow_code,status FROM synervox_campaigns WHERE id=%s", (campaign_id,))
        return cur.fetchone()


def pending_count(conn, campaign_id: int) -> int:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT COUNT(*) total FROM carsa_initial_survey "
            "WHERE campaign_id=%s AND status='pending'", (campaign_id,),
        )
        return int(cur.fetchone()['total'])


def active_carriers(conn) -> int:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT COUNT(*) total FROM vicidial_server_carriers "
            "WHERE active='Y' AND UPPER(protocol)='PJSIP' "
            "AND TRIM(COALESCE(account_entry,''))<>''"
        )
        return int(cur.fetchone()['total'])


def build_variables(campaign_id: int, row: Mapping) -> dict[str, str]:
    values = {'campaign_id': str(campaign_id), 'queue_id': str(row['id'])}
    for key, column in (('nombre', 'customer_name'), ('monto', 'amount'),
                        ('direccion', 'store_address')):
        if row.get(column) not in (None, ''):
            values[key] = str(row[column])
    raw = row.get('extra_json')
    if raw:
        try:
            extra = json.loads(raw)
            if isinstance(extra, dict):
                for key, value in extra.items():
                    if isinstance(value, (str, int, float, bool)):
                        values[str(key)] = str(value)
        except (TypeError, ValueError):
            pass
    return values


def mark_process(conn, campaign_id: int, state: str, error=None) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE synervox_campaign_processes SET state=%s,heartbeat_at=NOW(),"
            "finished_at=IF(%s IN ('failed','completed','stopped'),NOW(),finished_at),"
            "last_error=%s WHERE campaign_id=%s AND process_type='dialer'",
            (state, state, error[:500] if error else None, campaign_id),
        )
    conn.commit()


def wait_until_registered(conn, campaign_id: int) -> None:
    pid = os.getpid()
    for _ in range(20):
        with conn.cursor() as cur:
            cur.execute(
                "SELECT pid FROM synervox_campaign_processes "
                "WHERE campaign_id=%s AND process_type='dialer'", (campaign_id,),
            )
            row = cur.fetchone()
        if row and int(row.get('pid') or 0) == pid:
            return
        time.sleep(0.1)


def dry_run(conn, camp, max_channels: int, origin: str) -> int:
    pending = pending_count(conn, camp['id'])
    carriers = active_carriers(conn)
    print(
        f"dry-run campaign={camp['id']} flow={camp['flow_code']} pending={pending} "
        f"channels={max_channels} origin={origin} carriers={carriers}"
    )
    return 0


def main() -> int:
    args = arguments()
    conn = connect()
    if args.self_test:
        try:
            with conn.cursor() as cur:
                cur.execute("SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('synervox_campaigns','carsa_initial_survey','synervox_campaign_processes','synervox_campaign_events','synervox_call_runs','synervox_call_variables','synervox_call_node_events')")
                tables = int(cur.fetchone()['total'])
            print(f"self-test tables={tables}/7 carriers={active_carriers(conn)}")
            return 0 if tables == 7 else 1
        finally:
            conn.close()
    if args.campaign_id is None or args.max_channels is None or args.campaign_id <= 0 or not 0 <= args.max_channels <= 80:
        conn.close()
        raise SystemExit('campaign_id/max_channels inválidos')
    try:
        camp = campaign(conn, args.campaign_id)
        if not camp:
            raise SystemExit('Campaña inexistente')
        if args.dry_run:
            return dry_run(conn, camp, args.max_channels, args.origin)

        wait_until_registered(conn, args.campaign_id)
        process_heartbeat(conn, args.campaign_id, 'dialer')
        if process_stop_requested(conn, args.campaign_id):
            mark_process(conn, args.campaign_id, 'stopped')
            event(conn, args.campaign_id, 'info', 'stopped_before_start',
                  'Detención solicitada antes de reservar contactos.')
            return 0
        if active_carriers(conn) == 0:
            message = 'No existe un carrier PJSIP activo; no se reservaron contactos.'
            mark_process(conn, args.campaign_id, 'failed', message)
            event(conn, args.campaign_id, 'error', 'carrier_unavailable', message)
            return 2

        # Guardia deliberada hasta que el originador PJSIP y el motor de flujo
        # consuman synervox_call_runs/synervox_call_variables de extremo a extremo.
        message = 'Originador PJSIP SQL aún no habilitado; no se reservaron contactos.'
        mark_process(conn, args.campaign_id, 'failed', message)
        event(conn, args.campaign_id, 'error', 'originator_not_ready', message)
        return 3
    except Exception as exc:
        conn.rollback()
        try:
            mark_process(conn, args.campaign_id, 'failed', str(exc))
            event(conn, args.campaign_id, 'error', 'worker_error', str(exc))
        except Exception:
            pass
        raise
    finally:
        conn.close()


if __name__ == '__main__':
    raise SystemExit(main())

