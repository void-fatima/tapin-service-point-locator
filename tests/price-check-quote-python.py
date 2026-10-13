"""Execute original quote code with inert fixtures and forbidden live HTTP/database access."""
import asyncio
import ast
import base64
import copy
from collections.abc import Mapping
from datetime import datetime, timezone
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import types
from urllib.error import HTTPError, URLError
from urllib.request import Request, HTTPRedirectHandler
from urllib.parse import urlparse, urlunparse, urljoin, quote as quote_url
import string

source = Path(sys.argv[1]).resolve()
def forbidden(*args, **kwargs): raise AssertionError('Live access forbidden')
db = types.ModuleType('db'); db.connect = forbidden; sys.modules['db'] = db
storage = types.ModuleType('storage'); sys.modules['storage'] = storage
storage.get_shop = lambda key: {'id': key, 'token': ' inert-test-marker '} if key in ('01', '02', '0', '1') else ({'token': False} if key == 'empty' else None)
storage.list_shops = lambda *args: list_shops()
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

# Compile only the single-quote functions. Never import api/server/environment/security bootstrap.
tree = ast.parse((source / 'api.py').read_text(encoding='utf-8'))
names = {'ApiError', '_log_params', '_log_quote', '_cache_key', '_provider_timeout', '_strip_main_flags', '_with_current_main', '_quote_with_cache', '_body', '_provider_or_404', '_public_error', 'check_price'}
nodes = [ast.ImportFrom(module='__future__', names=[ast.alias(name='annotations')], level=0)] + [node for node in tree.body if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)) and node.name in names]
class JsonResponse:
    def __init__(self, data, status_code=200):
        json.dumps(data, ensure_ascii=False, allow_nan=False).encode('utf-8')
        self.data, self.status_code = data, status_code
async def guard(*args): return None
api = dict(json=json, hashlib=hashlib, Mapping=Mapping, Any=object, providers=providers, provider_calls=provider_calls, storage=storage, JSONResponse=JsonResponse, _guard=guard, _finish=lambda response, security: response, NO_PRICE_DETAIL='سرویس در حال حاضر پاسخگو نیست.')
exec(compile(ast.fix_missing_locations(ast.Module(body=nodes, type_ignores=[])), 'original single-quote api functions', 'exec'), api)
spec = importlib.util.spec_from_file_location('records', source / 'records.py'); records = importlib.util.module_from_spec(spec); sys.modules['records'] = records; spec.loader.exec_module(records)
def configured(origin=False, **changes):
    raw = dict(id=1, name='post', display_name='عنوان', check_price_url='https://example.test/quote', shop_id='01', location_config={'origin': {'enabled': origin}}, params=[{'name': 'shop_id', 'visible': False}, {'name': 'amount', 'type': 'float', 'visible': True, 'required': True, 'kind': 'money', 'default': 0}, {'name': 'weight', 'type': 'float', 'visible': True, 'kind': 'weight', 'default': 1}], price_fields=[{'key': 'entries.total', 'label': 'کل'}, {'key': 'entries.tax', 'label': 'مالیات'}], price_currency_out='toman', weight_unit_out='kilogram')
    raw.update(changes); return records.normalise(raw)
base_provider = configured(); origin_provider = configured(True)
shops = [{'id': '01', 'title': 'اول', 'province': '1', 'city': '1'}, {'id': '02', 'title': 'دوم', 'province': '1', 'city': '2'}]
def list_shops():
    if state['failure'] == 'shops': state['failure'] = None; raise RuntimeError('fixture unavailable')
    return copy.deepcopy(state['shops'])
def fail(kind):
    if state['failure'] == kind: state['failure'] = None; raise RuntimeError('fixture unavailable')
def lookup(identifier):
    fail('provider')
    return copy.deepcopy(state['provider']) if identifier in ('1', '01', state['provider']['name']) else None
providers.get_provider = lookup
def cache_get(provider_id, key):
    fail('cache_get'); entry = state['cache'].get(key)
    if entry and (state['now'] - datetime.fromisoformat(entry['created_at'])).total_seconds() > 10: del state['cache'][key]; entry = None
    return copy.deepcopy(entry)
def fresh(created_at): return (state['now'] - datetime.fromisoformat(created_at)).total_seconds() < 2
def cache_save(record):
    fail('cache_save'); state['cache'][record['params_hash']] = dict(created_at=state['now'].isoformat(timespec='seconds'), provider_name=record['provider_name'], params=copy.deepcopy(record['params']), endpoint=record['endpoint'], quote=copy.deepcopy(record['quote']))
