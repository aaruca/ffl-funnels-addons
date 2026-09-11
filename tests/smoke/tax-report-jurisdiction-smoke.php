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

echo "Tax report jurisdiction smoke checks passed.\n";
