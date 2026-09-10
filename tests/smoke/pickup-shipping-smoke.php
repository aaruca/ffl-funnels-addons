<?php
/** Offline regression + optional HTML fixture: php this-file.php [fixture]. */
define('ABSPATH', __DIR__);
define('FFLA_URL','https://fixture.invalid/');
define('FFLA_VERSION','test');
$hooks=[]; $options=[]; $blocks=false; $checkout=true; $ffl=false; $compliance=''; $doing='';
function __( $s,$d='' ){return $s;} function esc_html__($s,$d=''){return esc_html($s);}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);} function esc_textarea($s){return esc_html($s);} function esc_url($s){return esc_html($s);}
function esc_html_e($s,$d=''){echo esc_html($s);} function esc_attr_e($s,$d=''){echo esc_html($s);}
function selected($a,$b,$echo=true){$s=$a==$b?'selected':'';if($echo)echo $s;return $s;}
function checked($a,$b=true,$echo=true){$s=$a==$b?'checked':'';if($echo)echo $s;return $s;}
function disabled($a,$b=true,$echo=true){$s=$a==$b?'disabled':'';if($echo)echo $s;return $s;}
function add_action($n,$c,$p=10,$a=1){$GLOBALS['hooks'][$n][]=$c;}
function add_filter($n,$c,$p=10,$a=1){$GLOBALS['hooks'][$n][]=$c;}
function add_shortcode($n,$c){$GLOBALS['hooks'][$n][]=$c;}
function apply_filters($n,$v,...$a){return $v;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$a=false){$GLOBALS['options'][$k]=$v;}
function add_option($k,$v,...$a){if(!isset($GLOBALS['options'][$k]))$GLOBALS['options'][$k]=$v;}
function wp_unslash($s){return $s;}
function wp_json_encode($v){return json_encode($v);}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function sanitize_textarea_field($s){return sanitize_text_field($s);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_hex_color($s){return preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/i',$s)?$s:null;}
function wp_strip_all_tags($s){return strip_tags($s);}
function is_admin(){return false;} function wp_doing_ajax(){return false;}
function is_checkout(){return $GLOBALS['checkout'];} function is_order_received_page(){return false;} function is_wc_endpoint_url($s){return false;}
function has_block(...$a){return $GLOBALS['blocks'];} function wc_get_page_id($s){return 1;}
function current_user_can($s){return true;} function admin_url($s){return 'https://fixture.invalid/'.$s;}
function wp_nonce_field($s){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
if(($argv[1]??'')!=='missing-provider'){function order_requires_ffl_selector(){return $GLOBALS['ffl'];}}
function ffl_get_checkout_compliance_type(){return $GLOBALS['compliance'];}
function doing_action($s){return $GLOBALS['doing']===$s;}
class Session {public $data=[];function get($k,$d=null){return $this->data[$k]??$d;}function set($k,$v){$this->data[$k]=$v;}function __unset($k){unset($this->data[$k]);}}
class Product {public $firearm;public $parent;public $shipping;
function __construct($ffl=false,$parent=0,$shipping=true){$this->firearm=$ffl;$this->parent=$parent;$this->shipping=$shipping;}
function get_meta($k){return $this->firearm?'yes':'no';}function get_parent_id(){return $this->parent;}function needs_shipping(){return $this->shipping;}}
function wc_get_product($id){return $GLOBALS['products'][$id]??null;}
class Cart {public $items=[];function get_cart(){return $this->items;}function needs_shipping(){foreach($this->items as $i){if($i['data']->needs_shipping())return true;}return false;}}
class Shipping {public $packages=[];function get_packages(){return $this->packages;}}
class Errors {public $errors=[];function add($k,$v){$this->errors[$k]=$v;}}
class Rate {public $cost=17;public $taxes=[2.1];}
class ShipItem {public $meta=[];function add_meta_data($k,$v,$u){$this->meta[$k]=$v;}function get_meta($k){return $this->meta[$k]??'';}function get_name(){return 'Pickup';}}
class Order {public $items=[];function get_shipping_methods(){return $this->items;}}
$wc=(object)['session'=>new Session(),'cart'=>new Cart(),'shipping'=>new Shipping()];
function WC(){return $GLOBALS['wc'];}
// Use an object method for WC()->shipping(), like WooCommerce.
class Woo {public $session;public $cart;public $ship;function __construct(){ $this->session=new Session();$this->cart=new Cart();$this->ship=new Shipping();}function shipping(){return $this->ship;}}
$wc=new Woo();
$products=[1=>new Product(),2=>new Product(true),3=>new Product(false,2)];
function cart_items($ids){$items=[];foreach($ids as $id){$items['item'.$id]=['product_id'=>$id,'variation_id'=>0,'quantity'=>1,'data'=>$GLOBALS['products'][$id]];}return $items;}
$wc->cart->items=cart_items([1]);
$root=dirname(__DIR__,2);
require $root.'/includes/class-ffla-module.php';
require $root.'/modules/pickup-shipping/class-pickup-shipping-module.php';
require $root.'/modules/pickup-shipping/includes/class-pickup-shipping-settings.php';
require $root.'/modules/pickup-shipping/includes/class-pickup-shipping-engine.php';
require $root.'/modules/pickup-shipping/includes/class-pickup-shipping-checkout.php';
require $root.'/modules/pickup-shipping/admin/class-pickup-shipping-admin.php';
$catalog=['local_pickup:1'=>['pickup'=>true,'label'=>'Local zone — Pickup (local_pickup:1)'],'local_pickup:3'=>['pickup'=>true,'label'=>'Second branch — Pickup'],'flat_rate:2'=>['pickup'=>false,'label'=>'USA — Ground shipping (flat_rate:2)']];
class WC_Shipping_Zones {static function get_zones(){return [['zone_id'=>1,'zone_name'=>'Local zone']];}static function get_zone($id){return new Zone($id);}}
class Zone {public $id;function __construct($id){$this->id=$id;}function get_shipping_methods($enabled){return $this->id===1?[new Method('local_pickup',1),new Method('flat_rate',2),new Method('local_pickup',3)]:[];}}
class Method {public $id;public $instance;function __construct($id,$i){$this->id=$id;$this->instance=$i;}function get_instance_id(){return $this->instance;}function get_title(){return $this->id;}function supports($x){return $this->id==='local_pickup';}}
$s=Pickup_Shipping_Settings::sanitize([
'delivery'=>'both','default'=>'none','pickup_methods'=>['local_pickup:1','local_pickup:3'],'shipping_methods'=>['flat_rate:2'],'store_name'=>'Sample Store','store_address'=>'100 Sample Street','instructions'=>'Wait for your ready-for-pickup email.',
'locations'=>[['name'=>'Main store','license'=>'9-77-111-01-8A-05780','method'=>'local_pickup:1','address'=>'100 Sample Street'],['name'=>'Second store','license'=>'9-77-111-01-8A-05781','method'=>'local_pickup:3','address'=>'200 Sample Street']],
],$catalog);
$options[Pickup_Shipping_Settings::OPTION]=$s;
$options['ffl_local_pickup']='9-77-111-01-8A-05780';
$s=Pickup_Shipping_Settings::get();
$rates=['local_pickup:1'=>new Rate(),'flat_rate:2'=>new Rate(),'local_pickup:3'=>new Rate(),'free_shipping:4'=>new Rate()];
$package=['contents'=>$wc->cart->items,'destination'=>['country'=>'US','postcode'=>'10001']];
$checks=0;
function check($c,$m){$GLOBALS['checks']++;if(!$c)throw new RuntimeException('FAIL: '.$m);}
if(($argv[1]??'')==='missing-provider'){
 check(Pickup_Shipping_Settings::provider_license()==='','inactive provider cannot authorize native pickup from leftover options');
 $without=$s;$without['ffl_enabled']=true;
 check(!Pickup_Shipping_Settings::configured($without),'FFL integration pauses without provider');
 $without['ffl_enabled']=false;
 check(Pickup_Shipping_Settings::configured($without),'regular pickup remains usable without FFL provider');
 echo "$checks missing-provider checks passed.\n";exit;
}
if(($argv[1]??'')==='fixture'){
 if(($argv[4]??'')==='variables'){$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['accent'=>'var(--primary, #2271b1)','background'=>'var(--surface, #ffffff)','text_color'=>'var(--text, #123456)']);}
 if(($argv[3]??'')==='ffl'){$wc->cart->items=cart_items([2]);$package['contents']=$wc->cart->items;$ffl=true;$options[Pickup_Shipping_Settings::OPTION]['ffl_enabled']=true;}
 if(!empty($argv[2])){Pickup_Shipping_Checkout::update($argv[2]);}
 $wc->ship->packages=Pickup_Shipping_Checkout::packages([$package]);$wc->ship->packages[0]['rates']=Pickup_Shipping_Checkout::rates($rates,$wc->ship->packages[0]);
 ob_start();Pickup_Shipping_Admin::render();$admin=ob_get_clean();
 echo json_encode(['admin'=>$admin,'checkout'=>Pickup_Shipping_Checkout::html(),'rates'=>array_keys($wc->ship->packages[0]['rates'])]);exit;
}
check(Pickup_Shipping_Settings::license('9-77-111-01-8a-05780')==='977111018A05780','canonical formatting');
check(Pickup_Shipping_Settings::license(['bad'])==='' && Pickup_Shipping_Settings::license('store name')==='','malformed identity rejected');
check(Pickup_Shipping_Settings::defaults()['default']==='none','no implicit free/default pickup');
check(!Pickup_Shipping_Settings::configured(Pickup_Shipping_Settings::defaults()),'unconfigured module inactive');
check(Pickup_Shipping_Settings::configured($s),'valid config');
$bad=Pickup_Shipping_Settings::sanitize(['pickup_methods'=>['flat_rate:2','unknown'],'shipping_methods'=>['local_pickup:1'],'accent'=>'red;display:none','title'=>'<b>Hello</b>'],$catalog);
check(!$bad['pickup_methods']&&!$bad['shipping_methods']&&$bad['accent']===''&&$bad['title']==='Hello','invalid methods and CSS excluded');
$colors=json_decode(file_get_contents(__DIR__.'/pickup-shipping-colors.json'),true);
foreach($colors['accepted'] as $input=>$expected){
 $saved=Pickup_Shipping_Settings::sanitize(array_merge($s,['accent'=>$input,'background'=>$input,'text_color'=>$input]),$catalog);
 check($saved['accent']===$expected&&$saved['background']===$expected&&$saved['text_color']===$expected,'color saved: '.$input);
 $options[Pickup_Shipping_Settings::OPTION]=$saved;
 check(strpos(Pickup_Shipping_Checkout::html(),'--ffla-delivery-accent:'.$expected)!==false,'color rendered: '.$input);
}
foreach(array_merge($colors['rejected'],[[],null,str_repeat('a',257)]) as $input){
 check(Pickup_Shipping_Settings::color($input)==='','unsafe/malformed color rejected');
}
$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['accent'=>'var(--primary);display:none']);
check(strpos(Pickup_Shipping_Checkout::html(),'--ffla-delivery-accent:')===false,'unsafe stored color rejected at rendering');
$options[Pickup_Shipping_Settings::OPTION]=$s;
check(count($s['locations'])===2,'multiple own FFLs');
check(Pickup_Shipping_Engine::product_requires_ffl($products[3]),'variation inherits FFL flag');
$d=Pickup_Shipping_Engine::decision($s,'regular',['mode'=>'ship']);
check(array_keys(Pickup_Shipping_Engine::filter($rates,$d))===['flat_rate:2'],'ship only');
check(Pickup_Shipping_Engine::allows('ups:4:ground',['ups:4']),'carrier service suffix allowed');
check(!Pickup_Shipping_Engine::allows('ups:40:ground',['ups:4']),'adjacent carrier instance denied');
$carrier=new Rate();check(Pickup_Shipping_Engine::filter(['ups:4:ground'=>$carrier,'ups:40:ground'=>new Rate()],['methods'=>['ups:4']])===['ups:4:ground'=>$carrier],'carrier rate object preserved');
$d=Pickup_Shipping_Engine::decision($s,'regular',['mode'=>'pickup']);
$filtered=Pickup_Shipping_Engine::filter($rates,$d);
check(array_keys($filtered)===['local_pickup:1','local_pickup:3'],'pickup only');
check($filtered['local_pickup:1']===$rates['local_pickup:1']&&$filtered['local_pickup:1']->cost===17,'preserves rates and charges');
$sf=$s;$sf['ffl_enabled']=true;
$d=Pickup_Shipping_Engine::decision($sf,'ffl',['license'=>'9-77-111-01-8A-05780']);
check($d['methods']===['local_pickup:1','local_pickup:3'],'native local FFL uses configured WooCommerce pickup methods');
$d=Pickup_Shipping_Engine::decision($sf,'ffl',['license'=>'9-77-111-01-8A-05781']);
check($d['mode']==='ship','legacy own FFL cannot authorize pickup');
$d=Pickup_Shipping_Engine::decision($sf,'ffl',['license'=>'9-77-111-01-8A-99999','shipping_ffl_name'=>'Main store']);
check($d['mode']==='ship','matching name cannot authorize local pickup');
check(Pickup_Shipping_Engine::decision($sf,'ffl',[])['mode']==='pending','missing dealer blocks methods');
$sf['delivery']='pickup';
check(Pickup_Shipping_Engine::decision($sf,'ffl',['license'=>'9-77-111-01-8A-99999'])['methods']===[],'pickup-only cannot override external FFL');
check(Pickup_Shipping_Engine::filter(['flat_rate:2'=>new Rate()],Pickup_Shipping_Engine::decision($s,'regular',['mode'=>'pickup']))===[],'no unsafe fallback when pickup absent');
$wc->cart->items=cart_items([1,2]);
check(Pickup_Shipping_Engine::package_scope(['contents'=>$wc->cart->items],true,true)==='mixed','unsplit mixed package detected');
check(Pickup_Shipping_Engine::package_scope(['contents'=>cart_items([1])],true,true)==='regular','separated regular package');
check(Pickup_Shipping_Engine::package_scope(['contents'=>cart_items([2])],true,true)==='ffl','separated FFL package');
check(Pickup_Shipping_Engine::decision($sf,'mixed',[])['mode']==='blocked','no guessing mixed destinations');
$wc->cart->items=cart_items([1]);
Pickup_Shipping_Checkout::update('ffla_delivery_mode=ship');
Pickup_Shipping_Checkout::update('billing_city=Example');
check(Pickup_Shipping_Checkout::state()['mode']==='ship','missing field preserves explicit selection');
$checkout=false;$cartPackage=Pickup_Shipping_Checkout::packages([$package]);
$checkout=true;$checkoutPackage=Pickup_Shipping_Checkout::packages([$package]);
check($cartPackage[0]['ffla_delivery']!==$checkoutPackage[0]['ffla_delivery'],'cart->checkout cache context invalidates');
$shipHash=md5(json_encode($checkoutPackage));
Pickup_Shipping_Checkout::update('ffla_delivery_mode=pickup');
check($shipHash!==md5(json_encode(Pickup_Shipping_Checkout::packages([$package]))),'changing mode invalidates package hash');
$wc->ship->packages=Pickup_Shipping_Checkout::packages([$package]);
$wc->ship->packages[0]['rates']=Pickup_Shipping_Checkout::rates($rates,$wc->ship->packages[0]);
$e=new Errors();Pickup_Shipping_Checkout::validate(['shipping_method'=>['flat_rate:2']],$e);
check((bool)$e->errors,'final incompatible method rejected');
$e=new Errors();Pickup_Shipping_Checkout::validate(['shipping_method'=>['local_pickup:1']],$e);
check(!$e->errors,'valid pickup accepted');
$e=new Errors();Pickup_Shipping_Checkout::validate(['shipping_method'=>[['invalid']]],$e);
check((bool)$e->errors,'array injection in chosen method rejected');
Pickup_Shipping_Checkout::update('ffla_delivery_mode=bogus');$e=new Errors();Pickup_Shipping_Checkout::validate(['shipping_method'=>['local_pickup:1']],$e);
check((bool)$e->errors,'invalid delivery mode not treated as consent');
$wc->cart->items=cart_items([2]);Pickup_Shipping_Checkout::cart_updated();
check(Pickup_Shipping_Checkout::state()===[],'changed cart clears state');
$ffl=true;$sf['delivery']='both';$options[Pickup_Shipping_Settings::OPTION]=$sf;
Pickup_Shipping_Checkout::update('shipping_fflno=9-77-111-01-8A-05780');
$_POST=[];Pickup_Shipping_Checkout::process();
check(Pickup_Shipping_Checkout::state()['license']==='','final submit cannot rely on old dealer session');
$_POST=['shipping_fflno'=>'9-77-111-01-8A-05780'];Pickup_Shipping_Checkout::process();
$before=Pickup_Shipping_Checkout::packages([['contents'=>$wc->cart->items]]);
$options['ffl_local_pickup']='9-77-111-01-8A-05781';
$after=Pickup_Shipping_Checkout::packages([['contents'=>$wc->cart->items]]);
check($before[0]['ffla_delivery']['decision']['mode']==='pickup'&&$after[0]['ffla_delivery']['decision']['mode']==='ship','changing native configuration revokes old local identity without addon save');
check($before[0]['ffla_delivery']['version']!==$after[0]['ffla_delivery']['version'],'native setting changes invalidate shipping policy cache');
check(Pickup_Shipping_Engine::decision(Pickup_Shipping_Settings::get(),'ffl',['license'=>'977111018A05781'])['mode']==='pickup','new native local FFL is detected automatically');
foreach(['', 'not a license', ['invalid']] as $native){
 $options['ffl_local_pickup']=$native;
 check(Pickup_Shipping_Engine::decision(Pickup_Shipping_Settings::get(),'ffl',['license'=>'977111018A05780'])['mode']==='ship','missing/malformed native setting cannot fall back to legacy own locations');
}
ob_start();Pickup_Shipping_Admin::render();$missingHtml=ob_get_clean();
check(strpos($missingHtml,'No valid Local Pickup FFL detected.')!==false,'missing native setup explained in admin');
$options[Pickup_Shipping_Settings::OPTION]['ffl_pickup_license']='977111018A05780';
check(Pickup_Shipping_Settings::get()['ffl_pickup_license']==='','forged addon setting cannot override missing native configuration');
$options['ffl_local_pickup']='9-77-111-01-8A-05780';
$options[Pickup_Shipping_Settings::OPTION]['locations']=[];
check(Pickup_Shipping_Settings::configured(Pickup_Shipping_Settings::get()),'FFL integration no longer needs duplicate location setup');
check(Pickup_Shipping_Engine::decision(Pickup_Shipping_Settings::get(),'ffl',['license'=>'977111018A05780'])['mode']==='pickup','native pickup works with no addon location rows');
$doing='';$_POST=[];$ffl=false;$wc->cart->items=cart_items([1]);$options[Pickup_Shipping_Settings::OPTION]=$s;Pickup_Shipping_Checkout::clear();
Pickup_Shipping_Checkout::update('ffla_delivery_mode=pickup');
$item=new ShipItem();Pickup_Shipping_Checkout::shipping_meta($item,0,$package,new Order());
check($item->meta['_ffla_delivery']['address']==='100 Sample Street','pickup snapshot saved via CRUD');
$order=new Order();$order->items=[$item];ob_start();Pickup_Shipping_Checkout::email_summary($order,false,true,null);$email=ob_get_clean();
check(strpos($email,'100 Sample Street')!==false&&strpos($email,'<')===false,'plain text delivery summary');
$compliance='ammunition';check(Pickup_Shipping_Checkout::requires_ffl(),'provider state compliance retained');$compliance='';
$blocks=true;check(!Pickup_Shipping_Checkout::active(),'Blocks left untouched');$blocks=false;
Pickup_Shipping_Checkout::clear();
check($wc->session->get(Pickup_Shipping_Checkout::SESSION)===null&&$wc->session->get(Pickup_Shipping_Checkout::AVAILABLE)===null,'all module session keys cleared');
Pickup_Shipping_Checkout::init();
check(!isset($hooks['option_ffl_local_pickup']),'native FFL option and validation never overridden');
check(isset($hooks['woocommerce_after_checkout_validation'],$hooks['ffla_delivery_choice'],$hooks['woocommerce_cart_shipping_packages']),'server validation, shortcode, cache hooks registered');
$module=new Pickup_Shipping_Module();check($module->get_id()==='pickup-shipping','independent module id');
echo "$checks Pickup & Shipping checks passed.\n";
