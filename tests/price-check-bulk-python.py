"""Run selected original bulk code with synthetic records; no server/env/DB/HTTP imports."""
import ast
import asyncio
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import types
from collections.abc import Mapping
from datetime import datetime, timezone

source = Path(sys.argv[1]).resolve()
def forbidden(*args, **kwargs): raise AssertionError('Live access forbidden')
db = types.ModuleType('db'); db.connect = forbidden; sys.modules['db'] = db
storage = types.ModuleType('storage'); sys.modules['storage'] = storage
storage.list_shops = lambda: list_shops()
for name in ('providers', 'net', 'provider_calls', 'records'):
    spec = importlib.util.spec_from_file_location(name, source / (name + '.py'))
    module = importlib.util.module_from_spec(spec); sys.modules[name] = module; spec.loader.exec_module(module)
    globals()[name] = module
net.urlopen = forbidden; provider_calls._post_json = forbidden
tree = ast.parse((source / 'api.py').read_text(encoding='utf-8'))
names = {'ApiError', '_bulk_mirror_targets', '_bulk_source_routes', '_bulk_translated_value', '_bulk_price_unit', '_bulk_weight_unit', '_bulk_price_in_provider_unit', '_bulk_weight_in_provider_unit', '_bulk_incoming', '_bulk_variant_payload', '_bulk_variant_detail', '_bulk_is_blank', '_bulk_missing_required', '_bulk_shared_field', '_bulk_fields_with_kinds', '_bulk_effective_destination', '_bulk_dest_served'}
nodes = [ast.ImportFrom(module='__future__', names=[ast.alias(name='annotations')], level=0)] + [node for node in tree.body if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)) and node.name in names]
api = dict(Mapping=Mapping, providers=providers, records=records, provider_calls=provider_calls)
exec(compile(ast.fix_missing_locations(ast.Module(body=nodes, type_ignores=[])), 'original bulk helpers', 'exec'), api)

def configured(pid, name, **changes):
    raw = dict(id=pid, name=name, display_name=name + ' عنوان', check_price_url='https://example.test/' + name,
        location_config={'origin': {'enabled': False}}, params=[
            dict(id=11, name='amount', label='مبلغ', type='float', kind='money', required=True, visible=True, default=0),
            dict(id=12, name='weight', label='وزن', type='float', kind='weight', visible=True, default=1),
            dict(id=13, name='mode', label='نوع', input='select', visible=True, default='a', options=[dict(value='a', label='اول'), dict(value='b', label='دوم')]),
            dict(id=14, name='rows', label='ردیف', input='list', visible=True, min_items=1, fields=[dict(id=141, name='cost', label='هزینه', type='float', visible=True, default=0), dict(id=142, name='kind', label='گونه', input='select', visible=True, options=[dict(value='a', label='اول')])]),
            dict(id=15, name='obj', label='شیء', input='dict', visible=True, fields=[dict(id=151, name='city', label='شهر', visible=True, default='')]),
            dict(id=16, name='province', label='استان', input='select', visible=True, kind='province'), dict(id=17, name='city', label='شهر', input='select', visible=True, kind='city')],
        price_fields=[dict(key='total', label='کل')], price_currency_out='toman', weight_unit_out='kilogram')
    if pid == 2: raw['params'] = [param for param in raw['params'] if param['name'] != 'rows']
    raw.update(changes); return records.normalise(raw)
