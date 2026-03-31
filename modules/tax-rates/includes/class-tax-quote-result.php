<?php
/**
 * Tax Quote Result — Canonical response object.
 *
 * Represents the unified output contract for every tax quote,
 * matching the spec's canonical JSON response format.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Quote_Result
{
    /* Outcome codes. */
    const OUTCOME_SUCCESS              = 'SUCCESS';
    const OUTCOME_NO_SALES_TAX         = 'NO_SALES_TAX';
    const OUTCOME_UNSUPPORTED          = 'STATE_UNSUPPORTED';
    const OUTCOME_STATE_DISABLED       = 'STATE_DISABLED';
    const OUTCOME_RATE_NOT_DETERMINABLE = 'RATE_NOT_DETERMINABLE';
    const OUTCOME_DATASET_STALE        = 'DATASET_STALE';
    const OUTCOME_SOURCE_UNAVAILABLE   = 'SOURCE_UNAVAILABLE';
    const OUTCOME_GEOCODE_FAILED       = 'GEOCODE_FAILED';
    const OUTCOME_VALIDATION_ERROR     = 'VALIDATION_ERROR';
    const OUTCOME_INTERNAL_ERROR       = 'INTERNAL_ERROR';

    /* Confidence levels. */
    const CONFIDENCE_HIGH   = 'high';
    const CONFIDENCE_MEDIUM = 'medium';

    /** @var string UUID */
    public $queryId;

    /** @var array Raw input address. */
    public $inputAddress = [];

    /** @var array Normalized address components. */
    public $normalizedAddress = [];

    /** @var string|null Matched address from geocoder or resolver. */
    public $matchedAddress;

    /** @var string|null Two-letter state code. */
    public $state;

    /** @var string Coverage status for this state. */
    public $coverageStatus = Tax_Coverage::UNSUPPORTED;

    /** @var string What this quote resolves (e.g., 'address_rate_only'). */
    public $determinationScope = 'address_rate_only';

    /** @var string How the rate was resolved (e.g., 'dataset_match', 'remote_lookup'). */
    public $resolutionMode = '';

    /** @var string|null Source identifier (e.g., 'sst', 'idor_address_specific'). */
    public $source;

    /** @var string|null Specific version label of the dataset used. */
    public $sourceVersion;

    /** @var string|null Effective date of the rate data (YYYY-MM-DD). */
    public $effectiveDate;

    /** @var string Confidence level: 'high' or 'medium'. */
    public $confidence = self::CONFIDENCE_HIGH;

    /** @var float|null Total combined tax rate as decimal (e.g., 0.1025 = 10.25%). */
    public $totalRate;

    /** @var array[] Jurisdiction breakdown items. */
    public $breakdown = [];

    /** @var string Outcome code. */
    public $outcomeCode = self::OUTCOME_SUCCESS;

    /** @var string[] Limitations/disclaimers for this response. */
    public $limitations = [];

    /** @var array Trace/debug info. */
    public $trace = [];

    /** @var string|null Error message if not successful. */
    public $errorMessage;

    /**
     * Constructor — generate query ID.
     */
    public function __construct()
    {
        $this->queryId = wp_generate_uuid4();
        $this->trace   = [
            'resolver'    => null,
            'geocodeUsed' => false,
            'cacheHit'    => false,
            'durationMs'  => 0,
        ];
        $this->limitations = [
            'Quote for general goods profile.',
            'Not a universal tax determination for every seller scenario.',
        ];
    }

    /**
     * Add a jurisdiction breakdown item.
     *
     * @param string $type        'state', 'county', 'city', 'special'
     * @param string $jurisdiction Jurisdiction name.
     * @param float  $rate        Rate as decimal (e.g., 0.0625).
     */
    public function add_breakdown(string $type, string $jurisdiction, float $rate): void
    {
        $this->breakdown[] = [
            'type'         => $type,
            'jurisdiction' => $jurisdiction,
            'rate'         => $rate,
        ];
    }

    /**
     * Recalculate totalRate from breakdown items.
     */
    public function calculate_total(): void
    {
        $this->totalRate = 0.0;
        foreach ($this->breakdown as $item) {
            $this->totalRate += $item['rate'];
        }
        $this->totalRate = round($this->totalRate, 6);
    }

    /**
     * Mark this result as an error.
     */
    public function set_error(string $outcomeCode, string $message): void
    {
        $this->outcomeCode  = $outcomeCode;
        $this->errorMessage = $message;
        $this->totalRate    = null;
        $this->breakdown    = [];
    }

    /**
     * Check if this is a successful quote.
     */
    public function is_success(): bool
    {
        return in_array($this->outcomeCode, [
            self::OUTCOME_SUCCESS,
            self::OUTCOME_NO_SALES_TAX,
        ], true);
    }

    /**
     * Convert to the canonical JSON response structure.
     *
     * @return array
     */
    public function to_array(): array
    {
        $data = [
            'queryId'              => $this->queryId,
            'inputAddress'         => $this->inputAddress,
            'normalizedAddress'    => $this->normalizedAddress,
            'matchedAddress'       => $this->matchedAddress,
            'state'                => $this->state,
            'coverageStatus'       => $this->coverageStatus,
            'determinationScope'   => $this->determinationScope,
            'outcomeCode'          => $this->outcomeCode,
        ];

        if ($this->is_success()) {
            $data['resolutionMode'] = $this->resolutionMode;
            $data['source']         = $this->source;
            $data['sourceVersion']  = $this->sourceVersion;
            $data['effectiveDate']  = $this->effectiveDate;
            $data['confidence']     = $this->confidence;
            $data['totalRate']      = $this->totalRate;
            $data['breakdown']      = $this->breakdown;
        } else {
            $data['error'] = $this->errorMessage;
        }

        $data['limitations'] = $this->limitations;
        $data['trace']       = $this->trace;

        return $data;
    }

    /**
     * Create a "no sales tax" result.
     */
    public static function no_sales_tax(string $state_code, array $input, array $normalized): self
    {
        $result                   = new self();
        $result->inputAddress     = $input;
        $result->normalizedAddress = $normalized;
        $result->state            = $state_code;
        $result->coverageStatus   = Tax_Coverage::NO_SALES_TAX;
        $result->outcomeCode      = self::OUTCOME_NO_SALES_TAX;
        $result->totalRate        = 0.0;
        $result->resolutionMode   = 'no_tax_state';
        $result->source           = 'state_law';
        $result->confidence       = self::CONFIDENCE_HIGH;
        $result->limitations      = ['This state does not levy a general sales tax.'];

        return $result;
    }

    /**
     * Create an "unsupported state" result.
     */
    public static function unsupported(string $state_code, array $input, array $normalized): self
    {
        $result                   = new self();
        $result->inputAddress     = $input;
        $result->normalizedAddress = $normalized;
        $result->state            = $state_code;
        $result->coverageStatus   = Tax_Coverage::UNSUPPORTED;
        $result->set_error(self::OUTCOME_UNSUPPORTED, "Tax rate lookup is not yet supported for state: {$state_code}.");

        return $result;
    }

    /**
     * Create a "state disabled by store configuration" result.
     */
    public static function disabled_state(string $state_code, array $input, array $normalized): self
    {
        $result                    = new self();
        $result->inputAddress      = $input;
        $result->normalizedAddress = $normalized;
        $result->state             = $state_code;

        $rule = Tax_Coverage::get_state($state_code);
        if ($rule && !empty($rule['coverage_status'])) {
            $result->coverageStatus = $rule['coverage_status'];
        }

        $result->set_error(
            self::OUTCOME_STATE_DISABLED,
            sprintf('Tax resolver is currently disabled for state: %s. Enable it in Tax Resolver settings to use this state.', $state_code)
        );

        return $result;
    }

    /**
     * Create a validation error result.
     */
    public static function validation_error(array $input, array $errors): self
    {
        $result               = new self();
        $result->inputAddress = $input;
        $result->set_error(self::OUTCOME_VALIDATION_ERROR, implode(' ', $errors));

        return $result;
    }
}
