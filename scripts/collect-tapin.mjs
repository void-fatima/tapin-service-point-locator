/** Official PDF extraction into a review queue, never directly into WordPress. */
import fs from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import {createHash} from 'node:crypto';
import {getDocument} from 'pdfjs-dist/legacy/build/pdf.mjs';

export const provinces={kermanshah:'کرمانشاه',khouzestan:'خوزستان',alborz:'البرز',fars:'فارس',isfahan:'اصفهان',boushehr:'بوشهر',hormozgan:'هرمزگان',kohgilooye:'کهگیلویه و بویراحمد',charmahal:'چهارمحال و بختیاری',yazd:'یزد',kerman:'کرمان',qom:'قم',tehran:'تهران',mazandaran:'مازندران',lorestan:'لرستان',ilam:'ایلام',kordestan:'کردستان',markazi:'مرکزی',hamedan:'همدان',qazvin:'قزوین',zanjan:'زنجان','khorasan-shomali':'خراسان شمالی',semnan:'سمنان',golestan:'گلستان','azarbaijan-qarbi':'آذربایجان غربی','azarbaijan-sharqi':'آذربایجان شرقی',ardebil:'اردبیل',gilan:'گیلان','khorasan-jonoubi':'خراسان جنوبی','khorasan-razavi':'خراسان رضوی',sistan:'سیستان و بلوچستان'};
export const normalize=text=>text.normalize('NFKC').replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/ھ/g,'ه').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/\s+/g,' ').trim();
export function discover(html){return [...new Set([...html.matchAll(/href\s*=\s*["']([^"']+\.pdf\s*)["']/gi)].map(m=>new URL(m[1].trim(),'https://tapin.ir/map/').href).filter(u=>/^https:\/\/tapin\.ir\/map\/[a-z0-9-]+\.pdf$/.test(u)))];}

/** Column positions are inferred from each page's headers, not province-specific offsets. */
export async function extract(bytes,url){
  const slug=new URL(url).pathname.split('/').pop().replace('.pdf','');
  if(!provinces[slug])throw Error('Unknown province document; review its identity first: '+url);
  const task=getDocument({data:new Uint8Array(bytes),useSystemFonts:true,isEvalSupported:false});
  const doc=await task.promise;
  const candidates=[],issues=[];
  for(let n=1;n<=doc.numPages;n++){
    const page=await doc.getPage(n),content=await page.getTextContent();
    const items=content.items.filter(i=>i.str?.trim()).map(i=>({text:normalize(i.str),x:i.transform[4]+i.width/2,y:i.transform[5]}));
    const header=items.find(i=>i.text.includes('ردیف'));
    const rowHeader=header?items.filter(i=>Math.abs(i.y-header.y)<4):[];
    const columns=[['row',/ردیف/],['name',/نام|دفتر|واحد/],['address',/آدرس|نشانی/],['postal_code',/پستی/],['landline_phone',/تلفن/]].map(([field,pattern])=>({field,item:rowHeader.find(i=>pattern.test(i.text))}));
    if(columns.some(c=>!c.item)){issues.push({page:n,reason:'Unsupported or missing table headers'});continue;}
    const ordered=columns.sort((a,b)=>a.item.x-b.item.x);
    const anchors=items.filter(i=>i.y<header.y-5&&/^\d{1,3}$/.test(i.text)&&Math.abs(i.x-columns.find(c=>c.field==='row').item.x)<20).sort((a,b)=>b.y-a.y);
    if(!anchors.length){issues.push({page:n,reason:'No reliable row anchors'});continue;}
    anchors.forEach((anchor,index)=>{
      const next=anchors[index+1];
      const cells=Object.fromEntries(columns.map(c=>[c.field,[]]));
      items.filter(i=>i.y<=anchor.y+4&&i.y>(next?next.y+4:0)).forEach(i=>{
        const col=ordered.reduce((best,c)=>Math.abs(c.item.x-i.x)<Math.abs(best.item.x-i.x)?c:best,ordered[0]);cells[col.field].push(i);
      });
      const row=Object.fromEntries(Object.entries(cells).map(([key,values])=>[key,values.sort((a,b)=>Math.abs(a.y-b.y)>3?b.y-a.y:b.x-a.x).map(v=>v.text).join(' ')]));
      row.landline_phone=row.landline_phone.replace(/(?<=\d)[ -](?=\d)/g,'');
      if(!row.name||!row.address||!/^\d{10}$/.test(row.postal_code)||!/^0[1-8]\d{9}$/.test(row.landline_phone)){
        issues.push({page:n,row:anchor.text,reason:'Missing or ambiguous fields; manual review required',extracted:row});return;
      }
      candidates.push({name:row.name,province:provinces[slug],city:'',address:row.address.replace(/[()（][^()）]*(?:آقای|اقای|خانم)[^()）]*[()）]/g,'').trim(),postal_code:row.postal_code,landline_phone:row.landline_phone,latitude:null,longitude:null,source:url,metadata:{source_provider:'tapin',source_type:'official_postal_directory',source_url:url,source_checked_at:new Date().toISOString(),source_page:n,source_row:anchor.text,source_sha256:createHash('sha256').update(bytes).digest('hex')}});
    });
  }
  await task.destroy();return {url,province:provinces[slug],candidates,issues};
}
async function download(url){const r=await fetch(url,{signal:AbortSignal.timeout(45000),redirect:'error'});if(!r.ok)throw Error(url+': HTTP '+r.status);const bytes=Buffer.from(await r.arrayBuffer());if(bytes.length>20*1024*1024)throw Error('Document exceeds 20 MB');return bytes;}
async function main(){
  const html=(await download('https://tapin.ir/map/')).toString('utf8');
  const urls=discover(html),requested=process.argv[2],out='artifacts/tapin-directory';await fs.mkdir(out,{recursive:true});
  await fs.writeFile(path.join(out,'catalog.json'),JSON.stringify({checked_at:new Date().toISOString(),urls},null,2));
  for(const url of urls.filter(u=>!requested||u.endsWith('/'+requested+'.pdf'))){
    const slug=new URL(url).pathname.split('/').pop();
    try{const bytes=await download(url);await fs.writeFile(path.join(out,slug),bytes);const result=await extract(bytes,url);await fs.writeFile(path.join(out,slug+'.review.json'),JSON.stringify(result,null,2));console.log(slug+': '+result.candidates.length+' candidates, '+result.issues.length+' review issues');}
    catch(e){console.error(slug+': '+e.message);await fs.writeFile(path.join(out,slug+'.error.txt'),e.message);process.exitCode=1;}
  }
}
if(process.argv[1]&&import.meta.url===pathToFileURL(path.resolve(process.argv[1])).href)main().catch(e=>{console.error(e);process.exitCode=1;});
