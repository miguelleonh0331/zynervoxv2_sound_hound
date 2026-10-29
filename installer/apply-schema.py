#!/usr/bin/env python3
import re
import os
import subprocess
import sys
from pathlib import Path

database, schema_path = sys.argv[1:]
if not re.fullmatch(r'[A-Za-z0-9_]+', database):
    raise SystemExit('Invalid database name')


def query(statement):
    result = subprocess.run(['mysql', '--batch', '--skip-column-names', database],
                            input=statement, universal_newlines=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    return result.stdout.strip()


source = Path(schema_path).read_text(encoding='utf-8-sig')
config_table = os.environ.get('ZYNERVOX_CORE_CONFIG_TABLE', 'ivr_deploy_config')
if config_table not in ('ivr_deploy_config', 'v2_ivr_deploy_config'):
    raise SystemExit('Invalid configuration table')
source = re.sub(r'\bivr_deploy_config\b', config_table, source)
source = re.sub(r'^\s*--.*$', '', source, flags=re.MULTILINE)
source = re.sub(r'^\s*(?:USE\s+[^;]+|CREATE DATABASE[^;]+);', '', source, flags=re.MULTILINE | re.IGNORECASE)
for statement in source.split(';'):
    statement = statement.strip()
    if not statement:
        continue
    alter = re.fullmatch(r'ALTER TABLE\s+`?([A-Za-z0-9_]+)`?\s+(ADD COLUMN IF NOT EXISTS\s+.+)', statement, re.IGNORECASE | re.DOTALL)
    if not alter:
        query(statement)
        continue
    table, additions = alter.groups()
    for addition in re.split(r',\s*(?=ADD COLUMN IF NOT EXISTS)', additions, flags=re.IGNORECASE):
        column = re.match(r'ADD COLUMN IF NOT EXISTS\s+`?([A-Za-z0-9_]+)`?', addition, re.IGNORECASE).group(1)
        exists = query(f"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='{database}' AND table_name='{table}' AND column_name='{column}'")
        if exists == '0':
            clause = re.sub(r'ADD COLUMN IF NOT EXISTS', 'ADD COLUMN', addition, count=1, flags=re.IGNORECASE)
            query(f'ALTER TABLE `{table}` {clause}')
