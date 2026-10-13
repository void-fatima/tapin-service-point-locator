"""Execute original quote code with inert fixtures and forbidden live HTTP/database access."""
import asyncio
import base64
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
response_cases = []
def response_case(op, args, call):
    before = len(cases); capture(op, args, call); response_cases.append(cases.pop()); assert len(cases) == before
for status, raw in [(200, b' '), (500, b'not JSON'), (200, b'null'), (503, b'{"returns":{"status":" 0 ","message":false},"entries":{"total":"15"}}'), (200, b'{"returns":{"status":true,"message":null},"total":0}'), (200, b'{"returns":{"status":1.5,"message":{"why":2}}}'), (200, b'{"total":1,"text":"\xff"}')]:
    response_case('parse', [status, base64.b64encode(raw).decode(), 'https://example.test'], lambda status=status, raw=raw: provider_calls.parse_response(status, raw, 'https://example.test'))
fields = [dict(key='entries.total', label='کل'), dict(key='data.-1.cost', label='هزینه'), dict(key='absent', label='ناقص')]
for body, configured in [({'total': 0, 'entries': {'total': 99, 'send_price': '1_000', 'tax': '۱.۵'}, 'send_price': None}, []), ([{'value': None, 'price': 7}, {'price': '0', 'label': None}, {'amount': '-0.0', 'label': False}, {'total': 1.0, 'name': 0}, 4], []), ({'entries': {'total': '15'}, 'data': [{'cost': False}, {'cost': '-5'}]}, fields), ({'entries': {'total': 'bad'}, 'data': [{'cost': None}]}, fields), ({'a': 1, 'data': [False, {'x': '۲_۰۰۰'}]}, [])]:
    response_case('extract', [body, configured], lambda body=body, configured=configured: provider_calls.extract_prices(body, configured))
    response_case('evaluate', [body, configured], lambda body=body, configured=configured: provider_calls.evaluate_prices(body, configured))
    response_case('candidates', [body], lambda body=body: provider_calls.numeric_candidates(body))
for value in (None, True, False, 0, -1, 1.0, -0.0, '', ' ', '1_000', '۱۲۰', '1.5', '1e2', '0', 'bad', '1,000', {}, []):
    response_case('number', [value], lambda value=value: provider_calls._as_number(value))
prices = [dict(label='shared', value=1), dict(label='shared', value=2), dict(label='other', value=3)]
for config in ({}, {'main_price_key': 'missing', 'price_fields': [{'key': 'x', 'label': 'shared'}]}, {'main_price_key': 'x', 'price_fields': [{'key': 'x', 'label': 'shared'}]}, {'main_price_key': 'x', 'price_fields': [{'key': 'x', 'label': 'not returned'}]}):
    response_case('main', [prices, config], lambda config=config: provider_calls._mark_main_price(prices, config))
for referral in (None, {}, {'enabled': False, 'title': 'T', 'url': 'https://example.test'}, {'enabled': True, 'title': '', 'url': 'https://example.test'}, {'enabled': True, 'title': ' T ', 'url': ' http://127.0.0.1:8080/ ', 'description': ' desc '}, {'enabled': True, 'title': 'T', 'url': 'ftp://example.test'}):
    response_case('referral', [dict(referral=referral)], lambda referral=referral: provider_calls.public_referral(dict(referral=referral)))
fetch_cases = []
for status, raw, config in [(200, '{"entries":{"total":"15"},"tax":-5}', {'price_fields': [{'key': 'entries.total', 'label': 'کل'}, {'key': 'tax', 'label': 'مالیات'}, {'key': 'missing', 'label': 'ناقص'}], 'price_currency_out': 'toman', 'main_price_key': 'tax'}), (503, '{"total":0}', {}), (200, '[{"price":1.5}]', {'price_currency_in': 'toman'}), (200, '{"other":1}', {'price_fields': [{'key': 'missing', 'label': 'ناقص'}]})]:
    provider = dict(id=1, name='fixture', display_name='عنوان', logo=None, params=[], check_price_url='https://example.test', **config)
    async def sender(url, body, headers, timeout, status=status, raw=raw): return status, raw.encode()
    item = dict(provider=provider, status=status, raw=raw)
    try:
        quote = asyncio.run(provider_calls.fetch_quote(provider, {}, transport=sender))
        item['expected'] = dict(ok=True, quote=quote, public=provider_calls.quote_result(provider, {}, quote))
    except provider_calls.ProviderCallError as exc: item['expected'] = error_result(exc)
    fetch_cases.append(item)
result = dict(provenance=dict(python=sys.version.split()[0], hashes={name: hashlib.sha256((source / (name + '.py')).read_bytes()).hexdigest() for name in ('providers', 'net', 'provider_calls')}, live_access=False), cases=cases, transport_cases=transport_cases)
result['response_cases'] = response_cases; result['fetch_cases'] = fetch_cases
result['representation_limits'] = [dict(op='parse', input=raw, python_kind=type(provider_calls.parse_response(200, raw.encode(), 'https://example.test')[0]).__name__) for raw in ('{"total":NaN}', '{"total":9223372036854775808}', '{"total":1,"text":"\\ud800"}')]
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'Captured {len(cases)} request, {len(transport_cases)} HTTP, {len(response_cases)} response and {len(fetch_cases)} fetch cases; live access forbidden')
