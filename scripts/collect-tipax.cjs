/* Maintainer-only source snapshot. Never runs inside WordPress or during activation. */
const {chromium}=require('@playwright/test');
const fs=require('fs');
const normalize=s=>String(s).replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s‌]+/g,'').trim();
const quote=s=>'"'+String(s??'').replace(/"/g,'""')+'"';
(async()=>{
 const browser=await chromium.launch({headless:true});
 const records=[], rejected=[];
 try{
  const page=await browser.newPage();
  await page.goto('https://tipaxco.com/branches/standardpoint',{waitUntil:'domcontentloaded',timeout:60000});
  await page.getByText('تهران',{exact:true}).first().click();
  await page.locator('input[value="تهران"]').last().waitFor();
  await page.locator('input[value="تهران"]').last().click();
  const buttons=page.locator('input[id*="_btnAgency"]');await buttons.first().waitFor();
  const choices=await buttons.evaluateAll(xs=>xs.map(x=>({id:x.id,name:x.value})));
  const snapshot=()=>{fs.writeFileSync('artifacts/tipax-collected.json',JSON.stringify({retrieved_at:new Date().toISOString(),records,rejected},null,2));};
  for(const choice of choices){
   try{
    await page.locator('#'+choice.id).click();
    await page.waitForFunction(name=>document.querySelector('#_dvAgencyDetail')?.textContent.replace(/\s/g,'').includes(name.replace(/\s/g,'')),choice.name,{timeout:15000});
    await page.waitForTimeout(700);
    const detail=await page.locator('#_dvAgencyDetail').evaluate(el=>({text:el.innerText,source:[...el.querySelectorAll('a')].find(a=>a.textContent.includes('اطلاعات بیشتر'))?.href}));
    const markers=await page.evaluate(()=>{const el=document.querySelector('[data-role="map"]');return el?window.jQuery(el).data('kendoMap')?.options.markers:[];});
    const matches=(markers||[]).filter(m=>normalize(m.tooltip?.content)===normalize(choice.name));
    const lines=detail.text.split('\n').map(s=>s.trim()).filter(Boolean);
    const postal=lines.findIndex(s=>s.startsWith('کد پستی'));
    const code=lines.find(s=>/^کد\s*:/.test(s))?.split(':').slice(1).join(':').trim();
    const address=postal>=0?lines[postal+1]:'';
    if(!code||!address||!detail.source||address==='اطلاعات بیشتر')throw Error('missing required source fields');
    // The source repeats the selected marker. Identical positions are not ambiguity.
    const positions=[...new Map(matches.map(m=>[JSON.stringify(m.location),m.location])).values()];
    const position=positions.length===1?positions[0]:null;
    const valid=position&&position.length===2&&position.every(Number.isFinite)&&position[0]>=24&&position[0]<=41&&position[1]>=43&&position[1]<=65;
    records.push({code,name:choice.name.trim(),province:'تهران',city:'تهران',address,postal_code:lines[postal].split(':').slice(1).join(':').trim(),landline_phone:'',mobile_phone:'',latitude:valid?position[0]:'',longitude:valid?position[1]:'',source:detail.source});
   }catch(e){rejected.push({name:choice.name,reason:e.message.slice(0,100)});}
   snapshot();if((records.length+rejected.length)%20===0)console.log(JSON.stringify({processed:records.length+rejected.length,total:choices.length,accepted:records.length,rejected:rejected.length}));
  }
  const columns=['code','name','province','city','address','postal_code','landline_phone','mobile_phone','latitude','longitude','source'];
  fs.writeFileSync('assets/data/tipax-tehran.csv',columns.join(',')+'\n'+records.map(r=>columns.map(c=>quote(r[c])).join(',')).join('\n')+'\n');
  console.log(JSON.stringify({accepted:records.length,located:records.filter(r=>r.latitude!=='').length,rejected:rejected.length}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exit(1);});
