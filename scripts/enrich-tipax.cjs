/* Verify each branch against its own official detail page; no name-based geocoding. */
const {chromium}=require('@playwright/test');
const fs=require('fs');
const quote=s=>'"'+String(s??'').replace(/"/g,'""')+'"';
(async()=>{
 const snapshot=JSON.parse(fs.readFileSync('artifacts/tipax-collected.json','utf8'));
 const browser=await chromium.launch({headless:true});
 const records=[],rejected=[];
 try{
  const context=await browser.newContext(),page=await context.newPage();
  for(const record of snapshot.records){
   try{
    const url=new URL(record.source);if(url.origin!=='https://tipaxco.com'||!url.pathname.startsWith('/branches/'))throw Error('unexpected source');
    const response=await context.request.get(url.href,{timeout:20000});if(!response.ok())throw Error('HTTP '+response.status());
    const detail=await page.evaluate(html=>{
     const doc=new DOMParser().parseFromString(html,'text/html');
     const text=suffix=>doc.querySelector('[id$="'+suffix+'"]')?.textContent.trim()||'';
     const code=text('__lblBranchCode'),address=text('__lblAdress'),postal=text('__lblPostalCode');
     const mainCode=doc.querySelector('[id$="__lblBranchCode"]');
     const table=mainCode?.closest('table');
     const phones=[...(table?.querySelectorAll('tr')||[])].filter(tr=>tr.cells[0]?.textContent.includes('تلفن تماس')).map(tr=>tr.cells[1]?.textContent.trim()||'').join(' ');
     const route=table?.querySelector('a[id$="__hlRouting"]')?.getAttribute('href');
     return {code,address,postal,phones,route};
    },await response.text());
    if(detail.code!==record.code||!detail.address)throw Error('branch identity mismatch');
    const coords=detail.route?new URL(detail.route).searchParams.get('destination')?.split(',').map(Number):null;
    const valid=coords?.length===2&&coords.every(Number.isFinite)&&coords[0]>=24&&coords[0]<=41&&coords[1]>=43&&coords[1]<=65;
    const phones=detail.phones.replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).match(/0[0-9]{10}/g)||[];
    records.push({...record,address:detail.address,postal_code:detail.postal||record.postal_code,latitude:valid?coords[0]:'',longitude:valid?coords[1]:'',mobile_phone:[...new Set(phones.filter(p=>p.startsWith('09')))].join(' / '),landline_phone:[...new Set(phones.filter(p=>!p.startsWith('09')))].join(' / ')});
   }catch(e){rejected.push({code:record.code,source:record.source,reason:e.message});}
   fs.writeFileSync('artifacts/tipax-verified.json',JSON.stringify({retrieved_at:new Date().toISOString(),records,rejected},null,2));
   if((records.length+rejected.length)%25===0)console.log(JSON.stringify({processed:records.length+rejected.length,accepted:records.length,rejected:rejected.length}));
   await page.waitForTimeout(250);
  }
  const columns=['code','name','province','city','address','postal_code','landline_phone','mobile_phone','latitude','longitude','source'];
  fs.writeFileSync('assets/data/tipax-tehran.csv',columns.join(',')+'\n'+records.map(r=>columns.map(c=>quote(r[c])).join(',')).join('\n')+'\n');
  console.log(JSON.stringify({accepted:records.length,located:records.filter(r=>r.latitude!=='').length,contacts:records.filter(r=>r.mobile_phone||r.landline_phone).length,rejected:rejected.length}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exit(1);});
