/* Extract neighboring-country context from Natural Earth's public-domain 110m GeoJSON. */
const fs=require('fs');
const input=process.argv[2];if(!input)throw Error('Pass the downloaded ne_110m_admin_0_countries.geojson path.');
const codes=new Set(['TUR','IRQ','SYR','SAU','KWT','ARE','OMN','PAK','AFG','TKM','AZE','ARM','GEO','QAT','BHR','JOR','ISR','LBN','UZB','TJK','KAZ']);
const world=JSON.parse(fs.readFileSync(input,'utf8'));
const features=world.features.filter(f=>codes.has(f.properties.ADM0_A3)).map(f=>({type:'Feature',properties:{code:f.properties.ADM0_A3,name:f.properties.NAME_FA,label:[f.properties.LABEL_Y,f.properties.LABEL_X]},geometry:f.geometry}));
fs.writeFileSync('assets/neighbor-countries.geojson',JSON.stringify({type:'FeatureCollection',features})+'\n');
console.log(`Wrote ${features.length} contextual country features; no service-point data.`);
