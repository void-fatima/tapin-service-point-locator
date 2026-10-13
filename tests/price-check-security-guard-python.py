"""Execute original guard/lists with deterministic interface doubles; no startup/env/SQL/network."""
import ast
from dataclasses import asdict
import hashlib
import importlib
import json
from pathlib import Path
import sys
import types
from typing import Any, Dict, Optional

source = Path(sys.argv[1]).resolve(); package = types.ModuleType('security'); package.__path__ = [str(source/'security')]; sys.modules['security'] = package
memory = importlib.import_module('security.infra_memory'); fingerprints = importlib.import_module('security.fingerprint')
pipeline = importlib.import_module('security.pipeline'); rates = importlib.import_module('security.rate_limiter')
blocks = importlib.import_module('security.blocklist'); allows = importlib.import_module('security.allowlist'); models = importlib.import_module('security.models')
tree = ast.parse((source/'security/infra_starlette.py').read_text(encoding='utf-8'))
guard = next(node for node in tree.body if isinstance(node, ast.ClassDef) and node.name == 'Guard')
guard.body = [node for node in guard.body if isinstance(node, ast.FunctionDef) and node.name == 'extract_input']
class Response:
    def __init__(self, data=None, status_code=200, headers=None): self.data=data; self.status=status_code; self.headers=headers or {}; self.cookie=None
    def set_cookie(self, name, value, **kwargs): self.cookie=dict(name=name, value=value, **kwargs)
namespace = dict(Optional=Optional, Any=Any, Dict=Dict, Request=object, Response=Response, JSONResponse=Response, SecurityCheckInput=models.SecurityCheckInput, SecurityCheckResult=models.SecurityCheckResult, config=importlib.import_module('security.config'), new_client_id=lambda: 'b'*32)
functions = [node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name in ('deny_response', 'issue_client_id_cookie')]
exec(compile(ast.Module(body=[guard]+functions, type_ignores=[]), 'original extract_input/response descriptors', 'exec'), namespace)

class State:
    def __init__(self, scenario): self.allow = scenario.get('allow', []); self.rows = [models.BlockedClient(**row) for row in scenario.get('blocks', [])]; self.events = []; self.calls = []; self.fail = scenario.get('fail', '')
    def contains(self, ip):
        self.calls.append(['contains', ip])
        if self.fail == 'allow': raise RuntimeError('private storage failure')
        return ip in self.allow
    def find_active(self, type, value, now):
        self.calls.append(['find', type, value, now])
        if self.fail == 'block': raise RuntimeError('private storage failure')
        return next((row for row in reversed(self.rows) if row.type == type and row.value == value and row.is_active and (row.expires_at_ms is None or row.expires_at_ms > now)), None)
    def emit(self, kind, fp, ip, route, detail, now, client_id=''):
        if self.fail == 'event': raise RuntimeError('private storage failure')
        self.events.append(asdict(models.SecurityEvent(created_at_ms=now, kind=kind, client_fingerprint=fp, ip=ip, route=route, detail=detail, client_id=client_id)))
    def add(self, row): row.id = len(self.rows)+1; self.rows.append(row); return row
    def set_active(self, id, active):
        for row in self.rows:
            if row.id == id: changed = row.is_active != active; row.is_active = active; return changed
        return False
    def update(self, id, reason, expiry):
        for row in self.rows:
            if row.id == id: changed = row.reason != reason or row.expires_at_ms != expiry; row.reason = reason; row.expires_at_ms = expiry; return changed
        return False
    def delete(self, id): old = len(self.rows); self.rows = [row for row in self.rows if row.id != id]; return old != len(self.rows)

scenarios = []
base = dict(route='/api/price-check', method='POST', direct_ip='192.0.2.1', headers={'User-Agent': 'fixture-agent'}, body={'values': {'weight': 1}, 'client': {'language': 'fa'}}, cookies={'tapin_cid': 'a'*32})
deep = 1
for _ in range(11): deep = {'a': deep}
variants = [dict(), dict(body=None), dict(body={}), dict(body={'client': None}), dict(body={'client': []}), dict(body={'client': 'inert-test-marker'}), dict(cookies={}), dict(cookies={'tapin_cid': 'inert-test-marker'}), dict(cookies={'tapin_cid': False}), dict(direct_ip='invalid'), dict(direct_ip='[::1]:443'), dict(body_size=262144), dict(body_size=262145), dict(body={'values': None}), dict(body={'values': [], 'client': {'screenWidth': 0}}), dict(body={'values': deep}), dict(headers={'USER-AGENT': 'inert-test-marker', 'Authorization': 'Bearer inert-test-marker'}, body={'values': {'token': 'inert-test-marker'}, 'client': {'language': 'fa'}})]
for variant in variants: scenarios.append(dict(context=base | variant))
for type, value in [('ip', '192.0.2.1'), ('client_id', 'a'*32), ('client_fingerprint', fingerprints.client_fingerprint('a'*32, fingerprints.browser_fingerprint(fingerprints.browser_signals_from({'language': 'fa'})[0])[0]))]:
    for expiry, active in [(None, True), (1000, True), (1001, True), (None, False)]: scenarios.append(dict(context=base, blocks=[dict(id=1, type=type, value=value, reason='blocked by fixture', expires_at_ms=expiry, is_active=active)]))
