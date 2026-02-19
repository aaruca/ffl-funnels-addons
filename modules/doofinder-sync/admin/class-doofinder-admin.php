<?php
/**
 * Doofinder Admin — Debug page.
 *
 * Redesigned to use the FFLA shared design system.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Doofinder_Admin
{
    /**
     * Render the debug page content (inside FFLA shell).
     */
    public function render_debug_content(): void
    {
        // ── Field Mapping Card ──────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Doofinder Field Mapping', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-section-desc">' . esc_html__('Use these field names when configuring your Doofinder plugin mapping.', 'ffl-funnels-addons') . '</p>';

        $mappings = [
            ['_category_slugs', 'category_slugs'],
            ['_tag_slugs', 'tag_slugs'],
            ['_caliber_gauge_slugs', 'caliber_gauge_slugs'],
            ['_manufacturer_slugs', 'manufacturer_slugs'],
            ['_brand_slugs', 'brand_slugs'],
            ['_discount_codes', 'discount_price'],
            ['_pewc_has_extra_fields', 'pewc_has_extra_fields'],
            ['_product_class', 'product_class'],
        ];

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Internal Meta Key', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Doofinder Field Name', 'ffl-funnels-addons') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($mappings as $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html($row[0]) . '</code></td>';
            echo '<td><code>' . esc_html($row[1]) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>'; // end card

        // ── Inspect Product Card ────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Inspect Product Meta', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-section-desc">' . esc_html__('Enter a product ID to inspect the dynamic metadata values generated for Doofinder.', 'ffl-funnels-addons') . '</p>';

        $pid = isset($_GET['pid']) ? absint($_GET['pid']) : 0;

        echo '<form method="get" class="wb-field wb-field--inline" style="max-width:400px;">';
        echo '<input type="hidden" name="page" value="ffla-doofinder-debug">';
        echo '<label class="wb-field__label" for="dsync_pid" style="width:auto;">' . esc_html__('Product ID:', 'ffl-funnels-addons') . '</label>';
        echo '<input type="number" id="dsync_pid" name="pid" value="' . esc_attr($pid ? $pid : '') . '" class="wb-input" style="width:100px;">';
        echo '<button type="submit" class="wb-btn wb-btn--primary wb-btn--sm">' . esc_html__('Inspect', 'ffl-funnels-addons') . '</button>';
        echo '</form>';

        if ($pid) {
            $this->render_inspect_results($pid);
        }

        echo '</div></div>'; // end card

        // ── Doofinder Integration Card ──────────────────────────────
        $snippet = '<button type="button" class="wbw-doofinder-btn" data-product-id="<%= @item[&quot;id&quot;] %>" title="Add to Wishlist">' . "\n"
            . '  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . "\n"
            . '    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>' . "\n"
            . '  </svg>' . "\n"
            . '</button>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Doofinder Integration — Wishlist Button', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-section-desc">' . esc_html__('Add this HTML to your Doofinder Layer Template (Product Card) to show a wishlist button in search results. The plugin JS will automatically detect this button and handle wishlist logic.', 'ffl-funnels-addons') . '</p>';

        echo '<div id="dsync-snippet-wrap" style="position:relative;background:#1e1e1e;border-radius:6px;padding:16px 48px 16px 16px;margin-top:12px;">';
        echo '<button type="button" id="dsync-copy-btn" title="' . esc_attr__('Copy to clipboard', 'ffl-funnels-addons') . '" '
            . 'style="position:absolute;top:10px;right:10px;background:none;border:1px solid #555;border-radius:4px;cursor:pointer;color:#ccc;padding:4px 8px;font-size:12px;line-height:1;" '
            . 'onclick="(function(btn){var code=document.getElementById(\'dsync-snippet-code\').textContent;navigator.clipboard.writeText(code).then(function(){btn.textContent=\'✓ Copied\';btn.style.color=\'#4ade80\';setTimeout(function(){btn.textContent=\'Copy\';btn.style.color=\'#ccc\';},1800)});})(this)">'
            . esc_html__('Copy', 'ffl-funnels-addons') . '</button>';
        echo '<pre id="dsync-snippet-code" style="margin:0;white-space:pre;overflow-x:auto;color:#d4d4d4;font-size:13px;font-family:monospace;line-height:1.5;">' . esc_html($snippet) . '</pre>';
        echo '</div>';

        echo '</div></div>'; // end card
    }

    /**
     * Render inspection results for a given product ID.
     */
    private function render_inspect_results(int $pid): void
    {
        if (!function_exists('wc_get_product')) {
            FFLA_Admin::render_notice('warning', __('WooCommerce is not active.', 'ffl-funnels-addons'));
            return;
        }

        $product = wc_get_product($pid);

        if (!$product) {
            FFLA_Admin::render_notice('danger', sprintf(
                __('Product not found with ID: %d', 'ffl-funnels-addons'),
                $pid
            ));
            return;
        }

        echo '<div style="margin-top: var(--wb-spacing-xl);">';
        echo '<h4>' . sprintf(
            esc_html__('Results for Product #%d: %s', 'ffl-funnels-addons'),
            $pid,
            esc_html($product->get_name())
        ) . '</h4>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:30%;">' . esc_html__('Meta Key', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Computed Value', 'ffl-funnels-addons') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (Doofinder_Core::dynamic_meta_config() as $meta_key => $opts) {
            $val = Doofinder_Core::get_dynamic_meta_value($pid, $opts);

            echo '<tr>';
            echo '<td><code>' . esc_html($meta_key) . '</code></td>';
            echo '<td>';

            if (is_bool($val)) {
                echo $val ? 'true' : 'false';
            } elseif (is_array($val)) {
                echo '<pre style="margin:0;">' . esc_html(print_r($val, true)) . '</pre>';
            } else {
                $str = (string) $val;
                echo $str !== '' ? esc_html($str) : '<span class="wb-text--muted">—</span>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
