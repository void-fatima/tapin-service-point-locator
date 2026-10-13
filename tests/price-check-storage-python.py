"""Configuration storage fixtures from original code, using disposable SQLite SQL doubles.

Usage: python -I -B tests/price-check-storage-python.py ../tapin_check_price_web <output-json>
No original db.py import, environment settings, seed data, credentials or external connection.
SQLite replaces MySQL syntax and models changed-row counts; this is not MySQL integration parity.
"""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sqlite3
import sys
import types

source = Path(sys.argv[1]).resolve()
def forbidden(*args, **kwargs):
    raise AssertionError("External database access is forbidden")
db = types.ModuleType("db")
db.connect = forbidden
def upsert(table, columns, conflict, updates):
    return f"INSERT INTO {table} ({', '.join(columns)}) VALUES ({', '.join('?' for _ in columns)}) ON DUPLICATE KEY UPDATE " + ", ".join(f"{column} = VALUES({column})" for column in updates)
db.upsert_sql = upsert
sys.modules['db'] = db
real_os = sys.modules['os']
isolated_os = types.ModuleType('os')
isolated_os.getenv = lambda name, default=None: default
isolated_os.environ = {}
isolated_os.__getattr__ = lambda name: getattr(real_os, name)
sys.modules['os'] = isolated_os
modules = {}
for name in ('storage', 'providers', 'records'):
    spec = importlib.util.spec_from_file_location(name, source / (name + '.py'))
    module = importlib.util.module_from_spec(spec); sys.modules[name] = module
    spec.loader.exec_module(module); modules[name] = module
s, p, r = (modules[name] for name in ('storage', 'providers', 'records'))

class Connection:
    def __init__(self):
        self.raw = sqlite3.connect(':memory:', isolation_level=None)
        self.raw.row_factory = sqlite3.Row; self.raw.execute('PRAGMA foreign_keys=ON')
        self.failure = None
        for table in ('providers', 'provider_params', 'provider_fields', 'shops', 'bulk_config'):
            match = re.search(r'CREATE TABLE IF NOT EXISTS tapin_' + table + r' \((.*?)\) ENGINE[^;]*;', s.MYSQL_SCHEMA, re.S)
            lines = []; auto = False
            for line in match[1].splitlines():
                line = line.strip().rstrip(',')
                if not line or line.startswith('KEY '): continue
                if 'AUTO_INCREMENT' in line: line = 'id INTEGER PRIMARY KEY AUTOINCREMENT'; auto = True
                if auto and line.startswith('PRIMARY KEY'): continue
                line = re.sub(r'UNIQUE KEY \w+', 'UNIQUE', line)
                if line.startswith('REFERENCES '): lines[-1] += ' ' + line
                else: lines.append(line)
            self.raw.execute(f'CREATE TABLE tapin_{table} ({", ".join(lines)})')
    def execute(self, sql, args=()):
        if self.failure and re.match(self.failure['kind'] + r'\b', sql.strip(), re.I) and 'tapin_' + self.failure['table'] in sql:
            self.failure = None; raise RuntimeError('Synthetic database failure')
        sql = re.sub(r'ON DUPLICATE KEY UPDATE (.*)$', r'ON CONFLICT(id) DO UPDATE SET \1', sql, flags=re.S)
        sql = re.sub(r'VALUES\((\w+)\)', r'excluded.\1', sql)
        # Preserve MySQL's zero changed-row UPDATE result, including the provider-save fallback bug.
        match = re.match(r'\s*UPDATE (\w+) SET (.*?) WHERE (.*)', sql, re.S)
        if match:
            columns = [part.strip().split('=')[0].strip() for part in match[2].split(',')]
            sql += ' AND NOT (' + ' AND '.join(column + ' IS ?' for column in columns) + ')'
            args = tuple(args) + tuple(args[:len(columns)])
        return self.raw.execute(sql, args)
    def __enter__(self): return self
    def __exit__(self, *args): return False
    def close(self): pass

def record(name='fixture', **changes):
    value = {'name': name, 'params': [{'name': 'amount', 'input': 'number', 'type': 'int', 'visible': True, 'default': 0}], 'location_config': {'origin': {'enabled': False}}}
    value.update(changes); return r.normalise(value)
rich = record(params=[
    {'name': 'amount', 'input': 'number', 'type': 'float', 'default': 1.25, 'visible': True, 'kind': 'money', 'description': ' شرح ', 'required': True},
    {'name': 'items', 'input': 'list', 'min_items': 2, 'fields': [{'name': 'count', 'type': 'int', 'default': 0, 'visible': True}, {'name': 'code', 'input': 'select', 'options': ['0', 'x'], 'default': '0'}]},
    {'name': 'details', 'input': 'dict', 'fields': [{'name': 'text', 'default': '0', 'visible': True}]},
    {'name': 'choice', 'input': 'select', 'options': ['0', 'x'], 'default': '0', 'visible': True},
    {'name': 'point', 'input': 'map', 'map': {'mode': 'combined', 'param': 'coordinates'}},
], price_currency_out='toman', weight_unit_out='kilogram', timeout=1.23456789012345, shop_id='01', price_fields=[{'key': 'total', 'label': 'هزینه'}], referral={'enabled': True, 'title': '0', 'url': 'https://example.test'}, rules=[{'if_field': 'amount', 'if_op': 'gte', 'if_value': '0'}])
scenarios = []
def scenario(name, actions): scenarios.append({'name': name, 'actions': actions})
def action(op, *args, **extra): return {'op': op, 'args': copy.deepcopy(args), **extra}