p1 = configured(1, 'one'); p2 = configured(2, 'two', price_currency_out='rial', weight_unit_out='gram'); p3 = configured(3, 'inactive', active=False)
plist = [p1, p2, p3]
config = dict(providers=[2, 1, 3, 2], fields=[dict(name='money', label='مبلغ', required=True), dict(name='mass'), dict(name='mode', source={'provider': 1, 'param': 13}), dict(name='cost'), dict(name='town'), dict(name='prov', kind='province'), dict(name='dest', kind='city')], mappings={'1': {'money': '11', 'mass': '12', 'mode': '14.142', 'cost': '14.141', 'town': '15.151', 'prov': '16', 'dest': '17'}, '2': {'money': '11', 'mass': '12', 'mode': '13', 'prov': '16', 'dest': '17'}}, value_mappings={'1': {'mode': {'shared': 'a', 'False': 'b', '0': None}}, '2': {'mode': {'shared': 'b'}}}, price={'field': 'money', 'unit': 'rial'}, weight={'field': 'mass', 'unit': 'gram'}, variants={'1': {'13': ['a', 'b'], '14.141': ['2', '3']}})
cases = []
def capture(op, args, fn):
    try: expected = dict(ok=True, value=fn())
    except Exception as exc: expected = dict(ok=False, kind=type(exc).__name__)
    cases.append(dict(op=op, args=copy.deepcopy(args), expected=expected))
for kind in ('mirror', 'source'):
    modified = copy.deepcopy(config); modified['fields'][2][kind] = {'provider': 'one', 'param': 13}
    capture('routes', [modified, plist, kind], lambda: api['_bulk_' + ('mirror_targets' if kind == 'mirror' else 'source_routes')](modified, plist))
for ref in (None, {}, {'provider': 1}, {'provider': '01'}, {'provider': 'one'}, {'provider': True}, {'provider': 1.0}, {'provider': 'missing'}):
    capture('provider_id', [ref, plist], lambda: records.bulk_field_provider_id(ref, plist))
for val in (None, False, 0, 'shared', 'unknown', '', []):
    capture('translated', [config, 1, 'mode', val], lambda: api['_bulk_translated_value'](config, 1, 'mode', val))
for provider in (p1, p2, configured(4, 'alias', price_currency_out='تومان')):
    for val in (None, '', '15', '-15', 0, False):
        cfg = copy.deepcopy(config); cfg['mappings'][str(provider['id'])] = cfg['mappings']['1']
        values = dict(money=val, mass='1500', mode='shared', cost=7, town='تهران', origin_province_id=1)
        mirror = api['_bulk_mirror_targets'](cfg, plist); routes = api['_bulk_source_routes'](cfg, plist)
        capture('incoming', [provider, values, cfg, mirror, plist, routes], lambda: api['_bulk_incoming'](provider, values, cfg, mirror, plist, routes))
mirrorconfig = copy.deepcopy(config); mirrorconfig['fields'][2]['mirror'] = {'provider': 1, 'param': 13}
capture('incoming', [p1, {'mode': 'shared'}, mirrorconfig, api['_bulk_mirror_targets'](mirrorconfig, plist), plist], lambda: api['_bulk_incoming'](p1, {'mode': 'shared'}, mirrorconfig, api['_bulk_mirror_targets'](mirrorconfig, plist), plist))
for incoming in ({}, {'rows': []}, {'rows': [{'cost': 8}, {'cost': 9}]}, {'rows': {'cost': 8}}, {'rows': 2}):
    variant = {'14.141': '3', '13': 'b', 'missing': False}
    capture('variant_payload', [p1, incoming, variant], lambda: api['_bulk_variant_payload'](p1, incoming, variant))
capture('variant_detail', [p1, {'13': 'a', '14.142': 'a', '14.141': None, 'missing': False}], lambda: api['_bulk_variant_detail'](p1, {'13': 'a', '14.142': 'a', '14.141': None, 'missing': False}))
for value in (None, '', ' \t', False, 0, [], {}):
    capture('missing_required', [config, {'money': value}], lambda: api['_bulk_missing_required'](config, {'money': value}))
for target in ('mode', 'rows.kind', 'rows.cost', 'obj.city', 'unknown'):
    capture('shared_field', [p1, target, config, plist], lambda: api['_bulk_shared_field'](p1, target, config, plist))
