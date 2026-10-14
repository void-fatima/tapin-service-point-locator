/* Pure URL/header boundary tests; fetch captures calls and never uses the network. */
const assert=require('node:assert/strict');const path=require('node:path');const {readFileSync}=require('node:fs');
const config={restUrl:'https://wp.example/sub/wp-json/tapin-price-check/v1/',route:'/advanced',publicUrls:{single:'https://wp.example/sub/?page_id=11',bulk:'https://wp.example/sub/?page_id=12'},admin:false};
const location={href:'https://wp.example/sub/?page_id=11',assign(url){this.href=url;}};
global.window={TapinPriceUI:config,parent:{location,history:{pushState(a,b,url){location.href=url;},replaceState(a,b,url){location.href=url;}},addEventListener(){}},dispatchEvent(){}};
const calls=[];global.fetch=async(url,options)=>{calls.push({url,options});return{ok:true};};
(async()=>{const {restURL,request,pageURL,navigate,routeLocation}=await import('data:text/javascript;base64,'+readFileSync(path.resolve(__dirname,'../assets/price-check/wordpress.js')).toString('base64'));
assert.equal(restURL('/api/providers/sample?refresh=true'),'https://wp.example/sub/wp-json/tapin-price-check/v1/providers/sample?refresh=true');
assert.equal(restURL('/api/admin/cache/clear?kind=all',{restUrl:'https://wp.example/sub/?rest_route=/tapin-price-check/v1/'}),'https://wp.example/sub/?rest_route=%2Ftapin-price-check%2Fv1%2Fadmin%2Fcache%2Fclear&kind=all');
assert.throws(()=>restURL('https://external.invalid/api/admin/cache'));
await request('/api/price-check',{method:'POST',headers:{'X-WP-Nonce':'must-be-removed','X-Admin-Token':'must-be-removed'},body:'{}'});assert.equal(calls[0].options.headers.has('X-WP-Nonce'),false);assert.equal(calls[0].options.headers.has('X-Admin-Token'),false);assert.equal(calls[0].options.credentials,'same-origin');
assert.throws(()=>request('/api/admin/shops'));assert.throws(()=>pageURL('/preview/provider'));assert.throws(()=>pageURL('/admin/shops'));
assert.equal(pageURL('/bulk'),'https://wp.example/sub/?page_id=12');navigate('/advanced?provider=carrier');assert.deepEqual(routeLocation(),{pathname:'/advanced',search:'?provider=carrier'});
navigate('/bulk');assert.equal(location.href,'https://wp.example/sub/?page_id=12');
config.admin=true;config.nonce='test-rest-nonce';config.route='/admin/providers';config.adminUrls={providers:'https://wp.example/sub/wp-admin/admin.php?page=tapin-price-providers',shops:'https://wp.example/sub/wp-admin/admin.php?page=tapin-price-shops',security:'https://wp.example/sub/wp-admin/admin.php?page=tapin-price-security',bulk:'https://wp.example/sub/wp-admin/admin.php?page=tapin-price-bulk'};
for(const [method,suffix]of[['GET','shops'],['POST','shops/import'],['GET','shops/sample'],['POST','preview/price-check']]){await request('/api/admin/'+suffix,{method});assert.equal(calls.at(-1).options.headers.get('X-WP-Nonce'),'test-rest-nonce');}
const original=config.restUrl;config.restUrl='https://external.invalid/wp-json/tapin-price-check/v1/';assert.throws(()=>request('/api/admin/shops'));config.restUrl=original;
assert.equal(pageURL('/preview/provider'),'https://wp.example/sub/wp-admin/admin.php?page=tapin-price-providers&tapin_price_preview=provider');
navigate('/admin/providers/7');assert.deepEqual(routeLocation(),{pathname:'/admin/providers/7',search:''});navigate('/admin/security?sub=events');assert.deepEqual(routeLocation(),{pathname:'/admin/security',search:'?sub=events'});
console.log('Interface adapter: subdirectory/plain REST URLs, public/admin headers, navigation and preview authorization passed; no network.');
})().catch(error=>{console.error(error);process.exitCode=1;});
