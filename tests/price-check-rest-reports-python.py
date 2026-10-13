"""Original security report API over deterministic memory counters/events; no app/env/SQL."""
import ast, asyncio, hashlib, importlib, json, sys, types
from pathlib import Path
source=Path(sys.argv[1]).resolve();package=types.ModuleType('security');package.__path__=[str(source/'security')];sys.modules['security']=package
m=importlib.import_module('security.infra_memory');r=importlib.import_module('security.rate_limiter');config=importlib.import_module('security.config')
fixture=json.loads(Path(sys.argv[2]).read_text(encoding='utf-8'));catalog=fixture['catalog']['PUBLIC_API_CATALOG']
tree=ast.parse((source/'api.py').read_text(encoding='utf-8'))
names={'_route_limit_entry','normalize_public_route','admin_list_rate_limits','admin_list_repeat_offenders'}
nodes=[ast.ImportFrom(module='__future__',names=[ast.alias(name='annotations')],level=0)]+[n for n in tree.body if isinstance(n,(ast.FunctionDef,ast.AsyncFunctionDef)) and n.name in names]
class Response:
    def __init__(self,data,**kwargs):self.data=data
namespace=dict(PUBLIC_API_CATALOG=catalog,JSONResponse=Response,security_config=config)
exec(compile(ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[])),'original security report API','exec'),namespace)
module=types.ModuleType('security.infra_starlette');sys.modules['security.infra_starlette']=module
clock=m.ManualClock(125000);store=m.InMemoryRateLimitStore();overrides={}
def limits(route):return None if overrides.get(route) is False else (catalog.get(route,{}).get('default_max') or 4,60)
limiter=r.RateLimiter(store,clock,4,60,limits,namespace['normalize_public_route'])
seeds=[dict(key='tapin:rate:ip:/api/price-check:192.0.2.50',count=5,window=60,now=112000),dict(key='tapin:rate:ip:/api/providers/{provider_id}:192.0.2.50',count=61,window=60,now=124000)]
for seed in seeds:
    for _ in range(seed['count']):store.increment(seed['key'],seed['window'],seed['now'])
events=[dict(id=i+1,created_at_ms=at,kind='rate_limit_exceeded',ip=ip,client_id='',client_fingerprint='',route=route,detail='dimensions: ip') for i,(at,ip,route) in enumerate([(124999,'192.0.2.50','/api/price-check'),(124000,'192.0.2.50','/api/providers/3'),(110000,'192.0.2.50','/api/price-check'),(65000,'192.0.2.50','/api/price-check'),(60000,'192.0.2.50','/api/health'),(-1,'192.0.2.51','/unknown')])]
event_store=types.SimpleNamespace(list=lambda **kwargs:[types.SimpleNamespace(**e) for e in events][:max(1,min(kwargs.get('limit',200),1000))])
module.GUARD=types.SimpleNamespace(_clock=clock,repositories=lambda:(None,event_store,None),limiter=lambda:limiter,normalize_route=namespace['normalize_public_route'],limits_for_route=limits,effective_limits=lambda:(4,60))
cases=[]
for op in ['admin_list_rate_limits','admin_list_repeat_offenders']:
    for query in [{},{'q':'192.0.2.50','limit':'1','min':'1'},{'q':'missing','limit':'bad','min':'bad'},{'limit':'0','min':'-1'}]:
        cases.append(dict(op=op,query=query,result=asyncio.run(namespace[op](types.SimpleNamespace(query_params=query))).data))
entries=[dict(route=route,overrides={},result=namespace['_route_limit_entry'](route,{})) for route in catalog]
for override in [dict(enabled=False,max_requests=2,window_seconds=3),dict(enabled=True,max_requests=2,window_seconds=3)]:entries.append(dict(route='/api/price-check',overrides={'/api/price-check':override},result=namespace['_route_limit_entry']('/api/price-check',{'/api/price-check':override})))
out=dict(now=125000,seeds=seeds,events=events,cases=cases,entries=entries,api_sha256=hashlib.sha256((source/'api.py').read_bytes()).hexdigest())
Path(sys.argv[3]).write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n',encoding='utf-8');print('Executed 8 original security report cases and 10 catalog entries.')