section = dict(bindings={'origin_province_id': {'kind': 'province', 'items': [{'value': 9}]}, 'province': {'kind': 'province', 'items': [{'value': 1}, {'value': 2}]}, 'city': {'kind': 'city', 'items_by_parent': {'1': [{'value': 10}], '2': [{'value': 20}], 'ignored': {}}}})
for sec in (None, {}, section, dict(bindings={'city': section['bindings']['city']})):
    for province, city in ((None, None), (' ', ''), (1, 10), (1, 20), (3, 10), (None, 20), (2, None), (False, 10)):
        capture('destination_served', [sec, province, city], lambda: api['_bulk_dest_served'](sec, province, city))
for cfg in (config, dict(config, location={'destination': {'enabled': False}}), dict(config, location={'destination': {'enabled': 0, 'province_field': 'bad', 'city_field': 'bad'}})):
    original_fields = api['_bulk_fields_with_kinds'](cfg, plist, records.bulk_active_providers(cfg, plist), {p['id']: p for p in plist})
    capture('destination', [cfg, original_fields], lambda: api['_bulk_effective_destination'](cfg, original_fields))
    capture('inferred_destination', [cfg, plist], lambda: api['_bulk_effective_destination'](cfg, original_fields))
inferred = dict(config, fields=[dict(name='prov', mirror={'provider': 1, 'param': 16}), dict(name='dest', source={'provider': 2, 'param': 17})])
for cfg in (inferred, dict(config, fields=[dict(name='prov'), dict(name='dest')])):
    fields = api['_bulk_fields_with_kinds'](cfg, plist, records.bulk_active_providers(cfg, plist), {p['id']: p for p in plist})
    capture('inferred_destination', [cfg, plist], lambda: api['_bulk_effective_destination'](cfg, fields))

# Reference repeated fixture inputs to keep source-derived evidence reviewable.
inputs = dict(one=p1, two=p2, inactive=p3, providers=plist, config=config, section=section)
def compact(value):
    for key, original in inputs.items():
        if type(value) is type(original) and value == original: return {'$fixture': key}
    if isinstance(value, dict): return {key: compact(item) for key, item in value.items()}
    if isinstance(value, list): return [compact(item) for item in value]
    return value
for case in cases: case['args'] = compact(case['args'])
result = dict(provenance=dict(python=sys.version.split()[0], live_access=False, hashes={name: hashlib.sha256((source / (name + '.py')).read_bytes()).hexdigest() for name in ('api', 'records', 'providers', 'provider_calls')}), cases=cases, inputs=inputs, providers=plist, config=config, section=section)

# Execute the original orchestration, response pipeline and concurrent gather with mocked I/O.
flow_names = {'_run_bulk_provider', '_bulk_check_results', '_bulk_validate_outcome', '_public_bulk_error', '_log_params', '_log_quote', '_cache_key', '_provider_timeout', '_strip_main_flags', '_with_current_main', '_quote_with_cache', '_body', '_preview_config', '_preview_values', 'bulk_check', 'bulk_validate', 'preview_bulk_check', 'preview_bulk_validate'}
nodes = [ast.ImportFrom(module='__future__', names=[ast.alias(name='annotations')], level=0)] + [node for node in tree.body if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)) and node.name in flow_names]
class JsonResponse:
    def __init__(self, data, status_code=200):
        json.dumps(data, ensure_ascii=False, allow_nan=False)
        self.data, self.status_code = data, status_code
async def guard(*args): return None
async def snapshot(provider):
    state['sections'].append(provider['id'])
    if state['failure'] == 'section': raise RuntimeError('fixture unavailable')
    return copy.deepcopy(state['datasets'].get(str(provider['id'])))
