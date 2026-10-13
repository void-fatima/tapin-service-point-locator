"""Run original operational stores on disposable SQLite; no original db/env/network imports."""
import ast
import copy
from dataclasses import asdict, is_dataclass
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sqlite3
import sys
import types
from collections.abc import Mapping

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
api_tree = ast.parse((source / 'api.py').read_text(encoding='utf-8'))
key_function = next(node for node in api_tree.body if isinstance(node, ast.FunctionDef) and node.name == '_cache_key')
key_namespace = dict(Mapping=Mapping, Any=object, json=json, hashlib=hashlib)
exec(compile(ast.Module(body=[key_function], type_ignores=[]), 'original api.py::_cache_key', 'exec'), key_namespace)
package = types.ModuleType('security'); package.__path__ = [str(source / 'security')]; sys.modules['security'] = package
spec = importlib.util.spec_from_file_location('security.infra_mysql', source / 'security/infra_mysql.py')
security = importlib.util.module_from_spec(spec); sys.modules[spec.name] = security; spec.loader.exec_module(security)
# Stage 2 owns schema creation; the operational repositories must not perform runtime DDL.
security.ensure_schema = lambda conn: None
from security.models import BlockedClient, SecurityEvent

class Connection:
    def __init__(self):
        self.raw = sqlite3.connect(':memory:', isolation_level=None); self.raw.row_factory = sqlite3.Row
        self.failure = None
        self.raw.execute('PRAGMA foreign_keys=ON')
        schema = s.MYSQL_SCHEMA + security.SCHEMA_TABLES + security.SCHEMA_CLIENTS + security.SCHEMA_ALLOWLIST
        for table in ('providers', 'cache_settings', 'quote_logs', 'price_cache', 'security_settings', 'route_limits', 'blocked_clients', 'security_events', 'clients', 'security_allowlist'):
            body = re.search(r'CREATE TABLE IF NOT EXISTS tapin_' + table + r' \((.*?)\) ENGINE[^;]*;', schema, re.S)[1]
            body = re.sub(r',\s*(?=\w+\s+(?:VARCHAR|BIGINT|TEXT)|PRIMARY KEY|UNIQUE KEY)', '\n', body)
            lines = []; auto = False
            for line in body.splitlines():
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
        match = re.match(r'\s*UPDATE (\w+) SET (.*?) WHERE (.*)', sql, re.S)
        if match:
            columns = [part.strip().split('=')[0].strip() for part in match[2].split(',')]
            sql += ' AND NOT (' + ' AND '.join(column + ' IS ?' for column in columns) + ')'
            args = tuple(args) + tuple(args[:len(columns)])
        return self.raw.execute(sql, args)
    def executescript(self, sql): pass  # DDL already installed from original declarations above.
    def commit(self): pass
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

cache = dict(provider_id=1, provider_name=' fixture ', params_hash=' hash ', params=dict(weight=1.25, zero=-0.0, nested=[None, False]), endpoint=' https://example.test ', quote=dict(prices=[dict(label='هزینه', value=0)]))
replacement = dict(cache, provider_name='new', params={}, quote=[0, False])
case('cache miss hit replacement identity clear', [a('cache_get', 1, 'hash'), a('cache_save', cache), a('cache_get', 1, ' hash '), a('cache_get', 1, 'hash'), a('cache_ids'), a('cache_save', replacement, now='2026-01-20T12:00:01+00:00'), a('cache_get', 1, 'hash'), a('cache_ids'), a('cache_get', 2, 'hash'), a('cache_clear'), a('cache_clear')], provider=True)
case('cache retention separate from freshness', [a('settings_save', dict(price=2, price_retention=10)), a('cache_save', cache), a('cache_fresh', '2026-01-20T12:00:00+00:00', now='2026-01-20T12:00:01.999999+00:00'), a('cache_fresh', '2026-01-20T12:00:00+00:00', now='2026-01-20T12:00:02+00:00'), a('cache_get', 1, 'hash', now='2026-01-20T12:00:10+00:00'), a('cache_get', 1, 'hash', now='2026-01-20T12:00:11+00:00'), a('cache_save', cache), a('cache_save', dict(cache, params_hash='other'), now='2026-01-20T12:00:22+00:00'), a('cache_get', 1, 'hash')], provider=True)
case('cache false defaults and truncation', [a('cache_save', dict(cache, params=None, quote=False)), a('cache_get', 1, 'hash'), a('cache_save', dict(cache, quote=dict(text='آ' * 20000))), a('cache_get', 1, 'hash')], provider=True)
case('cache provider cascade', [a('cache_save', cache), a('provider_delete', 1), a('cache_get', 1, 'hash')], provider=True)
case('cache missing provider', [a('cache_save', cache)])
for stamp in (None, '', 0, 'bad', '2026-02-30T12:00:00+00:00', '2026-01-20T12:00:00Z', '2026-01-20 12:00:00', '2026-01-20T15:30:00+03:30', '2026-01-20', '2026-01-20T12:00:00.123456+00:00', '2026-01-21T12:00:00+00:00'):
    case('cache freshness ' + str(stamp), [a('cache_fresh', stamp)])