scenarios += [dict(context=base, allow=['192.0.2.1'], blocks=[dict(id=1, type='ip', value='192.0.2.1')]), dict(context=base | dict(body_size=262145), allow=['192.0.2.1']), dict(context=base | dict(cookies={'tapin_cid': 'forged'}, body={'client': []}), allow=['192.0.2.1'])]
for failure in ['allow', 'block', 'event', 'rate', 'rate_after_ip']: scenarios.append(dict(context=base, fail=failure, blocks=[dict(id=1, type='ip', value='192.0.2.1')] if failure == 'block' else []))
for trusted in [[], ['10.0.0.0/8']]: scenarios.append(dict(context=base | dict(direct_ip='10.1.1.1', headers={'X-Forwarded-For': '192.0.2.1'}), trusted=trusted))
scenarios.append(dict(context=base, blocks=[dict(id=1, type='client_id', value='a'*32, reason='client'), dict(id=2, type='ip', value='192.0.2.1', reason='ip')]))
for scenario in scenarios:
    state = State(scenario); clock = memory.ManualClock(1000); store = memory.InMemoryRateLimitStore()
    if scenario.get('fail') == 'rate':
        store.increment = lambda *args: (_ for _ in ()).throw(RuntimeError('private storage failure'))
    elif scenario.get('fail') == 'rate_after_ip':
        increment = store.increment
        store.increment = lambda key, window, now: (_ for _ in ()).throw(RuntimeError('private storage failure')) if ':client:' in key else increment(key, window, now)
    limiter = rates.RateLimiter(store, clock, 2, 3)
    p = pipeline.SecurityPipeline(fingerprints.FingerprintService(), limiter, blocks.BlocklistService(state, clock), memory.StaticProxyIpResolver(scenario.get('trusted', [])), memory.SecretsRandom(), clock, state, allows.AllowlistService(state, clock))
    context = scenario['context']; request = types.SimpleNamespace(method=context.get('method', 'GET'), url=types.SimpleNamespace(path=context.get('route', '')), client=types.SimpleNamespace(host=context.get('direct_ip', '')), headers=context.get('headers', {}), cookies=context.get('cookies', {}))
    extractor = namespace['Guard'](); extractor._clock = clock
    outputs = []
    for now in [1000, 1001, 2000, 3999, 4000]:
        clock._now = now; inp = extractor.extract_input(request, context.get('body'), context.get('body_size', 0)); result = p.check(inp)
        rendered = None if result.allowed else namespace['deny_response'](result)
        response = None if rendered is None else dict(status=rendered.status, headers=rendered.headers, body=rendered.data)
        outputs.append(dict(now=now, result=asdict(result), response=response))
    scenario.update(outputs=outputs, events=state.events, calls=state.calls, counters=store._counters)
list_cases = []; state = State({}); clock = memory.ManualClock(1000); b = blocks.BlocklistService(state, clock)
for op, args in [('block', ['browser_fingerprint', 'x', '', '']), ('block', ['ip', ' ', '', '']), ('block', ['ip', 'x', '', '', 1000]), ('block', ['ip', ' x ', ' reason ', ' actor ', 1001]), ('check', ['x', '', '']), ('unblock', [1]), ('check', ['x', '', '']), ('reactivate', [1]), ('update', [1, ' raw ', 999]), ('check', ['x', '', '']), ('delete', [1]), ('delete', [1])]:
    try:
        value = getattr(b, op)(*args); value = asdict(value) if isinstance(value, models.BlockedClient) else ([value[0], value[1], asdict(value[2]) if value[2] else None] if op == 'check' else value)
        list_cases.append(dict(op=op, args=args, result=value))
    except ValueError as error: list_cases.append(dict(op=op, args=args, error=str(error)))
files = ['security/pipeline.py', 'security/blocklist.py', 'security/allowlist.py', 'security/events.py', 'security/infra_starlette.py']
out = dict(source_hashes={name: hashlib.sha256((source/name).read_bytes()).hexdigest() for name in files}, scenarios=scenarios, list_cases=list_cases)
Path(sys.argv[2]).write_text(json.dumps(out, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(f'Executed {len(scenarios)} original guard scenarios ({len(scenarios)*5} checks) and {len(list_cases)} block operations; no external access.')
