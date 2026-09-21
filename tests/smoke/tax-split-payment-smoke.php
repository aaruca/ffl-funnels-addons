<?php
/** Isolated WooCommerce reporting fixtures. No database, network, charges or writes. */
define('ABSPATH', __DIR__ . '/');
function __($s, $domain = null) { return $s; }
function apply_filters($hook, $value, ...$args) { return isset($GLOBALS['test_filters'][$hook]) ? $GLOBALS['test_filters'][$hook]($value, ...$args) : $value; }
function current_datetime() { return new DateTimeImmutable('2026-09-21', wp_timezone()); }
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function wp_timezone_string() { return 'America/New_York'; }
function wp_date($f, $ts, $tz) { return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($f); }
function sanitize_text_field($s) { return strip_tags($s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $s)); }
function get_bloginfo($key) { return 'Fixture'; }
function home_url($p) { return 'https://fixture.invalid' . $p; }
function wp_json_encode($v) { return json_encode($v); }
function wc_get_price_decimals() { return 2; }
function get_option($key, $default = false) { return $default; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? null; }
function wc_get_orders($query) {
    $GLOBALS['queries'][] = $query;
    $field = isset($query['date_paid']) ? 'date_paid' : 'date_created';
    [$start, $end] = array_map('intval', explode('...', $query[$field]));
    $rows = array_values(array_filter($GLOBALS['orders'], static function ($order) use ($query, $field, $start, $end) {
        $date = $field === 'date_paid' ? $order->get_date_paid() : $order->get_date_created();
        return $order->type === $query['type'] && (!isset($query['status']) || in_array($order->get_status(), $query['status'], true))
            && $date && $date->getTimestamp() >= $start && $date->getTimestamp() <= $end;
    }));
    usort($rows, static function ($a, $b) { return $a->get_date_created() <=> $b->get_date_created(); });
    return (object) ['orders' => array_slice($rows, ($query['page'] - 1) * $query['limit'], $query['limit']), 'max_num_pages' => (int) ceil(count($rows) / $query['limit'])];
}
class WC_Order {
    public $id; public $meta = []; public $items = []; public $refunds = []; public $type = 'shop_order';
    public $created; public $paid; public $status = 'completed'; public $parent = 0; public $currency = 'USD'; public $state='GA'; public $transaction=''; public $gateway='fixture';
    function __construct($id, $date, $paid = null) { $this->id=$id; $this->created=new DateTimeImmutable($date, wp_timezone()); $this->paid=$paid ? new DateTimeImmutable($paid, wp_timezone()) : null; }
    function get_id() { return $this->id; }
    function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    function get_parent_id() { return $this->parent; }
    function get_status() { return $this->status; }
    function is_paid() { return in_array($this->status, ['processing','completed'], true); }
    function get_date_paid() { return $this->paid; }
    function get_date_created() { return $this->created; }
    function get_currency() { return $this->currency; }
    function get_items($type = 'line_item') { return $this->items[$type] ?? []; }
    function get_item($id) { foreach($this->items as $items) { if(isset($items[$id]))return $items[$id]; } return null; }
    function get_refunds() { return $this->refunds; }
    function get_total_tax() { return array_sum(array_map(static function ($items) { return array_sum(array_map(static function ($i) { return $i->get_total_tax(); }, $items)); }, array_diff_key($this->items,['tax'=>1]))); }
    function get_total() { return array_sum(array_map(static function ($items) { return array_sum(array_map(static function ($i) { return $i->get_total(); }, $items)); }, array_diff_key($this->items,['tax'=>1]))) + $this->get_total_tax(); }
    function get_shipping_total() { return array_sum(array_map(static function ($i) { return $i->get_total(); }, $this->get_items('shipping'))); }
    function get_shipping_country() { return 'US'; }
    function get_shipping_state() { return $this->state; }
    function get_billing_country() { return 'US'; }
    function get_billing_state() { return 'GA'; }
    function get_order_number() { return (string)$this->id; }
    function get_payment_method() { return $this->gateway; }
    function get_transaction_id() { return $this->transaction; }
    function __call($name, $args) {
        if (strpos($name,'_city')!==false)return 'Newnan';
        if (strpos($name,'_postcode')!==false)return '30263';
        return '';
    }
}
class TestLine {
    public $id; public $amount; public $tax; public $qty; public $meta=[]; public $product=77;
    function __construct($id, $amount, $tax, $qty=1) { $this->id=$id; $this->amount=$amount; $this->tax=$tax; $this->qty=$qty; }
    function get_id() { return $this->id; }
    function get_meta($key, $single=true) { return $this->meta[$key] ?? ''; }
    function get_product() { return null; }
    function get_product_id() { return $this->product; }
    function get_variation_id() { return 0; }
    function get_quantity() { return $this->qty; }
    function get_total() { return $this->amount; }
    function get_total_tax() { return $this->tax; }
    function get_subtotal() { return $this->amount; }
    function get_subtotal_tax() { return $this->tax; }
    function get_taxes() { return ['total'=>[1=>$this->tax], 'subtotal'=>[1=>$this->tax]]; }
    function get_name() { return 'Fixture product'; }
    function __call($name,$args) { return ''; }
}
class TestTax {
    private $amount; private $shipping;
    function __construct($amount,$shipping=0){$this->amount=$amount;$this->shipping=$shipping;}
    function get_rate_id(){return 1;}
    function get_rate_percent(){return 8;}
    function get_label(){return 'COWETA COUNTY NEWNAN : County Tax';}
    function get_tax_total(){return $this->amount;}
    function get_shipping_tax_total(){return $this->shipping;}
    function __call($name,$args){return '';}
}
class TestRefund extends WC_Order {
    public $type='shop_order_refund';
    function get_amount(){return abs($this->get_total());}
    function get_reason(){return 'Fixture refund';}
}
require_once __DIR__.'/../../modules/tax-rates/includes/class-tax-report-service.php';
require_once __DIR__.'/../../modules/tax-rates/includes/class-tax-report-jurisdiction-registry.php';
require_once __DIR__.'/../../modules/tax-rates/includes/class-tax-nexus-monitor.php';
require_once __DIR__.'/../../modules/tax-rates/includes/class-tax-report-exporter.php';
require_once __DIR__.'/../../modules/tax-rates/includes/class-tax-report-reconciliation.php';
$checks=0;
function check($ok,$label){global $checks; if(!$ok)throw new RuntimeException($label);$checks++;}
function fixture($id,$created,$paid,$parent=100,$principal=100){
    $o=new WC_Order($id,$created,$paid);$o->items=['line_item'=>[$id*10=>new TestLine($id*10,$principal,$principal*.08)], 'tax'=>[1=>new TestTax($principal*.08)]];
    if($id===$parent){$o->meta=['_fppc_managed_plan'=>'yes'];$o->status='fppc-plan-hold';}
    else {$o->meta=['_fppc_plan_subscription_id'=>200];}
    return $o;
}
function report($from='2026-01-01',$to='2026-03-31'){
    return (new Tax_Report_Service())->generate(['date_from'=>$from,'date_to'=>$to,'report_detail'=>'advanced']);
}
function nexus($from='2026-01-01',$to='2026-03-31'){
    $r=(new Tax_Nexus_Monitor())->generate(['date_from'=>$from,'date_to'=>$to],['as_of'=>$to,'timezone'=>wp_timezone(),'home_country'=>'US','home_state'=>'GA','page_size'=>1]);
    foreach($r['states'] as $s){if($s['state']==='GA')return $s;}
    throw new RuntimeException('GA missing');
}
$parent=fixture(100,'2026-01-02','2026-01-03');
$sub=new WC_Order(200,'2026-01-02');$sub->type='shop_subscription';$sub->parent=100;$sub->meta=['_fppc_managed_plan'=>'yes'];
$two=fixture(101,'2026-01-20','2026-02-03');
$three=fixture(102,'2026-02-20','2026-03-03');
$orders=[100=>$parent,200=>$sub,101=>$two,102=>$three];$queries=[];
$r=report();
check($r['stats']['orders']===1,'Three receipts must count one sale');
check($r['stats']['receipt_orders']===3,'Keep three receipts');
check($r['totals_by_currency'][0]['net_product_sales']==='300.00','Count each actual principal once');
check($r['totals_by_currency'][0]['tax_collected']==='24.00','Keep tax on all installments');
check($r['summaries']['states'][0]['orders']===1,'State count deduplicated');
check($r['summaries']['jurisdictions'][0]['orders']===1,'Jurisdiction count deduplicated');
check((float)$r['summaries']['products'][0]['quantity']===1.0,'Physical quantity not tripled');
check(count($r['split_payment_sales'])===1 && $r['split_payment_sales'][0]['payments']===3,'One grouped row with three payments');
check(count($r['orders'])===3,'Preserve original payment audits');
check($r['orders'][1]['sale_id']===100,'Audit points to original sale');
$n=nexus();check($n['actual_transactions']===1,'Nexus count once');
$feb=report('2026-02-01','2026-02-28');
check($feb['stats']['orders']===0 && $feb['stats']['receipt_orders']===1,'Later month has collection, no new sale');
check($feb['totals_by_currency'][0]['tax_collected']==='8.00','Receipt is selected by payment, not creation date');
check((float)$feb['summaries']['products'][0]['quantity']===0.0,'Later installment does not sell product again');
check(nexus('2026-02-01','2026-02-28')['actual_transactions']===0,'No recount in later nexus window');
check(report('2026-01-01','2026-01-31')['stats']['receipt_orders']===1,'Future installment not counted before capture');