api.update(json=json, hashlib=hashlib, asyncio=asyncio, storage=storage, JSONResponse=JsonResponse, _guard=guard,
    _finish=lambda response, security: response, _require_admin_token=lambda request: None, _section_or_none=snapshot,
    NO_PRICE_DETAIL='سرویس در حال حاضر پاسخگو نیست.', UNSERVED_DESTINATION_DETAIL='این شرکت به مقصد انتخاب‌شده سرویس نمی‌دهد.')
exec(compile(ast.fix_missing_locations(ast.Module(body=nodes, type_ignores=[])), 'original bulk flow', 'exec'), api)
def fail(kind):
    if state['failure'] == kind: state['failure'] = None; raise RuntimeError('fixture unavailable')
def provider_list(): fail('providers'); return copy.deepcopy(state['providers'])
def stored_config(): fail('config'); return copy.deepcopy(state['config'])
providers.get_providers = provider_list; storage.get_bulk_config = stored_config
def list_shops(): fail('shops'); return copy.deepcopy(state['shops'])
storage.list_shops = list_shops
storage.get_shop = lambda identifier: {'id': identifier, 'token': 'inert-test-marker'} if identifier == '01' else None
def cache_get(pid, key):
    fail('cache_get'); state['cache_reads'] += 1; identity = f'{pid}:{key}'; entry = state['cache'].get(identity)
    if entry and (state['now'] - datetime.fromisoformat(entry['created_at'])).total_seconds() > 10: del state['cache'][identity]; entry = None
    return copy.deepcopy(entry)
def cache_save(record):
    fail('cache_save'); state['cache_writes'] += 1
    state['cache'][f'{record["provider_id"]}:{record["params_hash"]}'] = dict(created_at=state['now'].isoformat(timespec='seconds'), provider_name=record['provider_name'], params=copy.deepcopy(record['params']), endpoint=record['endpoint'], quote=copy.deepcopy(record['quote']))
def log_save(record): fail('log_save'); state['logs'].append(copy.deepcopy(record))
storage.get_price_cache = cache_get; storage.save_price_cache = cache_save; storage.save_quote_log = log_save
storage.price_cache_is_fresh = lambda stamp: (state['now'] - datetime.fromisoformat(stamp)).total_seconds() < 2
async def sender(url, body, headers, timeout):
    name = url.rsplit('/', 1)[-1]; reply = state['replies'].get(name, {})
    state['requests'].append(dict(url=url, body=copy.deepcopy(body), authorization=headers.get('Authorization') == 'inert-test-marker', timeout=timeout))
    # Always suspend once, as source asyncio.to_thread does: all misses precede live writes.
    for _ in range(1 + reply.get('delay', 0)): await asyncio.sleep(0)
    if reply.get('mode') == 'timeout': raise provider_calls.ProviderCallError('سرویس قیمت در زمان مقرر پاسخ نداد.', status_code=504, endpoint=url)
    if reply.get('mode') == 'network': raise provider_calls.ProviderCallError('ارتباط با سرویس قیمت برقرار نشد.', endpoint=url)
    if reply.get('mode') == 'unexpected': raise RuntimeError('private fixture failure')
    state['completed'].append(name)
    return reply.get('status', 200), reply.get('raw', '{"total":"15"}').encode()
