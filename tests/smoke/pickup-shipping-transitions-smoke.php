<?php
/** Global Camarillo-style flow: posted selection -> cache -> rates -> session. */
require __DIR__.'/pickup-shipping-smoke.php';
$start=$checks;
$ffl=true;$wc->cart->items=cart_items([2]);
$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['ffl_enabled'=>true]);
$options['ffl_local_pickup']='5-72-015-07-6L-06681';
$store='5-72-015-07-9L-06681';$external='5-72-015-07-9L-06682';
$wc->cart->packages=[2=>['contents'=>$wc->cart->items,'destination'=>['country'=>'US','postcode'=>'71006']]];
$wc->ship->packages=$wc->cart->packages;
$wc->session->set('chosen_shipping_methods',[2=>'flat_rate:2']);
foreach([$store,$external,$store] as $license){
 $wc->session->set('shipping_for_package_2',['rates'=>['stale'=>new Rate()]]);
 $wc->session->set('unrelated_session_key','keep');
 Pickup_Shipping_Checkout::update(['shipping_fflno'=>$license]);
 check($wc->session->get('shipping_for_package_2')===null,'transition clears this package cache');
 check($wc->session->get('unrelated_session_key')==='keep','unrelated session values preserved');
 // Mirror WC_AJAX: an old posted shipping control is written AFTER update().
 $wc->session->set('chosen_shipping_methods',[2=>$license===$store?'flat_rate:2':'local_pickup:1']);
 $packages=Pickup_Shipping_Checkout::packages($wc->cart->packages);
 $packages[2]['rates']=Pickup_Shipping_Checkout::rates($rates,$packages[2]);
 $wc->ship->packages=Pickup_Shipping_Checkout::sync_packages($packages);
 $expected=$license===$store?'local_pickup:1':'flat_rate:2';
 check($wc->session->get('chosen_shipping_methods')[2]===$expected,'session follows actual dealer, not stale posted method');
 check($wc->ship->packages[2]['rates'][$expected]===$rates[$expected],'selected rate object, price and taxes preserved');
 check($wc->ship->packages[2]['destination']===$wc->cart->packages[2]['destination'],'package destination is untouched');
 $_POST=['shipping_fflno'=>$license];Pickup_Shipping_Checkout::process();
 $e=new Errors();if($license===$store)$e->add('ffl_local_pickup_conflict','Renewal string mismatch');
 Pickup_Shipping_Checkout::validate(['shipping_fflno'=>$license,'shipping_method'=>[2=>$expected]],$e);
 check(!$e->errors,'final addon validation accepts the synchronized valid selection');
 $wc->session->set('shipping_for_package_2',['sentinel'=>'retain']);
 Pickup_Shipping_Checkout::update(['shipping_fflno'=>$license]);
 check($wc->session->get('shipping_for_package_2')===['sentinel'=>'retain'],'identical selection does not invalidate cache again');
}
// Honor a valid customer choice among several configured pickup methods.
$wc->session->set('chosen_shipping_methods',[2=>'local_pickup:3']);
$wc->ship->packages=Pickup_Shipping_Checkout::sync_packages($wc->ship->packages);
check($wc->session->get('chosen_shipping_methods')[2]==='local_pickup:3','valid selected pickup instance retained');
// Missing pickup must never fabricate a zero-cost rate or fall back to shipping.
$without=$wc->cart->packages;$without[2]['rates']=['flat_rate:2'=>$rates['flat_rate:2']];
$empty=Pickup_Shipping_Checkout::sync_packages($without);
check($empty[2]['rates']===[]&&$wc->session->get('chosen_shipping_methods')[2]==='','no available pickup clears stale chosen method without inventing a rate');
Pickup_Shipping_Checkout::update(['shipping_fflno'=>'']);
$empty=Pickup_Shipping_Checkout::sync_packages($packages);
check($empty[2]['rates']===[]&&$wc->session->get('chosen_shipping_methods')[2]==='','cleared dealer cannot retain pickup');
// One FFL package and one customer package keep their own choice and destination.
$wc->cart->items=cart_items([1,2]);
$wc->cart->packages=[2=>['contents'=>cart_items([2])],7=>['contents'=>cart_items([1])]];
Pickup_Shipping_Checkout::update(['shipping_fflno'=>$store,'ffla_delivery_mode'=>'ship']);
$mixed=Pickup_Shipping_Checkout::packages($wc->cart->packages);
foreach($mixed as &$p){$p['rates']=$rates;}unset($p);
$mixed=Pickup_Shipping_Checkout::sync_packages($mixed);
check(array_keys($mixed[2]['rates'])===['local_pickup:1','local_pickup:3'],'mixed FFL package keeps pickup');
check(array_keys($mixed[7]['rates'])===['flat_rate:2'],'mixed regular package keeps shipping');
check($wc->session->get('chosen_shipping_methods')[2]==='local_pickup:1'&&$wc->session->get('chosen_shipping_methods')[7]==='flat_rate:2','package indexes are independent');
// A native provider-setting change invalidates the cache without an addon save.
$wc->session->set('shipping_for_package_2',['sentinel'=>'old setting']);
$options['ffl_local_pickup']=$external;
Pickup_Shipping_Checkout::update(['shipping_fflno'=>$store,'ffla_delivery_mode'=>'ship']);
check($wc->session->get('shipping_for_package_2')===null,'provider configuration change clears stale rates');
$blocks=true;$before=$wc->session->data;
check(Pickup_Shipping_Checkout::sync_packages($mixed)===$mixed&&$wc->session->data===$before,'Blocks stay unchanged');
$blocks=false;$checkout=false;$before=$wc->session->data;
check(Pickup_Shipping_Checkout::sync_packages($mixed)===$mixed&&$wc->session->data===$before,'non-checkout requests stay unchanged');
echo ($checks-$start)." shipping transition checks passed.\n";