// Final shipping remains actual receipt money/tax, never multiplied across installments.
$three->items['shipping']=[1021=>new TestLine(1021,15,1.20)];$three->items['tax']=[1=>new TestTax(8,1.20)];
$r=report();check($r['summaries']['states'][0]['taxable_sales']==='315.00','Include taxed shipping once');
check($r['totals_by_currency'][0]['tax_collected']==='25.20','Shipping tax retained');
check($r['split_payment_sales'][0]['net_collected']==='340.20','Grouped gross collection includes shipping and taxes');

// Later itemized refund of a February receipt, linked to the same sale.
$refund=new TestRefund(301,'2026-04-05');$refund->parent=101;
$refundLine=new TestLine(3010,-50,-4,0);$refundLine->meta=['_refunded_item_id'=>1010];
$refund->items=['line_item'=>[3010=>$refundLine]];$two->refunds=[$refund];$orders[301]=$refund;
$apr=report('2026-04-01','2026-04-30');
check($apr['stats']['orders']===0,'Refund cannot create a new sale');
check($apr['totals_by_currency'][0]['net_tax']==='-4.00','Refund tax belongs to refund month');
check($apr['split_payment_sales'][0]['sale_id']===100 && $apr['split_payment_sales'][0]['payments']===0,'Refund-only activity groups under original sale');
check($apr['split_payment_sales'][0]['net_collected']==='-54.00','Refund-only grouped money is negative');
check($apr['summaries']['jurisdictions'][0]['orders']===0,'Refund-only jurisdiction does not count receipt as a new order');
check(report()['totals_by_currency'][0]['tax_collected']==='25.20' && report()['totals_by_currency'][0]['tax_refunded']==='0.00','Later refund does not rewrite earlier period');
$all=report('2026-01-01','2026-04-30');
check($all['split_payment_sales'][0]['payments']===3 && $all['split_payment_sales'][0]['tax_refunded']==='4.00','Refund is not a fourth collection or counted twice');
check($all['totals_by_currency'][0]['net_tax']==='21.20','All-period tax reconciles');
check(nexus('2026-04-01','2026-04-30')['actual_transactions']===0,'Nexus refund month creates no transaction');

