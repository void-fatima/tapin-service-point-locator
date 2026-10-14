"""Original import-preview handlers with workbook-reader doubles (openpyxl not installed)."""
import ast,asyncio,hashlib,io,json,sys,types
from pathlib import Path
from collections.abc import Mapping
source=Path(sys.argv[1]).resolve();tree=ast.parse((source/'api.py').read_text(encoding='utf-8'))
constants={'SHOP_IMPORT_COLUMNS','SHOP_IMPORT_HEADERS','MAX_UPLOAD_BYTES','LOCATION_PROVINCE_SHEETS','LOCATION_CITY_SHEETS','LOCATION_HEADER_WORDS'}
names={'ApiError','_ensure_upload_size','_location_sheet_name','_location_is_header','_location_cell','admin_import_shops','admin_locations_import'}
nodes=[ast.ImportFrom(module='__future__',names=[ast.alias(name='annotations')],level=0)]+[n for n in tree.body if (isinstance(n,(ast.FunctionDef,ast.AsyncFunctionDef,ast.ClassDef)) and n.name in names) or (isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id in constants for t in n.targets))]
storage_tree=ast.parse((source/'storage.py').read_text(encoding='utf-8'));clean=next(n for n in storage_tree.body if isinstance(n,ast.FunctionDef) and n.name=='clean_shop_row');storage=types.SimpleNamespace();env=dict(Mapping=Mapping,Any=object)
exec(compile(ast.Module(body=[clean],type_ignores=[]),'original clean_shop_row','exec'),env);storage.clean_shop_row=env['clean_shop_row']
class Response:
    def __init__(self,data,**kwargs):self.data=data
namespace=dict(storage=storage,records=types.SimpleNamespace(MAX_LOCATION_CODE_CHARS=30,MAX_CUSTOM_PROVINCES=500,MAX_CUSTOM_CITIES=20000),_require_admin_token=lambda request:None,JSONResponse=Response,io=io)
exec(compile(ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[])),'original workbook preview handlers','exec'),namespace)
class Sheet:
    def __init__(self,s):self.title=s['title'];self.rows=s['rows']
    def iter_rows(self,**kwargs):return self.rows
module=types.ModuleType('openpyxl');sys.modules['openpyxl']=module
class Upload:
    async def read(self):return b'synthetic-workbook'
class Request:
    async def form(self):return {'file':Upload()}
cases=[]
books=[dict(active=0,sheets=[dict(title='shops',rows=[['id','title','province','city','token'],['01',' branch ',None,'0','TOKEN'],[True,'bool',1.0,False,'TOKEN'],['02',None,1,2,'TOKEN'],['03','no token',1,2,None],[]])]),dict(active=0,sheets=[dict(title='sheet',rows=[[],['01','leading blank',None,None,'TOKEN']])]),dict(active=1,sheets=[dict(title='ignored',rows=[['wrong']]),dict(title='shops',rows=[['101','active',None,None,'TOKEN']])])]
locations=[dict(active=0,sheets=[dict(title='استان‌ها',rows=[[],['code','title'],['1','One'],['1','Duplicate'],[None,'Missing'],['0',None],[True,'boolean']]),dict(title='cities',rows=[['code','title','province_code'],['10','Town','1'],['10','dupe','1'],['20','Bad',None],['20','Good','1'],['x'*31,'Long','1']])]),dict(active=0,sheets=[dict(title='unnamed',rows=[['code','title','province_code'],['1','Town','1']])]),dict(active=0,sheets=[dict(title='sheet',rows=[['0',False],['2',None]])]),dict(active=0,sheets=[dict(title='other',rows=[['1']]),dict(title='unknown',rows=[['2']])])]
for kind,series in [('shops',books),('locations',locations)]:
    for book in series:
        sheets=[Sheet(s) for s in book['sheets']];module.load_workbook=lambda *args,**kwargs:types.SimpleNamespace(worksheets=sheets,active=sheets[book['active']])
        try:result=asyncio.run(namespace['admin_import_shops' if kind=='shops' else 'admin_locations_import'](Request())).data;case=dict(kind=kind,book=book,result=result)
        except namespace['ApiError'] as error:case=dict(kind=kind,book=book,status=error.status_code,error=dict(detail=error.detail,errors=error.errors))
        cases.append(case)
out=dict(cases=cases,api_sha256=hashlib.sha256((source/'api.py').read_bytes()).hexdigest(),storage_sha256=hashlib.sha256((source/'storage.py').read_bytes()).hexdigest(),decoder_executed=False)
Path(sys.argv[2]).write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n',encoding='utf-8');print('Executed 7 original workbook row-preview cases; original openpyxl byte decoding unavailable.')
