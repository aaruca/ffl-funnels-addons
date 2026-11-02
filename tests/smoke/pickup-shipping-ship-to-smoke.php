<?php
/** Store pickup orders get the store as ship-to; everything else is left alone. */
require __DIR__.'/pickup-shipping-smoke.php';
$start=$checks;
function get_bloginfo($k){return $k==='name'?'Sample &amp; Sons':'';}
function wp_specialchars_decode($s,$q=ENT_NOQUOTES){return htmlspecialchars_decode((string)$s,$q);}
class Countries {public $a;function __construct(array $a){$this->a=$a;}
function get_base_address(){return $this->a['address_1'];}function get_base_address_2(){return $this->a['address_2'];}
function get_base_city(){return $this->a['city'];}function get_base_state(){return $this->a['state'];}
function get_base_postcode(){return $this->a['postcode'];}function get_base_country(){return $this->a['country'];}}
class WooWithCountries extends Woo {public $countries;}
/** Only WooCommerce's per-field setters exist; the legacy set_address() would write post meta. */
class PickupOrder extends Order {public $meta=[];public $shipping=[];public $billing=[];
function get_meta($k){return $this->meta[$k]??'';}
function update_meta_data($k,$v){$this->meta[$k]=$v;}
function delete_meta_data($k){unset($this->meta[$k]);}
function set_address($a,$type){throw new LogicException('legacy WC_Order::set_address() must not be used');}
function __call($n,$args){
 if(preg_match('/^get_(shipping|billing)_(\w+)$/',$n,$m)){return $this->{$m[1]}[$m[2]]??'';}
 if(preg_match('/^set_shipping_(first_name|last_name|company|address_1|address_2|city|state|postcode|country|phone)$/',$n,$m)){$this->shipping[$m[1]]=$args[0];return null;}
 throw new BadMethodCallException($n);}}