provider_calls._post_json = sender
flows = []
def action(values=None, **extra): return dict(body=json.dumps({'values': values if values is not None else {'money': '15', 'mass': '1500', 'mode': 'shared', 'cost': 7, 'town': 'T'}}, ensure_ascii=False), **extra)
def flow(name, actions, cfg=None, provider_rows=None): flows.append(dict(name=name, config=copy.deepcopy(config if cfg is None else cfg), providers=copy.deepcopy(plist if provider_rows is None else provider_rows), actions=actions))
flow('multi success variants units cache and completion order', [action(replies={'two': {'delay': 3}}), action(), action(now='2026-01-20T12:00:02+00:00', replies={'one': {'mode': 'timeout'}, 'two': {'mode': 'timeout'}}), action(now='2026-01-20T12:00:11+00:00', replies={'one': {'mode': 'timeout'}, 'two': {'mode': 'timeout'}})])
flow('partial no price then all malformed failures', [action(replies={'two': {'raw': '{"other":1}'}}), action(now='2026-01-20T12:00:03+00:00', replies={'two': {'raw': 'bad'}, 'one': {'mode': 'network'}})])
flow('unknown select values follow provider source rules', [action({'money': 15, 'mode': 'invalid', 'cost': 1}), action({'money': False})])
flow('provider rule validation fails independently', [action(), action(validate=True)], provider_rows=[p1, configured(2, 'two', rules=[dict(if_field='amount', if_op='gte', if_value=20, message='حداقل مبلغ')]), p3])
flow('body and values order', [dict(body='bad'), dict(body='[]'), dict(body='null'), dict(body=''), dict(body='{"values":[]}'), dict(body='{"payload":{"money":15}}'), dict(body='{"values":null}')])
for malformed in (None, {}):
    flow('malformed stored field ' + repr(malformed), [action()], cfg=dict(config, fields=[malformed]))
flow('inactive and no active precede missing required', [action({}), action({'money': 1})], cfg=dict(config, providers=[3]))
flow('absent variant list fails while other provider succeeds', [action({'money': 15})])
flow('destination before invalid payload and no logs', [action({'money': True, 'prov': 1, 'dest': 20}, datasets={'1': section, '2': section}), action({'money': 15, 'prov': 1, 'dest': 10}, datasets={'1': section}), action({'money': 15, 'prov': 3, 'dest': 10}, datasets={})])
flow('destination disabled still reads sections', [action({'money': 15, 'prov': 9, 'dest': 9}, datasets={'1': section, '2': section})], cfg=dict(config, location={'destination': {'enabled': False}}))
flow('preflight has no variants coverage cache or HTTP', [action({'money': 'bad', 'mode': 'invalid', 'cost': 'bad', 'prov': 9, 'dest': 9}, validate=True, datasets={'1': section, '2': section}), action({}, validate=True)])
flow('mirrors win translations and explicit mappings', [action({'money': 15, 'mode': 'a'})], cfg=mirrorconfig)
draft = copy.deepcopy(config); draft['value_mappings'] = {'1': {'mode': {'a': 'a'}}, '2': {'mode': {'a': 'b'}}}; draft['providers'] = [1, 2]; draft['location'] = {'destination': {'enabled': False}}; draft['variants'] = {}
def preview(values=None, **extra):
    step = action(values, preview=True, **extra); body = json.loads(step['body']); body['config'] = copy.deepcopy(draft)
    if values is None: body['values']['mode'] = 'a'
    step['body'] = json.dumps(body, ensure_ascii=False); return step
flow('draft previews bypass existing cache and logs', [action(), preview(replies={'one': {'raw': '{"other":1}'}}), preview(), preview({'money': 'bad'}, validate=True)])
flow('preview draft errors precede values errors', [dict(body='{"config":null,"values":[]}', preview=True), dict(body='{"config":{"providers":["missing"]},"values":[]}', preview=True)])
alias = preview(); alias_body = json.loads(alias['body']); alias_body['payload'] = alias_body.pop('values'); alias['body'] = json.dumps(alias_body, ensure_ascii=False)
flow('preview payload alias and explicit null precedence', [alias, dict(body=json.dumps(dict(config=draft, values=None, payload={'money': 1})), preview=True)])
variant_draft = copy.deepcopy(draft); variant_draft['fields'][2].pop('source'); variant_draft['mappings']['1'].pop('mode'); variant_draft['value_mappings'].pop('1'); variant_draft['variants'] = {'1': {'13': ['a', 'b']}}
variant_step = preview(); variant_body = json.loads(variant_step['body']); variant_body['config'] = variant_draft; variant_step['body'] = json.dumps(variant_body, ensure_ascii=False)
flow('valid unsaved variants with stored providers', [variant_step])
stored_variants = copy.deepcopy(variant_draft); stored_variants['providers'] = [2, 1]; stored_variants['mappings']['1']['mode'] = '14.142'; stored_variants['mappings']['1'].pop('cost'); stored_variants['variants']['1']['14.141'] = ['2', '3']
stored_variants = records.normalise_bulk_config(stored_variants, plist)
flow('valid normalized stored Cartesian variants', [action({'money': 15, 'mass': 1500, 'mode': 'a'})], cfg=stored_variants)
no_active_draft = dict(draft, providers=[3]); flow('preview required before active but validate active first', [dict(body=json.dumps(dict(config=no_active_draft, values={})), preview=True), dict(body=json.dumps(dict(config=no_active_draft, values={})), preview=True, validate=True)])
for kind in ('config', 'providers', 'cache_get', 'cache_save', 'log_save', 'section'):
    flow('failure ' + kind, [action(failure=kind, replies={'one': {'mode': 'network'}} if kind == 'log_save' else {})])
