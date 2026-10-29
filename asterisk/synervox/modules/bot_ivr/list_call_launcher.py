#!/usr/bin/env python3
"""Launch isolated PHP dial worker holding one exclusive lease per list."""
import argparse
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess


def launch(args):
    if args.list < 1 or args.campaign < 1 or not re.fullmatch(r'[a-f0-9]{32}', args.run):
        raise ValueError('invalid_identity')
    root, web = Path(args.root).resolve(), Path(args.web).resolve()
    directory = root / 'bot_ivr' / 'call_jobs'
    if not directory.is_dir() or directory.is_symlink():
        raise ValueError('invalid_runtime')
    worker = web / 'bot_ivr' / 'list_call_worker.php'
    if not worker.is_file():
        raise ValueError('worker_missing')
    os.umask(0o027)
    lock = (directory / ('list_' + str(args.list) + '.lock')).open('a+')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with open(os.devnull, 'rb') as source, open(directory / ('list_' + str(args.list) + '.log'), 'ab') as output:
            subprocess.Popen(['/usr/bin/php', str(worker), '--list', str(args.list), '--campaign', str(args.campaign),
                              '--run', args.run, '--lease-fd', str(lock.fileno())],
                             stdin=source, stdout=output, stderr=output, start_new_session=True,
                             pass_fds=(lock.fileno(),))
    finally:
        lock.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True)
    parser.add_argument('--web', required=True)
    parser.add_argument('--list', required=True, type=int)
    parser.add_argument('--campaign', required=True, type=int)
    parser.add_argument('--run', required=True)
    args = parser.parse_args()
    try:
        launch(args)
        print(json.dumps({'ok': True}))
    except Exception:
        print(json.dumps({'ok': False}))
        raise SystemExit(1)
