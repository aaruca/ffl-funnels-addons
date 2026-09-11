<?php
/**
 * Standalone smoke checks for official filing-jurisdiction consolidation.
 * Run: php tests/smoke/tax-report-jurisdiction-smoke.php
 */

define('ABSPATH', __DIR__ . '/');

function apply_filters($hook, $value) { return $value; }
function __($text, $domain = null) { return $text; }

function ffla_jurisdiction_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-jurisdiction-registry.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-service.php';

$entries = Tax_Report_Jurisdiction_Registry::georgia_entries();
ffla_jurisdiction_assert(count($entries) === 168, 'Georgia registry must contain state, 159 counties and eight special jurisdictions.');
$expected_codes = ['000'];
for ($code = 1; $code <= 159; $code++) {
    $expected_codes[] = str_pad((string) $code, 3, '0', STR_PAD_LEFT);
}
$expected_codes = array_merge($expected_codes, ['044A', '060A', '800', '801', '802', '803', '804', '805']);
sort($expected_codes);
$actual_codes = array_map('strval', array_keys($entries));
sort($actual_codes);
ffla_jurisdiction_assert($actual_codes === $expected_codes, 'Georgia registry codes must match the official state, county and special-code set exactly.');
ffla_jurisdiction_assert(($entries['038']['name'] ?? '') === 'Coweta', 'Coweta must use filing code 038.');
ffla_jurisdiction_assert(($entries['044A']['name'] ?? '') === 'DeKalb (Atlanta)', 'DeKalb Atlanta special code must be present.');

$coweta = Tax_Report_Jurisdiction_Registry::resolve(
    ['country' => 'US', 'state' => 'GA', 'city' => 'Newnan'],
    [
        ['name' => 'COWETA COUNTY NEWNAN : Special Tax'],
        ['name' => 'COWETA COUNTY NEWNAN : City Tax'],
        ['name' => 'COWETA COUNTY NEWNAN : County Tax'],
        ['name' => 'COWETA COUNTY NEWNAN : State Sales Tax'],
    ]
);
ffla_jurisdiction_assert(($coweta['code'] ?? '') === '038', 'WooCommerce Newnan tax components must resolve to Coweta code 038.');
ffla_jurisdiction_assert(($coweta['name'] ?? '') === 'Coweta', 'WooCommerce component order must not change the official jurisdiction name.');

$coweta_api = Tax_Report_Jurisdiction_Registry::resolve(
    ['country' => 'US', 'state' => 'GA', 'city' => 'Newnan'],
    [['type' => 'county', 'name' => 'Coweta']]
);
ffla_jurisdiction_assert(($coweta_api['code'] ?? '') === '038', 'A normalized API county component must resolve to the same official filing code.');

$fulton_atlanta = Tax_Report_Jurisdiction_Registry::resolve(
    ['country' => 'US', 'state' => 'GA', 'city' => 'Atlanta'],
    [['name' => 'FULTON COUNTY ATLANTA : County Tax']]
);
ffla_jurisdiction_assert(($fulton_atlanta['code'] ?? '') === '060A', 'Fulton Atlanta must use special code 060A.');

$unknown = Tax_Report_Jurisdiction_Registry::resolve(
    ['country' => 'US', 'state' => 'GA', 'city' => 'Imaginary'],
    [['name' => 'IMAGINARY DISTRICT : City Tax']]
);
ffla_jurisdiction_assert(($unknown['status'] ?? '') === 'needs_review', 'Unknown Georgia labels must be marked Needs Review.');
ffla_jurisdiction_assert(($unknown['code'] ?? 'unexpected') === '', 'Unknown Georgia labels must never receive an invented filing code.');

$service = new Tax_Report_Service();
$add = new ReflectionMethod(Tax_Report_Service::class, 'add_jurisdiction_bucket');
$add->setAccessible(true);
$finalize = new ReflectionMethod(Tax_Report_Service::class, 'finalize_jurisdiction_totals');
$finalize->setAccessible(true);
$totals = [];
$location = ['country' => 'US', 'state' => 'GA'];

$add->invokeArgs($service, [
    &$totals, $location, 'USD', 'county', 'Coweta', 8.0, 'api', 720, 0,
    'stored_quote', 1001, 9000, 720, '038', 'ready', 1000, 10000,
]);
$add->invokeArgs($service, [
    &$totals, $location, 'USD', 'county', 'Coweta', 7.5, 'sheet', 1350, 0,
    'woocommerce_tax_lines', 1002, 18000, 1350, '038', 'ready', 2000, 20000,
]);

