/* Server fragments own rate availability. No CSS-only shipping enforcement. */
jQuery(function($){'use strict';
const config=window.fflaDelivery||{};let timer=null;let focusedMode='';let reconcilingDealer=false;let recoveredDealer='';
const checkout='form.checkout, form.woocommerce-checkout';
const notifiedMethods={};const staleReviews={};let syncingMethods=false;let syncTimer=null;
function license(value){
 const raw=String(value||'').trim();const normalized=raw.toUpperCase().replace(/[\s-]/g,'');
 return /^[0-9]{9}[A-Z][0-9]{5}$/.test(normalized)?{raw:raw,normalized:normalized}:null;
}
function selectedNativeDealer(){
 const selected=$('#ffl-list .ffl-list-div.selectedFFLDivButton, #ffl-list .selectedFFLDivButton.ffl-list-div');
 if(selected.length!==1)return null;
 const button=selected.first();
 const candidates=[button.attr('data-marker-id'),button.closest('#ffl-list > [id]').attr('id'),button.parent().attr('id')];
 for(let i=0;i<candidates.length;i++){const found=license(candidates[i]);if(found)return found;}
 return null;
}
function reconcileNativeDealer(){
 if(reconcilingDealer||!$('#ffl_container').length)return '';
 const selected=selectedNativeDealer();if(!selected)return '';
 const form=$(checkout).first();if(!form.length)return '';
 const names=['shipping_fflno','backup_fflno','ffl_license_backup','ffl_id'];let conflict=false;let changed=false;
 names.forEach(function(name){
  form.find('[name="'+name+'"]').each(function(){
   const raw=String($(this).val()||'').trim();const current=license(raw);
   if(raw&&(!current||current.normalized!==selected.normalized))conflict=true;
  });
 });
 // A different or malformed current value is authoritative; never rewrite it.
 if(conflict)return '';
 reconcilingDealer=true;
 try{
  names.forEach(function(name){
   let fields=form.find('[name="'+name+'"]');
   if(!fields.length){fields=$('<input>',{type:'hidden',name:name}).appendTo(form);}
   fields.each(function(){
    if(!license($(this).val())){$(this).val(selected.raw);$(this).prop('disabled',false);changed=true;}
   });
  });
  let confirmed=form.find('[name="ffl_selection_confirmed"]');
  if(!confirmed.length){confirmed=$('<input>',{type:'hidden',name:'ffl_selection_confirmed'}).appendTo(form);}
  confirmed.each(function(){if(String($(this).val()||'')===''){$(this).val('1').prop('disabled',false);changed=true;}});
 }finally{reconcilingDealer=false;}
 return changed?selected.normalized:'';
}
function reviewRecoveredDealer(){
 const selected=selectedNativeDealer();
 if(!selected){recoveredDealer='';return false;}
 const recovered=reconcileNativeDealer();
 if(!recovered||recovered===recoveredDealer)return false;
 recoveredDealer=recovered;lastFfl=dealer();busy();update();return true;
}
function dealer(){
 const form=$(checkout).first();const primary=form.find('[name="shipping_fflno"]:enabled').first();
 if(primary.length)return String(primary.val()||'').toUpperCase().replace(/[\s-]/g,'');
 const backups=[];
 ['backup_fflno','ffl_license_backup','ffl_id'].forEach(function(name){const field=form.find('[name="'+name+'"]:enabled').first();if(field.length)backups.push(String(field.val()||'').toUpperCase().replace(/[\s-]/g,''));});
 return backups.length&&backups.every(value=>value===backups[0])?backups[0]:'';
}
let lastFfl=dealer();
function busy(){const box=$('#ffla-delivery-choice');box.attr('aria-busy','true');box.find('.ffla-delivery__status').text(config.updating||'Updating delivery options…');}
function update(){clearTimeout(timer);timer=setTimeout(function(){timer=null;$(document.body).trigger('update_checkout');},120);}
function allowed(rate,methods){return methods.some(function(method){return rate===method||rate.indexOf(method+':')===0;});}
function syncShippingMethods(){
 if(syncingMethods)return;
 const box=$('#ffla-delivery-choice');let policy={};
 try{policy=JSON.parse(String(box.attr('data-ffla-shipping-policy')||'{}'));}catch(error){return;}
 const form=$(checkout).first();
 Object.keys(policy).forEach(function(key){
  const rule=policy[key]||{};const methods=Array.isArray(rule.methods)?rule.methods:[];
  if(!methods.length||!['pickup','ship'].includes(rule.mode))return;
  const name='shipping_method['+key+']';
  const controls=form.find('input[name^="shipping_method["], select[name^="shipping_method["]').filter(function(){return this.name===name;});
  // An older response must not switch methods for a newly selected dealer.
  if(typeof rule.dealer==='string'&&rule.dealer!==dealer()){
   controls.filter(':enabled').attr('data-ffla-stale','1').prop('disabled',true).prop('checked',false);
   const stale=JSON.stringify([rule.dealer,dealer()]);
   if(staleReviews[key]!==stale){staleReviews[key]=stale;busy();update();}
   return;
  }
  controls.filter('[data-ffla-stale="1"]').prop('disabled',false).removeAttr('data-ffla-stale');
  const requestedMode=form.find('[name="ffla_delivery_mode"]:checked').val();
  if(rule.dealer===null&&requestedMode&&requestedMode!==rule.mode)return;
  if(!controls.length)return;
  const confirmed=typeof rule.selected==='string'&&allowed(rule.selected,methods)?rule.selected:'';
  let target=null;
  const select=controls.filter('select').first();
  if(select.length){
   const current=String(select.val()||'');
   if(confirmed?current===confirmed:allowed(current,methods))return;
   const option=select.find('option').filter(function(){return confirmed?this.value===confirmed:allowed(String(this.value||''),methods);}).first();
   if(!option.length)return;
   select.val(option.val());target=select;
  }else{
   const hidden=controls.filter('input[type="hidden"]:enabled');
   if(controls.length===1&&hidden.length&&confirmed){
    if(String(hidden.val())===confirmed)return;
    hidden.val(confirmed);target=hidden;
   }else{
    const matching=controls.filter(':enabled').filter(function(){return confirmed?String($(this).val()||'')===confirmed:allowed(String($(this).val()||''),methods);});
    if(!matching.length)return;
    const current=controls.filter(':enabled').filter(':checked, [type="hidden"]').first();
    if(current.length&&(confirmed?String(current.val())===confirmed:allowed(String(current.val()||''),methods)))return;
    target=matching.first();
    if(target.attr('type')==='radio'||target.attr('type')==='checkbox')target.prop('checked',true);
   }
  }
  // Like Camarillo, notify checkout after repairing a stale control. Bound
  // this to one notification per dealer/method so theme redraws cannot loop.
  const signature=JSON.stringify([rule.dealer,rule.mode,methods,String(target.val())]);
  if(notifiedMethods[key]===signature)return;
  notifiedMethods[key]=signature;
  update();syncingMethods=true;
  try{target.trigger('change');}finally{syncingMethods=false;}
 });
}
$(document).on('change','input[name="ffla_delivery_mode"]',function(){if(!$(this).closest(checkout).length)return;focusedMode=this.value;update();});
$(document).on('change input','[name="shipping_fflno"], [name="backup_fflno"], [name="ffl_license_backup"], [name="ffl_id"]',function(){if(!$(this).closest(checkout).length)return;const value=dealer();if(value!==lastFfl){lastFfl=value;update();}});
$(document).on('ffl-dealer-selected',function(){
 // g-FFL emits this after selecting a result and before its own checkout
 // refresh. Capture only that verified native selection, never the search click.
 recoveredDealer='';
 if(!reviewRecoveredDealer()){const value=dealer();if(value!==lastFfl){lastFfl=value;update();}}
});
// The native selector already requests a review after populating its fields.
// Coalesce our pending refresh rather than send a second, competing request.
$(document.body).on('update_checkout',function(){clearTimeout(timer);timer=null;lastFfl=dealer();busy();});
$(document.body).on('updated_checkout',function(){
 const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text('');
 // Some g-FFL/classic-checkout combinations redraw the hidden checkout fields
 // empty while leaving the selected dealer card intact. Restore the license
 // from that one native selected card and request one authoritative review.
 if(reviewRecoveredDealer())return;
 syncShippingMethods();
 // Native/theme listeners may redraw controls later in the same event turn.
 clearTimeout(syncTimer);syncTimer=setTimeout(syncShippingMethods,50);
 if(focusedMode){const input=box.find('input[name="ffla_delivery_mode"]').filter(function(){return this.value===focusedMode;})[0];if(input)input.focus({preventScroll:true});focusedMode='';}
});
$(document.body).on('checkout_error',function(){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');});
$(document).on('ajaxError',function(event,xhr,settings){if(settings&&String(settings.url||'').includes('update_order_review')){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');}});
// WooCommerce performs the initial review; do not start an extra refresh loop.
});
