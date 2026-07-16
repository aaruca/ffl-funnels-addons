<?php
/**
 * Customer Notes Module.
 *
 * @package FFL_Funnels_Addons
 */

if ( ! defined( 'ABSPATH' )) {
    exit;
}

class Customer_Notes_Module extends FFLA_Module {

    public function get_id(): string {
        return 'customer-notes';
    }

    public function get_name(): string {
        return __( 'Customer Notes', 'ffl-funnels-addons' );
    }

    public function get_description(): string {
        return __( 'Add specific notes for each customer that appear on their orders and profile.', 'ffl-funnels-addons' );
    }

    public function get_icon_svg(): string {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 006.5 22H20V17H6.5A2.5 2.5 0 004 19.5zM4 19.5V5A2 2 0 016 3h12a2 2 0 012 2v12M15 7H9m6 4H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    public function boot(): void {
        if (is_admin()) {
            // Order meta boxes.
            add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ), 10, 2 );

            // Handle save for both legacy (CPT) and HPOS (since WC calls this hook in HPOS meta boxes too).
            add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_meta_box' ) );

            // User profile fields.
            add_action( 'show_user_profile', array( $this, 'render_user_profile_fields' ) );
            add_action( 'edit_user_profile', array( $this, 'render_user_profile_fields' ) );
            add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
            add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );
        }
    }

    public function activate(): void {
        // Nothing special to activate.
    }

    public function deactivate(): void {
        // Nothing special to deactivate.
    }

    public function get_admin_pages(): array {
        // No custom settings page required.
        return array();
    }

    public function render_admin_page( string $page_slug ): void {
        // Not used.
    }

    /**
     * Get note and type for an order (from user meta or guest option).
     */
    private function get_customer_note_data( $order_id ): array {
        $default = array(
			'note' => '',
			'type' => 'general',
		);
        $order   = wc_get_order( $order_id );
        if ( ! $order) {
            return $default;
        }

        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            $note = get_user_meta( $customer_id, '_ffla_customer_note', true );
            $type = get_user_meta( $customer_id, '_ffla_customer_note_type', true );
            return array(
                'note' => (string) $note,
                'type' => $type ? (string) $type : 'general',
            );
        }

        $email = $order->get_billing_email();
        if ($email) {
            $key  = '_ffla_guest_note_' . md5( $email );
            $data = get_option( $key, $default );
            // Fallback for older format if it was just a string
            if ( ! is_array( $data )) {
                return array(
					'note' => (string) $data,
					'type' => 'general',
				);
            }
            return array_merge( $default, $data );
        }

        return $default;
    }

    /**
     * Set note and type for an order.
     */
    private function set_customer_note_data( $order_id, string $note, string $type ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order) {
            return;
        }

        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            update_user_meta( $customer_id, '_ffla_customer_note', $note );
            update_user_meta( $customer_id, '_ffla_customer_note_type', $type );
            return;
        }

        $email = $order->get_billing_email();
        if ($email) {
            $key = '_ffla_guest_note_' . md5( $email );
            update_option(
                $key,
                array(
					'note' => $note,
					'type' => $type,
                ),
                false
            );
        }
    }

    /**
     * Register the metabox.
     */
    public function add_order_meta_box( $post_type, $post_or_order_object = null ): void {
        // Get the actual screen ID based on HPOS or legacy.
        $screen_id = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && function_exists( 'wc_get_page_screen_id' )
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        if ($screen_id !== $post_type && 'shop_order' !== $post_type) {
            return;
        }

        add_meta_box(
            'ffla_customer_notes',
            __( 'Customer Note', 'ffl-funnels-addons' ),
            array( $this, 'render_order_meta_box' ),
            $post_type,
            'side',
            'high'
        );
    }

    /**
     * Render the metabox.
     */
    public function render_order_meta_box( $post_or_order_object ): void {
        // In HPOS, this receives a WC_Order object. In legacy, it receives a WP_Post object.
        $order_id = is_a( $post_or_order_object, 'WC_Order' ) ? $post_or_order_object->get_id() : $post_or_order_object->ID;
        $data     = $this->get_customer_note_data( $order_id );
        $note     = $data['note'];
        $type     = $data['type'];

        $types = array(
            'general' => __( 'General', 'ffl-funnels-addons' ),
            'vip'     => __( 'VIP', 'ffl-funnels-addons' ),
            'fraud'   => __( 'Fraud / Warning', 'ffl-funnels-addons' ),
            'returns' => __( 'High Returns', 'ffl-funnels-addons' ),
            'support' => __( 'Requires Support', 'ffl-funnels-addons' ),
        );

        // Styling based on type
        $styles = '';
        if ('fraud' === $type) {
            $styles = '#ffla_customer_notes { border: 2px solid #d63638; background: #fcf0f1; } #ffla_customer_notes h2.hndle, #ffla_customer_notes .hndle.ui-sortable-handle { border-bottom-color: #d63638; color: #d63638; }';
        } elseif ('vip' === $type) {
            $styles = '#ffla_customer_notes { border: 2px solid #d4af37; background: #fffdf0; } #ffla_customer_notes h2.hndle, #ffla_customer_notes .hndle.ui-sortable-handle { border-bottom-color: #d4af37; color: #b5952f; }';
        } elseif ('returns' === $type) {
            $styles = '#ffla_customer_notes { border: 2px solid #d67f36; background: #fcf6f0; } #ffla_customer_notes h2.hndle, #ffla_customer_notes .hndle.ui-sortable-handle { border-bottom-color: #d67f36; color: #b3611d; }';
        } elseif ('support' === $type) {
            $styles = '#ffla_customer_notes { border: 2px solid #7a00df; background: #f7f0fc; } #ffla_customer_notes h2.hndle, #ffla_customer_notes .hndle.ui-sortable-handle { border-bottom-color: #7a00df; color: #7a00df; }';
        }

        wp_nonce_field( 'ffla_customer_note_save', 'ffla_customer_note_nonce' );
        ?>
        <?php if ($styles) : ?>
            <style><?php echo wp_strip_all_tags( $styles ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
        <?php endif; ?>
        <div class="ffla-customer-note-wrapper">
            <p style="margin-top: 0;">
                <select name="ffla_customer_note_type" style="width: 100%;">
                    <?php foreach ($types as $val => $label) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <textarea name="ffla_customer_note" style="width: 100%; height: 100px; padding: 6px; box-sizing: border-box;"><?php echo esc_textarea( $note ); ?></textarea>
            <p class="description" style="margin-top: 8px;"><?php esc_html_e( 'This note is tied to the customer (via their account or billing email) and will appear on all their orders.', 'ffl-funnels-addons' ); ?></p>
        </div>
        <?php
    }

    /**
     * Save the metabox note.
     */
    public function save_order_meta_box( $post_id ): void {
        if ( ! isset( $_POST['ffla_customer_note_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ffla_customer_note_nonce'] ), 'ffla_customer_note_save' )) {
            return;
        }

        // Avoid autosaves.
        if (defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE) {
            return;
        }

        // Check capabilities.
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if (isset( $_POST['ffla_customer_note'] )) {
            $note = sanitize_textarea_field( wp_unslash( $_POST['ffla_customer_note'] ) );
            $type = isset( $_POST['ffla_customer_note_type'] ) ? sanitize_key( $_POST['ffla_customer_note_type'] ) : 'general';
            $this->set_customer_note_data( $post_id, $note, $type );
        }
    }

    /**
     * Render the note in the user profile.
     */
    public function render_user_profile_fields( \WP_User $user ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $note = (string) get_user_meta( $user->ID, '_ffla_customer_note', true );
        $type = (string) get_user_meta( $user->ID, '_ffla_customer_note_type', true );
        if ( ! $type) {
$type = 'general'; }

        $types = array(
            'general' => __( 'General', 'ffl-funnels-addons' ),
            'vip'     => __( 'VIP', 'ffl-funnels-addons' ),
            'fraud'   => __( 'Fraud / Warning', 'ffl-funnels-addons' ),
            'returns' => __( 'High Returns', 'ffl-funnels-addons' ),
            'support' => __( 'Requires Support', 'ffl-funnels-addons' ),
        );
        ?>
        <h2><?php esc_html_e( 'Customer Notes', 'ffl-funnels-addons' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="ffla_customer_note_type"><?php esc_html_e( 'Note Type', 'ffl-funnels-addons' ); ?></label></th>
                <td>
                    <select name="ffla_customer_note_type" id="ffla_customer_note_type">
                        <?php foreach ($types as $val => $label) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ffla_customer_note"><?php esc_html_e( 'Customer Note', 'ffl-funnels-addons' ); ?></label></th>
                <td>
                    <textarea name="ffla_customer_note" id="ffla_customer_note" rows="5" cols="30"><?php echo esc_textarea( $note ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'This note will appear on the Edit Order page for all orders placed by this customer.', 'ffl-funnels-addons' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the note from the user profile.
     */
    public function save_user_profile_fields( $user_id ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // We do not need a custom nonce check here because WordPress natively
        // handles and verifies the '_wpnonce' field during the 'edit_user_profile_update' action.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset( $_POST['ffla_customer_note'] )) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $note = sanitize_textarea_field( wp_unslash( $_POST['ffla_customer_note'] ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $type = isset( $_POST['ffla_customer_note_type'] ) ? sanitize_key( $_POST['ffla_customer_note_type'] ) : 'general';
            update_user_meta( $user_id, '_ffla_customer_note', $note );
            update_user_meta( $user_id, '_ffla_customer_note_type', $type );
        }
    }
}
