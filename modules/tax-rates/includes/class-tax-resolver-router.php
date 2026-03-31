<?php
/**
 * Tax Resolver Router.
 *
 * Selects the appropriate resolver for a given state based on
 * coverage rules, dataset freshness, and resolver registration.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Resolver_Router
{
    /** @var Tax_Resolver_Base[] Registered resolvers keyed by ID. */
    private static $resolvers = [];

    /**
     * Register a resolver instance.
     */
    public static function register(Tax_Resolver_Base $resolver): void
    {
        self::$resolvers[$resolver->get_id()] = $resolver;
    }

    /**
     * Get all registered resolvers.
     *
     * @return Tax_Resolver_Base[]
     */
    public static function get_all(): array
    {
        return self::$resolvers;
    }

    /**
     * Route to the appropriate resolver for a state.
     *
     * @param  string $state_code Two-letter state code.
     * @return Tax_Resolver_Base|null
     */
    public static function route(string $state_code): ?Tax_Resolver_Base
    {
        $state_code = strtoupper($state_code);

        // First, check coverage rules for a named resolver.
        $rule = Tax_Coverage::get_state($state_code);
        if ($rule && !empty($rule['resolver_name'])) {
            if (isset(self::$resolvers[$rule['resolver_name']])) {
                return self::$resolvers[$rule['resolver_name']];
            }
        }

        // Fallback: find any resolver that supports this state.
        foreach (self::$resolvers as $resolver) {
            if ($resolver->supports_state($state_code)) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * Get resolver health status for all registered resolvers.
     *
     * @return array[]
     */
    public static function get_health(): array
    {
        $health = [];

        foreach (self::$resolvers as $resolver) {
            $dataset = $resolver->get_id();
            $states  = $resolver->get_supported_states();

            $health[] = [
                'resolver'        => $resolver->get_id(),
                'name'            => $resolver->get_name(),
                'sourceCode'      => $resolver->get_source_code(),
                'supportedStates' => $states,
                'stateCount'      => count($states),
            ];
        }

        return $health;
    }

    /**
     * Reset all registered resolvers (for testing).
     */
    public static function reset(): void
    {
        self::$resolvers = [];
    }
}
