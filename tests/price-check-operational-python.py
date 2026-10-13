"""Run original operational stores on disposable SQLite; no original db/env/network imports."""
import copy
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sqlite3
import sys
import types

source = Path(sys.argv[1]).resolve()
def forbidden(*args, **kwargs): raise AssertionError('External access forbidden')
db = types.ModuleType('db'); db.connect = forbidden
db.upsert_sql = lambda table, columns, conflict, updates: f"INSERT INTO {table} ({', '.join(columns)}) VALUES ({', '.join('?' for _ in columns)}) ON CONFLICT({', '.join(conflict)}) DO UPDATE SET " + ', '.join(f'{key}=excluded.{key}' for key in updates)
db.insert_ignore_sql = lambda table, columns: f"INSERT OR IGNORE INTO {table} ({', '.join(columns)}) VALUES ({', '.join('?' for _ in columns)})"
sys.modules['db'] = db
real_os = sys.modules['os']; isolated_os = types.ModuleType('os')
isolated_os.getenv = lambda name, default=None: default; isolated_os.environ = {}
isolated_os.__getattr__ = lambda name: getattr(real_os, name); sys.modules['os'] = isolated_os
spec = importlib.util.spec_from_file_location('storage', source / 'storage.py')
s = importlib.util.module_from_spec(spec); sys.modules['storage'] = s; spec.loader.exec_module(s)
class FrozenDatetime(datetime):
    value = datetime(2026, 1, 20, 12, 0, 0, 123456, tzinfo=timezone.utc)
    @classmethod
    def now(cls, tz=None): return cls.value
s.datetime = FrozenDatetime

class Connection:
    def __init__(self):
        self.raw = sqlite3.connect(':memory:', isolation_level=None); self.raw.row_factory = sqlite3.Row
        self.failure = None
        for table in ('cache_settings', 'quote_logs'):
            body = re.search(r'CREATE TABLE IF NOT EXISTS tapin_' + table + r' \((.*?)\) ENGINE[^;]*;', s.MYSQL_SCHEMA, re.S)[1]
            lines = []; auto = False
            for line in body.splitlines():
                line = line.strip().rstrip(',')
                if not line or line.startswith('KEY '): continue
                if 'AUTO_INCREMENT' in line: line = 'id INTEGER PRIMARY KEY AUTOINCREMENT'; auto = True
                if auto and line.startswith('PRIMARY KEY'): continue
                lines.append(re.sub(r'UNIQUE KEY \w+', 'UNIQUE', line))
            self.raw.execute(f'CREATE TABLE tapin_{table} ({", ".join(lines)})')
    def execute(self, sql, args=()):
        if self.failure and re.match(self.failure['kind'] + r'\b', sql.strip(), re.I) and 'tapin_' + self.failure['table'] in sql:
            self.failure = None; raise RuntimeError('Synthetic database failure')
        return self.raw.execute(sql, args)
    def close(self): pass
    def __enter__(self): return self
    def __exit__(self, *args): return False

scenarios = []
def a(op, *args, **extra): return dict(op=op, args=copy.deepcopy(args), **extra)
def case(name, actions, **setup): scenarios.append(dict(name=name, actions=actions, **setup))
error = dict(status=' ERROR ', provider=dict(id=9, name=' technical ', display_name=' عنوان '), endpoint=' https://example.test/quote ', payload=dict(amount=0, nested=[None, False, '۰', -0.0]), response=dict(detail='عدم پاسخ'), error_message=' failed ')
case('error-only snapshot filters and deletes', [a('log_save', dict(status='success')), a('log_save', {}), a('log_save', error), a('log_save', dict(status='error', provider_id=0, provider_name='0', payload=[])), a('log_list'), a('log_list', 0, True), a('log_list', -1, ' 009 '), a('log_list', 5, ' technical ', None, None, ' ERROR '), a('log_list', 5, None, '2026-01-21'), a('log_delete', 'bad'), a('log_delete', 1), a('log_delete', 1), a('log_clear'), a('log_clear')])
case('unicode JSON truncation and false responses', [a('log_save', dict(status='error', payload=dict(text='آ' * 20000), response='آ' * 20000)), a('log_save', dict(status='error', payload=None, response=False)), a('log_list')])
case('retention boundary and save prune', [a('settings_save', dict(quote_logs=10)), a('log_save', error), a('log_list', now='2026-01-20T12:00:10.123456+00:00'), a('log_list', now='2026-01-20T12:00:11.123456+00:00'), a('log_save', error), a('log_save', error, now='2026-01-20T12:00:22+00:00'), a('log_list')])
case('settings defaults merge and integer coercion', [a('settings_get'), a('settings_save', dict(price='1_000', quote_logs=1.9, locations=True, unknown=4)), a('settings_get'), a('settings_save', dict(price=None)), a('settings_save', dict(price=0)), a('settings_save', dict(price=2592001)), a('settings_save', [])])
for raw in ('not json', '[]', '{}', '{"price": false, "quote_logs": "1", "locations": 0, "security_events": 9999999, "price_retention": 1.9}'):
    case('stored settings ' + raw, [a('settings_get')], initial_cache_json=raw)
for kind, table, op, args in [('SELECT', 'cache_settings', 'settings_get', []), ('INSERT', 'cache_settings', 'settings_save', [{}]), ('INSERT', 'quote_logs', 'log_save', [error]), ('DELETE', 'quote_logs', 'log_save', [error]), ('DELETE', 'quote_logs', 'log_list', []), ('SELECT', 'quote_logs', 'log_list', []), ('DELETE', 'quote_logs', 'log_delete', [1]), ('DELETE', 'quote_logs', 'log_clear', [])]:
    case('failure ' + op + kind, [a(op, *args, failure=dict(kind=kind, table=table)), a('log_list')])

def execute(item, conn):
    ops = dict(settings_get=s.get_cache_settings, settings_save=s.save_cache_settings, log_save=s.save_quote_log, log_list=s.list_quote_logs, log_clear=s.clear_quote_logs, log_delete=s.delete_quote_log)
    return ops[item['op']](*item['args'], conn=conn)

operations = 0
for scenario in scenarios:
    conn = Connection(); s.connect = lambda: conn
    FrozenDatetime.value = datetime(2026, 1, 20, 12, 0, 0, 123456, tzinfo=timezone.utc)
    if 'initial_cache_json' in scenario: conn.raw.execute('INSERT INTO tapin_cache_settings VALUES(1, ?)', (scenario['initial_cache_json'],))
    for item in scenario['actions']:
        if 'now' in item: FrozenDatetime.value = datetime.fromisoformat(item['now'])
        conn.failure = copy.deepcopy(item.get('failure'))
        try: item['expected'] = dict(ok=True, value=execute(item, conn))
        except (RuntimeError, sqlite3.DatabaseError): item['expected'] = dict(ok=False, kind='DatabaseError')
        except ValueError as exc: item['expected'] = dict(ok=False, kind='ValueError', message=str(exc))
        operations += 1
    conn.raw.close()
result = dict(provenance=dict(python=sys.version.split()[0], backend='original functions with SQLite SQL double; NOT WordPress/MySQL parity', hashes={'storage.py': hashlib.sha256((source / 'storage.py').read_bytes()).hexdigest()}, operations=operations), scenarios=scenarios)
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'Captured {operations} original Python operational-storage operations')
