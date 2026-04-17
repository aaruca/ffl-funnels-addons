<?php
/**
 * PHPStan bootstrap — defines plugin constants so static analysis can
 * parse files that reference them without running inside WordPress.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('FFLA_PATH')) {
    define('FFLA_PATH', dirname(__DIR__, 2) . '/');
}
if (!defined('FFLA_URL')) {
    define('FFLA_URL', 'https://example.test/wp-content/plugins/ffl-funnels-addons/');
}
if (!defined('FFLA_VERSION')) {
    define('FFLA_VERSION', '1.12.0');
}
if (!defined('FFLA_FILE')) {
    define('FFLA_FILE', dirname(__DIR__, 2) . '/ffl-funnels-addons.php');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
