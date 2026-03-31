<?php
/**
 * Virginia Official Resolver.
 *
 * Resolves Virginia's general retail sales and use tax using the official
 * Virginia Tax locality groupings published for the current rate structure.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Virginia_Official_Resolver extends Tax_Resolver_Base
{
    const SOURCE_URL = 'https://www.tax.virginia.gov/sales-and-use-tax';

    const RATE_7 = 0.0700;
    const RATE_63 = 0.0630;
    const RATE_6 = 0.0600;
    const RATE_53 = 0.0530;

    const LOCALITIES_7 = [
        'JAMES CITY COUNTY',
        'WILLIAMSBURG',
        'YORK COUNTY',
    ];

    const LOCALITIES_63 = [
        'CHARLOTTE COUNTY',
        'DANVILLE',
        'GLOUCESTER COUNTY',
        'HALIFAX COUNTY',
        'HENRY COUNTY',
        'NORTHAMPTON COUNTY',
        'PATRICK COUNTY',
        'PITTSYLVANIA COUNTY',
    ];

    const LOCALITIES_6 = [
        'ALEXANDRIA',
        'ARLINGTON COUNTY',
        'CHARLES CITY COUNTY',
        'CHESAPEAKE',
        'CHESTERFIELD COUNTY',
        'FAIRFAX',
        'FAIRFAX COUNTY',
        'FALLS CHURCH',
        'FRANKLIN',
        'GOOCHLAND COUNTY',
        'HAMPTON',
        'HANOVER COUNTY',
        'HENRICO COUNTY',
        'ISLE OF WIGHT COUNTY',
        'LOUDOUN COUNTY',
        'MANASSAS',
        'MANASSAS PARK',
        'NEW KENT COUNTY',
        'NEWPORT NEWS',
        'NORFOLK',
        'POQUOSON',
        'PORTSMOUTH',
        'POWHATAN COUNTY',
        'PRINCE WILLIAM COUNTY',
        'RICHMOND',
        'SOUTHAMPTON COUNTY',
        'SUFFOLK',
        'VIRGINIA BEACH',
    ];

    public function get_id(): string
    {
        return 'va_official';
    }

    public function get_name(): string
    {
        return 'Virginia Tax';
    }

    public function get_source_code(): string
    {
        return 'va_sales_tax';
    }

    public function get_supported_states(): array
    {
        return ['VA'];
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $locality = $this->resolve_locality_key($normalized, $geocode);
        $rate = $this->resolve_rate($locality);

        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress    = $geocode['matchedAddress'] ?? null;
        $result->state             = 'VA';
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->determinationScope = 'address_rate_only';
        $result->resolutionMode    = 'official_locality_group_match';
        $result->source            = $this->get_source_code();
        $result->sourceVersion     = 'current-law';
        $result->confidence        = !empty($geocode['success'])
            ? Tax_Quote_Result::CONFIDENCE_HIGH
            : Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);
        $result->trace['sourceUrl'] = self::SOURCE_URL;
        $result->trace['localityKey'] = $locality;
        $result->add_breakdown('state', 'Virginia Retail Sales Tax', $rate);
        $result->limitations[] = 'Virginia rates are resolved from official locality groups published by Virginia Tax.';
        $result->calculate_total();

        return $result;
    }

    private function resolve_locality_key(array $normalized, array $geocode): string
    {
        $city = strtoupper(trim((string) ($normalized['city'] ?? '')));
        $county = strtoupper(trim((string) ($geocode['countyName'] ?? '')));

        if ($city !== '') {
            return $city;
        }

        return $county;
    }

    private function resolve_rate(string $locality): float
    {
        if (in_array($locality, self::LOCALITIES_7, true)) {
            return self::RATE_7;
        }

        if (in_array($locality, self::LOCALITIES_63, true)) {
            return self::RATE_63;
        }

        if (in_array($locality, self::LOCALITIES_6, true)) {
            return self::RATE_6;
        }

        return self::RATE_53;
    }
}
