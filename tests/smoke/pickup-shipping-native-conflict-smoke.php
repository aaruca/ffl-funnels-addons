<?php
/** Regression scenarios for the provider's observed raw-string conflict. */
require __DIR__.'/pickup-shipping-smoke.php';
$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['ffl_enabled'=>true]);
$options['ffl_local_pickup']='5-72-015-07-6L-06681';
$ffl=true;$wc->cart->items=cart_items([2]);
$start=$checks;
function conflict_case(array $post, array $data=[], bool $available=true, bool $otherPackage=false): Errors {
 global $wc,$rates;
 $_POST=$post;
 Pickup_Shipping_Checkout::process();
 $wc->ship->packages=Pickup_Shipping_Checkout::packages([['contents'=>$wc->cart->items]]);
 $wc->ship->packages[0]['rates']=$available?Pickup_Shipping_Checkout::rates($rates,$wc->ship->packages[0]):[];
 if($otherPackage){$wc->ship->packages[1]=['contents'=>cart_items([1]),'rates'=>[]];}
 $wc->session->set('chosen_shipping_methods',['local_pickup:1']);
 $data=array_merge(['shipping_method'=>['local_pickup:1']],$data);
 $e=new Errors();
 // Seed the observed provider error; do not redistribute third-party code.
 $e->add('ffl_local_pickup_conflict','Selected pickup dealer conflicts with store.');
 $e->add('ffl_document_required','Keep native document validation.');
 $e->add('validation','Keep native license/expiration validation.');
 $e->add('payment_error','Keep other checkout validation.');
 Pickup_Shipping_Checkout::validate($data,$e);
 check(isset($e->errors['ffl_document_required'],$e->errors['validation'],$e->errors['payment_error']),'all unrelated native/checkout errors survive');
 return $e;
}
foreach(['5-72-015-07-9L-06681','572015076L06681','5-72-015-07-6l-06681'] as $license){
 $_POST=['shipping_fflno'=>$license];$wc->session->set('chosen_shipping_methods',['local_pickup:1']);
 check($license!==get_option('ffl_local_pickup'),'raw comparison rejects renewal/format '.$license);
 $e=conflict_case($_POST,['shipping_fflno'=>$license]);
 check(!isset($e->errors['ffl_local_pickup_conflict']),'same-store current selection resolves only native conflict');
 check($_POST['shipping_fflno']===$license&&get_option('ffl_local_pickup')==='5-72-015-07-6L-06681','selected license and provider settings unchanged');
}
$e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06682']);
check(isset($e->errors['ffl_local_pickup_conflict'],$e->errors['ffla_delivery_0']),'different dealer pickup still blocked');
foreach(['invalid',[],null,''] as $value){
 $_COOKIE['selectedFFL']=json_encode(['licenseNumber'=>'5-72-015-07-9L-06682']);
 // The native method cannot accept an array; pre-existing conflict models
 // the error while our parser must fail closed before reconciliation.
 if(is_array($value)){$_POST=['shipping_fflno'=>$value];Pickup_Shipping_Checkout::process();$e=new Errors();$e->add('ffl_local_pickup_conflict','keep');Pickup_Shipping_Checkout::validate(['shipping_method'=>['local_pickup:1']],$e);}
 else{$e=conflict_case(['shipping_fflno'=>$value,'backup_fflno'=>'5-72-015-07-9L-06681']);}
 check(isset($e->errors['ffl_local_pickup_conflict']),'empty or malformed current primary cannot resolve conflict');
}
$_COOKIE=[];
foreach(['backup_fflno','ffl_license_backup','ffl_id'] as $key){
 $e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06681',$key=>'5-72-015-07-9L-06682']);
 check(isset($e->errors['ffl_local_pickup_conflict']),'conflicting native backup keeps conflict '.$key);
}
$e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06681'],['shipping_fflno'=>'5-72-015-07-9L-06682']);
check(isset($e->errors['ffl_local_pickup_conflict']),'parsed checkout identity mismatch keeps conflict');
$e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06681'],[],false);
check(isset($e->errors['ffl_local_pickup_conflict'],$e->errors['ffla_delivery_0']),'missing pickup rate cannot clear conflict');
$e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06681'],['shipping_method'=>['flat_rate:2']]);
check(isset($e->errors['ffl_local_pickup_conflict'],$e->errors['ffla_delivery_0']),'invalid posted method cannot clear conflict');
$e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06681'],[],true,true);
check(isset($e->errors['ffl_local_pickup_conflict'],$e->errors['ffla_delivery_1']),'invalid second package prevents reconciliation');
$options[Pickup_Shipping_Settings::OPTION]['ffl_enabled']=false;
$e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06681']);
check(isset($e->errors['ffl_local_pickup_conflict']),'disabled integration leaves native conflict alone');
$options[Pickup_Shipping_Settings::OPTION]['ffl_enabled']=true;
$blocks=true;
$e=conflict_case(['shipping_fflno'=>'5-72-015-07-9L-06681']);
check(isset($e->errors['ffl_local_pickup_conflict']),'Blocks are not intercepted');
echo ($checks-$start)." native FFL compatibility checks passed.\n";