$moved=new WooWithCountries();$moved->session=$wc->session;$moved->cart=$wc->cart;$moved->ship=$wc->ship;$wc=$moved;
$store=['address_1'=>'200 Sample Avenue','address_2'=>'','city'=>'Sampleton','state'=>'TX','postcode'=>'75001','country'=>'US'];
$wc->countries=new Countries($store);
$customer=['first_name'=>'Pat','last_name'=>'Example','company'=>'','address_1'=>'100 Main Street','address_2'=>'Apt 4','city'=>'Springfield','state'=>'IL','postcode'=>'62701','country'=>'US','phone'=>'5555550100'];
function pickup_order(array $lines,array $customer,array $meta=[]){$o=new PickupOrder();$o->items=$lines;$o->billing=$customer+['email'=>'buyer@example.test'];$o->shipping=$customer;$o->meta=$meta;return $o;}
function delivery_line($mode){Pickup_Shipping_Checkout::update('ffla_delivery_mode='.$mode);$i=new ShipItem();Pickup_Shipping_Checkout::shipping_meta($i,0,$GLOBALS['package'],new Order());return $i;}
$storeShipTo=['first_name'=>'Pat','last_name'=>'Example','company'=>'Sample Store','address_1'=>'200 Sample Avenue','address_2'=>'','city'=>'Sampleton','state'=>'TX','postcode'=>'75001','country'=>'US','phone'=>'5555550100'];
// Regular cart, delivery cards, store pickup.
$doing='';$_POST=[];$ffl=false;$blocks=false;$checkout=true;$wc->cart->items=cart_items([1]);
$options[Pickup_Shipping_Settings::OPTION]=$s;Pickup_Shipping_Checkout::clear();
$pickup=delivery_line('pickup');
check($pickup->meta['_ffla_delivery']['mode']==='pickup'&&$pickup->meta['_ffla_delivery']['reason']==='customer','pickup line records that the customer chose it');
$order=pickup_order([$pickup],$customer);
Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$storeShipTo,'ship-to is the customer name and phone with the store company and address');
check($order->meta[Pickup_Shipping_Checkout::SHIP_TO_META]==='store','order is marked as having the store ship-to');
check($order->billing===$customer+['email'=>'buyer@example.test'],'billing address untouched');
// The customer typed another recipient: that name is kept, the street is not.
$order=pickup_order([$pickup],$customer);$order->shipping['first_name']='Alex';$order->shipping['last_name']='Other';
Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['first_name']==='Alex'&&$order->shipping['address_1']==='200 Sample Avenue','typed recipient name kept, street replaced');
// Empty shipping name and phone fall back to billing (classic checkout has no shipping phone by default).
$order=pickup_order([$pickup],$customer);$order->shipping['first_name']='';$order->shipping['last_name']='';unset($order->shipping['phone']);
Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['first_name']==='Pat'&&$order->shipping['last_name']==='Example'&&$order->shipping['phone']==='5555550100','empty shipping name and phone fall back to billing');
// Shipping orders keep the customer address.
$ship=delivery_line('ship');
check($ship->meta['_ffla_delivery']['mode']==='ship','shipping line recorded');
$order=pickup_order([$ship],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer&&!isset($order->meta[Pickup_Shipping_Checkout::SHIP_TO_META]),'shipping order keeps its address and gets no mark');
// One pickup and one shipping package: the customer address is still needed.
$order=pickup_order([$pickup,$ship],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'pickup plus shipping packages keep the customer address');
// A line the module did not mark (module was off, or another plugin built it).
$order=pickup_order([$pickup,new ShipItem()],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'unmarked shipping line leaves the address alone');
$order=pickup_order([],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'order without shipping lines untouched');
// An FFL dealer on the order belongs to g-FFL Checkout.
$order=pickup_order([$pickup],$customer,['_shipping_fflno'=>'9-77-111-01-8A-05780']);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'order with a dealer keeps the provider address');
// Resumed order (failed payment, same cart). WooCommerce resets only the shipping
// fields the checkout shows; here Company is hidden, as on many stores.
$visible=['first_name','last_name','country','address_1','address_2','city','state','postcode','phone'];
$copied=[];foreach($visible as $k){$copied['shipping_'.$k]=$customer[$k];}
function resume(PickupOrder $o,array $lines,array $data){$o->items=$lines;foreach($data as $k=>$v){$o->shipping[substr($k,9)]=$v;}Pickup_Shipping_Checkout::pickup_ship_to($o,$data);}
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
resume($order,[$ship],$copied);
check($order->shipping['company']===''&&$order->shipping['address_1']==='100 Main Street'&&$order->shipping['city']==='Springfield','resumed order that now ships drops the store company');
check(!isset($order->meta[Pickup_Shipping_Checkout::SHIP_TO_META]),'resumed shipping order loses the store mark');
$hidden2=$copied;unset($hidden2['shipping_address_2']);
$order=pickup_order([$pickup],$customer);$wc->countries=new Countries(array_merge($store,['address_2'=>'Suite 2']));Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['address_2']==='Suite 2','store line 2 written');
resume($order,[$ship],$hidden2);
check($order->shipping['address_2']==='Apt 4','hidden Address line 2 comes back from billing, not the store suite');
$wc->countries=new Countries($store);
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
resume($order,[$pickup],$copied);
check($order->shipping===$storeShipTo&&$order->meta[Pickup_Shipping_Checkout::SHIP_TO_META]==='store','resumed order that is still a pickup keeps the store ship-to');
$order=pickup_order([$ship],$customer);resume($order,[$ship],$copied);
check($order->shipping===$customer,'unmarked resumed shipping order is not touched');
// Resumed with "Ship to a different address": the typed address wins over billing.
$typed=$copied;$typed['shipping_address_1']='9 Other Road';$typed['shipping_city']='Lakeside';$typed['shipping_postcode']='10001';
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
resume($order,[$ship],$typed);
check($order->shipping['address_1']==='9 Other Road'&&$order->shipping['city']==='Lakeside'&&$order->shipping['postcode']==='10001'&&$order->shipping['company']==='','typed ship-to kept on a resumed order; only unsent fields come from billing');
// FFL pickup decided by the dealer: never rewritten, even without dealer meta.
$ffl=true;$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['ffl_enabled'=>true]);$wc->cart->items=cart_items([2]);Pickup_Shipping_Checkout::clear();
Pickup_Shipping_Checkout::update('shipping_fflno=9-77-111-01-8A-05780');
$fflPickup=new ShipItem();Pickup_Shipping_Checkout::shipping_meta($fflPickup,0,['contents'=>$wc->cart->items],new Order());
check($fflPickup->meta['_ffla_delivery']['mode']==='pickup'&&$fflPickup->meta['_ffla_delivery']['reason']==='ffl','dealer pickup line records the FFL reason');
$order=pickup_order([$fflPickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'FFL pickup line is left to g-FFL Checkout');
$ffl=false;$options[Pickup_Shipping_Settings::OPTION]=$s;$wc->cart->items=cart_items([1]);Pickup_Shipping_Checkout::clear();
$pickup=delivery_line('pickup');
// Paused module: Checkout block page, not checkout, incomplete settings.
$blocks=true;$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'Checkout block page untouched');$blocks=false;
$checkout=false;$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'request outside checkout untouched');$checkout=true;
$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['pickup_methods'=>[]]);
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'incomplete module settings untouched');
$options[Pickup_Shipping_Settings::OPTION]=$s;
// Never a partial address.
foreach(['address_1','city','country'] as $missing){
 $wc->countries=new Countries(array_merge($store,[$missing=>' ']));
 check(Pickup_Shipping_Checkout::store_ship_to()===[],'incomplete store address yields nothing: '.$missing);
 $order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
 check($order->shipping===$customer&&!isset($order->meta[Pickup_Shipping_Checkout::SHIP_TO_META]),'incomplete store address leaves the order alone: '.$missing);
}
$wc->countries=null;check(Pickup_Shipping_Checkout::store_ship_to()===[],'no WooCommerce countries object yields nothing');
$wc->countries=new Countries($store);
// No pickup location name: the site name, decoded.
$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['store_name'=>'']);
check(Pickup_Shipping_Checkout::store_ship_to()['company']==='Sample & Sons','company falls back to the decoded site name');
$options[Pickup_Shipping_Settings::OPTION]=$s;
// Filter: change, or cancel with an empty array.
$hooks['ffla_pickup_shipping_ship_to_address'][]=function($a,$o){$a['address_1']='2 Warehouse Road';return $a;};
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['address_1']==='2 Warehouse Road','filter can change the ship-to');
array_pop($hooks['ffla_pickup_shipping_ship_to_address']);
$hooks['ffla_pickup_shipping_ship_to_address'][]=function($a,$o){return [];};
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer&&!isset($order->meta[Pickup_Shipping_Checkout::SHIP_TO_META]),'filter returning an empty array cancels the change');
array_pop($hooks['ffla_pickup_shipping_ship_to_address']);
$hooks['ffla_pickup_shipping_ship_to_address'][]=function($a,$o){return 'not an array';};
check(Pickup_Shipping_Checkout::store_ship_to()===[],'filter returning a non-array is ignored safely');
array_pop($hooks['ffla_pickup_shipping_ship_to_address']);
$hooks['ffla_pickup_shipping_ship_to_address'][]=function($a,$o){$a['bogus']='x';$a['phone']=['array'];return $a;};
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check(!isset($order->shipping['bogus'])&&$order->shipping['phone']==='','unknown keys skipped, non-scalar values written as empty');
array_pop($hooks['ffla_pickup_shipping_ship_to_address']);
check(Pickup_Shipping_Checkout::store_ship_to(null)['first_name']==='','settings-screen preview has no customer name');
// Registered after g-FFL Checkout's create-order hook (priority 10).
$priority=null;foreach($hook_priorities['woocommerce_checkout_create_order']??[] as $entry){if($entry[0]===['Pickup_Shipping_Checkout','pickup_ship_to']){$priority=$entry[1];}}
check($priority===50,'ship-to runs on woocommerce_checkout_create_order at priority 50, after g-FFL Checkout');
// Settings screen shows the address that will be used.
ob_start();Pickup_Shipping_Admin::render();$adminHtml=ob_get_clean();
check(strpos($adminHtml,'Sample Store, 200 Sample Avenue, Sampleton, TX 75001, US')!==false,'settings screen shows the pickup ship-to address');
$wc->countries=new Countries(array_merge($store,['address_1'=>'']));
ob_start();Pickup_Shipping_Admin::render();$adminHtml=ob_get_clean();
check(strpos($adminHtml,'No complete store address found.')!==false,'settings screen explains a missing store address');
$wc->countries=new Countries($store);
echo ($checks-$start)." pickup ship-to checks passed.\n";