flow('HTTP status alone does not fail', [action(replies={'one': {'status': 503}, 'two': {'status': 500}})])
duplicate = dict(config, variants={'1': {'13': ['a', 'a']}})
flow('duplicate payload cache misses are concurrent', [action(), action()], cfg=duplicate)
flow('empty variant choices reset then expand', [action()], cfg=dict(config, variants={'1': {'13': [], '14.141': ['3', '4']}}))
flow('origin shop selection uses shared origin selectors', [action({'money': 15, 'cost': 1, 'origin_province_id': 1, 'origin_city_id': 1})], provider_rows=[configured(1, 'post', shop_id='01', location_config={'origin': {'enabled': True}})], cfg=dict(config, providers=[1], variants={}))
flow('unexpected carrier failure remains global 500', [action(replies={'two': {'mode': 'unexpected'}})], cfg=dict(config, variants={}))
flow('nonfinite result remains global serialization failure', [action(failure='cache_save', replies={'two': {'raw': '{"total":"NaN"}'}})], cfg=dict(config, providers=[2], variants={}))
for item in flows:
    state = dict(providers=copy.deepcopy(item['providers']), config=copy.deepcopy(item['config']), shops=[dict(id='01', title='first', province='1', city='1'), dict(id='02', title='second', province='1', city='2')], now=datetime(2026, 1, 20, 12, tzinfo=timezone.utc), cache={}, logs=[], requests=[], sections=[], completed=[], cache_reads=0, cache_writes=0, failure=None)
    for step in item['actions']:
        state['failure'] = step.get('failure'); state['replies'] = step.get('replies', {}); state['datasets'] = step.get('datasets', {})
        if 'now' in step: state['now'] = datetime.fromisoformat(step['now'])
        request = types.SimpleNamespace(scope={})
        async def body(step=step): return step['body'].encode()
        request.body = body
        handler = ('preview_' if step.get('preview') else '') + ('bulk_validate' if step.get('validate') else 'bulk_check')
        try:
            response = asyncio.run(api[handler](request)); step['expected'] = dict(status=response.status_code, body=response.data)
        except api['ApiError'] as exc: step['expected'] = dict(status=exc.status_code, body=dict(**exc.extra, detail=exc.detail, errors=exc.errors))
        except Exception: step['expected'] = dict(status=500, body={'detail': 'internal error'})
        step['state'] = copy.deepcopy({key: state[key] for key in ('cache', 'logs', 'requests', 'sections', 'completed', 'cache_reads', 'cache_writes')})
for item in flows:
    item['providers'] = compact(item['providers']); item['config'] = compact(item['config'])
result['flows'] = compact(flows)
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False), encoding='utf-8')
print(f'Captured {len(cases)} original helpers and {sum(len(item["actions"]) for item in flows)} original bulk actions; mocked concurrent HTTP only')