// Additional payments and early payoff are receipts, not new products/transactions.
$extra=fixture(103,'2026-02-06','2026-02-06',100,25);$extra->meta['_fppc_additional_payment']='yes';
$extra->meta['_fppc_additional_payment_slots_v1']=[0=>25];$orders[103]=$extra;
$payoff=fixture(104,'2026-02-08','2026-02-08',100,75);$payoff->meta['_fppc_early_payoff']='yes';$payoff->meta['_fppc_payoff_slots_v1']=[0=>25,1=>50];$orders[104]=$payoff;
$r=report();check($r['stats']['orders']===1 && $r['stats']['receipt_orders']===5,'Additional/early payment preserve one sale');
check($r['totals_by_currency'][0]['tax_collected']==='33.20','Never hide actual captured amounts by capping a financial slot');
check((float)$r['summaries']['products'][0]['quantity']===1.0,'Extra/payoff do not repeat units');
check(nexus()['actual_transactions']===1,'Same sale in nexus with additional/payoff');
unset($orders[103],$orders[104]);

// Unpaid retry is not money, even if created in period.
$pending=fixture(105,'2026-01-09',null);$pending->status='on-hold';$orders[105]=$pending;
check(report()['stats']['receipt_orders']===3,'Unpaid pending receipt omitted');
$pending->status='failed';check(report()['stats']['receipt_orders']===3,'Failed unpaid receipt omitted');unset($orders[105]);
$parent->status='fppc-defaulted';check(report()['stats']['orders']===1,'Defaulted plan retains historical captured deposit');$parent->status='fppc-plan-hold';

