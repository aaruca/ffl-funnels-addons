<?php
/**
 * FFL Dealer Finder — Bricks Builder Element.
 *
 * Renders the g-FFL Checkout dealer finder widget inside Bricks
 * templates, replicating the [ffl_dealer_finder] shortcode output.
 *
 * @package FFL_Funnels_Addons
 */

namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Dealer_Finder_Element extends \Bricks\Element
{
    public $category = 'FFL Checkout';
    public $name     = 'ffl-dealer-finder';
    public $icon     = 'ti-location-pin';
    public $tag      = 'div';

    public function get_label(): string
    {
        return esc_html__('FFL Dealer Finder', 'ffl-funnels-addons');
    }

    // ── Control Groups ─────────────────────────────────────────────────

    public function set_control_groups(): void
    {
        $this->control_groups['ffl_info'] = [
            'title' => esc_html__('Info', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];
    }

    // ── Controls ───────────────────────────────────────────────────────

    public function set_controls(): void
    {
        $this->controls['ffl_info_text'] = [
            'group'   => 'ffl_info',
            'tab'     => 'content',
            'type'    => 'info',
            'content' => esc_html__('This element renders the g-FFL Checkout dealer finder widget. It requires the g-FFL Checkout plugin to be active and a valid API key configured. The widget will only display when the cart contains firearms or ammo-compliance items.', 'ffl-funnels-addons'),
        ];
    }

    // ── Render ─────────────────────────────────────────────────────────

    public function render(): void
    {
        // In builder context, show a placeholder.
        if ($this->is_builder_context()) {
            $this->render_builder_placeholder();
            return;
        }

        // Delegate to the bridge class which has all the logic.
        if (!class_exists('FFL_Checkout_Dealer_Bridge')) {
            return;
        }

        $output = \FFL_Checkout_Dealer_Bridge::render([]);

        if (empty($output)) {
            return;
        }

        echo "<div {$this->render_attributes('_root')}>";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output is pre-escaped by the bridge class
        echo $output;
        echo '</div>';
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function is_builder_context(): bool
    {
        if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) {
            return true;
        }
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }
        if (class_exists('\Bricks\Database') && !empty(\Bricks\Database::$is_builder_call)) {
            return true;
        }
        return false;
    }

    private function render_builder_placeholder(): void
    {
        echo "<div {$this->render_attributes('_root')}>";
        echo '<div style="padding:24px;border:2px dashed #94a3b8;border-radius:8px;text-align:center;color:#64748b;background:#f8fafc;">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 8px;display:block;opacity:0.6;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        echo '<strong style="font-size:14px;">' . esc_html__('FFL Dealer Finder', 'ffl-funnels-addons') . '</strong>';
        echo '<p style="margin:8px 0 0;font-size:12px;">' . esc_html__('Dealer finder map and selection widget will render on the frontend when firearms or compliance items are in the cart.', 'ffl-funnels-addons') . '</p>';
        echo '</div>';
        echo '</div>';
    }
}
