"""Execute original pure security code only. No env, application startup, SQL or HTTP."""
import ast
from dataclasses import asdict
import hashlib
import importlib
import json
from pathlib import Path
import sys
import types

source = Path(sys.argv[1]).resolve()
package = types.ModuleType('security'); package.__path__ = [str(source / 'security')]
sys.modules['security'] = package  # never execute its framework-bootstrap __init__
f = importlib.import_module('security.fingerprint')
c = importlib.import_module('security.canonical')
m = importlib.import_module('security.infra_memory')
a = importlib.import_module('security.allowlist')

cases = []
def capture(kind, args, call):
    try: result = call(); cases.append(dict(kind=kind, args=args, result=result))
    except Exception as error: cases.append(dict(kind=kind, args=args, error=type(error).__name__, message=str(error)))

for raw in [None, False, True, '', ' ', '0', 123, 'A'*32, ' '+ 'a0'*16 +' ', 'a'*31, 'a'*33, [], {}, ['a'], '\u00a0'+ 'B'*32 +'\u001c']:
    capture('client_id', [raw], lambda: [f.normalize_client_id(raw), f.client_id_was_forged(raw)])
for raw in [None, [], False, {}, {'unknown': 1}, {'languages': [None, ' en ', '', True, 2]}, {'screenWidth': '۰۰۱۲', 'devicePixelRatio': '01.2500', 'deviceMemory': '-0.0'}, {'platform': {'a': 1}, 'timezone': '  =\n\\  '}, {'userAgent': 'Mozilla', 'language': 'fa', 'screenWidth': 1920, 'screenHeight': 1080, 'devicePixelRatio': 1.5}]:
    def browser():
        signals, malformed = f.browser_signals_from(raw); fp, doc = f.browser_fingerprint(signals)
        return [fp, dict(zip(f.BROWSER_FIELD_ORDER, asdict(signals).values())), malformed, f.signals_present(signals), doc]
    capture('browser', [raw], browser)
for raw in [None, {}, [], [True, None, 'a=b\n\\\r'], {'z': 1, 'a': {'b': [2, {}, None]}, 'é': 'é', 'آ': 'فارسی'}, {'f': -0.0, 'tiny': 1e-7, 'big': 1e23, 'round': 0.1+0.2}, 42, True, 'a=b']:
    capture('document', [raw], lambda: c.canonical_document(raw))
    capture('request', [raw], lambda: list(f.request_hash(raw)))
for raw in [None, True, '', ' 192.0.2.1 ', '192.168.001.1', '192.0.2.1:abc', '[::1]:443', '2001:DB8:0:0:1:0:0:1', '::ffff:192.0.2.1', 'FE80::1%ETH0', '::1%', '1.2.3.4/24', 'unknown', 123]:
    capture('ip', [raw], lambda: a.normalize_ip(raw))
    if isinstance(raw, str): capture('address', [raw], lambda: m._valid_ip(raw))
for trusted in [[], ['10.0.0.0/8'], ['10.2.3.4'], ['2001:db8::/32'], ['10.0.0.0/255.0.0.0'], ['invalid'], ['::ffff:192.0.2.1']]:
    for direct in ['10.2.3.4:443', '198.51.100.1', '[2001:db8::1]:80', '::ffff:192.0.2.1', 'invalid']:
        for headers in [{'X-Forwarded-For': 'unknown, 192.0.2.5, 192.0.2.6'}, {'x-real-ip': '[::1]:42'}, {'Forwarded': 'for=unknown;proto=https, for="[2001:db8::5]:80"'}, {'x-forwarded-for': 'invalid', 'x-real-ip': 'invalid', 'forwarded': 'for=192.0.2.9'}]:
            capture('resolve', [trusted, False, direct, headers], lambda: m.StaticProxyIpResolver(trusted).resolve(direct, headers))
capture('resolve', [[], True, 'invalid', {'x-forwarded-for': '192.0.2.5'}], lambda: m.StaticProxyIpResolver([], True).resolve('invalid', {'x-forwarded-for': '192.0.2.5'}))
capture('client', ['a'*32, 'b'*64], lambda: f.client_fingerprint('a'*32, 'b'*64))
capture('context', ['b'*64, '::1', 'agent\n', '/api/price-check', 'c'*64], lambda: f.request_fingerprint('b'*64, '::1', 'agent\n', '/api/price-check', 'c'*64))
files = ['security/config.py', 'security/canonical.py', 'security/fingerprint.py', 'security/models.py', 'security/interfaces.py', 'security/infra_memory.py', 'security/allowlist.py']
out = dict(source_hashes={name: hashlib.sha256((source/name).read_bytes()).hexdigest() for name in files}, helpers=cases)
Path(sys.argv[2]).write_text(json.dumps(out, ensure_ascii=False, indent=2, allow_nan=False)+'\n', encoding='utf-8')
print(f'Executed {len(cases)} original security helper cases; no startup, env, SQL or network.')