for kind, op, args in [('INSERT', 'cache_save', [cache]), ('DELETE', 'cache_save', [cache]), ('DELETE', 'cache_get', [1, 'hash']), ('SELECT', 'cache_get', [1, 'hash']), ('DELETE', 'cache_clear', [])]:
    case('cache failure ' + op + kind, [a(op, *args, failure=dict(kind=kind, table='price_cache')), a('cache_get', 1, 'hash')], provider=True)
for provider, params in [({}, {}), ({'check_price_url': 'https://other.test', 'shop_id': '02'}, {}), ({'price_currency_in': 'toman', 'price_currency_out': 'rial', 'weight_unit_in': 'kilogram', 'price_fields': [{'key': 'total', 'label': 'هزینه'}]}, {'z': 1.0, 'a': {'۱': -0.0, 'b': 1e-6}, 'array': [2, None, False], 'text': 'آ\u2028/"'}), ({}, {'b': 2, 'a': 1}), ({}, {'a': 1, 'b': 2})]:
    case('cache hash ' + str(len(scenarios)), [a('cache_key', provider, params)])

case('security singleton defaults and update', [a('security_get'), a('security_save', dict(max_requests='4', window_seconds=60.9)), a('security_get'), a('security_save', dict(max_requests=True, window_seconds='1_000')), a('security_get'), a('security_save', dict(max_requests=100000, window_seconds=86400))])
for raw in ({}, dict(max_requests=4), dict(max_requests='bad', window_seconds='bad'), dict(max_requests=0, window_seconds='bad'), dict(max_requests=0, window_seconds=1), dict(max_requests=100001, window_seconds=1), dict(max_requests=1, window_seconds=0), dict(max_requests=1, window_seconds=86401)):
    case('security invalid ' + str(raw), [a('security_save', raw)])
for raw in ('not JSON', '[]', '{}', '{"max_requests": 0, "window_seconds": 60}', '{"max_requests": true, "window_seconds": 1}'):
    case('security stored ' + raw, [a('security_get')], initial_security_json=raw)
case('routes replace delete and stored ms', [a('route_get'), a('route_save', ' /api/price-check ', 4, 60, ' OFF '), a('route_get'), a('route_raw'), a('route_save', '/api/price-check', 10, 30, 'unknown'), a('route_get'), a('route_delete', ' /api/price-check '), a('route_delete', '/api/price-check')])
for enabled in (False, 0, 0.5, None, '', '0', 'false', 'NO', 'n', 'off', 'disabled', 'true', 'yes', 'enabled', [], {}):
    case('route enabled ' + repr(enabled), [a('route_save', '/api/arbitrary', 1, 1, enabled), a('route_get')])
for args in [(None, 4, 60), ('/other', 4, 60), ('/api/x', None, 60), ('/api/x', 4, 'bad'), ('/api/x', 0, 60)]:
    case('route invalid ' + str(args), [a('route_save', *args)])