def log_save(record): fail('log_save'); state['logs'].append(copy.deepcopy(record)); return len(state['logs'])
storage.get_price_cache = cache_get; storage.price_cache_is_fresh = fresh; storage.save_price_cache = cache_save; storage.save_quote_log = log_save
async def single_send(url, body, headers, timeout):
    state['requests'].append(dict(url=url, body=copy.deepcopy(body), authorization=headers.get('Authorization') == 'inert-test-marker', timeout=timeout))
    if state['mode'] == 'timeout': raise provider_calls.ProviderCallError('سرویس قیمت در زمان مقرر پاسخ نداد.', status_code=504, endpoint=url)
    if state['mode'] == 'network': raise provider_calls.ProviderCallError('ارتباط با سرویس قیمت برقرار نشد.', endpoint=url)
    return state['status'], state['raw'].encode()
provider_calls._post_json = single_send
single_cases = []
def action(body=None, **extra): return dict(body=json.dumps(body, ensure_ascii=False) if body is not None else '', **extra)
def single(name, actions, provider=None, **extra): single_cases.append(dict(name=name, provider=copy.deepcopy(provider or base_provider), actions=actions, **extra))
single('success alias units and main refresh', [action({'provider': 1, 'values': {'amount': '1.5', 'weight': 1}}), action({'provider': 'post', 'payload': {'amount': '1.5', 'weight': 1}}, update={'main_price_key': 'entries.tax'}), action({'provider': 'post', 'values': {'amount': 1.5, 'weight': 1}}, update={'check_price_url': None})])
single('body and provider validation order', [dict(body='bad'), dict(body='[]'), dict(body='null'), action(), action({'provider': 'missing', 'values': []}), action({'provider': 1, 'values': []}), action({'provider': 1, 'values': None}), action({'provider': None}, query='post'), action({'provider': 0}, query='post')])
single('inactive precedes invalid values', [action({'provider': 1, 'values': []})], provider=configured(active=False))
single('values alias absence null precedence', [action({'provider': 1, 'values': None, 'payload': {'amount': 2}}), action({'provider': 1, 'values': [], 'payload': {}}), action({'provider': 1, 'payload': {'amount': ''}}), action({'provider': 1, 'values': {'amount': True, 'weight': 'bad'}})])
for mode in ('timeout', 'network'):
    single('stale ' + mode, [action({'provider': 1}), action({'provider': 1}, now='2026-01-20T12:00:02+00:00', mode=mode), action({'provider': 1}, now='2026-01-20T12:00:11+00:00', mode=mode)])
for raw in ('bad', '', 'null', '{"other":1}'):
    single('failed response ' + repr(raw), [action({'provider': 1}, raw=raw, status=503)])
single('HTTP error prices succeed', [action({'provider': 1}, status=503)])
single('missing endpoint', [action({'provider': 1})], provider=configured(check_price_url=None))
single('missing shop', [action({'provider': 1})], provider=configured(shop_id='missing'))
single('missing shop credential', [action({'provider': 1})], provider=configured(shop_id='empty'))
single('no configured prices failure', [action({'provider': 1}, raw='{"other":1}')], provider=configured(price_fields=[]))
for failure in ('provider', 'cache_get', 'cache_save', 'log_save'):
    single('failure ' + failure, [action({'provider': 1}, failure=failure, mode='network' if failure == 'log_save' else 'ok')])
