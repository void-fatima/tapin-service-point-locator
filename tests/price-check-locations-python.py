"""Original locations.py only, synthetic stores and forbidden live I/O; no server/env imports."""
import asyncio, base64, copy, hashlib, importlib.util, json, sys, types, unicodedata
from dataclasses import asdict
from pathlib import Path
source = Path(sys.argv[1]).resolve()
def forbidden(*args, **kwargs): raise AssertionError('Live access forbidden')
db = types.ModuleType('db'); db.connect = forbidden; sys.modules['db'] = db
storage = types.ModuleType('storage'); sys.modules['storage'] = storage
storage.get_shop = lambda key: own_shop(key)
storage.list_shops = lambda: shops()
storage.list_providers = lambda: []
storage.get_cache_settings = lambda: {'locations': 21600}
for name in ('providers', 'net', 'locations'):
    spec = importlib.util.spec_from_file_location(name, source / (name + '.py'))
    module = importlib.util.module_from_spec(spec); sys.modules[name] = module; spec.loader.exec_module(module); globals()[name] = module
net.urlopen = forbidden
cases = []
def capture(op, args, call):
    try: expected = dict(ok=True, value=call())
    except locations.LocationError as exc: expected = dict(ok=False, message=str(exc))
    cases.append(dict(op=op, args=copy.deepcopy(args), expected=expected))