scenario('rich round trip', [action('provider_save', rich), action('provider_raw', 1), action('provider_params', 1), action('provider_fields', [2, 3]), action('provider_load', 'fixture'), action('provider_all'), action('provider_load', 'missing'), action('provider_load', '01')])
renamed = copy.deepcopy(rich); renamed['id'] = 1; renamed['name'] = 'renamed'
for index, param in enumerate(renamed['params'], 1): param['id'] = index
renamed['params'][1]['fields'][0]['id'] = 1; renamed['params'][1]['fields'][1]['id'] = 2
renamed['params'][1]['fields'].reverse(); renamed['params'][1]['fields'][0]['name'] = 'renamed_code'
renamed['params'][2]['fields'][0]['id'] = 3
renamed['params'] = [renamed['params'][2], renamed['params'][1], renamed['params'][0]]
scenario('rename reorder delete omitted rows', [action('provider_save', rich), action('provider_save', renamed), action('provider_raw', 1), action('provider_params', 1), action('provider_fields', [1, 2, 3, 4, 5]), action('provider_load', 1)])
foreign = record('other', params=[{'id': 1, 'name': 'foreign', 'input': 'dict', 'fields': [{'id': 1, 'name': 'foreign_field', 'default': '0'}]}])
duplicate = copy.deepcopy(renamed); duplicate['name'] = 'duplicate-rows'; duplicate['params'] = [duplicate['params'][1], copy.deepcopy(duplicate['params'][1])]
duplicate['params'][0]['fields'].append(copy.deepcopy(duplicate['params'][0]['fields'][0]))
scenario('owned foreign and duplicate ids', [action('provider_save', rich), action('provider_save', foreign), action('provider_save', duplicate), action('provider_params', 1), action('provider_fields', [2, 7]), action('provider_load', 2), action('provider_load', 1)])
simple = record()
unchanged = copy.deepcopy(simple); unchanged['id'] = 1
scenario('unchanged provider update is a source failure', [action('provider_save', simple), action('provider_save', unchanged), action('provider_load', 1)])
scenario('supplied id allocation delete cascade', [action('provider_save', {**rich, 'id': 42}), action('provider_save', record('next')), action('provider_all'), action('provider_delete', 42), action('provider_fields', [2, 3]), action('provider_all'), action('provider_delete', 999)])
scenario('SQL quoting and text defaults', [action('provider_save', record("fixture'quoted", params=[{'name': 'flag', 'default': True}, {'name': 'empty', 'default': ''}, {'name': 'zero', 'default': '0'}, {'name': 'none', 'default': None}, {'name': 'string', 'default': ' خط\n"\\/\u2028 '}], logo='', check_price_url='')), action('provider_load', "fixture'quoted"), action('provider_raw', 1), action('provider_params', 1)])
scenario('missing name fails before writing', [action('provider_save', {'name': '', 'params': []}), action('provider_all')])
scenario('partial field write failure', [action('provider_save', rich, failure={'kind': 'INSERT', 'table': 'provider_fields'}), action('provider_raw', 1), action('provider_params', 1), action('provider_fields', [2]), action('provider_load', 1)])
scenario('provider read and delete failures', [action('provider_save', simple), action('provider_raw', 1, failure={'kind': 'SELECT', 'table': 'providers'}), action('provider_delete', 1, failure={'kind': 'DELETE', 'table': 'providers'}), action('provider_load', 1)])

def execute(item, conn):
    op = item['op']; args = item['args']
    if op == 'provider_save': return s.save_provider(*args, conn=conn)
    if op == 'provider_raw': return s.get_provider_row(*args, conn=conn)
    if op == 'provider_params': return s.get_param_rows(*args, conn=conn)
    if op == 'provider_fields': return s.get_field_rows(*args, conn=conn)
    if op == 'provider_load': return p.get_provider(*args)
    if op == 'provider_all': return p.get_providers()
    if op == 'provider_delete': return s.delete_provider(*args, conn=conn)
    raise AssertionError(op)
for case in scenarios:
    conn = Connection(); s.connect = lambda: conn
    for item in case['actions']:
        conn.failure = item.get('failure')
        try: item['expected'] = {'ok': True, 'value': execute(item, conn)}
        except ValueError as exc: item['expected'] = {'ok': False, 'kind': 'ValueError', 'message': str(exc)}
        except (sqlite3.DatabaseError, RuntimeError): item['expected'] = {'ok': False, 'kind': 'DatabaseError'}
    conn.raw.close()
result = {'source_sha256': {name: hashlib.sha256((source/(name+'.py')).read_bytes()).hexdigest() for name in modules}, 'python': sys.version.split()[0], 'backend': 'SQLite memory SQL double, changed-row UPDATE emulation, autocommit', 'scenarios': scenarios}
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False)+'\n', encoding='utf-8')
print(f"Captured {len(scenarios)} scenarios / {sum(len(case['actions']) for case in scenarios)} original storage operations; no external database or environment access.")
