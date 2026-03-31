<?php
/**
 * Official State Floor Resolver.
 *
 * Provides a conservative statewide base rate for states where local or
 * district taxes still require a richer official address-specific integration.
 * This keeps national coverage functional while clearly signaling that the
 * determination is a state-rate floor rather than a final local-inclusive rate.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Official_State_Floor_Resolver extends Tax_Resolver_Base
{
    /**
     * @var array<string,array<string,mixed>>
     */
    const STATES = [
        'AL' => [
            'rate'        => 0.0400,
            'source_code' => 'al_revenue_floor',
            'source_url'  => 'https://www.revenue.alabama.gov/sales-use/state-sales-use-tax-rates/',
            'jurisdiction'=> 'Alabama State Floor',
            'note'        => 'Alabama state general sales tax rate is 4%; local sales taxes may apply by destination.',
        ],
        'AZ' => [
            'rate'        => 0.0560,
            'source_code' => 'az_dor_floor',
            'source_url'  => 'https://azdor.gov/individuals/income-tax-filing-assistance/understanding-use-tax',
            'jurisdiction'=> 'Arizona State Floor',
            'note'        => 'Arizona state transaction privilege/use tax rate is 5.6%; county and city taxes vary by location.',
        ],
        'CA' => [
            'rate'        => 0.0725,
            'source_code' => 'ca_cdtfa_floor',
            'source_url'  => 'https://www.cdtfa.ca.gov/taxes-and-fees/know-your-rate.htm',
            'jurisdiction'=> 'California State Floor',
            'note'        => 'California statewide base sales tax rate is 7.25%; district taxes vary by city/county/address.',
        ],
        'CO' => [
            'rate'        => 0.0290,
            'source_code' => 'co_dor_floor',
            'source_url'  => 'https://tax.colorado.gov/sales-tax-rate-changes',
            'jurisdiction'=> 'Colorado State Floor',
            'note'        => 'Colorado state sales tax rate is 2.9%; county, city, and district taxes vary by destination.',
        ],
        'FL' => [
            'rate'        => 0.0600,
            'source_code' => 'fl_dor_floor',
            'source_url'  => 'https://floridarevenue.com/taxes/taxesfees/Pages/sales_tax.aspx',
            'jurisdiction'=> 'Florida State Floor',
            'note'        => 'Florida general state sales tax rate is 6%; county discretionary surtax can apply.',
        ],
        'ID' => [
            'rate'        => 0.0600,
            'source_code' => 'id_stc_floor',
            'source_url'  => 'https://tax.idaho.gov/taxes/sales-use/online-guide/',
            'jurisdiction'=> 'Idaho State Floor',
            'note'        => 'Idaho sales tax rate is 6%; certain local resort taxes are not yet modeled here.',
        ],
        'IL' => [
            'rate'        => 0.0625,
            'source_code' => 'il_dor_floor',
            'source_url'  => 'https://tax.illinois.gov/questionsandanswers/answer.139.html',
            'jurisdiction'=> 'Illinois State Floor',
            'note'        => 'Illinois state general merchandise rate is 6.25%; local occupation taxes vary by destination.',
        ],
        'MO' => [
            'rate'        => 0.04225,
            'source_code' => 'mo_dor_floor',
            'source_url'  => 'https://dor.mo.gov/taxation/business/tax-types/sales-use/',
            'jurisdiction'=> 'Missouri State Floor',
            'note'        => 'Missouri state sales tax rate is 4.225%; county, city, and district taxes vary by location.',
        ],
        'NM' => [
            'rate'        => 0.04875,
            'source_code' => 'nm_trd_floor',
            'source_url'  => 'https://www.tax.newmexico.gov/governments/municipal-county-governments/local-option-taxes/',
            'jurisdiction'=> 'New Mexico State Floor',
            'note'        => 'New Mexico base state gross receipts tax rate is 4.875%; county and municipal gross receipts taxes vary by location.',
        ],
        'NY' => [
            'rate'        => 0.0400,
            'source_code' => 'ny_dtf_floor',
            'source_url'  => 'https://www.tax.ny.gov/bus/st/rates.htm',
            'jurisdiction'=> 'New York State Floor',
            'note'        => 'New York state sales tax rate is 4%; local taxes and the MCTD surcharge can apply by destination.',
        ],
        'SC' => [
            'rate'        => 0.0600,
            'source_code' => 'sc_dor_floor',
            'source_url'  => 'https://www.dor.sc.gov/sales-use-tax-index/sales-tax',
            'jurisdiction'=> 'South Carolina State Floor',
            'note'        => 'South Carolina statewide sales tax rate is 6%; county and municipal local taxes may apply.',
        ],
    ];

    public function get_id(): string
    {
        return 'official_state_floor';
    }

    public function get_name(): string
    {
        return 'Official State Floor Resolver';
    }

    public function get_source_code(): string
    {
        return 'official_state_floor';
    }

    public function get_supported_states(): array
    {
        return array_keys(self::STATES);
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
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        $result->determinationScope = 'state_rate_only';
        $result->resolutionMode    = 'official_state_floor';
        $result->source            = $config['source_code'];
        $result->sourceVersion     = 'current-law';
        $result->confidence        = Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);
        $result->trace['sourceUrl'] = $config['source_url'];
        $result->limitations[] = $config['note'];
        $result->limitations[] = 'This result is a conservative statewide floor. Additional county, city, district, or regional taxes may apply and are not yet fully modeled for this state.';
        $result->add_breakdown('state', $config['jurisdiction'], (float) $config['rate']);
        $result->calculate_total();

        return $result;
    }
}
