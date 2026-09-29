<?php
/** WooCommerce "Ready for pickup" customer email: registration, automatic send and manual send from the order screen. */
defined('ABSPATH') || exit;

class FFLA_Customer_Operations_Ready_Email
{
    const ID = 'ffla_customer_ready_for_pickup';
    const CLASS_NAME = 'FFLA_Email_Ready_For_Pickup';
    const ORDER_ACTION = 'ffla_send_ready_pickup_email';

    public static function boot(): void
    {
        add_filter('woocommerce_email_classes', [__CLASS__, 'register']);
        // WooCommerce fires "{action}_notification" for listed actions (optionally deferred).
        add_filter('woocommerce_email_actions', [__CLASS__, 'email_actions']);
        // "Send order email" box (WooCommerce 9.8+ REST order actions).
        add_filter('woocommerce_rest_order_actions_email_valid_template_classes', [__CLASS__, 'rest_templates'], 10, 2);
        add_filter('woocommerce_rest_order_actions_email_preferred_template_ids', [__CLASS__, 'rest_preferred'], 10, 2);
        add_action('woocommerce_rest_order_actions_email_send', [__CLASS__, 'rest_send'], 10, 2);
        // Classic "Order actions" box.
        add_filter('woocommerce_order_actions', [__CLASS__, 'order_actions'], 10, 2);
        add_action('woocommerce_order_action_' . self::ORDER_ACTION, [__CLASS__, 'order_action']);
        // "Send order email" box of PDF Invoices & Packing Slips for WooCommerce (WP Overnight).
        // It lists enabled emails by ID and sends them with trigger().
        add_filter('wpo_wcpdf_resend_order_emails_available', [__CLASS__, 'wpo_emails'], 10, 2);
    }

    public static function available(): bool
    {
        return FFLA_Customer_Operations_Settings::enabled('pickup');
    }

    public static function register($emails): array
    {
        $emails = is_array($emails) ? $emails : [];
        if (self::available() && class_exists('WC_Email')) {
            require_once __DIR__ . '/class-ffla-email-ready-for-pickup.php';
            $emails[self::CLASS_NAME] = new FFLA_Email_Ready_For_Pickup();
        }
        return $emails;
    }

    public static function email_actions($actions): array
    {
        $actions = is_array($actions) ? $actions : [];
        if (self::available()) { $actions[] = 'woocommerce_order_status_' . FFLA_Customer_Operations::STATUS; }
        return array_values(array_unique($actions));
    }

    /** The registered WooCommerce email, or null when it is unavailable. */
    public static function email()
    {
        if (!self::available() || !function_exists('WC')) { return null; }
        $emails = WC()->mailer()->get_emails();
        return isset($emails[self::CLASS_NAME]) && $emails[self::CLASS_NAME] instanceof WC_Email ? $emails[self::CLASS_NAME] : null;
    }

    /** The email when WooCommerce is set to send it automatically; this module's plain-text notice is skipped then. */
    public static function enabled_email()
    {
        $email = self::email();
        return $email && $email->is_enabled() ? $email : null;
    }

    private static function ready($order): bool
    {
        return $order instanceof WC_Order && $order->get_type() === 'shop_order' && $order->get_status() === FFLA_Customer_Operations::STATUS && self::available();
    }

    public static function rest_templates($classes, $order): array
    {
        $classes = is_array($classes) ? $classes : [];
        if (self::ready($order)) { $classes[] = self::CLASS_NAME; }
        return $classes;
    }

    /** Preselect this email in "Send order email" for a ready order. */
    public static function rest_preferred($ids, $order): array
    {
        $ids = is_array($ids) ? $ids : [];
        if (self::ready($order)) { array_unshift($ids, self::ID); }
        return $ids;
    }

    /** WooCommerce records its own "Email template ... sent" order note for this path. */
    public static function rest_send($order_id, $template_id): void
    {
        if ($template_id !== self::ID) { return; }
        $order = wc_get_order($order_id); $email = self::email();
        if (self::ready($order) && $email) { $email->send_manually($order); }
    }

    public static function wpo_emails($emails, $order_id = 0): array
    {
        $emails = is_array($emails) ? $emails : [];
        if ($order_id && self::ready(wc_get_order($order_id))) { $emails[] = self::ID; }
        return array_values(array_unique($emails));
    }

    public static function order_actions($actions, $order = null): array
    {
        $actions = is_array($actions) ? $actions : [];
        if (self::ready($order)) { $actions[self::ORDER_ACTION] = __('Send ready for pickup email to customer', 'ffl-funnels-addons'); }
        return $actions;
    }

    public static function order_action($order): void
    {
        $email = self::email();
        if (!self::ready($order) || !$email) { return; }
        FFLA_Customer_Operations::audit($order, $email->send_manually($order)
            ? 'Ready for pickup email sent to the billing email from Order actions. Acceptance does not confirm delivery.'
            : 'Ready for pickup email could not be sent from Order actions. Review the billing email and mail logs.');
    }
}