block = dict(type='ip', value='192.0.2.1', reason='reason_%', created_at_ms=1000, created_by='fixture-admin')
case('blocks duplicates newest expiry toggles and delete', [a('block_add', block), a('block_add', dict(block, expires_at_ms=2000)), a('block_find', 'ip', '192.0.2.1', 1999), a('block_find', 'ip', '192.0.2.1', 2000), a('block_list', '', '', '0', 2000), a('block_list', '', '', '1', 2000), a('block_active', 1, False), a('block_active', 1, False), a('block_update', 2, 'changed', None), a('block_update', 2, 'changed', None), a('block_list'), a('block_find', 'ip', 'missing', 2000), a('block_delete', 2), a('block_delete', 2), a('block_update', 9, '', None), a('block_active', 9, True)])
case('block defaults search and limit', [a('block_add', {}), a('block_add', block), a('block_list', 'reason_%', 'ip', '', 1000, 5000), a('block_list', 'fixture-admin'), a('block_list', '', '', '', 0, -1), a('block_list', '', '', '', 0, 0)])
case('allowlist reason-only upsert', [a('allow_contains', '192.0.2.1'), a('allow_add', '192.0.2.1', 'first', 'actor1', 1), a('allow_add', '192.0.2.1', 'second', 'actor2', 2), a('allow_contains', '192.0.2.1'), a('allow_list'), a('allow_list', 'sec%', 5000), a('allow_list', 'missing', 0), a('allow_delete', 1), a('allow_delete', 1)])
case('client first-seen preserved', [a('client_exists', '01'), a('client_remember', '01', 1234), a('client_remember', '01', 9999), a('client_exists', '01'), a('client_exists', '1'), a('client_rows')])
event = dict(created_at_ms=100000, kind='rate_limited', ip='192.0.2.1', client_id='01', client_fingerprint='fingerprint', route='/api/price-check', detail='dimension=ip')
case('events timestamp retention only on write', [a('settings_save', dict(security_events=10)), a('event_record', dict(event, created_at_ms=89999)), a('event_record', dict(event, created_at_ms=90000)), a('event_record', event), a('event_list'), a('event_list', '', '01'), a('event_list', 'rate_limited', 'fingerprint'), a('event_list', 'missing'), a('event_list', now='2027-01-01T00:00:00+00:00'), a('event_record', dict(event, created_at_ms=100001, detail=None)), a('event_list'), a('stats'), a('event_clear'), a('event_clear')])
case('events defaults order limit', [a('event_record', {}), a('event_record', dict(event, created_at_ms=1)), a('event_list', '', '', -1), a('event_list', '', '192.0.2.1', 0)])
security_failures = [('SELECT', 'security_settings', 'security_get', []), ('INSERT', 'security_settings', 'security_save', [dict(max_requests=4, window_seconds=60)]), ('SELECT', 'route_limits', 'route_get', []), ('INSERT', 'route_limits', 'route_save', ['/api/x', 4, 60]), ('DELETE', 'route_limits', 'route_delete', ['/api/x']), ('SELECT', 'blocked_clients', 'block_list', []), ('SELECT', 'blocked_clients', 'block_find', ['ip', 'x', 0]), ('INSERT', 'blocked_clients', 'block_add', [block]), ('UPDATE', 'blocked_clients', 'block_active', [1, False]), ('UPDATE', 'blocked_clients', 'block_update', [1, '', None]), ('DELETE', 'blocked_clients', 'block_delete', [1]), ('SELECT', 'security_allowlist', 'allow_contains', ['192.0.2.1']), ('SELECT', 'security_allowlist', 'allow_list', []), ('INSERT', 'security_allowlist', 'allow_add', ['192.0.2.1', '', '', 0]), ('SELECT', 'security_allowlist', 'allow_add', ['192.0.2.1', '', '', 0]), ('DELETE', 'security_allowlist', 'allow_delete', [1]), ('SELECT', 'clients', 'client_exists', ['01']), ('INSERT', 'clients', 'client_remember', ['01', 0]), ('INSERT', 'security_events', 'event_record', [event]), ('DELETE', 'security_events', 'event_record', [event]), ('SELECT', 'security_events', 'event_list', []), ('DELETE', 'security_events', 'event_clear', []), ('SELECT', 'price_cache', 'stats', [])]
for kind, table, op, args in security_failures:
    extra = dict(wordpress_error='DatabaseError') if op in ('route_get', 'route_delete', 'event_clear', 'stats') else {}
    case('security failure ' + op + kind, [a(op, *args, failure=dict(kind=kind, table=table), **extra), a('event_list')])

