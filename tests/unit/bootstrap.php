<?php
/**
 * Bootstrap for unit tests that do NOT require WordPress.
 *
 * These tests cover the pure-math portions of the plugin. WordPress-bound
 * functionality is covered separately by integration tests under
 * tests/integration (requires the WP test suite).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

// Minimal stubs for the WP functions referenced from class bodies we load.
if (!function_exists('wc_get_price_decimals')) {
    function wc_get_price_decimals() { return 2; }
}

require_once __DIR__ . '/../../modules/woobooster/includes/class-woobooster-bundle.php';
