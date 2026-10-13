"""Execute original quote code with inert fixtures and forbidden live HTTP/database access."""
import asyncio
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import types
from urllib.error import HTTPError, URLError

source = Path(sys.argv[1]).resolve()
def forbidden(*args, **kwargs): raise AssertionError('Live access forbidden')
db = types.ModuleType('db'); db.connect = forbidden; sys.modules['db'] = db
storage = types.ModuleType('storage'); sys.modules['storage'] = storage
storage.get_shop = lambda key: {'id': key, 'token': ' inert-test-marker '} if key in ('01', '0', '1') else ({'token': False} if key == 'empty' else None)
storage.list_shops = lambda *args: []
for name in ('providers', 'net', 'provider_calls'):
    spec = importlib.util.spec_from_file_location(name, source / (name + '.py'))
    module = importlib.util.module_from_spec(spec); sys.modules[name] = module; spec.loader.exec_module(module)
    globals()[name] = module
net.urlopen = forbidden
def error_result(exc):
    return dict(ok=False, message=str(exc), status_code=exc.status_code, field=exc.field, endpoint=exc.endpoint, http_status=exc.http_status, provider_status=exc.provider_status, provider_message=exc.provider_message, provider_response=exc.provider_response, errors=exc.errors, extra=exc.extra)
cases = []
def capture(op, args, call):
    item = dict(op=op, args=args)
    try: item['expected'] = dict(ok=True, value=call())
    except provider_calls.ProviderCallError as exc: item['expected'] = error_result(exc)
    cases.append(item)

for name, payload, params in [('post', {'shop_id': '01', 'amount': 0, 'nested': [None, False, -0.0, 'آ/']}, []), ('irpost', {'shop_id': 0}, []), ('tipax', {'shop_id': 1.0}, []), ('POST', {'shop_id': 'missing'}, []), ('custom', {'shop_id': '01'}, [{'name': 'origin_city_id'}]), ('post', {}, []), ('post', {'shop_id': None}, []), ('post', {'shop_id': True}, []), ('post', {'shop_id': 1.5}, []), ('post', {'shop_id': ' '}, []), ('post', {'shop_id': 'missing'}, []), ('post', {'shop_id': 'empty'}, [])]:
    provider = dict(name=name, check_price_url='https://example.test/quote', params=params)
    def build(provider=provider, payload=payload):
        body, token = provider_calls.build_request(provider, payload)
        return dict(body=body, authorization=token == 'inert-test-marker' if token else False, wire=json.dumps(body, ensure_ascii=False))
    capture('request', [provider, payload], build)
for value in (None, '', 'relative', 'ftp://example.test', ' http://127.0.0.1:8080/path ', 'https://example.test:invalid', 'https://example.test:99999', 'HTTPS://example.test'):
    capture('endpoint', [value], lambda value=value: provider_calls.normalize_endpoint(value))

class Response:
    def __init__(self, status, raw): self.status, self.raw = status, raw
    def getcode(self): return self.status
    def read(self, size=-1): return self.raw if size < 0 else self.raw[:size]
    def __enter__(self): return self
    def __exit__(self, *args): return False
transport_cases = []
for mode in ('ok', 'http_error', 'timeout', 'network', 'oversize', 'http_error_oversize'):
    raw = b'{"total": 1}' if 'oversize' not in mode else b'x' * 2000001
    seen = []
    def opener(request, timeout):
        headers = dict(request.header_items()); authorized = headers.pop('Authorization', None) == 'inert-test-marker'
        seen.append(dict(url=request.full_url, method=request.get_method(), body=request.data.decode(), headers=headers, authorization=authorized, timeout=timeout))
        if mode == 'timeout': raise TimeoutError()
        if mode == 'network': raise URLError('fixture network unavailable')
        if mode.startswith('http_error'): raise HTTPError(request.full_url, 503, 'fixture', {}, io.BytesIO(raw))
        return Response(200, raw)
    net.urlopen = opener
    item = dict(mode=mode)
    try:
        status, body = provider_calls._post_json_sync('https://example.test/quote', {'amount': 1.0, 'text': 'آ'}, {'Authorization': 'inert-test-marker'}, 1.25)
        item['expected'] = dict(ok=True, status=status, bytes=len(body))
    except provider_calls.ProviderCallError as exc: item['expected'] = error_result(exc)
    item['request'] = seen[0]; transport_cases.append(item)
net.urlopen = forbidden
result = dict(provenance=dict(python=sys.version.split()[0], hashes={name: hashlib.sha256((source / (name + '.py')).read_bytes()).hexdigest() for name in ('providers', 'net', 'provider_calls')}, live_access=False), cases=cases, transport_cases=transport_cases)
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'Captured {len(cases)} pure request cases and {len(transport_cases)} original HTTP cases with mocked urlopen')
