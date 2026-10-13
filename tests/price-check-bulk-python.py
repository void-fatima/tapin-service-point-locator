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

source = Path(sys.argv[1]).resolve()
def forbidden(*args, **kwargs): raise AssertionError('Live access forbidden')
db = types.ModuleType('db'); db.connect = forbidden; sys.modules['db'] = db
storage = types.ModuleType('storage'); sys.modules['storage'] = storage
storage.list_shops = lambda: []
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
Path(sys.argv[2]).write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'Captured {len(cases)} original bulk helper cases; live access forbidden')
