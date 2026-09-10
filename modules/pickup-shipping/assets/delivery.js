/* Server fragments own rate availability. No CSS-only shipping enforcement. */
jQuery(function($){'use strict';
const config=window.fflaDelivery||{};let timer=null;let focusedMode='';
const checkout='form.checkout, form.woocommerce-checkout';
function dealer(){
 const form=$(checkout).first();const primary=form.find('[name="shipping_fflno"]:enabled').first();
 if(primary.length)return String(primary.val()||'').toUpperCase().replace(/[\s-]/g,'');
 return ['backup_fflno','ffl_license_backup','ffl_id'].map(function(name){const field=form.find('[name="'+name+'"]:enabled').first();return field.length?String(field.val()||'').toUpperCase().replace(/[\s-]/g,''):'<absent>';}).join('|');
}
let lastFfl=dealer();
function busy(){const box=$('#ffla-delivery-choice');box.attr('aria-busy','true');box.find('.ffla-delivery__status').text(config.updating||'Updating delivery options…');}
function update(){clearTimeout(timer);timer=setTimeout(function(){timer=null;$(document.body).trigger('update_checkout');},120);}
function allowed(rate,methods){return methods.some(function(method){return rate===method||rate.indexOf(method+':')===0;});}
function syncShippingMethods(){
 const box=$('#ffla-delivery-choice');let policy={};
 try{policy=JSON.parse(String(box.attr('data-ffla-shipping-policy')||'{}'));}catch(error){return;}
 const form=$(checkout).first();
 Object.keys(policy).forEach(function(key){
  const rule=policy[key]||{};const methods=Array.isArray(rule.methods)?rule.methods:[];
  if(!methods.length||!['pickup','ship'].includes(rule.mode))return;
  const name='shipping_method['+key+']';
  const controls=form.find('input[name^="shipping_method["], select[name^="shipping_method["]').filter(function(){return this.name===name;});
  if(!controls.length)return;
  const select=controls.filter('select').first();
  if(select.length){
   const current=String(select.val()||'');if(allowed(current,methods))return;
   const option=select.find('option').filter(function(){return allowed(String(this.value||''),methods);}).first();
   if(option.length)select.val(option.val());
   return;
  }
  const matching=controls.filter(function(){return allowed(String($(this).val()||''),methods);});
  if(!matching.length)return;
  const current=controls.filter(':checked').first();
  if(current.length&&allowed(String(current.val()||''),methods))return;
  const target=matching.first();
  if(target.attr('type')==='radio'||target.attr('type')==='checkbox')target.prop('checked',true);
  else if(!allowed(String(target.val()||''),methods))target.val(methods[0]);
 });
}
$(document).on('change','input[name="ffla_delivery_mode"]',function(){if(!$(this).closest(checkout).length)return;focusedMode=this.value;update();});
$(document).on('change input','[name="shipping_fflno"], [name="backup_fflno"], [name="ffl_license_backup"], [name="ffl_id"]',function(){if(!$(this).closest(checkout).length)return;const value=dealer();if(value!==lastFfl){lastFfl=value;update();}});
// The native selector already requests a review after populating its fields.
// Coalesce our pending refresh rather than send a second, competing request.
$(document.body).on('update_checkout',function(){clearTimeout(timer);timer=null;lastFfl=dealer();busy();});
$(document.body).on('updated_checkout',function(){
 const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text('');
 syncShippingMethods();
 if(focusedMode){const input=box.find('input[name="ffla_delivery_mode"]').filter(function(){return this.value===focusedMode;})[0];if(input)input.focus({preventScroll:true});focusedMode='';}
});
$(document.body).on('checkout_error',function(){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');});
$(document).on('ajaxError',function(event,xhr,settings){if(settings&&String(settings.url||'').includes('update_order_review')){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');}});
// WooCommerce performs the initial review; do not start an extra refresh loop.
});