single('origin exact fallback and default metadata', [action({'provider': 1, 'values': {'origin_province_id': 1, 'origin_city_id': 2}}), action({'provider': 1, 'values': {'origin_province_id': 1, 'origin_city_id': 9}}), action({'provider': 1})], provider=origin_provider)
single('origin missing shops', [action({'provider': 1, 'values': {'origin_province_id': 2}})], provider=origin_provider)
single('origin shop read failures and prior errors', [action({'provider': 1, 'values': {'amount': 'bad', 'origin_province_id': 1}}, failure='shops', sanitized_shop_error=True), action({'provider': 1}, failure='shops')], provider=origin_provider)
single('corrupted retained quote', [action({'provider': 1}), action({'provider': 1}, corrupt_quote='truncated')])
single('configured timeout', [action({'provider': 1})], provider=configured(timeout=1.25))
single('UTF8 BOM request', [dict(body='\ufeff' + json.dumps({'provider': 1}))])
single('non-finite public output rejected', [action({'provider': 1}, raw='{"entries":{"total":"NaN","tax":0}}', failure='cache_save')], provider=configured(price_currency_out='rial'))
for item in single_cases:
    state = dict(provider=copy.deepcopy(item['provider']), shops=copy.deepcopy(shops), now=datetime(2026, 1, 20, 12, tzinfo=timezone.utc), cache={}, logs=[], requests=[], failure=None, mode='ok', raw='{"entries":{"total":"15","tax":0}}', status=200)
    for step in item['actions']:
        if 'now' in step: state['now'] = datetime.fromisoformat(step['now'])
        state['provider'].update(step.get('update', {})); state['failure'] = step.get('failure'); state['mode'] = step.get('mode', 'ok'); state['raw'] = step.get('raw', '{"entries":{"total":"15","tax":0}}'); state['status'] = step.get('status', 200)
        if 'corrupt_quote' in step:
            for entry in state['cache'].values(): entry['quote'] = step['corrupt_quote']
        request = types.SimpleNamespace(scope={}, query_params={'provider': step.get('query')})
        async def request_body(step=step): return step['body'].encode()
        request.body = request_body
        try:
            response = asyncio.run(api['check_price'](request)); step['expected'] = dict(status=response.status_code, body=response.data)
        except api['ApiError'] as exc: step['expected'] = dict(status=exc.status_code, body=dict(**exc.extra, detail=exc.detail, errors=exc.errors))
        except Exception: step['expected'] = dict(status=500, body={'detail': 'internal error'})
        step['state'] = copy.deepcopy({key: state[key] for key in ('cache', 'logs', 'requests')})
result['single_cases'] = single_cases
for name in ('api', 'records'): result['provenance']['hashes'][name] = hashlib.sha256((source / (name + '.py')).read_bytes()).hexdigest()
result['timeout_cases'] = [dict(input=value, expected=api['_provider_timeout']({'timeout': value})) for value in (None, False, 0, -1, 'bad', '1.25', 300)]
result['request_encoding_limits'] = []
for raw in ('{"provider":1}'.encode('utf-16'), '{"provider":1}'.encode('utf-32'), b'{"provider":"\xff"}'):
    request = types.SimpleNamespace(scope={})
    async def request_body(raw=raw): return raw
    request.body = request_body
    try: expected = dict(kind='parsed', value=asyncio.run(api['_body'](request)))
    except Exception as exc: expected = dict(kind=type(exc).__name__)
    result['request_encoding_limits'].append(dict(input_b64=base64.b64encode(raw).decode(), python=expected))
result['redirect_cases'] = []
for method, status, target in [(method, status, 'https://example.test/next') for method in ('POST', 'GET') for status in (301, 302, 303, 307, 308)] + [('POST', 302, 'https://other.test/')]:
    request = Request('https://example.test/quote', data=b'{}' if method == 'POST' else None, method=method, headers={'Content-Type': 'application/json', 'Authorization': 'inert-test-marker'})
    try:
        redirected = HTTPRedirectHandler().redirect_request(request, None, status, 'fixture', {}, target)
        expected = dict(follow=True, method=redirected.get_method(), body=redirected.data, authorization=redirected.get_header('Authorization') == 'inert-test-marker', content_type=redirected.get_header('Content-type'))
    except HTTPError: expected = dict(follow=False)
    result['redirect_cases'].append(dict(method=method, status=status, target=target, expected=expected))
result['redirect_url_cases'] = []
base = 'https://example.test/a/quote?q=1#fragment'
for location in ('0', '?0', '#0', '../next', './same', ';new', '//other.test', '/root', 'https://other.test?x', '?', '#', '', '/x;', '../', '/a/../b', 'a b', '/caf\xe9'):
    parts = urlparse(location)
    if not parts.path and parts.netloc: parts = parts._replace(path='/')
    normalized = quote_url(urlunparse(parts), encoding='iso-8859-1', safe=string.punctuation)
    result['redirect_url_cases'].append(dict(location_b64=base64.b64encode(location.encode('iso-8859-1')).decode(), base=base, expected=urljoin(base, normalized)))
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'Captured {len(cases)} request, {len(transport_cases)} HTTP, {len(response_cases)} response, {len(fetch_cases)} fetch and {sum(len(item["actions"]) for item in single_cases)} single actions; live access forbidden')
