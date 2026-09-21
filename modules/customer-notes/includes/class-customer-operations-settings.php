<?php
/** Feature switches for the existing customer-notes module. */
defined('ABSPATH') || exit;

class FFLA_Customer_Operations_Settings
{
    const OPTION = 'ffla_customer_operations';

    public static function fields(): array
    {
        return [
            'notes' => ['General', 'Customer notes', 'Internal customer notes follow the customer across orders. Never printed or shared with buyers.', 'switch', true],
            'pickup' => ['Pickup', 'Ready for Pickup', 'Staff can mark eligible, paid local-pickup orders ready. Does not charge, refund or bypass fulfillment checks.', 'switch', false],
            'checklist' => ['Pickup', 'Preparation checklist', 'Track item inspection, serial review and prepared documents inside each order.', 'switch', false],
            'partial_pickup' => ['Pickup', 'Partial collection', 'Record cumulative units collected per order line, with employee and time in the audit history.', 'switch', false],
            'store_name' => ['Pickup', 'Pickup location name', 'Shown only in enabled pickup messages and customer progress.', 'text', ''],
            'store_address' => ['Pickup', 'Pickup address', 'Enter the location customers should visit. This does not change shipping zones or tax addresses.', 'textarea', ''],
            'store_hours' => ['Pickup', 'Pickup hours', 'Describe opening hours, including exceptions.', 'text', ''],
            'store_instructions' => ['Pickup', 'Pickup instructions', 'Customer-facing instructions. Never include internal notes or access codes.', 'textarea', ''],
            'serials' => ['Serial Numbers', 'Serial numbers', 'One serial per physical unit on firearm order lines. Saved on the order item, never on the customer or catalog product.', 'switch', false],
            'item_details' => ['Serial Numbers', 'Manufacturer, model and caliber', 'Save an editable historical copy from product attributes when staff save the item details.', 'switch', false],
            'require_serials' => ['Serial Numbers', 'Require serials before ready / collected', 'Stops this module’s readiness and collection actions if firearm units are missing serials. Requires Serial numbers.', 'switch', false],
            'invoice_serials' => ['Serial Numbers', 'Serials on invoices', 'Adds saved serials and enabled item details to WP Overnight invoice item rows. Regenerate existing PDFs to reflect changes.', 'switch', false],
            'packing_serials' => ['Serial Numbers', 'Serials on packing slips', 'Adds saved serials and enabled item details to WP Overnight packing slips. Internal notes are never printed.', 'switch', false],
            'followup' => ['Follow-up', 'Order follow-up', 'Track an internal case independently of the WooCommerce order status, including resolved orders.', 'switch', false],
            'attachments' => ['Follow-up', 'Private case attachments', 'Staff-only JPEG, PNG and PDF evidence, up to 2 MB each and 8 files per order. Stored in private, non-autoloaded database records, not public Media Library URLs.', 'switch', false],
            'notifications' => ['Notifications', 'Email communications', 'Master switch for this module’s messages. wp_mail acceptance is not proof of delivery; review your SMTP logs.', 'switch', false],
            'auto_ready' => ['Notifications', 'Automatic ready notification', 'Email the billing recipient once for each new ready cycle. Requires Ready for Pickup and Email communications.', 'switch', false],
            'pickup_reminders' => ['Notifications', 'Pickup reminders', 'Schedule bounded reminders for new ready cycles only. Requires automatic ready notifications and working WP-Cron.', 'switch', false],
            'reminder_days' => ['Notifications', 'Days between pickup reminders', '1–30 days. Existing scheduled reminders re-check all switches and current order state.', 'number', 3],
            'reminder_max' => ['Notifications', 'Maximum pickup reminders', '1–5 reminders per ready cycle. Never scans the complete order table.', 'number', 2],
            'staff_reminders' => ['Notifications', 'Assigned follow-up reminder', 'Notify the assigned employee at the due time. Requires Follow-up and Email communications.', 'switch', false],
            'ready_subject' => ['Notifications', 'Ready email subject', 'Available variables: {order_number}, {store_name}. No customer notes are available as variables.', 'text', 'Order #{order_number} is ready for pickup'],
            'ready_body' => ['Notifications', 'Ready email body', 'Plain text variables: {order_number}, {store_name}, {store_address}, {store_hours}, {store_instructions}, {order_url}.', 'textarea', 'Your order #{order_number} is ready for pickup at {store_name}.\n{store_address}\n{store_hours}\n{store_instructions}\n{order_url}'],
            'reminder_subject' => ['Notifications', 'Reminder email subject', 'Available variables match the ready email.', 'text', 'Pickup reminder for order #{order_number}'],
            'reminder_body' => ['Notifications', 'Reminder email body', 'Plain text. Only the approved pickup information is inserted.', 'textarea', 'Order #{order_number} is waiting for collection.\n{store_name}\n{store_address}\n{store_hours}\n{order_url}'],
            'public_subject' => ['Notifications', 'Public update subject', 'The message body is the explicit public update written by staff, not the internal case note.', 'text', 'Update on order #{order_number}'],
            'customer_progress' => ['Customer Visibility', 'Customer progress', 'Show the signed-in order owner a preparation / pickup summary in My Account. Guests use existing store support channels.', 'switch', false],
            'customer_serials' => ['Customer Visibility', 'Customer serial numbers', 'Expose saved serials to the signed-in order owner only. Requires Serial numbers and Customer progress.', 'switch', false],
            'public_messages' => ['Customer Visibility', 'Public order updates', 'Staff explicitly compose buyer-visible updates. Internal notes and case details never become public automatically.', 'switch', false],
            'customer_help' => ['Customer Visibility', 'Request help', 'Signed-in order owners can submit a bounded, rate-limited help request from My Account. Opens or reopens the internal case.', 'switch', false],
            'customer_tracking' => ['Customer Visibility', 'Shipment tracking summary', 'Read tracking information already stored by Advanced Shipment Tracking / WooCommerce Shipment Tracking. Does not contact carriers or create shipments.', 'switch', false],
            'customer_documents' => ['Customer Visibility', 'Document access', 'Show existing WP Overnight customer-authorized document actions. This does not expose staff-only PDFs or generate invoices automatically.', 'switch', false],
        ];
    }

