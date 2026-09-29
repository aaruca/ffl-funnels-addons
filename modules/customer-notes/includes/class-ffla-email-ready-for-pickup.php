<?php
/**
 * Customer email sent when an order is marked Ready for Pickup.
 *
 * Loaded from the woocommerce_email_classes filter, once WC_Email exists.
 *
 * @package FFL_Funnels_Addons
 */
defined('ABSPATH') || exit;

if (!class_exists('FFLA_Email_Ready_For_Pickup', false)) :

class FFLA_Email_Ready_For_Pickup extends WC_Email
{
    public function __construct()
    {
        $this->id = FFLA_Customer_Operations_Ready_Email::ID;
        $this->customer_email = true;
        $this->title = __('Ready for pickup', 'ffl-funnels-addons');
        $this->description = __('Sent to customers when their order is marked Ready for Pickup. Staff can also send it from the order screen (Send order email or Order actions), even when automatic sending is disabled here.', 'ffl-funnels-addons');
        $this->template_html = 'emails/customer-ready-for-pickup.php';
        $this->template_plain = 'emails/plain/customer-ready-for-pickup.php';
        $this->template_base = dirname(__DIR__) . '/templates/';
        $this->placeholders = ['{order_date}' => '', '{order_number}' => ''];
        if (property_exists($this, 'email_group')) { $this->email_group = 'order-updates'; }

        add_action('woocommerce_order_status_' . FFLA_Customer_Operations::STATUS . '_notification', [$this, 'trigger'], 10, 2);

        parent::__construct();
    }

    /** Automatic send when the order becomes Ready for Pickup. Respects the Enable setting. */
    public function trigger($order_id, $order = false): bool
    {
        if (!$this->is_enabled()) { return false; }
        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        $sent = $this->send_for($order);
        if ($order instanceof WC_Order) {
            FFLA_Customer_Operations::audit($order, $sent
                ? 'Ready for pickup email sent automatically to the billing email. Acceptance does not confirm delivery.'
                : 'Ready for pickup email was not sent automatically. Review the billing email and mail logs.');
        }
        return $sent;
    }

    /** Manual send from the order screen. Available for any ready order, even when automatic sending is off. */
    public function send_manually($order): bool
    {
        return $this->send_for($order);
    }

    private function send_for($order): bool
    {
        if (!$order instanceof WC_Order || $order->get_status() !== FFLA_Customer_Operations::STATUS) { return false; }
        $this->setup_locale();
        $this->object = $order;
        $this->recipient = $order->get_billing_email();
        $this->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $recipient = $this->get_recipient();
        $sent = $recipient ? (bool) $this->send($recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments()) : false;
        $this->restore_locale();
        return $sent;
    }

    public function get_default_subject()
    {
        return __('Your {site_title} order #{order_number} is ready for pickup', 'ffl-funnels-addons');
    }

    public function get_default_heading()
    {
        return __('Your order is ready for pickup', 'ffl-funnels-addons');
    }

    public function get_default_additional_content()
    {
        return __('We look forward to seeing you.', 'ffl-funnels-addons');
    }

    public function get_content_html()
    {
        return wc_get_template_html($this->template_html, $this->template_args(false), '', $this->template_base);
    }

    public function get_content_plain()
    {
        return wc_get_template_html($this->template_plain, $this->template_args(true), '', $this->template_base);
    }

    private function template_args(bool $plain_text): array
    {
        return [
            'order' => $this->object,
            'email_heading' => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'pickup' => self::pickup_details($this->object),
            'sent_to_admin' => false,
            'plain_text' => $plain_text,
            'email' => $this,
        ];
    }

    /** Pickup location saved with the order's ready cycle, or the current settings. */
    public static function pickup_details($order): array
    {
        $saved = $order instanceof WC_Order ? (FFLA_Customer_Operations::data($order)['pickup_location'] ?? null) : null;
        $source = is_array($saved) ? $saved : FFLA_Customer_Operations_Settings::get();
        $details = [];
        foreach (['store_name', 'store_address', 'store_hours', 'store_instructions'] as $key) {
            $details[$key] = trim(str_replace('\\n', "\n", (string) ($source[$key] ?? '')));
        }
        return $details;
    }
}

endif;