// Mixed full-pay and plan products preserve true quantities.
$fullLine=new TestLine(1002,30,2.4,2);$fullLine->product=88;$parent->items['line_item'][1002]=$fullLine;
$parent->items['tax']=[1=>new TestTax(10.4)];
$r=report();$qty=[];foreach($r['summaries']['products'] as $p)$qty[$p['product_id']]=$p['quantity'];
check((float)$qty[77]===1.0 && (float)$qty[88]===2.0,'Do not reduce a real two-unit purchase to one');
check($r['stats']['orders']===1,'Mixed checkout remains one underlying transaction');
unset($parent->items['line_item'][1002]);$parent->items['tax']=[1=>new TestTax(8)];

// Two plan subscriptions under one checkout still represent that one checkout.
$sub2=new WC_Order(201,'2026-01-02');$sub2->type='shop_subscription';$sub2->parent=100;$orders[201]=$sub2;
$renewal2=fixture(106,'2026-02-02','2026-02-02');$renewal2->meta['_fppc_plan_subscription_id']=201;$orders[106]=$renewal2;
check(report()['stats']['orders']===1 && nexus()['actual_transactions']===1,'Multiple plans share parent identity');unset($orders[106],$orders[201]);

// An unrelated ordinary order and a non-FPPC subscription must remain separate.
$regular=fixture(107,'2026-02-04','2026-03-04');$regular->meta=[];$orders[107]=$regular;
check(report('2026-02-01','2026-02-28')['stats']['orders']===1,'Ordinary order keeps created-date behavior');
check(report('2026-03-01','2026-03-31')['stats']['receipt_orders']===1,'Second paid-date query must not duplicate ordinary orders');
$plainSub=new WC_Order(202,'2026-01-01');$plainSub->type='shop_subscription';$plainSub->parent=107;$orders[202]=$plainSub;
$regular->meta=['_subscription_renewal'=>202];check(report()['stats']['orders']===2,'Do not collapse ordinary recurring subscriptions');
unset($orders[107],$orders[202]);

// Legacy FPPC renewal linkage without direct plan metadata.
$two->meta=['_subscription_renewal'=>200];check(report()['stats']['orders']===1,'Legacy WCS FPPC relation resolves');$two->meta=['_fppc_plan_subscription_id'=>200];

// Orphans retain charged money, but never pretend that identity is valid.
$orphan=fixture(108,'2026-02-09','2026-02-09');$orphan->meta['_fppc_plan_subscription_id']=999;$orders[108]=$orphan;
$r=report();check($r['stats']['receipt_orders']===4 && $r['stats']['orders']===1,'Unresolved receipt does not disappear or become a confident sale');
check(in_array('split_payment_identity_unresolved',array_column($r['exceptions'],'code'),true),'Orphan explicitly flagged');
check(nexus()['actual_evaluation']['status']==='indeterminate','Nexus cannot claim a reliable threshold with unknown identity');
check($r['summaries']['jurisdictions'][0]['filing_status']==='Needs review','Filing jurisdiction flagged for orphan identity');unset($orders[108]);
$two->currency='EUR';check(in_array('split_payment_currency_mismatch',array_column(report()['exceptions'],'code'),true),'Do not silently merge currencies');$two->currency='USD';
$two->state='FL';check(in_array('split_payment_destination_changed',array_column(report()['exceptions'],'code'),true),'Changed state requires review');$two->state='GA';
$parent->transaction='duplicated';$two->transaction='duplicated';
check(in_array('split_payment_duplicate_transaction_reference',array_column(report()['exceptions'],'code'),true),'Shared capture reference requires review, not silent money deletion');
$parent->transaction='';$two->transaction='';

