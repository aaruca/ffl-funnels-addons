<?php
/**
 * Checkout consent for review requests (Review requests → Ask for consent
 * at checkout). The box is unticked; only orders where the customer ticked
 * it get a request. Works on the classic checkout and, through
 * WooCommerce's additional checkout fields (WooCommerce 8.9+), on the
 * Checkout block.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Consent
{
    const META = '_ffla_review_consent';

    /** Field id for WooCommerce's additional checkout fields (Checkout block). */
    const BLOCK_FIELD = 'ffla/review-consent';

    public static function init(): void
    {
        if (!self::enabled()) {
            return;
        }

        add_action('woocommerce_review_order_before_submit', [__CLASS__, 'render_classic_field']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_classic_field'], 10, 2);
        add_action('woocommerce_init', [__CLASS__, 'register_block_field']);
    }

    public static function enabled(): bool
    {
        return 'optin' === Product_Reviews_Core::get_setting('request_consent', 'none');
    }

    public static function label(): string
    {
        $text = trim((string) Product_Reviews_Core::get_setting('request_consent_text', ''));

        return $text !== '' ? $text : __('Email me a link to review my purchase', 'ffl-funnels-addons');
    }

    public static function render_classic_field(): void
    {
        echo '<p class="form-row ffla-review-consent">';
        echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
        echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="ffla_review_consent" value="1"> ';
        echo '<span>' . esc_html(self::label()) . '</span>';
        echo '</label></p>';
    }

    /**
     * @param \WC_Order|mixed $order
     */
    public static function save_classic_field($order, $data = []): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        $ticked = isset($_POST['ffla_review_consent']) && '1' === (string) wp_unslash($_POST['ffla_review_consent']);
        $order->update_meta_data(self::META, $ticked ? 'yes' : 'no');
    }

    public static function register_block_field(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id'       => self::BLOCK_FIELD,
            'label'    => self::label(),
            'location' => 'order',
            'type'     => 'checkbox',
        ]);
    }

    /**
     * Ticked on the classic checkout, or on the Checkout block (stored by
     * WooCommerce as `_wc_other/ffla/review-consent`).
     */
    public static function order_consented(\WC_Order $order): bool
    {
        if ('yes' === (string) $order->get_meta(self::META)) {
            return true;
        }

        $block = $order->get_meta('_wc_other/' . self::BLOCK_FIELD);

        return in_array($block, [true, 1, '1', 'yes', 'true'], true);
    }
}
