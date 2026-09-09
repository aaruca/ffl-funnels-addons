/* Server fragments own rate availability. No CSS-only shipping enforcement. */
jQuery(function($){'use strict';
const config=window.fflaDelivery||{};let timer=null;let lastFfl='';let focusedMode='';
function busy(){const box=$('#ffla-delivery-choice');box.attr('aria-busy','true');box.find('.ffla-delivery__status').text(config.updating||'Updating delivery options…');}
function update(){clearTimeout(timer);timer=setTimeout(function(){busy();$(document.body).trigger('update_checkout');},120);}
$(document).on('change','form.checkout input[name="ffla_delivery_mode"]',function(){focusedMode=this.value;update();});
$(document).on('change input','form.checkout [name="shipping_fflno"]',function(){const value=String(this.value||'').toUpperCase().replace(/[\s-]/g,'');if(value!==lastFfl){lastFfl=value;update();}});
$(document.body).on('update_checkout',busy);
$(document.body).on('updated_checkout',function(){
 const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text('');
 if(focusedMode){const input=box.find('input[name="ffla_delivery_mode"]').filter(function(){return this.value===focusedMode;})[0];if(input)input.focus({preventScroll:true});focusedMode='';}
});
$(document.body).on('checkout_error',function(){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');});
$(document).on('ajaxError',function(event,xhr,settings){if(settings&&String(settings.url||'').includes('update_order_review')){const box=$('#ffla-delivery-choice');box.attr('aria-busy','false');box.find('.ffla-delivery__status').text(config.error||'Please review your delivery selection.');}});
// WooCommerce performs the initial review; do not start an extra refresh loop.
});
