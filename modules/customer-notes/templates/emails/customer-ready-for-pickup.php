<?php
/**
 * Customer "Ready for pickup" email (HTML).
 *
 * Override by copying it to yourtheme/woocommerce/emails/customer-ready-for-pickup.php.
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

$email_improvements_enabled = ! empty( $email->email_improvements_enabled );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'ffl-funnels-addons' ), esc_html( $order->get_billing_first_name() ) );
} else {
	esc_html_e( 'Hi,', 'ffl-funnels-addons' );
}
?>
</p>
<?php /* translators: %s: Order number */ ?>
<p><?php printf( esc_html__( 'Your order #%s is ready for pickup.', 'ffl-funnels-addons' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php if ( '' !== $pickup['store_name'] || '' !== $pickup['store_address'] || '' !== $pickup['store_hours'] ) : ?>
	<h2><?php esc_html_e( 'Pickup location', 'ffl-funnels-addons' ); ?></h2>
	<p>
		<?php if ( '' !== $pickup['store_name'] ) : ?>
			<strong><?php echo esc_html( $pickup['store_name'] ); ?></strong><br>
		<?php endif; ?>
		<?php if ( '' !== $pickup['store_address'] ) : ?>
			<?php echo nl2br( esc_html( $pickup['store_address'] ) ); ?><br>
		<?php endif; ?>
		<?php if ( '' !== $pickup['store_hours'] ) : ?>
			<?php echo esc_html( $pickup['store_hours'] ); ?>
		<?php endif; ?>
	</p>
<?php endif; ?>
<?php if ( '' !== $pickup['store_instructions'] ) : ?>
	<p><?php echo nl2br( esc_html( $pickup['store_instructions'] ) ); ?></p>
<?php endif; ?>

<?php

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