// Unresolvable captured date fails explicitly instead of dropping known paid funds.
$two->paid=null;$thrown=false;try{report();}catch(RuntimeException $e){$thrown=strpos($e->getMessage(),'payment date')!==false;}
check($thrown,'Known paid receipt without date must not silently disappear');$two->paid=new DateTimeImmutable('2026-02-03',wp_timezone());

// Columns used by UI, CSV and XLSX carry grouping without replacing receipt IDs.
check(in_array('sale_id',Tax_Report_Service::get_columns('orders'),true),'Audit exports carry sale identity');
check(in_array('sale_quantity',Tax_Report_Service::get_columns('order_lines'),true),'Audit distinguishes receipt vs sale quantity');
$datasets=new ReflectionMethod(Tax_Report_Exporter::class,'datasets');$datasets->setAccessible(true);
check(isset($datasets->invoke(null,report())['split-payment-sales']),'Export includes grouped table');
$csv=new ReflectionMethod(Tax_Report_Exporter::class,'build_csv');$csv->setAccessible(true);
$bytes=$csv->invoke(null,Tax_Report_Service::get_columns('split-payment-sales'),report()['split_payment_sales']);
check(strpos($bytes,'receipt_ids')!==false && strpos($bytes,'340.20')!==false,'Grouped CSV contains totals and references');
check(report()['orders'][1]['currency']==='USD' && report()['order_lines'][1]['tax_state']==='GA','New audit columns do not overwrite existing base fields');
$thrown=false;try{(new Tax_Report_Service())->generate(['date_from'=>'2026-01-01','date_to'=>'2026-03-31'],['max_orders'=>1]);}catch(RuntimeException $e){$thrown=strpos($e->getMessage(),'safety cap')!==false;}
check($thrown,'Bounded scan rejects truncated report');

// Amounts and counts share the same paginated receipt selection in nexus.
check((float)nexus()['actual_revenue']===315.0,'Nexus preserves each principal and taxed shipping');
check((float)nexus('2026-04-01','2026-04-30')['actual_revenue']===-50.0,'Nexus refund excludes refunded tax');
check((float)nexus('2026-01-01','2026-04-30')['actual_revenue']===265.0,'Nexus complete-period revenue reconciles');
check(count($queries)>3,'Exercise multiple query pages');

// Authorization-only data must not become captured cash, even with a paid WC status/date.
$two->gateway='anet';
check(report()['stats']['receipt_orders']===2,'Known uncaptured authorization skipped without a false missing-date error');
check(report()['totals_by_currency'][0]['tax_collected']==='17.20','Authorization contributes no tax collection');
$two->meta['_anet_credit_card_charge_captured']='yes';$two->meta['_anet_credit_card_charge_id']='capture-101';
check(report()['stats']['receipt_orders']===3,'Confirmed capture included when FPPC is deactivated');
$two->gateway='fixture';unset($two->meta['_anet_credit_card_charge_captured'],$two->meta['_anet_credit_card_charge_id']);
$parent->gateway='anet';
check(in_array('split_payment_parent_capture_unconfirmed',array_column(report()['exceptions'],'code'),true),'Uncaptured parent cannot authorize a sale count through a child');
check(report()['stats']['orders']===0,'Unconfirmed parent not counted');$parent->gateway='fixture';
$two->status='cancelled';$r=report();
check($r['stats']['receipt_orders']===3 && $r['totals_by_currency'][0]['tax_collected']==='25.20','Cancelled historical captured receipt money retained');
check(in_array('split_payment_capture_status_conflict',array_column($r['exceptions'],'code'),true),'Conflicting capture/status flagged');
check(nexus()['advisory_status']==='review_split_payment_identity','Nexus advisory reflects incomplete identity');
check(nexus()['actual_evaluation']['transaction_threshold_met']===null,'Indeterminate nexus cannot retain a confident transaction evaluation');$two->status='completed';

