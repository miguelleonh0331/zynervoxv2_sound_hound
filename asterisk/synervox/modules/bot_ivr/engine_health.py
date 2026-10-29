#!/usr/bin/python3
"""Validación no destructiva de dependencias del motor Bot IVR."""
from runtime_store import connect


def main() -> int:
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT DATABASE() db, VERSION() version")
            info = cur.fetchone()
            cur.execute(
                "SELECT COUNT(*) tables_ready FROM information_schema.tables "
                "WHERE table_schema=DATABASE() AND table_name IN "
                "('synervox_call_runs','synervox_call_variables','synervox_call_node_events',"
                "'synervox_campaign_processes','synervox_campaign_audio_builds',"
                "'synervox_campaign_audio_files','synervox_campaign_events')"
            )
            ready = int(cur.fetchone()['tables_ready'])
        print(f"ok db={info['db']} tables={ready}/7")
        return 0 if ready == 7 else 1
    finally:
        conn.close()


if __name__ == '__main__':
    raise SystemExit(main())