    public static function get(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $values = [];
        foreach (self::fields() as $key => $field) { $values[$key] = $saved[$key] ?? $field[4]; }
        return $values;
    }

    public static function enabled(string $key): bool
    {
        $s = self::get();
        if (empty($s[$key]) || !in_array('customer-notes', (array) get_option('ffla_active_modules', []), true)) { return false; }
        $dependencies = ['partial_pickup'=>['pickup'], 'require_serials'=>['serials'], 'invoice_serials'=>['serials'], 'packing_serials'=>['serials'],
            'attachments'=>['followup'], 'auto_ready'=>['pickup','notifications'], 'pickup_reminders'=>['auto_ready'], 'staff_reminders'=>['followup','notifications'],
            'customer_serials'=>['serials','customer_progress'], 'customer_help'=>['followup','customer_progress'], 'customer_tracking'=>['customer_progress'], 'customer_documents'=>['customer_progress']];
        foreach ($dependencies[$key] ?? [] as $dependency) { if (!self::enabled($dependency)) { return false; } }
        return true;
    }

    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::fields() as $key => $f) {
            $v = $input[$key] ?? '';
            if (!is_scalar($v)) { $v = ''; }
            if ($f[3] === 'switch') { $out[$key] = $v === '1' || $v === true; }
            elseif ($f[3] === 'number') { $out[$key] = max(1, min($key === 'reminder_max' ? 5 : 30, (int) $v)); }
            else { $out[$key] = substr($f[3] === 'textarea' ? sanitize_textarea_field($v) : sanitize_text_field($v), 0, 5000); }
        }
        return $out;
    }
}