// Distinct orders for the same SKU must never be merged.
$otherParent=fixture(110,'2026-02-01','2026-02-02',110);$orders[110]=$otherParent;
check(report()['stats']['orders']===2 && count(report()['split_payment_sales'])===2,'Same product on different original orders remains two sales');
check((float)report()['summaries']['products'][0]['quantity']===2.0 && nexus()['actual_transactions']===2,'Distinct sales contribute units and nexus independently');unset($orders[110]);

// Local midnight and year boundaries use the site's timezone, not UTC day truncation.
$two->paid=new DateTimeImmutable('2026-03-01 00:00:00',wp_timezone());
check(report('2026-02-01','2026-02-28')['stats']['receipt_orders']===0,'Local midnight not included in prior month');
check(report('2026-03-01','2026-03-31')['stats']['receipt_orders']===2,'Midnight receipt included in current month');
$two->paid=new DateTimeImmutable('2026-02-03',wp_timezone());
$parent->paid=new DateTimeImmutable('2025-12-31 23:59:59',wp_timezone());
check(report()['stats']['orders']===0 && nexus()['actual_transactions']===0,'Later-year installments do not recount previous-year sale');
$parent->paid=new DateTimeImmutable('2026-01-03',wp_timezone());

// Reusing a service must not retain the previous run's deduplication map.
$service=new Tax_Report_Service();$scope=['date_from'=>'2026-01-01','date_to'=>'2026-03-31'];
$service->generate($scope);check($service->generate($scope)['stats']['orders']===1,'Per-run sale counts reset');
check(isset($service->generate($scope)['split_payment_sales'][0]),'Filing-only mode retains grouped table');
check(report()['manifest']['data_quality']['snapshot_coverage_percent']<=100,'Coverage measures receipts, not unique sales');
$reconciled=(new Tax_Report_Reconciliation())->reconcile(report(),['allow_order_fallback'=>false]);
check(isset($reconciled['checks']['date_basis']) && $reconciled['checks']['date_basis']['comparable']===false,'Analytics date-basis check explicitly not comparable for Split Payment');
check(strpos(implode(' ', $reconciled['warnings']),'underlying sales')!==false,'Analytics warns about receipt vs sale counts');

// Generated summaries keep the counting policy visible.
$htmlMethod=new ReflectionMethod(Tax_Report_Exporter::class,'build_html_summary');$htmlMethod->setAccessible(true);
$html=$htmlMethod->invoke(null,report());
check(strpos($html,'Split Payment sales')!==false && strpos($html,'captured deposit date')!==false,'HTML summary includes grouped table and policy');
$numericMethod=new ReflectionMethod(Tax_Report_Exporter::class,'is_numeric_column');$numericMethod->setAccessible(true);
check($numericMethod->invoke(null,'payments') && $numericMethod->invoke(null,'sale_quantity'),'Workbook count/quantity columns remain numeric');

// Fully refunded receipts remain historical collections, reversed only in the refund period.
$two->status='refunded';
check(report()['stats']['receipt_orders']===3 && report()['totals_by_currency'][0]['tax_collected']==='25.20','Refunded status retains original collection');
check(report('2026-01-01','2026-04-30')['totals_by_currency'][0]['net_tax']==='21.20','Refunded status does not subtract the same refund twice');
$two->status='fppc-paid-manual';$two->paid=null;$thrown=false;
try{report();}catch(RuntimeException $e){$thrown=strpos($e->getMessage(),'payment date')!==false;}
check($thrown,'Manual-paid status without date requires review');
$two->status='completed';$two->paid=new DateTimeImmutable('2026-02-03',wp_timezone());
$ordinaryCancelled=fixture(112,'2026-01-05','2026-01-06');$ordinaryCancelled->meta=[];$ordinaryCancelled->status='cancelled';$orders[112]=$ordinaryCancelled;
check(report()['stats']['receipt_orders']===3,'Extra scan statuses never include excluded ordinary cancelled orders');unset($orders[112]);
echo "$checks Split Payment tax-report checks passed.\n";
