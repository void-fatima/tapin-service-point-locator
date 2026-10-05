/* Real PHP API -> generated repository SQL -> isolated SQLite -> actual PHP response formatting.
 * Requires Node 22.13+ (node:sqlite); set TAPIN_PHP to a PHP 8.1+ CLI.
 * No WordPress session/database or live writes are used.
 */
const {execFileSync}=require('node:child_process');
const {DatabaseSync}=require('node:sqlite');
const assert=require('node:assert/strict');
const path=require('node:path');
const php=process.env.TAPIN_PHP||'php';
const db=new DatabaseSync(':memory:');
db.exec("CREATE TABLE qa_tapin_providers(id INTEGER, is_active INTEGER, name TEXT, slug TEXT); INSERT INTO qa_tapin_providers VALUES(1,1,'پست','post'),(2,1,'تیپاکس','tipax'),(3,0,'غیرفعال','inactive'); CREATE TABLE qa_tapin_service_points(id INTEGER, provider_id INTEGER, name TEXT, code TEXT, province TEXT, city TEXT, address TEXT, postal_code TEXT, phone TEXT, mobile_phone TEXT, landline_phone TEXT, latitude REAL, longitude REAL, has_coordinates INTEGER, status TEXT, metadata TEXT, created_at TEXT, updated_at TEXT)");
const insert=db.prepare("INSERT INTO qa_tapin_service_points VALUES(?,?,?,NULL,'تهران','تهران','QA only',NULL,NULL,NULL,NULL,?,?,?,'active',NULL,NULL,NULL)");
// Mirrors the reported five address-only Post / five located Tipax mix with synthetic IDs.
for(let i=1;i<=10;i++)insert.run(i,i<=5?1:2,'Branch '+i,i<=5?null:35.7,i<=5?null:51.4,i<=5?0:1);
function api(params={},options={}) {
  const input={params,...options};
  const run=data=>JSON.parse(execFileSync(php,[path.join(__dirname,'public-directory-query.php')],{input:JSON.stringify(data),encoding:'utf8'}));
  const plan=run(input);
  const rows=db.prepare(plan.queries.rows).all();
  const total=Object.values(db.prepare(plan.queries.count).get())[0];
  const response=run({...input,rows,total});
  assert.deepEqual(response.queries,plan.queries);
  return response.result;
}
function ids(result){assert.equal(result.items.length,result.total);return result.items.map(p=>p.id);}
assert.deepEqual(ids(api()),[1,2,3,4,5,6,7,8,9,10]);
assert.deepEqual(ids(api({has_coordinates:'0'})),[1,2,3,4,5]);
assert.deepEqual(ids(api({has_coordinates:'1'})),[6,7,8,9,10]);
assert.deepEqual(ids(api({has_coordinates:''})),[1,2,3,4,5,6,7,8,9,10]);
assert.deepEqual(ids(api({has_coordinates:'0',provider_id:'2'})),[]);
assert.deepEqual(ids(api({has_coordinates:'1',provider_id:'2',province:'تهران',city:'تهران',search:'Branch 6'})),[6]);
assert.deepEqual(ids(api({has_coordinates:'0'},{directory:false})),[6,7,8,9,10]);
insert.run(11,1,'Stale null',null,null,1);
insert.run(12,1,'Invalid latitude',91,51.4,1);
insert.run(13,1,'Invalid longitude',35.7,181,1);
insert.run(14,1,'Partial coordinates',35.7,null,1);
insert.run(15,2,'Stale flag',35.7,51.4,0);
insert.run(16,2,'Zero coordinates',0,0,0);
assert.deepEqual(ids(api({has_coordinates:'0'})),[1,2,3,4,5,11,12,13,14]);
const located=api({has_coordinates:'1'});
assert.deepEqual(ids(located),[6,7,8,9,10,15,16]);
assert(located.items.every(p=>p.has_coordinates));
assert(api({has_coordinates:'0'}).items.every(p=>!p.has_coordinates));
assert.deepEqual(ids(api({north:'36',south:'35',east:'52',west:'51'},{directory:false})),[6,7,8,9,10,15]);
const paginated=api({has_coordinates:'0',per_page:'2',page:'2'});
assert.equal(paginated.total,9);assert.equal(paginated.total_pages,5);assert.deepEqual(paginated.items.map(p=>p.id),[3,4]);
// Existing admin availability semantics are untouched, even for stale flags.
assert.deepEqual(ids(api({has_coordinates:'0'},{admin:true})),[1,2,3,4,5,15,16]);
db.exec("UPDATE qa_tapin_service_points SET status='inactive' WHERE id=6; INSERT INTO qa_tapin_service_points SELECT 17,3,'Inactive provider',code,province,city,address,postal_code,phone,mobile_phone,landline_phone,latitude,longitude,has_coordinates,status,metadata,created_at,updated_at FROM qa_tapin_service_points WHERE id=7");
assert(!ids(api()).includes(6));assert(!ids(api()).includes(17));
console.log('PASS public directory: real API/query SQL, all/located/missing, combined filters, reset, invalid/null/stale coordinates, bounds, pagination/counts, public visibility and unchanged admin semantics. SQLite is isolated; MySQL/WordPress installation is not verified.');
db.close();