def execute(item, conn):
    op, args = item['op'], item['args']
    blocks = security.MysqlBlockedClientRepository(lambda: conn); allow = security.MysqlAllowlistRepository(lambda: conn)
    events = security.MysqlSecurityEventStore(lambda: conn); clients = security.MysqlClientStore(lambda: conn)
    if op == 'block_add': return blocks.add(BlockedClient(**args[0]))
    if op == 'event_record': return events.record(SecurityEvent(**args[0]))
    if op == 'client_rows': return [dict(row) for row in conn.execute('SELECT * FROM tapin_clients').fetchall()]
    if op == 'route_raw': return [dict(row) for row in conn.execute('SELECT * FROM tapin_route_limits').fetchall()]
    repository_ops = dict(block_find=blocks.find_active, block_list=blocks.list, block_update=blocks.update, block_active=blocks.set_active, block_delete=blocks.delete, allow_add=allow.add, allow_contains=allow.contains, allow_list=allow.list, allow_delete=allow.delete, event_list=events.list, client_exists=clients.exists, client_remember=clients.remember)
    if op in repository_ops: return repository_ops[op](*args)
    if item['op'] == 'cache_key': return key_namespace['_cache_key'](*item['args'])
    if item['op'] == 'cache_fresh': return s.price_cache_is_fresh(*item['args'])
    if item['op'] == 'cache_ids': return [dict(row) for row in conn.execute('SELECT id, provider_id, params_hash FROM tapin_price_cache ORDER BY id').fetchall()]
    if item['op'] == 'provider_delete': conn.execute('DELETE FROM tapin_providers WHERE id = ?', item['args']); return None
    ops = dict(settings_get=s.get_cache_settings, settings_save=s.save_cache_settings, log_save=s.save_quote_log, log_list=s.list_quote_logs, log_clear=s.clear_quote_logs, log_delete=s.delete_quote_log, cache_get=s.get_price_cache, cache_save=s.save_price_cache, cache_clear=s.clear_price_cache, security_get=s.get_security_settings, security_save=s.save_security_settings, route_get=s.get_route_limits, route_save=s.save_route_limit, route_delete=s.delete_route_limit, event_clear=s.clear_security_events, stats=s.cache_stats)
    return ops[item['op']](*item['args'], conn=conn)

operations = 0
for scenario in scenarios:
    conn = Connection(); s.connect = lambda: conn
    FrozenDatetime.value = datetime(2026, 1, 20, 12, 0, 0, 123456, tzinfo=timezone.utc)
    if 'initial_cache_json' in scenario: conn.raw.execute('INSERT INTO tapin_cache_settings VALUES(1, ?)', (scenario['initial_cache_json'],))
    if 'initial_security_json' in scenario: conn.raw.execute('INSERT INTO tapin_security_settings VALUES(1, ?)', (scenario['initial_security_json'],))
    if scenario.get('provider'): conn.raw.execute("INSERT INTO tapin_providers(id, name, display_name) VALUES(1, 'fixture', 'fixture')")
    for item in scenario['actions']:
        if 'now' in item: FrozenDatetime.value = datetime.fromisoformat(item['now'])
        conn.failure = copy.deepcopy(item.get('failure'))
        try:
            value = execute(item, conn)
            if is_dataclass(value): value = asdict(value)
            if isinstance(value, list): value = [asdict(entry) if is_dataclass(entry) else entry for entry in value]
            item['expected'] = dict(ok=True, value=value)
        except (RuntimeError, sqlite3.DatabaseError): item['expected'] = dict(ok=False, kind='DatabaseError')
        except ValueError as exc: item['expected'] = dict(ok=False, kind='ValueError', message=str(exc))
        operations += 1
    conn.raw.close()
result = dict(provenance=dict(python=sys.version.split()[0], backend='original functions with SQLite SQL double; NOT WordPress/MySQL parity', hashes={name: hashlib.sha256((source / name).read_bytes()).hexdigest() for name in ('storage.py', 'api.py', 'security/infra_mysql.py', 'security/models.py')}, operations=operations), scenarios=scenarios)
conn = Connection(); s.connect = lambda: conn
FrozenDatetime.value = datetime(2026, 1, 20, 12, 0, 0, 123456, tzinfo=timezone.utc)
result['iso_compatibility_limits'] = [dict(input=value, python=s.price_cache_is_fresh(value)) for value in ('20260120T120000', '2026-W04-2T12:00:00', '2026-01-20T12')]
conn.raw.close()
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'Captured {operations} original Python operational-storage operations')
