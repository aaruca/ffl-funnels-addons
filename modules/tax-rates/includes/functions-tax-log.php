<?php
/**
 * Shared logger for the Sales Tax Resolver and Sales Tax Reports modules.
 *
 * Both modules call ffla_tax_log() for failures that happen without anyone
 * watching (checkout tax lookups, order snapshots, scheduled report emails).
 * Entries go to the WooCommerce log under the `ffla-tax` source
 * (WooCommerce → Status → Logs).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ffla_tax_log')) {
    /**
     * Write one entry to the WooCommerce log (source `ffla-tax`).
     *
     * @param string               $level   PSR-3 level (error, warning, notice, info, debug, ...).
     * @param string               $message Short description.
     * @param array<string,mixed>  $context Extra data, JSON-encoded after the message.
     */
    function ffla_tax_log(string $level, string $message, array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        $level = in_array($level, $levels, true) ? $level : 'notice';

        $line = $message;
        if (!empty($context)) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
            if (is_string($encoded)) {
                $line .= ' ' . $encoded;
            }
        }

        $logger = wc_get_logger();
        if (is_object($logger) && method_exists($logger, 'log')) {
            $logger->log($level, $line, ['source' => 'ffla-tax']);
        }
    }
}
