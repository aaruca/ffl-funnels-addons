/* Offline regression tests using the actual PHP admin renderer and assets. */
'use strict';
const {chromium}=require('playwright');
const path=require('node:path');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..');
let checks=0;
function check(value,label){assert.ok(value,label);checks++;}
(async()=>{
 const html=execFileSync('php',[path.join(__dirname,'google-merchant-policy-admin-fixture.php')],{encoding:'utf8'});
 check(!html.includes('<script>alert(1)</script>'),'category names are escaped by actual renderer');
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 const out=process.argv[2]?path.resolve(process.argv[2]):null;
 if(out)fs.mkdirSync(out,{recursive:true});
 try{
  for(const width of [2227,1440,390]){
   const page=await browser.newPage({viewport:{width,height:850}});
   await page.route('**/*',r=>r.abort());
   await page.setContent(html);
   await page.addStyleTag({path:path.join(root,'admin/css/ffla-admin.css')});
   await page.addStyleTag({content:'body{margin:0;font:14px Arial;background:#f6f7f7}.ffla-admin{margin:0;padding:20px}input,select{box-sizing:border-box}td,th{padding:12px;text-align:left}table{border-collapse:collapse}input[type=checkbox]:focus-visible{outline:2px solid #2271b1;outline-offset:3px}'});
   await page.addStyleTag({path:path.join(root,'modules/google-merchant-policy/admin/css/google-merchant-policy-admin.css')});
   await page.addScriptTag({path:path.join(root,'modules/google-merchant-policy/admin/js/google-merchant-policy-admin.js')});
   check(await page.locator('.ffla-gmp-help__steps li').count()===7,'seven usage steps '+width);
   check(await page.locator('.ffla-gmp-help details').count()===4,'advanced guidance is grouped '+width);
   check(await page.locator('.ffla-gmp-help details[open]').count()===0,'advanced guidance starts collapsed '+width);
   const summary=page.locator('.ffla-gmp-help summary').first();
   await summary.focus();await page.keyboard.press('Enter');
   check(await page.locator('.ffla-gmp-help details[open]').count()===1,'keyboard opens guidance '+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'expanded help does not overflow '+width);
   await page.keyboard.press('Enter');
   check(!(await page.locator('#ffla-gmp-unsaved').isVisible()),'no initial false unsaved warning '+width);
   await page.locator('#ffla-gmp-category-search').fill('Example 40');
   check(await page.locator('tbody tr:visible').count()===1,'category search still works '+width);
   check(!(await page.locator('#ffla-gmp-unsaved').isVisible()),'search is not a policy edit '+width);
   check(await page.locator('.ffla-gmp-form').evaluate(e=>Array.from(new FormData(e).keys()).filter(k=>k.startsWith('category_policy[')).length)===40,'search-hidden policies remain in save form '+width);
   await page.locator('#ffla-gmp-category-search').fill('');
   const box=page.locator('[name=content_safety]');
   const rect=await box.boundingBox();
   const text=await page.locator('.ffla-gmp-checkbox > span').boundingBox();
   check(rect.width===18&&rect.height===18,'checkbox stays square '+width);
   check(text.x-rect.x-rect.width>=9&&text.x-rect.x-rect.width<=11,'checkbox and label have a compact gap '+width);
   check(text.width>150,'label keeps available width '+width);
   check(await page.locator('input[type=number]').evaluate(e=>e.getBoundingClientRect().width>100),'number field is not constrained to checkbox size '+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'page does not overflow '+width);
   await page.locator('.ffla-gmp-checkbox strong').click();
   check(!(await box.isChecked()),'label still toggles checkbox '+width);
   await box.focus();await page.keyboard.press('Space');
   check(await box.isChecked(),'keyboard still toggles checkbox '+width);
   check(await page.locator('#ffla-gmp-unsaved').isVisible(),'editing settings displays unsaved warning '+width);
   const field=await page.locator('#ffla-gmp-batch').boundingBox();
   const fieldLabel=await page.locator('[for=ffla-gmp-batch]').boundingBox();
   const fieldHelp=await page.locator('#ffla-gmp-batch-help').boundingBox();
   check(fieldLabel.y+fieldLabel.height<=field.y&&field.y+field.height<=fieldHelp.y,'field label input and explanation stack correctly '+width);
   await page.locator('#ffla-gmp-mode').selectOption('enforce');
   let warning='';
   page.once('dialog',async dialog=>{warning=dialog.message();await dialog.dismiss();});
   const submitted=await page.locator('.ffla-gmp-form').evaluate(e=>e.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
   check(!submitted&&warning.includes('Blocked AND Pending')&&warning.includes('NEW scan'),'Enforce warning is precise and cancellation prevents submission '+width);
   check(await page.locator('form').count()===3,'guide adds no nested forms or submit controls '+width);
   check(await page.locator('thead').evaluate(e=>getComputedStyle(e).backgroundColor)==='rgb(255, 255, 255)','white table header '+width);
   await page.locator('.ffla-gmp-table-wrap').evaluate(e=>{e.scrollTop=300;});
   const wrap=await page.locator('.ffla-gmp-table-wrap').boundingBox();
   const header=await page.locator('thead').boundingBox();
   check(Math.abs(header.y-wrap.y)<=2,'header remains sticky while scrolling '+width);
   if(out){
    await page.locator('.ffla-gmp-settings').screenshot({path:path.join(out,'merchant-safety-'+width+'.png')});
    await page.locator('.ffla-gmp-help').screenshot({path:path.join(out,'merchant-guide-'+width+'.png')});
   }
   await page.close();
  }
  console.log(checks+' Merchant UI checks passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
