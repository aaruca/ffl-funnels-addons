<?php
/**
 * Customer "Ready for pickup" email (plain text).
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/customer-ready-for-pickup.php.
 *
 * @package FFL_Funnels_Addons
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var array    $pickup  store_name, store_address, store_hours, store_instructions.
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	echo sprintf( esc_html__( 'Hi %s,', 'ffl-funnels-addons' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html__( 'Hi,', 'ffl-funnels-addons' ) . "\n\n";
}
/* translators: %s: Order number */
echo sprintf( esc_html__( 'Your order #%s is ready for pickup.', 'ffl-funnels-addons' ), esc_html( $order->get_order_number() ) ) . "\n\n";

$pickup_lines = array_filter( array( $pickup['store_name'], $pickup['store_address'], $pickup['store_hours'] ), 'strlen' );
if ( $pickup_lines ) {
	echo esc_html__( 'Pickup location', 'ffl-funnels-addons' ) . "\n";
	echo esc_html( implode( "\n", $pickup_lines ) ) . "\n\n";
}
if ( '' !== $pickup['store_instructions'] ) {
	echo esc_html( $pickup['store_instructions'] ) . "\n\n";
}

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n----------------------------------------\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
