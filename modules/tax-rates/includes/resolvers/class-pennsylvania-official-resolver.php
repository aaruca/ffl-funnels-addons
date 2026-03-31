<?php
/**
 * Pennsylvania Official Resolver.
 *
 * Uses the Pennsylvania Department of Revenue's current statewide sales tax
 * rate and official local add-ons for Allegheny County and Philadelphia.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Pennsylvania_Official_Resolver extends Tax_Resolver_Base
{
    const STATE_RATE = 0.0600;
    const ALLEGHENY_RATE = 0.0100;
    const PHILADELPHIA_RATE = 0.0200;
    const SOURCE_URL = 'https://www.pa.gov/agencies/revenue/resources/tax-types-and-information/sales-use-and-hotel-occupancy-tax';

    public function get_id(): string
    {
        return 'pa_official';
    }

    public function get_name(): string
    {
        return 'Pennsylvania Department of Revenue';
    }

    public function get_source_code(): string
    {
        return 'pa_dor_sales_tax';
    }

    public function get_supported_states(): array
    {
        return ['PA'];
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress    = $geocode['matchedAddress'] ?? null;
        $result->state             = 'PA';
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->determinationScope = 'address_rate_only';
        $result->resolutionMode    = 'official_state_and_local_overlay';
        $result->source            = $this->get_source_code();
        $result->sourceVersion     = 'current-law';
        $result->confidence        = !empty($geocode['success'])
            ? Tax_Quote_Result::CONFIDENCE_HIGH
            : Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);
        $result->trace['sourceUrl'] = self::SOURCE_URL;

        $result->add_breakdown('state', 'Pennsylvania State', self::STATE_RATE);

        $city = strtoupper(trim((string) ($normalized['city'] ?? '')));
        $county = strtoupper(trim((string) ($geocode['countyName'] ?? '')));
        $matched = strtoupper(trim((string) ($geocode['matchedAddress'] ?? '')));

        $is_philadelphia = $city === 'PHILADELPHIA'
            || strpos($county, 'PHILADELPHIA') !== false
            || strpos($matched, 'PHILADELPHIA') !== false;

        $is_allegheny = strpos($county, 'ALLEGHENY') !== false;

        if ($is_philadelphia) {
            $result->add_breakdown('city', 'Philadelphia', self::PHILADELPHIA_RATE);
            $result->trace['localOverlay'] = 'philadelphia';
        } elseif ($is_allegheny) {
            $result->add_breakdown('county', 'Allegheny County', self::ALLEGHENY_RATE);
            $result->trace['localOverlay'] = 'allegheny';
        } elseif (empty($geocode['success'])) {
            $result->confidence = Tax_Quote_Result::CONFIDENCE_MEDIUM;
            $result->limitations[] = 'Pennsylvania local tax could not be geocode-confirmed; returned the statewide rate only.';
        }

        $result->limitations[] = 'Pennsylvania imposes a 1% local tax in Allegheny County and a 2% local tax in Philadelphia, in addition to the 6% statewide rate.';
        $result->calculate_total();

        return $result;
    }
}
