<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SnapFind (Typesense) Integration for the Wishlist module.
 *
 * Feature A — Injects wishlist heart buttons into SnapFind search result cards.
 * Feature C — Boosts wishlisted products in Typesense search ranking via _eval sort.
 * Optional — Adds `wishlist_count` to indexed product documents (requires schema + reindex in SnapFind).
 *
 * End-user documentation: FFL Funnels → Wishlist → Documentation (when SnapFind is active).
 *
 * @package FFL_Funnels_Addons
 */
class Alg_Wishlist_SnapFind
{
    /**
     * Register all hooks. Called once from the module bootstrap.
     */
    public function listen(): void
    {
        add_action('wp_enqueue_scripts', array($this, 'inject_snapfind_integration'), 99);

        // Index-time: add wishlist_count to each product document.
        add_filter('snapfind_formatted_document', array($this, 'add_wishlist_count_to_document'), 10, 2);
    }

    /**
     * Inject inline JS and CSS into the SnapFind frontend.
     *
     * Priority 99 so SnapFind (default priority) has already enqueued its script.
     */
    public function inject_snapfind_integration(): void
    {
        if (!wp_script_is('snapfind-public', 'enqueued')) {
            return;
        }

        // Ensure the wishlist script is also enqueued so AlgWishlist + AlgWishlistSettings exist.
        if (!wp_script_is('alg-wishlist-js', 'enqueued')) {
            return;
        }

        $this->inject_boost_script();
        $this->inject_button_script();
        $this->inject_css();
    }

    // ── Feature C: Per-user boost ──────────────────────────────────

    /**
     * Inject a "before" inline script on snapfind-public that pushes the
     * user's wishlisted product IDs into the boost config BEFORE the
     * SnapFind bundle reads it on DOMContentLoaded.
     *
     * Disabled by default. The store admin opts in from
     * Wishlist Settings → SnapFind Integration → "Boost wishlisted products in search".
     * Filter `alg_wishlist_snapfind_boost_enabled` allows code-level override.
     */
    private function inject_boost_script(): void
    {
        $options = get_option('alg_wishlist_settings', array());
        $enabled = !empty($options['alg_wishlist_snapfind_boost']);

        /**
         * Allow code to force-enable or force-disable the SnapFind wishlist boost.
         *
         * @param bool $enabled Current toggle state from the admin setting.
         */
        $enabled = (bool) apply_filters('alg_wishlist_snapfind_boost_enabled', $enabled);

        if (!$enabled) {
            return;
        }

        $ids = Alg_Wishlist_Core::get_wishlist_items();
        if (empty($ids)) {
            return;
        }

        $ids_json = wp_json_encode(array_map('strval', $ids));

        $js = <<<JS
(function(){
    var cfg = window.snapFindPublic;
    if (!cfg || !cfg.boosts) return;
    var ids = {$ids_json};
    if (!ids.length) return;
    if (!cfg.boosts.boosting) cfg.boosts.boosting = {};
    if (!cfg.boosts.boosting.product) cfg.boosts.boosting.product = [];
    cfg.boosts.boosting.product.push({
        field: 'id',
        operation: 'is',
        values: ids,
        boost: 5
    });
})();
JS;

        wp_add_inline_script('snapfind-public', $js, 'before');
    }

    // ── Feature A: Heart buttons on search results ─────────────────

    /**
     * Inject an "after" inline script on the wishlist JS that listens for
     * SnapFind render events and injects wishlist buttons into hit cards.
     */
    private function inject_button_script(): void
    {
        $heart_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
        $svg_escaped = str_replace("'", "\\'", $heart_svg);

        $js = <<<JS
(function(){
    var heartSvg = '{$svg_escaped}';

    function getWishlistIds() {
        return (window.AlgWishlistSettings && Array.isArray(AlgWishlistSettings.initial_items))
            ? AlgWishlistSettings.initial_items.map(String)
            : [];
    }

    function injectButtons() {
        var ids = getWishlistIds();
        var hits = document.querySelectorAll('.snaf-hit-inner[data-snaf-id]');

        hits.forEach(function(hit) {
            if (hit.querySelector('.snaf-wishlist-btn')) return;

            var productId = hit.getAttribute('data-snaf-id');
            if (!productId) return;

            var isActive = ids.indexOf(productId) !== -1;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'alg-add-to-wishlist snaf-wishlist-btn' + (isActive ? ' active' : '');
            btn.setAttribute('data-product-id', productId);
            btn.setAttribute('aria-label', isActive ? 'Remove from Wishlist' : 'Add to Wishlist');
            btn.innerHTML = heartSvg;

            if (isActive) {
                var path = btn.querySelector('path');
                if (path) path.setAttribute('fill', 'currentColor');
            }

            hit.appendChild(btn);
        });
    }

    document.addEventListener('snapfind:updated', injectButtons);
    document.addEventListener('snapfind:init', function() {
        setTimeout(injectButtons, 100);
    });
})();
JS;

        wp_add_inline_script('alg-wishlist-js', $js, 'after');
    }

    // ── CSS ────────────────────────────────────────────────────────

    /**
     * Add inline CSS to position and style the heart button inside SnapFind cards.
     */
    private function inject_css(): void
    {
        $options = get_option('alg_wishlist_settings');
        $primary = $this->sanitize_css_color(isset($options['alg_wishlist_color_primary']) ? $options['alg_wishlist_color_primary'] : '#ff4b4b');
        $active  = $this->sanitize_css_color(isset($options['alg_wishlist_color_active']) ? $options['alg_wishlist_color_active'] : '#cc0000');

        $css = "
.snaf-hit-inner { position: relative; }
.snaf-wishlist-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(255,255,255,0.9);
    color: {$primary};
    cursor: pointer;
    box-shadow: 0 1px 4px rgba(0,0,0,0.12);
    transition: transform 0.2s ease, color 0.2s ease, background 0.2s ease;
    line-height: 1;
}
.snaf-wishlist-btn:hover {
    transform: scale(1.15);
    background: #fff;
}
.snaf-wishlist-btn.active {
    color: {$active};
}
.snaf-wishlist-btn.active path {
    fill: currentColor;
}
.snaf-wishlist-btn.loading {
    opacity: 0.6;
    pointer-events: none;
}
";

        wp_add_inline_style('snapfind-public', $css);
    }

    /**
     * Sanitize a CSS color value to prevent injection.
     */
    private function sanitize_css_color(string $color): string
    {
        $color = sanitize_text_field($color);
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) || preg_match('/^(rgb|hsl)a?\([\d\s%,.\/]+\)$/i', $color)) {
            return $color;
        }
        return '#ff4b4b';
    }

    // ── Index-time: wishlist_count ──────────────────────────────────

    /**
     * Add a wishlist_count field to product documents at index time.
     *
     * Requires the user to add a `wishlist_count` field (int32, sort: yes)
     * in SnapFind's Schema Builder and reindex.
     *
     * @param array    $document The Typesense document being built.
     * @param \WP_Post $post     The WordPress post object.
     * @return array
     */
    public function add_wishlist_count_to_document(array $document, $post): array
    {
        if (!isset($post->post_type) || 'product' !== $post->post_type) {
            return $document;
        }

        global $wpdb;
        $table_items = $wpdb->prefix . 'alg_wishlist_items';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT wishlist_id) FROM {$table_items} WHERE product_id = %d",
            $post->ID
        ));

        $document['wishlist_count'] = $count;

        return $document;
    }
}
