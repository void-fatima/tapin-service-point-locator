/* Read-only acceptance check for the bundled sources after an explicit local import. */
const {chromium,expect}=require('@playwright/test');const fs=require('fs');
(async()=>{const session=JSON.parse(fs.readFileSync(process.env.TAPIN_SESSION_FILE,'utf8'));const browser=await chromium.launch({headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:1100}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(session.public_url);await expect(page.locator('.marker-status')).toContainText('۲۳۰');
 await page.screenshot({path:'artifacts/data-engine/source-national.png',fullPage:true});
 await page.locator('[data-provider="2"]').click();await expect(page.locator('.provider-pin img').first()).toHaveAttribute('src',/tipax\.svg/);
 await page.locator('[data-province]').selectOption('تهران');await expect(page.locator('.marker-status')).toContainText('۲۳۰');
 await page.screenshot({path:'artifacts/data-engine/source-tipax.png',fullPage:true});
 await page.locator('[data-provider="1"]').click();await page.locator('[data-province]').selectOption('سمنان');
 await expect(page.locator('.branch-card')).toHaveCount(10);await expect(page.locator('.marker-status')).toContainText('۰ نقطه');await expect(page.locator('.tapin-pin')).toHaveCount(0);
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:'artifacts/data-engine/source-post-mobile.png',fullPage:true});
 if(errors.length)throw Error(errors.join('\n'));console.log('PASS actual sources: 230 Tipax positions, official logo filter, ten postal addresses without markers, mobile rendering');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