$rows = $finalize->invoke($service, $totals);
ffla_jurisdiction_assert(count($rows) === 1, 'Rate, source and method differences must not split one filing code into multiple rows.');
ffla_jurisdiction_assert($rows[0]['orders'] === 2, 'The consolidated jurisdiction must count all unique orders.');
ffla_jurisdiction_assert($rows[0]['gross_sales'] === '300.00', 'Gross sales including shipping must be totaled.');
ffla_jurisdiction_assert($rows[0]['taxable_sales'] === '270.00', 'Taxable sales including taxed shipping must be totaled.');
ffla_jurisdiction_assert($rows[0]['taxable_shipping'] === '30.00', 'Taxed shipping must remain visible without being added twice.');
ffla_jurisdiction_assert($rows[0]['tax_collected'] === '20.70', 'Collected tax must be totaled.');
ffla_jurisdiction_assert($rows[0]['calculated_tax'] === '20.70', 'Expected tax must be totaled order by order.');
ffla_jurisdiction_assert($rows[0]['rate_percent'] === '7.6667', 'Displayed rate must be the weighted effective rate.');

$master = new ReflectionMethod(Tax_Report_Service::class, 'build_filing_master');
$master->setAccessible(true);
$master_rows = $master->invoke($service, [[
    'country' => 'US',
    'state' => 'GA',
    'filing_code' => '000',
    'currency' => 'USD',
    'orders' => 2,
    'jurisdictions' => 1,
    'gross_sales' => '300.00',
    'taxable_sales' => '270.00',
    'taxable_shipping' => '30.00',
    'non_taxable_sales' => '30.00',
    'needs_review_sales' => '0.00',
    'tax_collected' => '20.70',
    'tax_refunded' => '0.00',
    'net_tax' => '20.70',
    'calculated_tax' => '20.70',
    'over_under' => '0.00',
    'filing_status' => 'Ready',
]], $rows);
ffla_jurisdiction_assert(count($master_rows) === 2, 'The complete filing table must preserve one state total and every jurisdiction row.');
ffla_jurisdiction_assert($master_rows[0]['row_type'] === 'State total', 'The state total must appear before its jurisdictions.');
ffla_jurisdiction_assert($master_rows[0]['filing_code'] === '000', 'The complete table must preserve the state filing code.');
ffla_jurisdiction_assert($master_rows[0]['jurisdictions'] === 1, 'The complete table must preserve the state jurisdiction count.');
ffla_jurisdiction_assert($master_rows[1]['row_type'] === 'Jurisdiction', 'Jurisdiction rows must remain identifiable in the complete table.');
ffla_jurisdiction_assert($master_rows[1]['filing_code'] === '038', 'The complete table must preserve the official jurisdiction code.');
ffla_jurisdiction_assert($master_rows[1]['taxable_sales'] === '270.00', 'Jurisdiction taxable sales including shipping must remain unchanged in the complete table.');

$master_columns = Tax_Report_Service::get_columns('filing-master');
ffla_jurisdiction_assert($master_columns[0] === 'row_type', 'The complete filing export must begin with the row type.');
ffla_jurisdiction_assert(in_array('taxable_shipping', $master_columns, true), 'The complete filing export must retain taxed shipping visibility.');
ffla_jurisdiction_assert(in_array('over_under', $master_columns, true), 'The complete filing export must retain over/under collection.');

$admin_source = file_get_contents(__DIR__ . '/../../modules/tax-rates/admin/class-tax-reports-admin.php');
$complete_table_position = strpos((string) $admin_source, "__('Complete tax filing table'");
$filing_totals_position = strpos((string) $admin_source, "__('Filing totals'");
ffla_jurisdiction_assert(
    $complete_table_position !== false && $filing_totals_position !== false && $complete_table_position < $filing_totals_position,
    'The complete filing table must render before the existing Overview tables.'
);

$exporter_source = file_get_contents(__DIR__ . '/../../modules/tax-rates/includes/class-tax-report-exporter.php');
$master_export_position = strpos((string) $exporter_source, "'filing-master'");
$totals_export_position = strpos((string) $exporter_source, "'filing-totals'");
ffla_jurisdiction_assert(
    $master_export_position !== false && $totals_export_position !== false && $master_export_position < $totals_export_position,
    'The complete filing table must be the first exported dataset without removing existing exports.'
);

echo "Tax report jurisdiction smoke checks passed.\n";
