/** Run after node scripts/collect-tapin.mjs; uses actual downloaded official PDFs. */
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {discover,extract,normalize,provinces} from '../scripts/collect-tapin.mjs';
assert.deepEqual(discover('<a href="semnan.pdf\t"><a href="semnan.pdf"><a href="https://evil.test/fake.pdf">'),['https://tapin.ir/map/semnan.pdf']);
assert.equal(normalize('ﻛﻴﺎن ۱۲٣'),'کیان 123');
const catalog=JSON.parse(await fs.readFile('artifacts/tapin-directory/catalog.json','utf8'));
assert.equal(catalog.urls.length,31);
for(const url of catalog.urls)assert.ok(provinces[new URL(url).pathname.split('/').pop().replace('.pdf','')]);
const semnan=await extract(await fs.readFile('artifacts/tapin-directory/semnan.pdf'),'https://tapin.ir/map/semnan.pdf');
assert.equal(semnan.candidates.length,11);assert.equal(semnan.issues.length,1);assert.equal(semnan.issues[0].row,'3');
assert.equal(semnan.candidates[0].name,'دفتر پست شهید رجایی');assert.equal(semnan.candidates[0].postal_code,'3513674686');assert.equal(semnan.candidates[0].landline_phone,'02333348602');
for(const row of semnan.candidates){assert.equal(row.province,'سمنان');assert.equal(row.city,'');assert.equal(row.latitude,null);assert.equal(row.longitude,null);assert.equal(row.source,'https://tapin.ir/map/semnan.pdf');assert.match(row.metadata.source_sha256,/^[a-f0-9]{64}$/);}
const tehran=await extract(await fs.readFile('artifacts/tapin-directory/tehran.pdf'),'https://tapin.ir/map/tehran.pdf');
assert.equal(tehran.candidates[0].name,'پست لشگر');assert.equal(tehran.candidates[0].postal_code,'1318915513');assert.equal(tehran.candidates[0].landline_phone,'02166404883');assert.equal(tehran.candidates[0].province,'تهران');
assert.ok(tehran.issues.length>0);
console.log('PASS Tapin parser: 31 official province documents discovered, Persian normalization, Semnan and Tehran field extraction, malformed row quarantine, exact URLs/hashes, no invented city or coordinates');
