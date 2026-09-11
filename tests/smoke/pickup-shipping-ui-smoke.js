/* Offline browser tests. Playwright/Chrome and a local jQuery fixture required.
 * FFLA_TEST_JQUERY may point to wp-includes/js/jquery/jquery.min.js.
 * node tests/smoke/pickup-shipping-ui-smoke.js [screenshot directory]
 */
'use strict';
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const jquery=process.env.FFLA_TEST_JQUERY||path.join(root,'tmp/pickup-jquery.min.js');
function fixture(body='',scope=''){return JSON.parse(execFileSync('php',[path.join(__dirname,'pickup-shipping-smoke.php'),'fixture',body,scope,'variables'],{encoding:'utf8'}));}
const colors=JSON.parse(fs.readFileSync(path.join(__dirname,'pickup-shipping-colors.json'),'utf8'));
const radii=JSON.parse(fs.readFileSync(path.join(__dirname,'pickup-shipping-radii.json'),'utf8'));
let checks=0;function check(v,msg){assert.ok(v,msg);checks++;}
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.FFLA_TEST_BROWSER_CHANNEL||'chrome'});
 const errors=[];const out=process.argv[2]?path.resolve(process.argv[2]):null;if(out)fs.mkdirSync(out,{recursive:true});
 try{
 for(const width of [1440,390]){
 const page=await browser.newPage({viewport:{width,height:1000}});page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',r=>r.abort());
 await page.setContent('<html lang="en"><head><meta charset="utf-8"></head><body><main class="ffla-admin">'+fixture().admin+'</main></body></html>');
 await page.addStyleTag({path:path.join(root,'admin/css/ffla-admin.css')});
 await page.addStyleTag({content:'body{margin:0}.ffla-admin{margin:0;padding:20px}input,select,textarea{padding:8px;border:1px solid #aaa;border-radius:4px;font:inherit}input[type=checkbox],input[type=radio]{padding:0}button{cursor:pointer;padding:8px 12px}.button-primary{background:#2271b1;color:white;border:0;border-radius:5px}'});
 for(const f of ['delivery.css','admin.css'])await page.addStyleTag({path:path.join(root,'modules/pickup-shipping/assets',f)});
 await page.addScriptTag({path:path.join(root,'modules/pickup-shipping/assets/admin.js')});
 check(await page.locator('[data-ps-panel]:visible').count()===1,'only selected admin panel visible '+width);
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no admin page overflow '+width);
 const search=page.locator('[data-method-search]').first();await search.fill('no matching method');
 check(await page.locator('fieldset').first().locator('[data-method-row]:visible').count()===0,'method search filters '+width);await search.fill('');
 await page.locator('#ps-tab-general').focus();await page.keyboard.press('ArrowRight');
 check(await page.locator('#ps-tab-ffl').getAttribute('aria-selected')==='true','keyboard tab switching '+width);
 check(await page.locator('#ffla-ps-provider-pickup').innerText().then(t=>t.includes('977111018A05780')),'native local pickup displayed '+width);
 check(await page.locator('[name*="[locations]"]').count()===0,'no duplicate local FFL fields '+width);
 check(await page.locator('#ffla-ps-add-location').count()===0,'no duplicate location setup '+width);
 if(out)await page.locator('.ffla-ps-admin').screenshot({path:path.join(out,'pickup-admin-ffl-'+width+'.png')});
 await page.locator('#ps-tab-appearance').click();
 await page.locator('[name="ps[title]"]').fill('<img src=x onerror=alert(1)> New title');
 check(await page.locator('#ffla-ps-preview h3').textContent()==='<img src=x onerror=alert(1)> New title','preview is plain text '+width);
 check(await page.locator('#ffla-ps-preview img').count()===0,'no preview HTML injection '+width);
 await page.locator('[name="ps[title]"]').fill('How would you like to receive your order?');
 await page.locator('[name="ps[accent]"]').fill('#e01919');
 check(await page.locator('#ffla-ps-preview').evaluate(e=>e.style.getPropertyValue('--ffla-delivery-accent'))==='#e01919','color preview '+width);
 for(const [input,expected] of Object.entries(colors.accepted)){
 await page.locator('[name="ps[accent]"]').fill(input);
 check(await page.locator('#ffla-ps-preview').evaluate(e=>e.style.getPropertyValue('--ffla-delivery-accent'))===expected,'accepted preview color '+input);
 }
 for(const input of colors.rejected){
 await page.locator('[name="ps[accent]"]').fill(input);
 check(await page.locator('#ffla-ps-preview').evaluate(e=>e.style.getPropertyValue('--ffla-delivery-accent'))==='','rejected preview color '+input);
 }
 await page.addStyleTag({content:':root{--primary:#e01919;--surface:#f1f2f3;--text:#123456}'});
 for(const [key,value] of Object.entries({accent:'var(--primary)',background:'--surface',text_color:'var(--text)'}))await page.locator('[name="ps['+key+']"]').fill(value);
 check(await page.locator('#ffla-ps-preview').evaluate(e=>getComputedStyle(e).backgroundColor==='rgb(241, 242, 243)'&&getComputedStyle(e).color==='rgb(18, 52, 86)'),'site background and text variables resolve '+width);
 check(await page.locator('#ffla-ps-preview .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).borderTopColor)==='rgb(224, 25, 25)','site accent resolves '+width);
 await page.evaluate(()=>document.documentElement.style.setProperty('--primary','#008800'));
 check(await page.locator('#ffla-ps-preview .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).borderTopColor)==='rgb(0, 136, 0)','site color changes without resaving '+width);
 await page.locator('[name="ps[accent]"]').fill('var(--not-defined, var(--also-missing, #2271b1))');
 check(await page.locator('#ffla-ps-preview .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).borderTopColor)==='rgb(34, 113, 177)','missing site variables use nested fallback '+width);
 await page.locator('[data-preview-width=mobile]').click();
 for(const key of ['container_radius','card_radius','card_gap']){
  for(const [input,expected] of Object.entries(radii.accepted)){
   await page.locator('[name="ps['+key+']"]').fill(input);
   check(await page.locator('[name="ps['+key+']"]').getAttribute('aria-invalid')==='false','valid '+key+' '+input);
  }
  for(const input of radii.rejected){
   await page.locator('[name="ps['+key+']"]').fill(input);
   check(await page.locator('[name="ps['+key+']"]').getAttribute('aria-invalid')==='true','invalid '+key+' '+input);
  }
  await page.locator('[name="ps['+key+']"]').fill('0px');
 }
 await page.addStyleTag({content:':root{--panel-round:12px;--card-round:8px;--space:24px}'});
 await page.locator('[name="ps[container_radius]"]').fill('var(--panel-round)');
 await page.locator('[name="ps[card_radius]"]').fill('var(--card-round)');
 await page.locator('[name="ps[card_gap]"]').fill('var(--space)');
 check(await page.locator('#ffla-ps-preview').evaluate(e=>getComputedStyle(e).borderTopLeftRadius)==='12px','container radius variable preview '+width);
 check(await page.locator('#ffla-ps-preview .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).borderTopLeftRadius)==='8px','card radius variable preview '+width);
 check(await page.locator('#ffla-ps-preview .ffla-delivery__options').evaluate(e=>getComputedStyle(e).gap)==='24px','addon gap controls mobile and desktop '+width);
 await page.evaluate(()=>document.documentElement.style.setProperty('--space','30px'));
 check(await page.locator('#ffla-ps-preview .ffla-delivery__options').evaluate(e=>getComputedStyle(e).gap)==='30px','gap variable updates live '+width);
 check(await page.locator('#ffla-ps-preview').evaluate(e=>e.getBoundingClientRect().width<=390),'mobile preview bounded '+width);
 if(out)await page.locator('.ffla-ps-admin').screenshot({path:path.join(out,'pickup-admin-preview-'+width+'.png')});
 await page.close();
 }
 for(const scope of ['','ffl']){
 const page=await browser.newPage({viewport:{width:1100,height:900}});page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',r=>r.abort());
 const first=fixture('',scope);
 await page.setContent('<html lang="en"><head><meta charset="utf-8"></head><body style="font-family:Arial;padding:24px"><form class="checkout">'+first.checkout+'<input type="hidden" name="shipping_fflno" value=""><div id="rates"></div><div id="shipping-controls"><input type="radio" name="shipping_method[0]" value="flat_rate:2" checked></div></form></body></html>');
 await page.addStyleTag({path:path.join(root,'modules/pickup-shipping/assets/delivery.css')});
 await page.addStyleTag({content:':root{--primary:#008800;--surface:#f1f2f3;--text:#123456}'});
 if(!scope)check(await page.locator('#ffla-delivery-choice').evaluate(e=>getComputedStyle(e).backgroundColor==='rgb(241, 242, 243)'&&getComputedStyle(e).color==='rgb(18, 52, 86)'),'PHP-rendered checkout resolves site variables');
 await page.addScriptTag({path:jquery});
 let activeScope=scope;
 await page.exposeFunction('getDeliveryFixture',body=>fixture(body,activeScope));
 await page.evaluate(()=>{
 window.fflaDelivery={updating:'Updating delivery options…',error:'Please review delivery.'};window.updateCalls=0;window.completedCalls=0;
 jQuery(document.body).on('update_checkout',async function(){
 window.updateCalls++;const data=await window.getDeliveryFixture(jQuery('form.checkout').serialize());
 jQuery('#ffla-delivery-choice').replaceWith(data.checkout);
 jQuery('#rates').text(data.rates.join(','));
 jQuery('#shipping-controls').html(data.rates.map(function(rate){return '<input type="radio" name="shipping_method[0]" value="'+rate+'"'+(data.selected[0]===rate?' checked':'')+'>';}).join(''));
 jQuery(document.body).trigger('updated_checkout');window.completedCalls++;
 });
 });
 await page.addScriptTag({path:path.join(root,'modules/pickup-shipping/assets/delivery.js')});
 if(!scope){
 await page.addStyleTag({content:'.checkout label,.checkout label span,.checkout h3{color:#ff00ff}.checkout .ffla-delivery__options{gap:80px}'});
 check(await page.locator('#ffla-delivery-choice h3').evaluate(e=>getComputedStyle(e).color)==='rgb(18, 52, 86)','heading color survives generic checkout theme styles');
 check(await page.locator('.ffla-delivery__options').evaluate(e=>getComputedStyle(e).gap)==='16px','addon spacing is not inherited from theme grid defaults');
 await page.locator('[name=ffla_delivery_mode][value=ship]').locator('..').click();
 await page.waitForFunction(()=>window.completedCalls===1);
 check(await page.locator('#rates').innerText()==='flat_rate:2','shipping card filters to shipping server-side');
 await page.locator('[name=ffla_delivery_mode][value=pickup]').focus();await page.keyboard.press('Space');
 await page.waitForFunction(()=>window.completedCalls===2);
 check(!(await page.locator('#rates').innerText()).includes('flat_rate'),'pickup card removes shipping');
 check(await page.locator('[name=ffla_delivery_mode][value=pickup]').isChecked(),'selection preserved after fragment');
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).borderTopColor)==='rgb(0, 136, 0)','site accent survives checkout fragment refresh');
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).backgroundColor)==='rgb(0, 136, 0)','selected card uses solid site color');
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__card strong').first().evaluate(e=>getComputedStyle(e).color)==='rgb(255, 255, 255)','selected text color survives generic theme label styles');
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__card').last().evaluate(e=>getComputedStyle(e).backgroundColor==='rgb(17, 17, 17)'&&getComputedStyle(e).color==='rgb(221, 221, 221)'),'unselected card has independent background and text');
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).outlineStyle)==='solid','keyboard selection has a visible focus ring');
 await page.evaluate(()=>{document.documentElement.style.setProperty('--panel-radius','12px');document.documentElement.style.setProperty('--card-radius','8px');document.documentElement.style.setProperty('--space','24px');});
 check(await page.locator('#ffla-delivery-choice').evaluate(e=>getComputedStyle(e).borderTopLeftRadius)==='12px','saved container radius variable resolves on checkout');
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).borderTopLeftRadius)==='8px','saved card radius variable resolves on checkout');
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__options').evaluate(e=>getComputedStyle(e).gap)==='24px','saved gap variable resolves on checkout');
 check(await page.locator('#ffla-delivery-choice').getAttribute('aria-busy')==='false','busy cleared after review');
 check(await page.evaluate(()=>window.updateCalls)===2,'no updated_checkout recursion');
 await page.setViewportSize({width:390,height:900});
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'checkout fits mobile');
 if(out)await page.locator('#ffla-delivery-choice').screenshot({path:path.join(out,'pickup-checkout-mobile.png')});
 await page.setViewportSize({width:1100,height:900});
 await page.evaluate(()=>{
  const root=document.documentElement;
  Object.entries({'--primary':'#ff1616','--surface':'#050505','--text':'#ffffff','--card-surface':'#111111','--card-text':'#dddddd','--selected-text':'#ffffff','--line':'#333333','--panel-radius':'0px','--card-radius':'0px'}).forEach(([key,value])=>root.style.setProperty(key,value));
  document.activeElement.blur();
 });
 check(await page.locator('#ffla-delivery-choice .ffla-delivery__card').first().evaluate(e=>getComputedStyle(e).backgroundColor)==='rgb(255, 22, 22)','site variable changes update checkout without resaving');
 if(out)await page.locator('#ffla-delivery-choice').screenshot({path:path.join(out,'pickup-checkout-reference-style.png')});
 await page.evaluate(()=>jQuery(document.body).trigger('checkout_error'));
 check(await page.locator('.ffla-delivery__status').innerText()==='Please review delivery.','error notice accessible');
 await page.evaluate(()=>{jQuery(document.body).trigger('update_checkout');jQuery(document).trigger('ajaxError',[{}, {url:'/?wc-ajax=update_order_review'}]);});
 check(await page.locator('#ffla-delivery-choice').getAttribute('aria-busy')==='false','transport failure clears busy state');
 }else{
 check(await page.locator('#ffla-delivery-choice').isHidden(),'FFL cart hides entire duplicate delivery block');
 check(await page.locator('#ffla-delivery-choice').innerHTML()==='','FFL placeholder has no heading, notice or cards');
 check(await page.locator('[name=ffla_delivery_mode]').count()===0,'FFL cart does not ask redundant mode choice');
 // The native widget emits a premature updated_checkout before its real AJAX
 // response. The old shipping control must not win that race.
 await page.evaluate(()=>{
  jQuery('[name=shipping_fflno]').val('9-77-111-01-8A-05780');
  jQuery(document.body).trigger('update_checkout');
  jQuery(document.body).trigger('updated_checkout');
 });
 await page.waitForFunction(()=>window.completedCalls===1);
 check(await page.locator('#rates').innerText()==='local_pickup:1,local_pickup:3','native own FFL only configured pickup methods');
 check(await page.locator('[name="shipping_method[0]"][value="local_pickup:1"]').isChecked(),'post-AJAX policy selects native local pickup after provider race');
 check(await page.locator('#ffla-delivery-choice').isHidden(),'native pickup refresh does not reveal duplicate UI');
 // Real g-FFL 2.2.5 can preserve the selected green card while WooCommerce
 // redraws every native hidden FFL field with an empty value.
 await page.evaluate(()=>{
  const form=jQuery('form.checkout');
  ['shipping_fflno','backup_fflno','ffl_license_backup','ffl_id'].forEach(function(name){
   let field=form.find('[name="'+name+'"]');if(!field.length)field=jQuery('<input>',{type:'hidden',name}).appendTo(form);
   field.val('');
  });
  jQuery('<div>',{id:'9-77-111-01-8A-05780'}).append(jQuery('<button>',{type:'button','class':'ffl-list-div selectedFFLDivButton','data-marker-id':'9-77-111-01-8A-05780'})).appendTo(jQuery('<div>',{id:'ffl-list'}).appendTo(form));
  jQuery('<div>',{id:'ffl_container'}).appendTo(form);
  jQuery(document.body).trigger('updated_checkout');
 });
 await page.waitForFunction(()=>window.completedCalls===2);
 check(await page.locator('[name=shipping_fflno]').getAttribute('value').then(()=>page.locator('[name=shipping_fflno]').evaluate(e=>e.value))==='9-77-111-01-8A-05780','selected native card restores primary FFL field after fragment loss');
 check(await page.locator('[name=backup_fflno]').evaluate(e=>e.value)==='9-77-111-01-8A-05780','selected native card restores backup FFL field after fragment loss');
 check(await page.locator('[name=ffl_selection_confirmed]').evaluate(e=>e.value)==='1','selected native card restores the provider confirmation flag');
 check(await page.locator('#rates').innerText()==='local_pickup:1,local_pickup:3','restored native pickup selection returns pickup rates');
 await page.evaluate(()=>{
  ['shipping_fflno','backup_fflno','ffl_license_backup','ffl_id'].forEach(name=>jQuery('[name="'+name+'"]').val(''));
  jQuery(document.body).trigger('updated_checkout');
 });
 await page.waitForTimeout(350);
 check(await page.evaluate(()=>window.updateCalls)===2,'repeated native field loss is repaired without a checkout refresh loop');
 check(await page.locator('[name=shipping_fflno]').evaluate(e=>e.value)==='9-77-111-01-8A-05780','repeat fragment loss still restores the native selected FFL');
 await page.evaluate(()=>jQuery('[name=shipping_fflno]').val('9-77-111-01-8A-99999').trigger('change'));
 await page.waitForFunction(()=>window.completedCalls===3);
 check(await page.locator('#rates').innerText()==='flat_rate:2','external FFL only shipping');
 check(await page.locator('[name="shipping_method[0]"][value="flat_rate:2"]').isChecked(),'post-AJAX policy selects shipping for an external FFL');
 check(await page.locator('#ffla-delivery-choice').isHidden(),'external FFL refresh does not reveal duplicate UI');
 await page.evaluate(()=>{jQuery('#ffl-list .selectedFFLDivButton').removeClass('selectedFFLDivButton');jQuery('[name=shipping_fflno]').val('').trigger('change');});
 await page.waitForFunction(()=>window.completedCalls===4);
 check(await page.locator('#rates').innerText()==='','clearing FFL removes authorized methods');
 check(await page.locator('#ffla-delivery-choice').isHidden(),'clearing dealer does not reveal duplicate UI');
 // Native selectFFL fills backup fields and requests update_checkout itself.
 // A disabled primary is excluded by WooCommerce's form serialization.
 await page.evaluate(()=>{
  const form=jQuery('form.checkout');
  jQuery('[name=shipping_fflno]').prop('disabled',true);
  ['backup_fflno','ffl_license_backup','ffl_id'].forEach(name=>jQuery('<input>',{type:'hidden',name,value:'9-77-111-01-8A-05780'}).appendTo(form).trigger('change'));
  jQuery(document.body).trigger('update_checkout');
 });
 await page.waitForFunction(()=>window.completedCalls===5);
 check(await page.locator('#rates').innerText()==='local_pickup:1,local_pickup:3','native backup fields select pickup while primary is disabled');
 check(await page.locator('[name="shipping_method[0]"][value="local_pickup:1"]').isChecked(),'backup-field pickup remains selected after checkout refresh');
 await page.waitForTimeout(350);
 check(await page.evaluate(()=>window.updateCalls)===5,'native update coalesces pending addon refresh');
 await page.evaluate(()=>{
  jQuery('form.checkout').removeClass('checkout').addClass('woocommerce-checkout');
  jQuery('[name=shipping_fflno]').prop('disabled',false).val('9-77-111-01-8A-99999').trigger('change');
 });
 // This fixture backend uses form.checkout just as classic Woo does; restore
 // the class after the event to test the alternative selector independently.
 await page.evaluate(()=>jQuery('form.woocommerce-checkout').addClass('checkout'));
 await page.waitForFunction(()=>window.completedCalls===6);
 check(await page.locator('#rates').innerText()==='flat_rate:2','current external primary overrides old pickup backups');
 check(await page.locator('#ffla-delivery-choice').isHidden(),'backup integration does not add duplicate FFL UI');
 await page.evaluate(()=>jQuery('[name=shipping_fflno]').val('').trigger('change'));
 await page.waitForFunction(()=>window.completedCalls===7);
 check(await page.locator('#rates').innerText()==='','cleared primary is not revived by old native backups');
 activeScope='';
 await page.evaluate(()=>jQuery(document.body).trigger('update_checkout'));
 await page.waitForFunction(()=>window.completedCalls===8);
 check(await page.locator('#ffla-delivery-choice').isVisible(),'AJAX can replace hidden FFL anchor with regular-item selector');
 check(await page.locator('[name=ffla_delivery_mode]').count()===2,'regular-item choices restored after cart transition');
 activeScope='ffl';
 await page.evaluate(()=>jQuery(document.body).trigger('update_checkout'));
 await page.waitForFunction(()=>window.completedCalls===9);
 check(await page.locator('#ffla-delivery-choice').isHidden(),'AJAX hides regular selector when cart becomes FFL-only');
 }
 await page.close();
 }
 check(errors.length===0,'no browser errors: '+errors.join('; '));
 console.log(checks+' Pickup & Shipping browser checks passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
