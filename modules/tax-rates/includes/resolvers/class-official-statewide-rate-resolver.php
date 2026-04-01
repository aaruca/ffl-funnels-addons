<?php
/**
 * Official Statewide Rate Resolver.
 *
 * Covers states where the general retail sales tax is a single statewide
 * rate without an additional local general sales tax layer for standard
 * retail sales. Sources are official state tax authority pages.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Official_Statewide_Rate_Resolver extends Tax_Resolver_Base
{
    /**
     * Official statewide general sales tax rates.
     *
     * Source URLs are kept for auditability and future maintenance.
     *
     * @var array<string,array<string,mixed>>
     */
    const STATES = [
        'CT' => [
            'rate'        => 0.0635,
            'jurisdiction'=> 'Connecticut State',
            'source_code' => 'ct_drs_statewide',
            'source_url'  => 'https://portal.ct.gov/drs/sales-tax/tax-information',
            'note'        => 'Connecticut imposes a 6.35% general sales tax and no additional local general sales tax.',
        ],
        'DC' => [
            'rate'        => 0.0600,
            'jurisdiction'=> 'District of Columbia',
            'source_code' => 'dc_otr_statewide',
            'source_url'  => 'https://otr.cfo.dc.gov/am/node/1800521',
            'note'        => 'DC general sales tax remains 6.0% through September 30, 2026 under the Oct. 1, 2025 tax notice.',
        ],
        'MA' => [
            'rate'        => 0.0625,
            'jurisdiction'=> 'Massachusetts State',
            'source_code' => 'ma_dor_statewide',
            'source_url'  => 'https://www.mass.gov/guides/sales-and-use-tax',
            'note'        => 'Massachusetts general sales tax is 6.25% with no additional local general sales tax.',
        ],
        'MD' => [
            'rate'        => 0.0600,
            'jurisdiction'=> 'Maryland State',
            'source_code' => 'md_comptroller_statewide',
            'source_url'  => 'https://www.marylandtaxes.gov/forms/Tax_rate_chart.pdf',
            'note'        => 'Maryland general sales and use tax is 6% with no additional local general sales tax.',
        ],
        'ME' => [
            'rate'        => 0.0550,
            'jurisdiction'=> 'Maine State',
            'source_code' => 'me_revenue_statewide',
            'source_url'  => 'https://www.maine.gov/revenue/taxes/sales-use-service-provider-tax/rates-due-dates',
            'note'        => 'Maine general sales tax is 5.5% with no additional local general sales tax.',
        ],
        'MS' => [
            'rate'        => 0.0700,
            'jurisdiction'=> 'Mississippi State',
            'source_code' => 'ms_dor_statewide',
            'source_url'  => 'https://www.dor.ms.gov/business/sales-use-tax/sales-tax-rates',
            'note'        => 'Mississippi general retail sales tax is 7% statewide.',
        ],
    ];

    public function get_id(): string
    {
        return 'official_statewide';
    }

    public function get_name(): string
    {
        return 'Official Statewide Rate Resolver';
    }

    public function get_source_code(): string
    {
        return 'official_statewide';
    }

    public function get_supported_states(): array
    {
        return array_keys(self::STATES);
    }

    public function requires_geocode(): bool
    {
        return false;
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $state_code = strtoupper((string) ($normalized['state'] ?? ''));
        $config = self::STATES[$state_code] ?? null;

        if (!$config) {
            return Tax_Quote_Result::unsupported($state_code, $normalized, $normalized);
        }

        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress    = $geocode['matchedAddress'] ?? null;
        $result->state             = $state_code;
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->determinationScope = 'address_rate_only';
        $result->resolutionMode    = 'official_statewide_rate';
        $result->source            = $config['source_code'];
        $result->sourceVersion     = 'current-law';
        $result->confidence        = Tax_Quote_Result::CONFIDENCE_HIGH;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);
        $result->trace['sourceUrl'] = $config['source_url'];
        $result->limitations[] = $config['note'];
        $result->add_breakdown('state', $config['jurisdiction'], (float) $config['rate']);
        $result->calculate_total();

        return $result;
    }
}
