"""Selected original API presentation with injected providers/locations; no app/env/SQL/network."""
import ast, asyncio, copy, hashlib, importlib.util, json, sys, types
from pathlib import Path
from collections.abc import Mapping
source=Path(sys.argv[1]).resolve()
db=types.ModuleType('db'); db.connect=lambda: (_ for _ in ()).throw(AssertionError('DB forbidden')); sys.modules['db']=db
storage=types.ModuleType('storage'); sys.modules['storage']=storage
storage.list_shops=lambda: []
for name in ['providers','records']:
    spec=importlib.util.spec_from_file_location(name,source/(name+'.py')); module=importlib.util.module_from_spec(spec); sys.modules[name]=module; spec.loader.exec_module(module); globals()[name]=module
tree=ast.parse((source/'api.py').read_text(encoding='utf-8'))
catalog={n.target.id: ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.AnnAssign) and isinstance(n.target,ast.Name) and n.target.id in ('PUBLIC_API_CATALOG','ADMIN_API_GROUPS')}
routes=[]
for n in tree.body:
    if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='routes' for t in n.targets):
        for call in n.value.elts: routes.append(dict(path=ast.literal_eval(call.args[0]),operation=call.args[1].id,method=next((ast.literal_eval(k.value)[0] for k in call.keywords if k.arg=='methods'),'GET')))
class Response:
    def __init__(self,data,status_code=200,**kwargs): self.data=data; self.status=status_code
async def section(provider,refresh=False): return sections.get(provider['name'])
locations=types.SimpleNamespace(provider_location_section=section)
# Binding merge functions are executed independently in Stage 8; use a fixture with no sections here.
locations.merge_origin_bindings=lambda sections: dict(status='unavailable',message='لیست شهر/استان در دسترس نیست.',provinces=[],cities_by_province={})
locations.merge_bulk_location_bindings=locations.merge_origin_bindings
names={'ApiError','_schema_with_locations','_admin_schema_with_locations','list_providers','_bulk_schema_data','_section_or_none','_bulk_fields_with_kinds','_bulk_effective_destination','_bulk_price_for_form','_bulk_weight_for_form','_bulk_price_unit','_bulk_weight_unit'}
nodes=[ast.ImportFrom(module='__future__',names=[ast.alias(name='annotations')],level=0)]+[n for n in tree.body if isinstance(n,(ast.FunctionDef,ast.AsyncFunctionDef,ast.ClassDef)) and n.name in names]
async def guard(*args): return None
namespace=dict(providers=providers,records=records,storage=storage,locations=locations,asyncio=asyncio,Mapping=Mapping,JSONResponse=Response,_guard=guard,_finish=lambda response,result: response)
exec(compile(ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[])),'selected original API schemas','exec'),namespace)
raw=dict(id=1,name='one',display_name='One',check_price_url='https://example.test/one',location_config={'origin':{'enabled':False}},params=[dict(id=11,name='amount',input='number',type='float',visible=True,default=0),dict(id=12,name='mode',input='select',visible=True,options=[dict(value=1,label='first')]),dict(id=13,name='rows',input='list',fields=[dict(id=131,name='cost',type='float',visible=True)])])
base=records.normalise(raw); cases=[]; sections={}
for changes in [{},{'active':False},{'price_currency_out':'تومان','weight_unit_out':'kg'},{'rules':[dict(name='private name',if_field='11',if_op='eq',if_value=1)]}]:
    record=base|changes
    cases.append(dict(op='public',provider=record,result=records.public_schema(record)))
    cases.append(dict(op='admin',provider=record,result=records.admin_schema(record)))
providers.get_providers=lambda: plist
storage.list_shops=lambda: [dict(id='01',title='origin',province=None,city=None,has_token=1)]
for changes in [{},{'providers':[]},{'price':{'field':'removed','unit':'تومان'},'weight':{'field':'amount','unit':'kg'}},{'location':{'origin':{'enabled':False}}}]:
    plist=[base,base|dict(id=2,name='inactive',active=False)]; config=dict(providers=[2,1],fields=[dict(name='amount')],mappings={'1':{'amount':'11'}})|changes
    result=asyncio.run(namespace['_bulk_schema_data'](config)); cases.append(dict(op='bulk',providers=plist,config=config,shops=storage.list_shops(),result=result))
out=dict(catalog=catalog,routes=routes,cases=cases,source_hashes={name:hashlib.sha256((source/name).read_bytes()).hexdigest() for name in ('api.py','records.py','providers.py')})
Path(sys.argv[2]).write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(f'Executed {len(cases)} original schema cases; captured {len(routes)} source method/path entries.')