fold = {chr(i): locations._canonical(chr(i)) for i in range(0x110000) if chr(i).casefold() != chr(i)}
table = Path(__file__).resolve().parents[1] / 'src/PriceCheck/unicode-casefold.json'
if table.exists(): assert json.loads(table.read_text(encoding='utf-8')) == fold, 'Python Unicode casefold version differs from checked-in port'
else: table.write_text(json.dumps(fold, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
for value in (None, True, False, 0, 1.0, 1.5, '', '0', ' 01 ', [], {}, 'A\x1cB', ' İ Straße Σς K ', 'POST', 'تهران'):
    for name in ('canonical', 'identifier', 'display_name', 'flag'):
        capture(name, [value], lambda name=name, value=value: getattr(locations, '_' + name)(value))
for value in ({'available': 'yes'}, {'is_available': 'bad', 'active': False}, {'enabled': {'is_active': 1}}, {'available': 2}, 'YES', 'n', '۱'):
    capture('flag', [value], lambda: locations._flag(value))
flat = [dict(pk=10, title='تهران', province_pk=1, province_title='اول', post=True, tipax=0), dict(pk=11, title='ری', province={'pk': 1, 'title': 'جدید'}, post=1, tipax={'available':'yes'}), dict(pk=20, title='دوم', state={'id':2,'name':'دوم'}, post=False, tipax=True)]
nested = [dict(pk='01', title='استان', post=True, cities=[dict(pk=1.0,title='شهر',post=False,tipax=True),dict(pk=2,title='دیگر'),None]),dict(pk=3,title='خالی',cities=[])]
responses = [(200,b' '),(200,b'bad'),(200,b'null'),(200,b'[]'),(200,b'{"entries":[]}')]
for payload in (flat, dict(cities=flat), dict(entries={'cities':flat},cities=nested), dict(list=nested), dict(data=[None,{'pk':None,'id':1,'title':'bad'},flat[0],flat[0]]), dict(entries=flat), dict(returns={'status':' 01 ','message':False},items=flat), dict(returns={'status':1.0,'message':None},data=flat), dict(returns={'status':True,'message':{'x':1}},data=flat)):
    responses.append((503,json.dumps(payload,ensure_ascii=False).encode()))
responses.append((200,b'{"cities":[{"pk":0,"title":"\xff","province_pk":0,"province_title":"zero","post":true}]}'))
for status, raw in responses:
    def decode():
        rows, ps, pm = locations._decode_all_cities(status, raw, locations.LOCATION_CITIES_URL)
        return [[asdict(row) for row in rows], ps, pm]
    capture('decode', [status,base64.b64encode(raw).decode()], decode)
records, _, _ = locations._decode_all_cities(200,json.dumps(flat,ensure_ascii=False).encode(),locations.LOCATION_CITIES_URL)
provider_rows = [dict(name='post',display_name='پست'),dict(name='tipax',display_name='تیپاکس'),dict(name='another',display_name='پست')]
for aliases in (['پست',' POST ','tipax','missing'],['post','post',None,True],[],['POST','پست']):
    capture('aliases', [aliases], lambda: locations._requested_aliases(aliases))
    try: aliases = locations._requested_aliases(aliases)
    except locations.LocationError: aliases = []
    targets = locations._provider_targets(aliases,provider_rows)
    capture('targets', [aliases,provider_rows], lambda: targets)
    capture('snapshot_data', [aliases,targets,[asdict(x) for x in records]], lambda: locations._build_snapshot_data(aliases,targets,records))
custom = dict(provinces=[dict(code='1',title='manual conflict'),dict(code=0,title='zero'),dict(code='3',title=' manual '),dict(code='3',title='duplicate')],cities=[dict(code='10',title='wrong',province_code='3'),dict(code='30',province_code='3'),dict(code='30',province_code='1'),dict(code='31',province_code='unknown')])
cp, cc = locations._custom_location_lists(custom)
capture('custom',[custom],lambda: (cp,cc))
bp = [dict(value='1',label='service'),dict(value='2',label='second')]; bc = {'1':[dict(value='10',label='first')],'2':[dict(value='20',label='second')]}
capture('merge',[bp,bc,cp,cc],lambda: locations._merge_location_lists(bp,bc,cp,cc))
for src in ('service','custom'):
    for mode in (None,'only','merge'):
        for basep,basec in ((bp,bc),([],{})):
            capture('dataset',[basep,basec,cp,cc,src,mode,'stale'],lambda:locations._resolve_section_dataset(basep,basec,cp,cc,src,mode,'stale'))
for blockedp, blockedc in (([],[]),(['1'],[]),([],['10']),([],['20']),(['9'],['missing'])):
    capture('blocked',[bp+[dict(value='3',label='province only')],bc,blockedp,blockedc],lambda:locations._apply_blocked_locations(bp+[dict(value='3',label='province only')],bc,set(blockedp),set(blockedc)))
for p,c in (([],[]),([dict(name='p'),dict(name='origin_province_id')],[dict(name='c'),dict(name='origin_city_id'),dict(name='extra')]),([dict(name='origin_province_id')],[])):
    capture('pairs',[p,c],lambda:locations._location_pairs(p,c))
groups = {'empty':[], '1':[dict(value='10',label='first')]}
capture('blocked',[bp,groups,[],[]],lambda:locations._apply_blocked_locations(bp,groups,set(),set()))
limits = []
for raw in ('{"cities":[{"pk":9223372036854775808,"title":"T","province_pk":1,"province_title":"P","post":true}]}', '{"ignored":NaN,"cities":'+json.dumps(flat)+'}', '{"cities":[{"pk":1,"title":"\\ud800","province_pk":1,"province_title":"P","post":true}]}'):
    try: locations._decode_all_cities(200,raw.encode(),locations.LOCATION_CITIES_URL); kind='decoded'
    except locations.LocationError: kind='LocationError'
    limits.append(dict(raw=raw,python=kind))
result = dict(provenance=dict(python=sys.version.split()[0],unicode=unicodedata.unidata_version,live_access=False,hashes={name:hashlib.sha256((source/(name+'.py')).read_bytes()).hexdigest() for name in ('locations','net','providers')}),casefold=fold,cases=cases,limits=limits,flat=flat,providers=provider_rows,custom=custom)
Path(sys.argv[2]).write_text(json.dumps(result,ensure_ascii=False,indent=2,allow_nan=False),encoding='utf-8')
print(f'Captured {len(cases)} original location helpers and {len(fold)} original Unicode casefold cases; live access forbidden')
