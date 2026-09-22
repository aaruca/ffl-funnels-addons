<?php
/** Internal-rate extension contract; no FPPC plugin or live checkout required. */
require __DIR__.'/pickup-shipping-smoke.php';
$start=$checks;
$hook='ffla_pickup_shipping_keep_internal_rate';
$internalId='fppc_deferred_shipping';
class InternalPlanRate {
    public $id; public $cost; public $marked; public $taxes=[];
    function __construct($id='fppc_deferred_shipping',$cost=0,$marked=true){$this->id=$id;$this->cost=$cost;$this->marked=$marked;}
    function get_id(){return $this->id;}
    function get_cost(){return $this->cost;}
}
$internal=new InternalPlanRate();
$internalRates=[$internalId=>$internal];
$store='9-77-111-01-8A-05780';$remote='9-77-111-01-8A-99999';
$ffl=true;$wc->cart->items=cart_items([2]);
$options[Pickup_Shipping_Settings::OPTION]=array_merge($s,['ffl_enabled'=>true]);
$options['ffl_local_pickup']=$store;
// Fixture-only plan flags: production ownership/plan checks belong to FPPC.
$plan=['contents'=>cart_items([2]),'destination'=>['country'=>'US','postcode'=>'10001'],'test_plan'=>['excluded'=>true,'allow_local_pickup'=>true]];
function internal_packages(array $base,array $offered,string $license,string $mode='ship'): array {
    $_POST=['shipping_fflno'=>$license,'ffla_delivery_mode'=>$mode];
    Pickup_Shipping_Checkout::process();
    $packages=Pickup_Shipping_Checkout::packages($base);
    foreach($packages as &$package){$package['rates']=Pickup_Shipping_Checkout::rates($offered,$package);}unset($package);
    return Pickup_Shipping_Checkout::sync_packages($packages);
}
function internal_validate(array $packages,array $methods): Errors {
    WC()->ship->packages=$packages;
    $errors=new Errors();
    Pickup_Shipping_Checkout::validate(['shipping_method'=>$methods],$errors);
    return $errors;
}
$without=internal_packages([2=>$plan],$internalRates,$remote);
check($without[2]['rates']===[],'known internal ID has no built-in exemption');
$calls=[];
$extension=function($keep,$rateId,$rate,$package,$decision) use (&$calls,$internalId) {
    $calls[]=[$keep,$rateId,$rate,$package,$decision];
    check($keep===false,'extension defaults to false');
    check(($package['ffla_delivery']['decision']??null)===$decision,'callback receives coherent server decision in package');
    return $rateId===$internalId && $rate instanceof InternalPlanRate && $rate->get_id()===$rateId
        && $rate->marked && (float)$rate->get_cost()===0.0
        && ($package['test_plan']['excluded']??false)===true
        && ($decision['mode']==='ship' || ($package['test_plan']['allow_local_pickup']??false)===true);
};
add_filter($hook,$extension,10,5);
foreach([$remote=>'ship',$store=>'pickup'] as $dealer=>$mode){
    $packages=internal_packages([2=>$plan],$internalRates,$dealer);
    check($packages[2]['rates']===$internalRates,'authorized internal '.$mode.' survives fresh rates and cached sync');
    check($packages[2]['ffla_delivery']['decision']['mode']===$mode,'semantic mode is not inferred from internal rate ID');
    check($packages[2]['ffla_delivery']['decision']['policy_permitted']===true,'permitted decision is explicit');
    check($wc->session->get('chosen_shipping_methods')[2]===$internalId,'WooCommerce selects offered internal rate');
    check(!internal_validate($packages,[2=>$internalId])->errors,'valid internal '.$mode.' passes checkout validation');
    check(end($calls)[2]===$internal,'validation receives actual offered rate object');
    $item=new ShipItem();Pickup_Shipping_Checkout::shipping_meta($item,2,$packages[2],new Order());
    check($item->meta['_ffla_delivery']['mode']===$mode,'order shipping item freezes semantic '.$mode);
    check($packages[2]['destination']===$plan['destination'] && $internal->cost===0 && $internal->taxes===[],'destination, cost and taxes are unchanged');
    $wc->ship->packages=$packages;
    preg_match('/data-ffla-shipping-policy="([^"]*)"/',Pickup_Shipping_Checkout::html(),$match);
    $browserPolicy=json_decode(html_entity_decode($match[1]??'',ENT_QUOTES),true);
    check(in_array($internalId,$browserPolicy[2]['methods']??[],true),'browser can reconcile the authorized internal rate');
    check(!in_array($internalId,$packages[2]['ffla_delivery']['decision']['methods'],true),'configured method whitelist is not expanded');
}
// The extension, not an ID shortcut, decides whether a plan permits pickup.
$noPickup=$plan;$noPickup['test_plan']['allow_local_pickup']=false;
$implicitPickup=$plan;unset($implicitPickup['test_plan']['allow_local_pickup']);
foreach([$noPickup,$implicitPickup] as $restricted){
    $packages=internal_packages([2=>$restricted],$internalRates,$store);
    check($packages[2]['rates']===[],'pickup requires explicit plan permission');
    $packages[2]['rates']=$internalRates;
    check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'cached internal rate cannot bypass plan pickup restriction');
}
$packages=internal_packages([2=>$noPickup],$internalRates,$remote);
check($packages[2]['rates']===$internalRates,'shipping remains available for a plan without pickup');
foreach(['ship'=>$remote,'pickup'=>$store] as $policy=>$dealer){
    $options[Pickup_Shipping_Settings::OPTION]['delivery']=$policy;
    $permittedPackages=internal_packages([2=>$plan],$internalRates,$dealer);
    check($permittedPackages[2]['rates']===$internalRates,'matching '.$policy.'-only storefront permits internal rate');
    check(!internal_validate($permittedPackages,[2=>$internalId])->errors,'matching '.$policy.'-only storefront validates');
}
$options[Pickup_Shipping_Settings::OPTION]['delivery']='both';
// Backward compatible two-argument calls still only use the configured methods.
$decision=$packages[2]['ffla_delivery']['decision'];
check(Pickup_Shipping_Engine::filter($internalRates,$decision)===[],'missing package context cannot authorize internal rate');
check(!Pickup_Shipping_Engine::allows($internalId,$decision['methods']),'legacy allows call has no exemption');
check(Pickup_Shipping_Engine::filter($rates,['methods'=>['flat_rate:2']])===['flat_rate:2'=>$rates['flat_rate:2']],'legacy filter still preserves paid normal rates');
check(Pickup_Shipping_Engine::allows($internalId,$decision['methods'],$packages[2],$decision,$internal),'context-aware allows supports actual internal rate');
check(!Pickup_Shipping_Engine::allows($internalId,$decision['methods'],$packages[2],$decision),'ID without an offered rate is not sufficient');
// Even a permissive extension must not run for forbidden/pending decisions.
$permissiveCalls=0;
$hooks[$hook]=[function() use (&$permissiveCalls){$permissiveCalls++;return true;}];
foreach(['ship'=>$store,'pickup'=>$remote] as $policy=>$dealer){
    $options[Pickup_Shipping_Settings::OPTION]['delivery']=$policy;
    $packages=internal_packages([2=>$plan],$internalRates,$dealer);
    check($packages[2]['ffla_delivery']['decision']['policy_permitted']===false,'storefront policy denial is explicit');
    check($packages[2]['rates']===[],'extension cannot override '.$policy.'-only policy');
    $packages[2]['rates']=$internalRates;
    check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'validation enforces '.$policy.'-only policy');
}
$options[Pickup_Shipping_Settings::OPTION]['delivery']='both';
$packages=internal_packages([2=>$plan],$internalRates,'');
check($packages[2]['rates']===[] && $packages[2]['ffla_delivery']['decision']['policy_permitted']===false,'missing FFL stays pending');
$packages[2]['rates']=$internalRates;
check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'cached rate does not authorize missing FFL');
$wc->cart->items=cart_items([1,2]);$mixed=$plan;$mixed['contents']=$wc->cart->items;
$packages=internal_packages([2=>$mixed],$internalRates,$remote);
check($packages[2]['rates']===[] && $packages[2]['ffla_delivery']['decision']['policy_permitted']===false,'unsplit mixed destinations remain blocked');
$packages[2]['rates']=$internalRates;
check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'mixed destination validation remains blocked');
check($permissiveCalls===0,'extension never runs for forbidden or unresolved modes');
foreach(['pickup','ship'] as $mode){
    $d=Pickup_Shipping_Engine::decision(Pickup_Shipping_Settings::get(),'regular',['mode'=>$mode]);
    check($d['policy_permitted']===true,'regular permitted mode carries explicit policy flag');
}
$none=Pickup_Shipping_Engine::decision(Pickup_Shipping_Settings::get(),'regular',[]);
check($none['policy_permitted']===false && Pickup_Shipping_Engine::filter($internalRates,$none,$plan)===[],'unselected regular mode has no exemption');
check($permissiveCalls===0,'unselected mode cannot call extension');
$allowed=Pickup_Shipping_Engine::decision(Pickup_Shipping_Settings::get(),'ffl',['license'=>$remote]);
foreach([12,-1,'invalid',null] as $cost){
    check(!Pickup_Shipping_Engine::allows($internalId,$allowed['methods'],$plan,$allowed,new InternalPlanRate($internalId,$cost)),'permissive extension cannot authorize a nonzero or malformed cost');
}
check($permissiveCalls===0,'invalid-cost rates never reach extension');
$hooks[$hook]=[$extension];$wc->cart->items=cart_items([2]);
// A name alone, an unmarked object or a charged rate is never a valid plan rate.
foreach([
    'unmarked'=>new InternalPlanRate($internalId,0,false),
    'paid'=>new InternalPlanRate($internalId,12),
    'mismatched ID'=>new InternalPlanRate('forged_rate'),
    'not a rate'=>new stdClass(),
    'scalar'=>0,
] as $label=>$badRate){
    $offered=[$internalId=>$badRate];
    $packages=internal_packages([2=>$plan],$offered,$remote);
    check($packages[2]['rates']===[],$label.' is stripped');
    $packages[2]['rates']=$offered;
    check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),$label.' cannot pass validation');
}
$unmarked=$plan;unset($unmarked['test_plan']);
$packages=internal_packages([2=>$unmarked],$internalRates,$remote);
check($packages[2]['rates']===[],'normal firearm package does not inherit internal-rate exemption');
$packages[2]['rates']=$internalRates;
check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'unmarked package rejected during validation');
$forgedId=$internalId.':forged';
$packages=internal_packages([2=>$plan],[$forgedId=>new InternalPlanRate($forgedId)],$remote);
check($packages[2]['rates']===[],'lookalike internal ID denied');
$packages=internal_packages([2=>$plan],$internalRates,$remote);
$packages[2]['rates']=[];$before=count($calls);
check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'unoffered posted method rejected');
check(count($calls)===$before,'validation never asks extension to approve an unoffered rate');
// Revalidation uses current submitted FFL, even if session/cached context differs.
$packages=internal_packages([2=>$noPickup],$internalRates,$remote);
foreach([[],['shipping_fflno'=>''],['shipping_fflno'=>[]],['shipping_fflno'=>'invalid'],['shipping_fflno'=>$store],['backup_fflno'=>$store]] as $post){
    $_POST=$post;
    check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'current missing/invalid/pickup FFL beats remote session');
}
$packages=internal_packages([2=>$plan],$internalRates,$store);
$_POST=['shipping_fflno'=>$remote,'ffla_delivery_mode'=>'pickup','ffla_delivery'=>['decision'=>['mode'=>'pickup','policy_permitted'=>true]]];
check(!internal_validate($packages,[2=>$internalId])->errors,'current remote FFL can use existing authorized internal rate');
$last=end($calls);
check($last[4]['mode']==='ship' && $last[3]['ffla_delivery']['dealer']===Pickup_Shipping_Settings::license($remote),'validation callback receives fresh semantic mode and dealer');
// Cached policy and package marker must be replaced before extension invocation.
$options[Pickup_Shipping_Settings::OPTION]['delivery']='ship';
$wc->ship->packages=Pickup_Shipping_Checkout::sync_packages($packages);
check($wc->ship->packages[2]['rates']===[],'current store policy revokes a cached pickup rate');
check(Pickup_Shipping_Checkout::rates($internalRates,$packages[2])===[],'fresh-rate filtering does not trust a stale permitted decision');
$options[Pickup_Shipping_Settings::OPTION]['delivery']='both';
Pickup_Shipping_Checkout::update(['shipping_fflno'=>$remote]);
$synced=Pickup_Shipping_Checkout::sync_packages($packages);
check($synced[2]['rates']===$internalRates && $synced[2]['ffla_delivery']['decision']['mode']==='ship','cached decision refreshed to current dealer before extension');
check($synced[2]['ffla_delivery']['dealer']===Pickup_Shipping_Settings::license($remote),'cached dealer context refreshed');
// Mixed carts: plan FFL, normal FFL and regular customer destinations are independent.
$wc->cart->items=cart_items([1,2]);
$regular=['contents'=>cart_items([1]),'destination'=>['country'=>'US','postcode'=>'90210']];
$base=[2=>$plan,5=>$unmarked,9=>$regular];
foreach([$store=>'pickup',$remote=>'ship'] as $dealer=>$mode){
    $packages=internal_packages($base,$internalRates+$rates,$dealer,'ship');
    check($packages[2]['rates'][$internalId]===$internal,'mixed cart plan keeps internal rate');
    check(!isset($packages[5]['rates'][$internalId]) && !isset($packages[9]['rates'][$internalId]),'normal packages reject internal ID');
    check(array_keys($packages[5]['rates'])===($mode==='pickup'?['local_pickup:1','local_pickup:3']:['flat_rate:2']),'normal firearm methods unchanged');
    check($packages[9]['rates']===['flat_rate:2'=>$rates['flat_rate:2']],'normal customer package rate and charges unchanged');
    $selected=[2=>$internalId,5=>$mode==='pickup'?'local_pickup:1':'flat_rate:2',9=>'flat_rate:2'];
    check(!internal_validate($packages,$selected)->errors,'mixed cart validates package-specific choices');
    foreach($base as $key=>$original){check($packages[$key]['destination']===$original['destination'],'mixed destinations remain intact');}
}
$packages=internal_packages($base,$internalRates+$rates,'','ship');
check($packages[2]['rates']===[] && $packages[5]['rates']===[],'missing FFL blocks both plan and normal firearm packages');
check($packages[9]['rates']===['flat_rate:2'=>$rates['flat_rate:2']],'missing FFL does not alter regular customer package');
// The same callback supports regular plan packages and their delivery controls.
$wc->cart->items=cart_items([1]);$ffl=false;
$regularPlan=$plan;$regularPlan['contents']=cart_items([1]);
foreach(['pickup','ship'] as $mode){
    $packages=internal_packages([2=>$regularPlan],$internalRates,'',$mode);
    check($packages[2]['rates']===$internalRates,'regular internal '.$mode.' accepted');
    check($wc->session->get(Pickup_Shipping_Checkout::AVAILABLE)[2]===['pickup'=>true,'ship'=>true],'regular plan options use extension availability');
    check(!internal_validate($packages,[2=>$internalId])->errors,'regular plan validates');
}
$regularPlan['test_plan']['allow_local_pickup']=false;
$packages=internal_packages([2=>$regularPlan],$internalRates,'','ship');
check($wc->session->get(Pickup_Shipping_Checkout::AVAILABLE)[2]===['pickup'=>false,'ship'=>true],'regular plan pickup option respects extension refusal');
// FFL/address validators retain their errors, even when internal rate is permitted.
$wc->cart->items=cart_items([2]);$ffl=true;$noAddress=$plan;$noAddress['destination']=[];
$packages=internal_packages([2=>$noAddress],$internalRates,$remote);$wc->ship->packages=$packages;
$errors=new Errors();$errors->add('ffl_document_required','Required document');$errors->add('shipping_address','Required destination');
Pickup_Shipping_Checkout::validate(['shipping_method'=>[2=>$internalId]],$errors);
check(array_keys($errors->errors)===['ffl_document_required','shipping_address'],'native destination/document errors remain registered and intact');
$packages=internal_packages([2=>$noAddress],$internalRates,'');
check($packages[2]['rates']===[],'no address and no FFL cannot use internal exemption');
unset($hooks[$hook]);
$packages=internal_packages([2=>$plan],$internalRates,$remote);
check($packages[2]['rates']===[],'removing extension removes internal rate permission');
$packages[2]['rates']=$internalRates;
check(isset(internal_validate($packages,[2=>$internalId])->errors['ffla_delivery_2']),'no extension means no validation exemption for a cached internal rate');
echo ($checks-$start)." internal-rate compatibility checks passed.\n";
