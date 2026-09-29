<?php
/** Isolated order-management regression tests. No WordPress DB, network or real mail. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/'); define('DAY_IN_SECONDS', 86400); define('MB_IN_BYTES', 1048576);
define('FFLA_URL', 'https://fixture.invalid/plugin/');
$GLOBALS['options'] = ['ffla_active_modules'=>['customer-notes']]; $GLOBALS['staff'] = true; $GLOBALS['uid'] = 7;
$GLOBALS['orders'] = []; $GLOBALS['hooks'] = []; $GLOBALS['sent'] = []; $GLOBALS['events'] = [];
function __( $s, $d = null ) { return $s; }
function _n_noop($a,$b,$d=null) { return [$a,$b]; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); } function esc_textarea($s) { return esc_html($s); }
function esc_url($s,$protocols=null) { return preg_match('~^https?://~', $s) ? esc_attr($s) : ''; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n]+/', ' ', strip_tags(is_scalar($s) ? (string)$s : ''))); }
function sanitize_textarea_field($s) { return trim(strip_tags(is_scalar($s) ? (string)$s : '')); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',is_scalar($s) ? $s : '')); }
function sanitize_file_name($s) { return basename($s); } function absint($n) { return abs((int)$n); }
function wp_json_encode($v) { return json_encode($v); } function wp_unslash($v) { return $v; }
function get_option($k,$default=false) { return $GLOBALS['options'][$k] ?? $default; }
function update_option($k,$v,$autoload=null) { $GLOBALS['options'][$k]=$v; return true; }
function add_option($k,$v,$deprecated='',$autoload=null) { if(isset($GLOBALS['options'][$k]))return false; $GLOBALS['options'][$k]=$v; return true; }
function wp_cache_delete($k,$g) {}
function wp_generate_uuid4() { static $n=0; return '00000000-0000-4000-8000-' . str_pad((string)++$n,12,'0',STR_PAD_LEFT); }
function current_user_can($cap,$id=null) { return $GLOBALS['staff'] && $id !== 999; }
function get_current_user_id() { return $GLOBALS['uid']; }
function get_user_by($field,$id) { return $id===7 ? (object)['ID'=>7,'user_email'=>'staff@example.invalid','display_name'=>'Fixture Employee'] : false; }
function get_users($args) { return [get_user_by('id', 7)]; }
function user_can($user,$cap,...$args) { return $user->ID===7; }
function wp_get_current_user() { return get_user_by('id', 7); }
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function wp_date($f,$ts) { return (new DateTimeImmutable('@'.$ts))->setTimezone(wp_timezone())->format($f); }
function get_transient($k) { return $GLOBALS['options'][$k] ?? false; }
function set_transient($k,$v,$ttl) { $GLOBALS['options'][$k]=$v; }
function delete_transient($k) { unset($GLOBALS['options'][$k]); }
function add_action($hook,$cb,$priority=10,$args=1) { $GLOBALS['hooks'][$hook][]=$cb; }
function add_filter(...$args) { add_action(...$args); }
function remove_action($hook,$cb) { $GLOBALS['hooks'][$hook]=array_filter($GLOBALS['hooks'][$hook] ?? [],static function($x)use($cb){return $x!==$cb;}); }
function apply_filters($hook,$value,...$args) { return isset($GLOBALS['filters'][$hook]) ? $GLOBALS['filters'][$hook]($value,...$args) : $value; }
function wp_next_scheduled($hook,$args) { return $GLOBALS['events'][$hook.json_encode($args)]['at'] ?? false; }
function wp_schedule_single_event($at,$hook,$args) { $GLOBALS['events'][$hook.json_encode($args)]=compact('at','hook','args'); return true; }
function wp_mail($to,$subject,$body,$headers=[]) { $GLOBALS['sent'][]=compact('to','subject','body'); if(!empty($GLOBALS['mail_throw']))throw new RuntimeException('secret transport error'); return $GLOBALS['mail_ok'] ?? true; }
function is_email($v) { return filter_var($v,FILTER_VALIDATE_EMAIL)!==false; }
function wc_get_page_permalink($p) { return 'https://fixture.invalid/account/'; }
function admin_url($p='') { return 'https://fixture.invalid/admin/'.$p; }
function wp_create_nonce($a) { return 'fixture-nonce'; }
function wp_nonce_field($a,$name='_wpnonce') { echo '<input type="hidden" name="'.esc_attr($name).'" value="fixture-nonce">'; }
function checked($a,$b=true,$echo=true) { $s=$a==$b?'checked':'';if($echo)echo$s;return$s; }
function selected($a,$b=true,$echo=true) { $s=$a==$b?'selected':'';if($echo)echo$s;return$s; }
function wc_get_order_status_name($s) { return $s==='ffla-ready'?'Ready for Pickup':ucfirst($s); }
function wp_nonce_url($url,$action) { return $url; }
function add_query_arg($args,$url,$third=null) { if($third!==null){$args=[$args=>$url];$url=$third;}return $url.'?'.http_build_query($args); }
function is_admin() { return true; }
function register_post_status($id,$args) {}
function check_ajax_referer($action,$field='nonce',$stop=true) { return ($_POST[$field]??'')==='fixture-nonce'; }
function check_admin_referer($action) { if(($_POST['_wpnonce']??$_GET['_wpnonce']??'')!=='fixture-nonce')wp_die('Invalid nonce.','',['response'=>403]); }
function response_fixture($data) { $data['fixture']=['orders'=>$GLOBALS['orders'],'sent'=>$GLOBALS['sent'],'options'=>$GLOBALS['options']];echo json_encode($data);exit; }
function wp_send_json_success($data) { response_fixture(['success'=>true,'data'=>$data]); }
function wp_send_json_error($data,$status=400) { response_fixture(['success'=>false,'data'=>$data,'status'=>$status]); }
function wp_die($text,$title='',$args=[]) { response_fixture(['success'=>false,'data'=>['message'=>$text],'status'=>$args['response']??400]); }
function wp_safe_redirect($url) { response_fixture(['success'=>true,'redirect'=>$url]); }
function wc_get_page_screen_id($id) { return 'woocommerce_page_wc-orders'; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? null; }
class OpsDB {
    public $options='wp_options';
    function prepare($sql,...$args) { return $args; }
    function query($args) { [$key,$value]=$args;if(($GLOBALS['options'][$key]??null)===$value)unset($GLOBALS['options'][$key]); }
}
$GLOBALS['wpdb']=new OpsDB();
class OpsProduct {
    public $yes=true; public $parent=0;
    function get_meta($k) { return $this->yes?'yes':''; }
    function get_parent_id() { return $this->parent; }
    function get_attribute($k) { return 'Fixture '.str_replace('pa_','',$k); }
}
class WC_Order_Item_Product {
    public $id; public $order=1; public $qty=2; public $meta=[]; public $product;
    function __construct($id) { $this->id=$id;$this->product=new OpsProduct(); }
    function get_id(){return $this->id;} function get_order_id(){return $this->order;}
    function get_meta($k,$single=true){return $this->meta[$k]??'';} function update_meta_data($k,$v){$this->meta[$k]=$v;}
    function get_quantity(){return $this->qty;} function get_product(){return $this->product;}
    function get_name(){return 'Fixture item '.$this->id;} function save(){}
}
class OpsShipping {
    public $method='local_pickup'; public $meta=[];
    function get_method_id(){return $this->method;} function get_meta($k){return $this->meta[$k]??'';}
}
class WC_Order {
    public $id=1;public $customer=7;public $status='processing';public $paid=true;public $gateway='stripe';public $meta=[];public $items=[];public $shipping=[];public $changes=[];public $notes=[];public $refund=[];public $veto=false;
    function __construct($id=1){$this->id=$id;$this->items=[11=>new WC_Order_Item_Product(11)];$this->shipping=[new OpsShipping()];}
    function get_id(){return $this->id;}function get_type(){return 'shop_order';}function get_customer_id(){return $this->customer;}
    function get_meta($k,$single=true){return $this->meta[$k]??'';}function update_meta_data($k,$v){$this->meta[$k]=$v;}
    function get_status(){return $this->status;}function get_date_paid(){return $this->paid?new DateTimeImmutable():null;}
    function get_payment_method(){return $this->gateway;}function get_shipping_methods(){return $this->shipping;}
    function get_items(){return $this->items;}function get_item($id){return $this->items[$id]??null;}
    function get_changes(){return $this->changes;}function get_qty_refunded_for_item($id){return $this->refund[$id]??0;}
    function get_order_number(){return (string)$this->id;}function get_billing_email(){return 'buyer@example.invalid';}
    function get_billing_first_name(){return 'Pat';}function get_date_created(){return new DateTimeImmutable('2026-09-29');}
    function get_view_order_url(){return 'https://fixture.invalid/account/view-order/'.$this->id;}
    function get_edit_order_url(){return 'https://fixture.invalid/admin/order/'.$this->id;}
    function add_order_note($text,$public=false,$by_user=false){$this->notes[]=[$text,$public];if(isset($GLOBALS['orders'][$this->id]))$GLOBALS['orders'][$this->id]->notes=$this->notes;}
    function save_meta_data(){$GLOBALS['orders'][$this->id]=clone $this;}
    function save(){FFLA_Customer_Operations::guard_status($this);$this->changes=[];$this->save_meta_data();}
    function update_status($to,$note='',$manual=false){
        if($this->veto)return false;$from=$this->status;$this->status=$to;$this->changes=['status'=>$to];
        try{$this->save();FFLA_Customer_Operations::status_changed($this->id,$from,$to,$this);return true;}
        catch(Exception $e){$this->status=$from;$this->changes=[];return false;}
    }
}
function wc_get_order($id){return isset($GLOBALS['orders'][$id])?clone $GLOBALS['orders'][$id]:null;}
class FPPC_Hold {static $settled=false;static function plan_is_settled($o){return self::$settled;}}
class FPPC_Procurement {static $ready=false;static function customer_fulfillment_gate_satisfied($o){return self::$ready;}}
// Minimal WooCommerce email runtime for the Ready for pickup email.
function esc_html__($s,$d=null){return esc_html($s);} function esc_html_e($s,$d=null){echo esc_html($s);}
function wp_kses_post($s){return (string)$s;} function wpautop($s){return '<p>'.$s.'</p>';} function wptexturize($s){return $s;}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function do_action($hook,...$args){$GLOBALS['did'][$hook]=($GLOBALS['did'][$hook]??0)+1;foreach($GLOBALS['hooks'][$hook]??[] as $cb){$cb(...$args);}}
function did_action($hook){return $GLOBALS['did'][$hook]??0;}
function wc_format_datetime($date){return $date?$date->format('Y-m-d'):'';}
function wc_get_template_html($name,$args=[],$path='',$default=''){extract($args);ob_start();include $default.$name;return ob_get_clean();}
function WC(){return new class{function mailer(){return new class{function get_emails(){return $GLOBALS['wc_emails']??[];}};}};}
class WC_Email {
    public $id,$title,$description,$customer_email=false,$template_html,$template_plain,$template_base,$placeholders=[],$object,$recipient,$enabled='yes',$email_type='html',$sent=[];
    function __construct(){}
    function is_enabled(){return $this->enabled==='yes';} function get_recipient(){return $this->recipient;} function get_title(){return $this->title;}
    function get_subject(){return strtr($this->get_default_subject(),$this->placeholders+['{site_title}'=>'Fixture Store']);}
    function get_heading(){return $this->get_default_heading();} function get_additional_content(){return $this->get_default_additional_content();}
    function get_content(){return $this->email_type==='plain'?$this->get_content_plain():$this->get_content_html();}
    function get_headers(){return '';} function get_attachments(){return [];} function setup_locale(){} function restore_locale(){}
    function send($to,$subject,$message,$headers,$attachments){$this->sent[]=compact('to','subject','message');return true;}
}
require_once __DIR__.'/../../includes/class-ffla-module.php';
require_once __DIR__.'/../../modules/customer-notes/class-customer-notes-module.php';
(new Customer_Notes_Module())->boot();
function switches(array $values){$GLOBALS['options'][FFLA_Customer_Operations_Settings::OPTION]=$values;}
function all_on(){ $s=[];foreach(FFLA_Customer_Operations_Settings::fields() as $k=>$f){if($f[3]==='switch')$s[$k]=true;}switches($s); }
function fixture_order(){ $o=new WC_Order();$o->save_meta_data();return $o; }
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;}
function rejects($callback,$message){$error=false;try{$callback();}catch(Throwable $e){$error=true;}check($error,$message);}
function output_of($cb){ob_start();$cb();return ob_get_clean();}
if (($argv[1]??'')==='request') {
    $input=json_decode($argv[2]??'{}',true);all_on();$o=fixture_order();
    $scenario=$input['_scenario']??'';
    if($scenario==='ready') {
        $o->items[11]->update_meta_data(FFLA_Customer_Operations::ITEM,['serials'=>['SN-A','SN-B']]);
        $o->update_status('ffla-ready');
        $input['ops']['revision']=FFLA_Customer_Operations::data($o)['revision'];
    }
    if($scenario==='other-user'){$GLOBALS['uid']=8;$GLOBALS['staff']=false;}
    if($scenario==='no-staff'){$GLOBALS['staff']=false;}
    if($scenario==='disabled'){$GLOBALS['options']['ffla_active_modules']=[];}
    if($scenario==='mail-off'){$s=FFLA_Customer_Operations_Settings::get();$s['notifications']=false;switches($s);}
    if($scenario==='stale'){$o->meta[FFLA_Customer_Operations::DATA]=['revision'=>'newer'];$o->save_meta_data();}
    if($scenario==='help-repeat'){$o->meta['_ffla_ops_help_at']=time();$o->save_meta_data();}
    if($scenario==='help-resolved'){$o->meta['_ffla_ops_case']='resolved';$o->save_meta_data();}
    $_POST=$input;
    if(($input['_endpoint']??'')==='help'){FFLA_Customer_Operations_Customer::help();}
    elseif(($input['_endpoint']??'')==='template'){FFLA_Customer_Operations_Admin::template_ajax();}
    else{FFLA_Customer_Operations_Admin::ajax();}
    exit;
}
if (($argv[1]??'')==='fixture') {
    all_on();$o=fixture_order();$o->meta['_ffla_ops_case']='open';$o->meta['_ffla_ops_reason']='shortage';
    $o->meta['_ffla_ops']=['public'=>[['at'=>time(),'text'=>'We are reviewing your request.']], 'revision'=>'fixture'];
    $o->save_meta_data();
    echo json_encode(['settings'=>output_of([FFLA_Customer_Operations_Admin::class,'settings']), 'order'=>output_of(static function()use($o){FFLA_Customer_Operations_Admin::render($o);})]);exit;
}
$defaults=FFLA_Customer_Operations_Settings::get();check($defaults['notes']===true,'old notes remain enabled');
foreach(FFLA_Customer_Operations_Settings::fields() as $k=>$f){if($f[3]==='switch'&&$k!=='notes')check($defaults[$k]===false,'new feature off: '.$k);}
check(FFLA_Customer_Operations::statuses([])===[],'disabled unused status not added');
switches(['invoice_serials'=>true]);check(!FFLA_Customer_Operations_Settings::enabled('invoice_serials'),'PDF depends on serials');
$clean=FFLA_Customer_Operations_Settings::sanitize(['reminder_days'=>'900','reminder_max'=>'-1','store_name'=>['bad'],'pickup'=>'1']);
check($clean['reminder_days']===30&&$clean['reminder_max']===1&&$clean['store_name']===''&&$clean['pickup'],'settings bounds and scalar validation');
all_on();$o=fixture_order();check(FFLA_Customer_Operations::staff($o),'staff allowed');$o->id=999;check(!FFLA_Customer_Operations::staff($o),'object capability required');$o->id=1;
$GLOBALS['staff']=false;check(!FFLA_Customer_Operations::staff($o),'buyer cannot staff-edit');check(FFLA_Customer_Operations::owner($o),'signed owner allowed');$GLOBALS['uid']=8;check(!FFLA_Customer_Operations::owner($o),'different buyer denied');$GLOBALS['uid']=0;$o->customer=0;check(!FFLA_Customer_Operations::owner($o),'guest id zero not authorization');$GLOBALS['uid']=7;$GLOBALS['staff']=true;$o->customer=7;
check(strpos(FFLA_Customer_Operations::ready_error($o),'serial')!==false,'missing serial gate');
$changes=FFLA_Customer_Operations::validate_items($o,[11=>['serials'=>"Ab-123\nAb-124"]]);check($changes[11]['serials']===['Ab-123','Ab-124'],'serial punctuation preserved');
rejects(static function()use($o){FFLA_Customer_Operations::validate_items($o,[11=>['serials'=>"AA\naa"]]);},'case insensitive serial duplicate');
rejects(static function()use($o){FFLA_Customer_Operations::validate_items($o,[11=>['serials'=>"1\n2\n3"]]);},'serial count bound');
check(FFLA_Customer_Operations::validate_items($o,[999=>['serials'=>'foreign']])===[],'foreign item ignored');
$o->items[11]->product->yes=false;check(FFLA_Customer_Operations::validate_items($o,[11=>['serials'=>'not-firearm']])===[],'non firearm serial ignored');$o->items[11]->product->yes=true;
FFLA_Customer_Operations::save($o,['revision'=>'','items'=>[11=>['serials'=>"Ab-123\nAb-124"]]]);check($o->items[11]->get_meta('_ffla_ops_firearm')==='yes','historical item detection frozen');
check(FFLA_Customer_Operations::ready_error($o)==='','valid order eligible');
$o->refund[11]=-1;$o->items[11]->update_meta_data(FFLA_Customer_Operations::ITEM,['serials'=>['Ab-123']]);check(FFLA_Customer_Operations::ready_error($o)==='','refunded units do not need invented serials');
$o->refund[11]=-2;check(FFLA_Customer_Operations::ready_error($o)!=='','no remaining units cannot become ready');$o->refund=[];$o->items[11]->update_meta_data(FFLA_Customer_Operations::ITEM,['serials'=>['Ab-123','Ab-124']]);
rejects(static function()use($o){FFLA_Customer_Operations::save($o,['revision'=>'stale']);},'stale revision rejected');
$o->paid=false;check(FFLA_Customer_Operations::ready_error($o)!=='','unpaid blocked');$o->paid=true;
$o->shipping[0]->method='flat_rate';check(FFLA_Customer_Operations::ready_error($o)!=='','shipping blocked');$o->shipping[0]->method='local_pickup';
$o->gateway='anet';check(!FFLA_Customer_Operations::paid($o),'authorization not capture');$o->meta['_anet_credit_card_charge_captured']='yes';$o->meta['_anet_credit_card_charge_id']='cap';check(FFLA_Customer_Operations::paid($o),'capture accepted');$o->gateway='stripe';
$o->meta['_fppc_managed_plan']='yes';check(strpos(FFLA_Customer_Operations::ready_error($o),'Split Payment')!==false,'plan not settled blocked');FPPC_Hold::$settled=true;check(FFLA_Customer_Operations::ready_error($o)!=='','procurement gate required');FPPC_Procurement::$ready=true;check(FFLA_Customer_Operations::ready_error($o)==='','settled plan eligible');
$o->status='completed';$o->save_meta_data();$o->status='ffla-ready';$o->changes=['status'=>'ffla-ready'];rejects(static function()use($o){FFLA_Customer_Operations::guard_status($o);},'core dropdown cannot reopen completed');$o->status='processing';$o->changes=[];$o->save_meta_data();
check($o->update_status('ffla-ready'),'ready transition');check(FFLA_Customer_Operations::preserve_paid(false,$o),'paid preserved for ready');
rejects(static function()use($o){FFLA_Customer_Operations::validate_items($o,[11=>['serials'=>'only-one']]);},'ready order cannot lose required serials');
$d=FFLA_Customer_Operations::data($o);check($d['ready_cycle']!==''&&$d['ready_since']>0,'ready cycle recorded');check(count($GLOBALS['events'])===1,'one ready event scheduled');
FFLA_Customer_Operations_Messages::queue_ready($o,$d['ready_cycle']);check(count($GLOBALS['events'])===1,'duplicate ready schedule suppressed');
FFLA_Customer_Operations_Messages::ready_job(1,$d['ready_cycle'],0);check(count($GLOBALS['sent'])===1,'ready mail once');
FFLA_Customer_Operations_Messages::ready_job(1,$d['ready_cycle'],0);check(count($GLOBALS['sent'])===1,'ready mail deduped');
FFLA_Customer_Operations_Messages::ready_job(1,'obsolete',1);check(count($GLOBALS['sent'])===1,'stale cycle ignored');
FFLA_Customer_Operations_Messages::ready_job(1,$d['ready_cycle'],99);check(count($GLOBALS['sent'])===1,'reminder max enforced');
$o=wc_get_order(1);$revision=FFLA_Customer_Operations::data($o)['revision'];
FFLA_Customer_Operations::save($o,['revision'=>$revision,'items'=>[11=>['collected'=>1]]]);check(FFLA_Customer_Operations::item($o->items[11])['collected']===1,'partial collection');
rejects(static function()use($o){FFLA_Customer_Operations::validate_items($o,[11=>['collected'=>0]]);},'collection cannot decrease');
rejects(static function()use($o){FFLA_Customer_Operations::validate_items($o,[11=>['collected'=>3]]);},'collection cannot exceed qty');
$o->refund[11]=-2;check(FFLA_Customer_Operations::validate_items($o,[11=>['collected'=>1]])===[],'later refund does not block unchanged history');$o->refund=[];
$o->veto=true;rejects(static function()use($o){FFLA_Customer_Operations::collect($o);},'completion veto respected');check(FFLA_Customer_Operations::item($o->items[11])['collected']===1,'veto leaves quantities unchanged');$o->veto=false;
$prior=FFLA_Customer_Operations::item($o->items[11]);$prior['collected']=2;$o->items[11]->update_meta_data(FFLA_Customer_Operations::ITEM,$prior);$o->refund[11]=-1;
FFLA_Customer_Operations::collect($o);check($o->get_status()==='completed'&&FFLA_Customer_Operations::data($o)['collected_at']>0,'collection completes order with audit');
check(FFLA_Customer_Operations::item($o->items[11])['collected']===2,'final collection preserves units collected before refund');
FFLA_Customer_Operations_Messages::ready_job(1,$d['ready_cycle'],1);check(count($GLOBALS['sent'])===1,'completed order reminders suppressed');
switches([]);check(isset(FFLA_Customer_Operations::statuses([])['wc-ffla-ready']),'historical status remains readable');all_on();
$case=['state'=>'open','reason'=>'lost_shipment','priority'=>'high','assignee'=>7,'due'=>'2026-10-01T15:30','related'=>'Replacement #456','note'=>'PRIVATE CASE TEXT'];
FFLA_Customer_Operations::save_case($o,$case);$o->save_meta_data();check(FFLA_Customer_Operations::case_data($o)['state']==='open'&&$o->status==='completed','case independent of order status');
$bad=$case;$bad['assignee']=8;rejects(static function()use($o,$bad){FFLA_Customer_Operations::save_case($o,$bad);},'invalid assignee denied');
$bad=$case;$bad['due']='2026-02-31T15:30';rejects(static function()use($o,$bad){FFLA_Customer_Operations::save_case($o,$bad);},'invalid date denied');
$case['state']='resolved';FFLA_Customer_Operations::save_case($o,$case);$o->save_meta_data();check($o->get_meta('_ffla_ops_resolved_at')>0,'resolved timestamp');
FFLA_Customer_Operations_Messages::case_job(1,FFLA_Customer_Operations::case_data($o)['due'],7);check(count($GLOBALS['sent'])===1,'resolved case email suppressed');
$case['state']='open';FFLA_Customer_Operations::save_case($o,$case);$o->save_meta_data();FFLA_Customer_Operations_Messages::case_job(1,FFLA_Customer_Operations::case_data($o)['due'],7);check(count($GLOBALS['sent'])===2,'staff due email sent');
$o=wc_get_order(1);$GLOBALS['mail_ok']=false;FFLA_Customer_Operations_Messages::send($o,'fail-test','buyer@example.invalid','test','safe');$n=count($GLOBALS['sent']);FFLA_Customer_Operations_Messages::send($o,'fail-test','buyer@example.invalid','test','safe');check(count($GLOBALS['sent'])===$n,'failed attempt not blindly resent');
$GLOBALS['mail_throw']=true;FFLA_Customer_Operations_Messages::send($o,'uncertain-test','buyer@example.invalid','test','safe');check($o->get_meta('_ffla_ops_mail')['uncertain-test']['status']==='uncertain','transport exception uncertain, no secret');$GLOBALS['mail_throw']=false;$GLOBALS['mail_ok']=true;
rejects(static function()use($o){FFLA_Customer_Operations_Messages::send($o,'invalid','not-email','test','body');},'invalid recipient denied');
$pdf=output_of(static function()use($o){FFLA_Customer_Operations_Documents::pdf_item('invoice',['item_id'=>11],$o);});check(strpos($pdf,'Ab-123')!==false,'serial printed on invoice');check(strpos($pdf,'PRIVATE')===false,'private case excluded from PDF');
check(output_of(static function()use($o){FFLA_Customer_Operations_Documents::pdf_item('invoice',['item_id'=>999],$o);})==='','foreign PDF item rejected');
$s=FFLA_Customer_Operations_Settings::get();$s['invoice_serials']=false;switches($s);check(output_of(static function()use($o){FFLA_Customer_Operations_Documents::pdf_item('invoice',['item_id'=>11],$o);})==='','invoice toggle independent');check(output_of(static function()use($o){FFLA_Customer_Operations_Documents::pdf_item('packing-slip',['item_id'=>11],$o);})!=='','packing toggle remains on');all_on();
rejects(static function()use($o){FFLA_Customer_Operations_Documents::upload($o,[]);},'invalid upload rejected');
$GLOBALS['staff']=false;$o->customer=7;$d=FFLA_Customer_Operations::data($o);$d['public']=[['id'=>'pub','at'=>time(),'text'=>'PUBLIC SAFE TEXT']];$o->meta[FFLA_Customer_Operations::DATA]=$d;$o->meta['_wc_shipment_tracking_items']=[['tracking_number'=>'ABC123','tracking_provider'=>'carrier','custom_tracking_link'=>'javascript:alert(1)']];$o->save_meta_data();
$html=output_of(static function(){FFLA_Customer_Operations_Customer::render(1);});check(strpos($html,'PUBLIC SAFE TEXT')!==false&&strpos($html,'PRIVATE CASE TEXT')===false,'customer sees only explicit public text');check(strpos($html,'javascript:')===false&&strpos($html,'ABC123')!==false,'safe stored tracking');
$GLOBALS['uid']=8;check(output_of(static function(){FFLA_Customer_Operations_Customer::render(1);})==='','other customer gets no view');$GLOBALS['uid']=7;$GLOBALS['staff']=true;
$_GET['ffla_followup']='overdue';$q=FFLA_Customer_Operations_Admin::hpos_query(['meta_query'=>[['key'=>'existing','value'=>'keep']]]);check($q['meta_query'][0][0]['key']==='existing'&&$q['meta_query'][1][1]['compare']==='BETWEEN','HPOS filter preserves existing clauses');$_GET=[];
$GLOBALS['options']['_ffla_ops_lock_1']=(time()+50).':other';rejects(static function(){FFLA_Customer_Operations::locked(1,static function(){});},'concurrent update lease blocked');$GLOBALS['options']['_ffla_ops_lock_1']=(time()-50).':old';check(FFLA_Customer_Operations::locked(1,static function(){return 'ok';})==='ok','expired lease recovered');check(!isset($GLOBALS['options']['_ffla_ops_lock_1']),'lease released');
// Ready for pickup: WooCommerce email, Send order email box, classic Order actions, no duplicate notice.
all_on();$s=FFLA_Customer_Operations_Settings::get();$s['store_name']='Fixture Range';$s['store_address']="1 Main St\nTown";$s['store_hours']='Mon-Fri 9-5';$s['store_instructions']='Bring <b>photo ID</b>';switches($s);
$GLOBALS['sent']=[];$GLOBALS['events']=[];
$ready=fixture_order();$ready->items[11]->update_meta_data(FFLA_Customer_Operations::ITEM,['serials'=>['SN-A','SN-B']]);check($ready->update_status('ffla-ready'),'fixture order ready');
$emails=FFLA_Customer_Operations_Ready_Email::register([]);$email=$emails['FFLA_Email_Ready_For_Pickup']??null;$GLOBALS['wc_emails']=$emails;
check($email instanceof FFLA_Email_Ready_For_Pickup&&$email->id==='ffla_customer_ready_for_pickup'&&$email->customer_email,'ready email registered as a WooCommerce customer email');
check(in_array('woocommerce_order_status_ffla-ready',FFLA_Customer_Operations_Ready_Email::email_actions(['woocommerce_order_status_completed']),true),'status change fires the WooCommerce notification');
check(in_array([$email,'trigger'],$GLOBALS['hooks']['woocommerce_order_status_ffla-ready_notification']??[],true),'email listens to the ready notification');
$processing=new WC_Order(2);
check(FFLA_Customer_Operations_Ready_Email::rest_templates(['WC_Email_Customer_Invoice'],$processing)===['WC_Email_Customer_Invoice'],'not offered before the order is ready');
check(in_array('FFLA_Email_Ready_For_Pickup',FFLA_Customer_Operations_Ready_Email::rest_templates(['WC_Email_Customer_Invoice'],$ready),true),'offered in Send order email for a ready order');
check(FFLA_Customer_Operations_Ready_Email::rest_preferred(['customer_invoice'],$ready)[0]==='ffla_customer_ready_for_pickup','preselected in Send order email for a ready order');
check(isset(FFLA_Customer_Operations_Ready_Email::order_actions([],$ready)['ffla_send_ready_pickup_email'])&&FFLA_Customer_Operations_Ready_Email::order_actions([],$processing)===[],'classic Order actions entry only for ready orders');
$email->enabled='no';
check(!$email->trigger(1,$ready)&&$email->sent===[],'automatic send respects the WooCommerce Enable setting');
FFLA_Customer_Operations_Ready_Email::rest_send(1,'customer_invoice');check($email->sent===[],'other templates ignored');
FFLA_Customer_Operations_Ready_Email::rest_send(1,'ffla_customer_ready_for_pickup');check(count($email->sent)===1&&$email->sent[0]['to']==='buyer@example.invalid','manual send works even when automatic sending is off');
$html=$email->sent[0]['message'];
check(strpos($html,'Your order #1 is ready for pickup.')!==false&&strpos($html,'Hi Pat,')!==false,'HTML email greets the customer');
check(strpos($html,'Fixture Range')!==false&&strpos($html,"1 Main St<br />\nTown")!==false&&strpos($html,'Mon-Fri 9-5')!==false,'HTML email shows the pickup location saved with the ready cycle');
check(strpos($html,'&lt;b&gt;photo ID&lt;/b&gt;')!==false&&strpos($html,'<b>')===false,'pickup instructions escaped');
check(strpos($email->sent[0]['subject'],'order #1 is ready for pickup')!==false,'subject names the order');
$email->email_type='plain';$email->enabled='yes';
check($email->trigger(1,$ready)&&strpos(end($email->sent)['message'],'Pickup location')!==false,'automatic plain-text email');
check(strpos(end($ready->notes)[0],'sent automatically')!==false,'automatic send recorded in order notes');
check(!$email->trigger(2,$processing)&&count($email->sent)===2,'never sent for an order that is not ready');
FFLA_Customer_Operations_Ready_Email::order_action($ready);check(count($email->sent)===3&&strpos(end($ready->notes)[0],'Order actions')!==false,'classic Order actions send recorded');
// PDF Invoices & Packing Slips (WP Overnight) "Send order email" box: listed by ID, sent with trigger().
$wpo=['new_order','customer_invoice'];
check(in_array('ffla_customer_ready_for_pickup',FFLA_Customer_Operations_Ready_Email::wpo_emails($wpo,1),true),'listed in the WP Overnight Send order email box for a ready order');
$GLOBALS['orders'][2]=new WC_Order(2);
check(FFLA_Customer_Operations_Ready_Email::wpo_emails($wpo,2)===$wpo&&FFLA_Customer_Operations_Ready_Email::wpo_emails($wpo,0)===$wpo&&FFLA_Customer_Operations_Ready_Email::wpo_emails($wpo,404)===$wpo,'not listed for other or unknown orders');
unset($GLOBALS['orders'][2]);
$notes=count($ready->notes);do_action('woocommerce_before_resend_order_emails',$ready,'ffla_customer_ready_for_pickup');
check($email->trigger(1,$ready)&&count($email->sent)===4&&count($ready->notes)===$notes,'resend box send leaves the note to the calling plugin');
do_action('woocommerce_after_resend_order_email',$ready,'ffla_customer_ready_for_pickup');
array_pop($email->sent);
$cycle=FFLA_Customer_Operations::data(wc_get_order(1))['ready_cycle'];$GLOBALS['events']=[];
FFLA_Customer_Operations_Messages::ready_job(1,$cycle,0);
check($GLOBALS['sent']===[],'module plain-text ready notice skipped while the WooCommerce email is enabled');
check(count($GLOBALS['events'])===1,'pickup reminders still scheduled');
FFLA_Customer_Operations_Messages::send_ready(wc_get_order(1),'manual:fixture');$log=wc_get_order(1)->get_meta('_ffla_ops_mail');
check(count($email->sent)===4&&$GLOBALS['sent']===[]&&$log['manual:fixture']['status']==='accepted'&&strpos($log['manual:fixture']['subject'],'WooCommerce email')!==false,'module resend button sends and logs the WooCommerce email');
$email->enabled='no';
FFLA_Customer_Operations_Messages::send_ready(wc_get_order(1),'manual:plain');check(count($GLOBALS['sent'])===1&&strpos($GLOBALS['sent'][0]['body'],'ready for pickup')!==false,'plain-text template used while the WooCommerce email is disabled');
$s['pickup']=false;switches($s);check(FFLA_Customer_Operations_Ready_Email::register([])===[]&&FFLA_Customer_Operations_Ready_Email::rest_templates([],$ready)===[],'nothing registered while Ready for Pickup is off');
all_on();$GLOBALS['wc_emails']=[];

// Order Management panel: serial numbers, then checklist, then Ready for Pickup.
$panel=output_of(static function(){FFLA_Customer_Operations_Admin::render(fixture_order());});
$at=static function($needle)use($panel){$p=strpos($panel,$needle);check($p!==false,'panel shows '.$needle);return $p;};
check($at('ops[items][11][serials]')<$at('ops[checklist][]')&&$at('ops[checklist][]')<$at('data-ops-action="save"')&&$at('data-ops-action="save"')<$at('<h3>Pickup</h3>')&&$at('<h3>Pickup</h3>')<$at('<h3>Internal follow-up case</h3>'),'serial numbers, then checklist, then save, then Pickup');
check(substr_count($panel,'data-ops-action="save"')===2,'follow-up case keeps its own save button below Pickup');
$s=FFLA_Customer_Operations_Settings::get();$s['followup']=false;switches($s);
check(substr_count(output_of(static function(){FFLA_Customer_Operations_Admin::render(fixture_order());}),'data-ops-action="save"')===1,'one save button without follow-up');all_on();
$GLOBALS['options']['ffla_active_modules']=[];check(!FFLA_Customer_Operations_Settings::enabled('notes')&&!FFLA_Customer_Operations_Settings::enabled('pickup'),'module master respected');$n=count($GLOBALS['sent']);FFLA_Customer_Operations_Messages::ready_job(1,'old',0);check(count($GLOBALS['sent'])===$n,'disabled module sends no mail');
echo "Customer operations: $checks checks passed.\n";
