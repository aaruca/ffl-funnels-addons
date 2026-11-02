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
class PickupOrder extends Order {public $meta=[];public $shipping=[];public $billing=[];
function get_meta($k){return $this->meta[$k]??'';}
function set_address($a,$type){foreach($a as $k=>$v){$this->{$type}[$k]=$v;}}
function __call($n,$args){if(preg_match('/^get_(shipping|billing)_(\w+)$/',$n,$m)){return $this->{$m[1]}[$m[2]]??'';}throw new BadMethodCallException($n);}}
$moved=new WooWithCountries();$moved->session=$wc->session;$moved->cart=$wc->cart;$moved->ship=$wc->ship;$wc=$moved;
$store=['address_1'=>'1780 Ventura Blvd','address_2'=>'','city'=>'Camarillo','state'=>'CA','postcode'=>'93010','country'=>'US'];
$wc->countries=new Countries($store);
$customer=['first_name'=>'Sean','last_name'=>'Buyer','company'=>'','address_1'=>'1207 Example Way','address_2'=>'Apt 4','city'=>'Oxnard','state'=>'CA','postcode'=>'93030','country'=>'US','phone'=>'8055550100'];
function pickup_order(array $lines,array $customer,array $meta=[]){$o=new PickupOrder();$o->items=$lines;$o->billing=$customer+['email'=>'buyer@example.test'];$o->shipping=$customer;$o->meta=$meta;return $o;}
function delivery_line($mode){Pickup_Shipping_Checkout::update('ffla_delivery_mode='.$mode);$i=new ShipItem();Pickup_Shipping_Checkout::shipping_meta($i,0,$GLOBALS['package'],new Order());return $i;}
// Regular cart, delivery cards, store pickup.
$doing='';$_POST=[];$ffl=false;$blocks=false;$checkout=true;$wc->cart->items=cart_items([1]);
$options[Pickup_Shipping_Settings::OPTION]=$s;Pickup_Shipping_Checkout::clear();
$pickup=delivery_line('pickup');
check($pickup->meta['_ffla_delivery']['mode']==='pickup'&&$pickup->meta['_ffla_delivery']['reason']==='customer','pickup line records that the customer chose it');
$order=pickup_order([$pickup],$customer);
Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['company']==='Sample Store','ship-to company is the pickup location name');
check($order->shipping['address_1']==='1780 Ventura Blvd'&&$order->shipping['address_2']===''&&$order->shipping['city']==='Camarillo'&&$order->shipping['state']==='CA'&&$order->shipping['postcode']==='93010'&&$order->shipping['country']==='US','ship-to street, city, state, ZIP and country are the store address');
check($order->shipping['first_name']==='Sean'&&$order->shipping['last_name']==='Buyer','ship-to keeps the customer name');
check($order->shipping['phone']==='8055550100','ship-to keeps the customer phone');
check($order->billing===$customer+['email'=>'buyer@example.test'],'billing address untouched');
// The customer typed another recipient: that name is kept, the street is not.
$order=pickup_order([$pickup],$customer);$order->shipping['first_name']='Alex';$order->shipping['last_name']='Other';
Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['first_name']==='Alex'&&$order->shipping['address_1']==='1780 Ventura Blvd','typed recipient name kept, street replaced');
// Empty shipping names fall back to billing.
$order=pickup_order([$pickup],$customer);$order->shipping['first_name']='';$order->shipping['last_name']='';
Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['first_name']==='Sean'&&$order->shipping['last_name']==='Buyer','empty shipping names fall back to billing');
// Shipping orders keep the customer address.
$ship=delivery_line('ship');
check($ship->meta['_ffla_delivery']['mode']==='ship','shipping line recorded');
$order=pickup_order([$ship],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'shipping order keeps its address');
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
// FFL pickup decided by the dealer: never rewritten, even without dealer meta.
$ffl=true;$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['ffl_enabled'=>true]);$wc->cart->items=cart_items([2]);Pickup_Shipping_Checkout::clear();
Pickup_Shipping_Checkout::update('shipping_fflno=9-77-111-01-8A-05780');
$fflPickup=new ShipItem();Pickup_Shipping_Checkout::shipping_meta($fflPickup,0,['contents'=>$wc->cart->items],new Order());
check($fflPickup->meta['_ffla_delivery']['mode']==='pickup'&&$fflPickup->meta['_ffla_delivery']['reason']==='ffl','dealer pickup line records the FFL reason');
$order=pickup_order([$fflPickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping===$customer,'FFL pickup line is left to g-FFL Checkout');
$ffl=false;$options[Pickup_Shipping_Settings::OPTION]=$s;$wc->cart->items=cart_items([1]);Pickup_Shipping_Checkout::clear();
$pickup=delivery_line('pickup');
// Paused module: Checkout block page, old Camarillo snippet era, not checkout.
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
 check($order->shipping===$customer,'incomplete store address leaves the order alone: '.$missing);
}
$wc->countries=null;check(Pickup_Shipping_Checkout::store_ship_to()===[],'no WooCommerce countries object yields nothing');
$wc->countries=new Countries(array_merge($store,['address_2'=>'Suite 2']));
$order=pickup_order([$pickup],$customer);Pickup_Shipping_Checkout::pickup_ship_to($order,[]);
check($order->shipping['address_2']==='Suite 2','store address line 2 replaces the customer line 2');
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
check($order->shipping===$customer,'filter returning an empty array cancels the change');
array_pop($hooks['ffla_pickup_shipping_ship_to_address']);
$hooks['ffla_pickup_shipping_ship_to_address'][]=function($a,$o){return 'not an array';};
check(Pickup_Shipping_Checkout::store_ship_to()===[],'filter returning a non-array is ignored safely');
array_pop($hooks['ffla_pickup_shipping_ship_to_address']);
check(Pickup_Shipping_Checkout::store_ship_to(null)['first_name']==='','settings-screen preview has no customer name');
// Registered after g-FFL Checkout's create-order hook.
$registered=false;foreach($hooks['woocommerce_checkout_create_order']??[] as $cb){if($cb===['Pickup_Shipping_Checkout','pickup_ship_to']){$registered=true;}}
check($registered,'ship-to runs on woocommerce_checkout_create_order');
check(strpos(file_get_contents($root.'/modules/pickup-shipping/includes/class-pickup-shipping-checkout.php'),"'woocommerce_checkout_create_order', [__CLASS__,'pickup_ship_to'], 50, 2")!==false,'ship-to hook priority is after g-FFL Checkout (10)');
// Settings screen shows the address that will be used.
ob_start();Pickup_Shipping_Admin::render();$adminHtml=ob_get_clean();
check(strpos($adminHtml,'Sample Store, 1780 Ventura Blvd, Camarillo, CA 93010, US')!==false,'settings screen shows the pickup ship-to address');
$wc->countries=new Countries(array_merge($store,['address_1'=>'']));
ob_start();Pickup_Shipping_Admin::render();$adminHtml=ob_get_clean();
check(strpos($adminHtml,'No complete store address found.')!==false,'settings screen explains a missing store address');
$wc->countries=new Countries($store);
echo ($checks-$start)." pickup ship-to checks passed.\n";
