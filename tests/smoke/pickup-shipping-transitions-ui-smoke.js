/* In-memory browser checkout: no network, orders, or payments. */
'use strict';
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const jquery=process.env.FFLA_TEST_JQUERY||path.join(root,'tmp/pickup-jquery.min.js');
const store='9-77-111-01-8A-05780',external='9-77-111-01-8A-99999';
function fixture(body){return JSON.parse(execFileSync('php',[path.join(__dirname,'pickup-shipping-smoke.php'),'fixture',body,'ffl'],{encoding:'utf8'}));}
let checks=0;function check(value,message){assert.ok(value,message);checks++;}
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',r=>r.abort());
  const initial=fixture('');
  await page.setContent('<form class="checkout">'+initial.checkout+'<div id="shipping-controls"></div><div id="ffl_container"><button type="button" id="ffl-local-pickup-search">Search store</button><div id="ffl-list"></div></div><input name="shipping_fflno"><input name="backup_fflno"><input name="ffl_id"></form>');
  await page.addScriptTag({path:jquery});
  await page.exposeFunction('serverReview',fixture);
  await page.evaluate(()=>{
   window.updateCalls=0;window.completedCalls=0;window.methodChanges=0;
   window.applyReview=function(data){
    jQuery('#ffla-delivery-choice').replaceWith(data.checkout);
    jQuery('#shipping-controls').html(data.rates.map(rate=>'<input type="radio" name="shipping_method[0]" value="'+rate+'"'+(data.selected[0]===rate?' checked':'')+'>').join(''));
    window.lastResponse=data;jQuery(document.body).trigger('updated_checkout');
   };
   jQuery(document.body).on('change','[name^="shipping_method["]',function(){window.methodChanges++;jQuery(document.body).trigger('update_checkout');});
   jQuery(document.body).on('update_checkout',async function(){
    window.updateCalls++;const data=await window.serverReview(jQuery('form.checkout').serialize());
    window.applyReview(data);window.completedCalls++;
   });
  });
  await page.addScriptTag({path:path.join(root,'modules/pickup-shipping/assets/delivery.js')});
  async function settle(count){await page.waitForFunction(n=>window.completedCalls>=n,count);await page.waitForTimeout(300);}
  async function choose(license,nativeRefresh=false){
   const before=await page.evaluate(()=>window.completedCalls);
   await page.evaluate(({license,nativeRefresh})=>{
    jQuery('[name=shipping_fflno],[name=backup_fflno],[name=ffl_id]').val(license);
    jQuery('#ffl-list').empty();
    if(license)jQuery('<div>',{id:license}).append(jQuery('<button>',{type:'button',class:'ffl-list-div selectedFFLDivButton','data-marker-id':license})).appendTo('#ffl-list');
    jQuery(document).trigger('ffl-dealer-selected');
    if(nativeRefresh)jQuery(document.body).trigger('update_checkout');
   },{license,nativeRefresh});
   await settle(before+1);
   check(await page.evaluate(()=>window.completedCalls)===before+1,'one review per native dealer change');
  }
  await page.locator('#ffl-local-pickup-search').click();await page.waitForTimeout(180);
  check(await page.evaluate(()=>window.updateCalls)===0,'search click alone never authorizes pickup');
  for(const [license,method] of [[store,'local_pickup:1'],[external,'flat_rate:2'],[store,'local_pickup:1']]){
   await choose(license,true);
   check(await page.locator('[name="shipping_method[0]"]:checked').inputValue()===method,'store -> external -> store selects server-confirmed method');
   check(await page.evaluate(()=>window.lastResponse.selected[0])===method,'browser and server choice agree');
   check(await page.locator('#ffla-delivery-choice').isHidden(),'no duplicate FFL delivery selector');
  }
  await page.locator('[name="shipping_method[0]"][value="local_pickup:3"]').check();await settle(4);
  check(await page.locator('[name="shipping_method[0]"]:checked').inputValue()==='local_pickup:3','alternate available pickup instance remains selected');
  // A theme changes the radio AFTER the addon handles updated_checkout.
  const beforeRepair=await page.evaluate(()=>window.completedCalls);
  await page.evaluate(()=>{
   window.themeBreaks=true;
   jQuery(document.body).on('updated_checkout.theme',function(){if(window.themeBreaks)jQuery('[name="shipping_method[0]"]').prop('checked',false);});
   jQuery(document.body).trigger('updated_checkout');
  });
  await settle(beforeRepair+1);
  check(await page.locator('[name="shipping_method[0]"]:checked').inputValue()==='local_pickup:3','late theme redraw repaired to confirmed server choice');
  check(await page.evaluate(()=>window.completedCalls)===beforeRepair+1,'persistent theme redraw cannot create a review loop');
  await page.evaluate(()=>{window.themeBreaks=false;jQuery(document.body).off('.theme');});
  const oldResponse=await page.evaluate(()=>window.lastResponse);
  await choose(external);const afterExternal=await page.evaluate(()=>window.completedCalls);
  await page.evaluate(data=>window.applyReview(data),oldResponse);await settle(afterExternal+1);
  check(await page.evaluate(()=>window.completedCalls)===afterExternal+1,'stale response requests one fresh review');
  check(await page.locator('[name="shipping_method[0]"]:checked').inputValue()==='flat_rate:2','stale pickup response cannot leave an external dealer on pickup');
  // Restore authoritative external response, then emulate a single hidden
  // shipping field left with a stale method by a multi-step checkout plugin.
  const externalResponse=fixture('shipping_fflno='+encodeURIComponent(external));
  await page.evaluate(data=>{
   window.applyReview(data);
   jQuery('#shipping-controls').html('<input type="hidden" name="shipping_method[0]" value="local_pickup:1">');
   jQuery(document.body).trigger('updated_checkout');
  },externalResponse);
  await settle(afterExternal+2);
  check(await page.evaluate(()=>window.lastResponse.selected[0])==='flat_rate:2','stale hidden method is submitted as server-confirmed shipping');
  await choose(store);const beforeSelect=await page.evaluate(()=>window.completedCalls);
  await page.evaluate(()=>{
   jQuery('#shipping-controls').html('<select name="shipping_method[0]"><option value="flat_rate:2">Shipping</option><option value="local_pickup:1">Pickup</option></select>');
   jQuery(document.body).trigger('updated_checkout');
  });
  await settle(beforeSelect+1);
  check(await page.evaluate(()=>window.lastResponse.selected[0])==='local_pickup:1','select-based theme receives same confirmed pickup choice');
  await choose('');
  check(await page.locator('[name="shipping_method[0]"]').count()===0,'clearing dealer removes all authorized shipping methods');
  check(errors.length===0,'no browser errors: '+errors.join('; '));
  console.log(checks+' checkout transition browser checks passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
