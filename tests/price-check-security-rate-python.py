"""Original in-memory counters, limiter and extracted route resolver; no application imports."""
import ast
from dataclasses import asdict
import hashlib
import importlib
import json
from pathlib import Path
import sys
import types

source = Path(sys.argv[1]).resolve()
package = types.ModuleType('security'); package.__path__ = [str(source/'security')]; sys.modules['security'] = package
m = importlib.import_module('security.infra_memory'); r = importlib.import_module('security.rate_limiter')
config = importlib.import_module('security.config')
api = types.ModuleType('api'); api_tree = ast.parse((source/'api.py').read_text(encoding='utf-8'))
nodes = [node for node in api_tree.body if (isinstance(node, ast.AnnAssign) and isinstance(node.target, ast.Name) and node.target.id == 'PUBLIC_API_CATALOG') or (isinstance(node, ast.FunctionDef) and node.name == 'normalize_public_route')]
api.__dict__['Any'] = object; exec(compile(ast.Module(body=nodes, type_ignores=[]), 'original api catalog', 'exec'), api.__dict__); sys.modules['api'] = api
storage = types.ModuleType('storage'); settings = dict(max_requests=7, window_seconds=11); overrides = {}
storage.get_security_settings = lambda: settings; storage.get_route_limits = lambda: overrides; sys.modules['storage'] = storage
tree = ast.parse((source/'security/infra_starlette.py').read_text(encoding='utf-8'))
guard = next(node for node in tree.body if isinstance(node, ast.ClassDef) and node.name == 'Guard')
guard.body = [node for node in guard.body if isinstance(node, ast.FunctionDef) and node.name in ('normalize_route', 'limits_for_route', 'effective_limits')]
namespace = dict(config=config, Optional=__import__('typing').Optional)
exec(compile(ast.Module(body=[guard], type_ignores=[]), 'original Guard resolver', 'exec'), namespace)
g = namespace['Guard'](); g._env_int = lambda name, default: default  # no env access/overrides
route_cases = []
for raw in [{}, {'/api/providers/{provider_id}': dict(max_requests=2, window_seconds=3, enabled=True)}, {'/api/price-check': dict(max_requests=1, window_seconds=9, enabled=False)}, {'/api/bulk-check': dict(max_requests=0, window_seconds=0, enabled=True)}]:
    overrides.clear(); overrides.update(raw)
    for route in ['/api/providers', ' /api/providers/42 ', '/api/locations', '/api/price-check', '/api/bulk', '/api/bulk-check', '/api/bulk-validate', '/unknown', '/api/health']:
        route_cases.append(dict(route=route, settings=settings.copy(), overrides=raw, normalized=g.normalize_route(route), result=g.limits_for_route(route)))
overrides.clear()
clock = m.ManualClock(1000); store = m.InMemoryRateLimitStore(); limiter = r.RateLimiter(store, clock, 4, 60, g.limits_for_route, g.normalize_route)
actions = []
def action(op, args):
    if op == 'time': clock._now = args[0]; result = None
    elif op == 'override': overrides.clear(); overrides.update(args[0]); result = None
    elif op == 'consume': result = asdict(limiter.check_and_consume(*args))
    elif op == 'dimensions': result = limiter.dimensions(*args)
    elif op == 'peek': result = limiter.peek(*args)
    elif op == 'inspect': result = limiter.inspect_identity(*args)
    elif op == 'reset': result = limiter.reset_identity(*args)
    elif op == 'clear': result = limiter.reset_all()
    elif op == 'increment': result = store.increment(*args)
    elif op == 'read': result = store.read(*args)
    actions.append(dict(op=op, args=args, result=result))
for identity in [('', '', 'f', 0), ('192.0.2.1', '', 'f', 0), ('', '', 'f', 1), ('::1', 'a'*32, 'f', 0)]: action('dimensions', list(identity)+['/api/providers/3'])
identity = ['::1', 'a'*32, 'f', 1, '/api/price-check']
for _ in range(6): action('consume', identity)
action('peek', identity); action('inspect', ['::1', 'a'*32, 'f', '/api/price-check'])
for now in [1001, 2000, 60999, 61000, 61001, 60000]: action('time', [now]); action('consume', identity); action('peek', identity)
action('reset', ['::1', 'a'*32, 'f', '/api/price-check']); action('peek', identity)
for _ in range(3): action('consume', ['::1', 'a'*32, 'f', 1, '/api/providers/3'])
action('peek', ['::1', 'a'*32, 'f', 1, '/api/providers/9'])
action('override', [{'/api/price-check': dict(max_requests=1, window_seconds=2, enabled=False)}]); action('consume', identity); action('inspect', ['::1', 'a'*32, 'f', '/api/price-check'])
action('override', [{'/api/price-check': dict(max_requests=1, window_seconds=2, enabled=True)}]); action('consume', identity); action('consume', identity)
action('override', [{'/api/price-check': dict(max_requests=1, window_seconds=120, enabled=True)}]); action('peek', identity)
action('clear', []); action('peek', identity)
for key, window, now in [('tapin:rate:ip:test', 2, -1), ('tapin:rate:ip:test', 2, 1998), ('tapin:rate:ip:test', 2, 1999), ('tapin:rate:ip:test', 3, 1999), ('tapin:rate:ip:test', 1, 2999)]:
    action('increment', [key, window, now]); action('read', [key, window, now])
files = ['security/rate_limiter.py', 'security/infra_memory.py', 'security/infra_starlette.py', 'security/config.py']
out = dict(source_hashes={name: hashlib.sha256((source/name).read_bytes()).hexdigest() for name in files}, route_cases=route_cases, actions=actions)
Path(sys.argv[2]).write_text(json.dumps(out, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(f'Executed {len(route_cases)} original route resolutions and {len(actions)} counter/limiter actions; no env or external access.')
