<?php
/**
 * Hawaii General Excise Tax Resolver.
 *
 * Uses Hawaii Department of Taxation guidance for the 4.0% state GET and the
 * 0.5% county surcharge currently in effect across Honolulu, Kauai, Hawaii,
 * and Maui counties through 2030.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hawaii_GET_Resolver extends Tax_Resolver_Base
{
    const SOURCE_URL = 'https://tax.hawaii.gov/geninfo/countysurcharge/';
    const RATE = 0.0450;

    public function get_id(): string
    {
        return 'hi_get';
    }

    public function get_name(): string
    {
        return 'Hawaii Department of Taxation';
    }

    public function get_source_code(): string
    {
        return 'hi_get';
    }

    public function get_supported_states(): array
    {
        return ['HI'];
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress    = $geocode['matchedAddress'] ?? null;
        $result->state             = 'HI';
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->determinationScope = 'address_rate_only';
        $result->resolutionMode    = 'official_county_surcharge_schedule';
        $result->source            = $this->get_source_code();
        $result->sourceVersion     = 'current-law';
        $result->confidence        = Tax_Quote_Result::CONFIDENCE_HIGH;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);
        $result->trace['sourceUrl'] = self::SOURCE_URL;
        $result->add_breakdown('state', 'Hawaii GET', self::RATE);
        $result->limitations[] = 'Hawaii imposes a general excise tax rather than a conventional sales tax; this quote models the consumer-visible general retail rate.';
        $result->limitations[] = 'The official 0.5% county surcharge is currently in effect for Honolulu, Kauai, Hawaii, and Maui counties through 2030.';
        $result->calculate_total();

        return $result;
    }
}
