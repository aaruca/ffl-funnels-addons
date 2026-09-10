<?php
/** Offline renderer: real admin markup with synthetic WordPress/provider data. */
define('ABSPATH', __DIR__);
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_attr__($text, $domain = '') { return esc_attr($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function selected($value, $expected, $echo = true) { return $value === $expected ? ' selected' : ''; }
function checked($value, $expected, $echo = true) { return $value === $expected ? ' checked' : ''; }
function number_format_i18n($value) { return number_format($value); }
function admin_url($path) { return 'https://example.invalid/wp-admin/' . $path; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="offline-fixture">'; }
function is_wp_error($value) { return false; }
function get_terms($args) {
    $terms = [];
    for ($id = 1; $id <= 40; $id++) {
        $terms[] = (object) ['term_id' => $id, 'name' => $id === 1 ? 'Example <script>alert(1)</script>' : 'Example ' . $id, 'slug' => 'example-' . $id, 'count' => 10, 'parent' => 0];
    }
    return $terms;
}
class FFLA_Admin {
    public static function render_notice($type, $message) { echo '<p class="notice">' . $message . '</p>'; }
}
class Google_Merchant_Policy_Engine {
    public static function dependency_available() { return true; }
    public static function get_settings() { return ['mode' => 'audit', 'batch_size' => 50, 'content_safety' => '1']; }
    public static function get_category_policy($id) { return 'pending'; }
    public static function get_effective_category_policy($id) { return ['policy' => 'pending', 'reason' => 'Category has not been reviewed.']; }
}
class Google_Merchant_Policy_Reconciler {
    public static function get_state() {
        return ['status' => 'paused', 'processed' => 40, 'allowed' => 10, 'blocked' => 10, 'pending' => 20, 'updated_at' => '2026-09-10 12:00:00', 'withdrawal_requests' => 0, 'skipped' => 0, 'last_error' => ''];
    }
}
require dirname(__DIR__, 2) . '/modules/google-merchant-policy/admin/class-google-merchant-policy-admin.php';
echo '<!doctype html><html lang="en"><meta charset="utf-8"><body><main class="ffla-admin">';
(new Google_Merchant_Policy_Admin())->render();
echo '</main></body></html>';
