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
$(document).on('change','input[name="ffla_delivery_mode"]',function(){if(!$(this).closest(checkout).length)return;focusedMode=this.value;update();});
$(document).on('change input','[name="shipping_fflno"], [name="backup_fflno"], [name="ffl_license_backup"], [name="ffl_id"]',function(){if(!$(this).closest(checkout).length)return;const value=dealer();if(value!==lastFfl){lastFfl=value;update();}});
// The native selector already requests a review after populating its fields.
// Coalesce our pending refresh rather than send a second, competing request.
$(document.body).on('update_checkout',function(){clearTimeout(timer);timer=null;lastFfl=dealer();busy();});
$(document.body).on('updated_checkout',function(){
 const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text('');
 if(focusedMode){const input=box.find('input[name="ffla_delivery_mode"]').filter(function(){return this.value===focusedMode;})[0];if(input)input.focus({preventScroll:true});focusedMode='';}
});
$(document.body).on('checkout_error',function(){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');});
$(document).on('ajaxError',function(event,xhr,settings){if(settings&&String(settings.url||'').includes('update_order_review')){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');}});
// WooCommerce performs the initial review; do not start an extra refresh loop.
});
