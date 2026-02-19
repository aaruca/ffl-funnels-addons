<?php
/**
 * WooBooster Rule Tester — Diagnostics Tool.
 *
 * Enter a product ID/SKU and see which rule matched, query args, and resulting products.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Rule_Tester
{

    /**
     * Render the tester form and results.
     */
    public function render()
    {
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('Rule Tester', 'woobooster') . '</h2></div>';
        echo '<div class="wb-card__body">';

        echo '<p class="wb-section-desc">' . esc_html__('Enter a product ID or SKU to test which rule matches and see the resulting recommendations.', 'woobooster') . '</p>';

        echo '<div class="wb-field wb-field--inline">';
        echo '<input type="text" id="wb-test-product" class="wb-input" placeholder="' . esc_attr__('Product ID or SKU…', 'woobooster') . '">';
        echo '<button type="button" id="wb-test-btn" class="wb-btn wb-btn--primary">';
        echo WooBooster_Icons::get('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo esc_html__('Test', 'woobooster');
        echo '</button>';
        echo '</div>';

        echo '<div id="wb-test-results" class="wb-test-results" style="display:none;"></div>';

        echo '</div></div>';
    }
}
