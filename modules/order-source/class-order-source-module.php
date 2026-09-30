<?php
/**
 * Order Source Labels module.
 *
 * Classifies each WooCommerce order by where its products are sold and shows a
 * colour-coded label:
 *   - In Store    — every product carries an "In Store" product tag
 *   - Online Only — no product carries the tag
 *   - Mixed       — a combination of both
 *
 * The tag match is case- and separator-insensitive, so "In Store", "in store",
 * "IN STORE" and "In-store" are all treated the same.
 *
 * The label appears on the Orders list (a dedicated "Product Type" column, HPOS
 * and legacy) and on the Edit Order screen (an order-level badge in the details
 * panel plus a per-item badge on each product line).
 *
 * Single-file module — no settings, no storage.
 *
 * @package FFL_Funnels_Addons
 */

if ( ! defined( 'ABSPATH' )) {
    exit;
}

class Order_Source_Module extends FFLA_Module {

    /** Order/item classifications. */
    private const IN_STORE = 'in_store';
    private const ONLINE   = 'online';
    private const MIXED    = 'mixed';

    /** Orders-list column id. */
    private const COLUMN = 'ffla_order_source';

    /** The product tag (normalised) that marks a product as in-store. */
    private const IN_STORE_TAG = 'instore';

    /** Per-request memo: product_id => bool (has the in-store tag). */
    private $product_cache = array();

    public function get_id(): string {
        return 'order-source';
    }

    public function get_name(): string {
        return __( 'Order Source Labels', 'ffl-funnels-addons' );
    }

    public function get_description(): string {
        return __( 'Labels each order In Store, Online Only or Mixed — based on an "In Store" product tag — on the Orders list and the Edit Order screen.', 'ffl-funnels-addons' );
    }

    public function get_icon_svg(): string {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M3 9h18"/><path d="M9 20v-6h6v6"/></svg>';
    }

    public function boot(): void {
        // Admin-only: the labels are for store staff on the order screens.
        if ( ! is_admin()) {
            return;
        }

        // Orders list column — HPOS (custom orders table) and legacy (CPT).
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

        // Edit Order screen: order-level badge + a badge on each product line.
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_badge' ) );
        add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_item_badge' ), 10, 2 );

        add_action( 'admin_head', array( $this, 'print_styles' ) );
    }

    public function activate(): void {
        // Nothing to set up — classification is derived from product tags live.
    }

    public function deactivate(): void {
        // Nothing to clean up.
    }

    public function get_admin_pages(): array {
        return array();
    }

    public function render_admin_page( string $page_slug ): void {
        // No settings page.
    }

    /* =====================================================================
     * Orders list column
     * ================================================================== */

    /**
     * Add the "Product Type" column right after the order-number column.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function add_column( array $columns ): array {
        $out = array();
        foreach ($columns as $key => $label) {
            $out[ $key ] = $label;
            if ('order_number' === $key) {
                $out[ self::COLUMN ] = __( 'Product Type', 'ffl-funnels-addons' );
            }
        }
        // Fallback if the order-number column wasn't found for some reason.
        if ( ! isset( $out[ self::COLUMN ] )) {
            $out[ self::COLUMN ] = __( 'Product Type', 'ffl-funnels-addons' );
        }

        return $out;
    }

    /**
     * Render the column cell. WooCommerce passes the order object on HPOS and the
     * post ID on the legacy CPT screen, so accept either.
     *
     * @param string          $column
     * @param int|WC_Order    $order_or_id
     */
    public function render_column( string $column, $order_or_id ): void {
        if (self::COLUMN !== $column) {
            return;
        }

        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
        if ( ! $order instanceof WC_Order) {
            return;
        }

        // Controlled markup (labels escaped in badge_html).
        echo $this->badge_html( $this->classify( $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /* =====================================================================
     * Edit Order screen
     * ================================================================== */

    /**
     * Order-level badge in the order details panel, under the General fields.
     *
     * @param WC_Order $order
     */
    public function render_order_badge( $order ): void {
        if ( ! $order instanceof WC_Order) {
            $order = wc_get_order( $order );
        }
        if ( ! $order instanceof WC_Order) {
            return;
        }

        $badge = $this->badge_html( $this->classify( $order ) );
        if ('' === $badge) {
            return;
        }

        echo '<p class="form-field form-field-wide ffla-os-order-badge">'
            . '<span class="ffla-os-order-badge__label">' . esc_html__( 'Product Type', 'ffl-funnels-addons' ) . '</span>'
            . $badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . '</p>';
    }

    /**
     * Per-item badge next to each product line on the Edit Order screen.
     *
     * @param int           $item_id
     * @param WC_Order_Item $item
     */
    public function render_item_badge( $item_id, $item ): void {
        if ( ! $item instanceof WC_Order_Item_Product) {
            return; // Skip fees, shipping, etc.
        }

        $type = $this->item_is_in_store( $item ) ? self::IN_STORE : self::ONLINE;
        echo $this->badge_html( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /* =====================================================================
     * Classification
     * ================================================================== */

    /**
     * Classify an order from its product lines.
     *
     * @return string self::IN_STORE|self::ONLINE|self::MIXED, or '' when the
     *                order has no product lines to classify.
     */
    private function classify( WC_Order $order ): string {
        $items = $order->get_items();
        $this->prime_product_terms( $items );

        $has_in_store = false;
        $has_online   = false;

        foreach ($items as $item) {
            if ( ! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ($this->item_is_in_store( $item )) {
                $has_in_store = true;
            } else {
                $has_online = true;
            }
            if ($has_in_store && $has_online) {
                return self::MIXED;
            }
        }

        if ($has_in_store) {
            return self::IN_STORE;
        }
        if ($has_online) {
            return self::ONLINE;
        }

        return '';
    }

    /**
     * Warm the product-tag cache for a set of line items in a single query, so
     * the per-item get_the_terms() calls below are cache hits instead of one
     * query each. Only primes products not already memoised this request.
     *
     * @param array<int, WC_Order_Item> $items
     */
    private function prime_product_terms( array $items ): void {
        $ids = array();
        foreach ($items as $item) {
            if ( ! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ($product_id > 0 && ! isset( $this->product_cache[ $product_id ] )) {
                $ids[ $product_id ] = $product_id;
            }
        }
        if ($ids) {
            // update_object_term_cache skips objects already cached, so this is a
            // no-op when a persistent object cache is warm.
            update_object_term_cache( array_values( $ids ), 'product' );
        }
    }

    /**
     * Whether a line item's product carries the in-store tag. Variations inherit
     * the tag from their parent product, which get_product_id() already returns.
     */
    private function item_is_in_store( WC_Order_Item_Product $item ): bool {
        return $this->product_has_in_store_tag( (int) $item->get_product_id() );
    }

    /**
     * Whether the product has a tag that normalises to "instore".
     */
    private function product_has_in_store_tag( int $product_id ): bool {
        if ($product_id <= 0) {
            return false;
        }
        if (isset( $this->product_cache[ $product_id ] )) {
            return $this->product_cache[ $product_id ];
        }

        $is_in_store = false;
        $terms       = get_the_terms( $product_id, 'product_tag' );
        if (is_array( $terms )) {
            foreach ($terms as $term) {
                if (self::IN_STORE_TAG === $this->normalise_tag( $term->name )) {
                    $is_in_store = true;
                    break;
                }
            }
        }

        $this->product_cache[ $product_id ] = $is_in_store;

        return $is_in_store;
    }

    /**
     * Lowercase and strip everything but letters/numbers, so "In Store",
     * "in-store" and "IN  STORE" all collapse to "instore".
     */
    private function normalise_tag( string $name ): string {
        return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $name ) );
    }

    /* =====================================================================
     * Presentation
     * ================================================================== */

    /**
     * The coloured badge markup for a classification (label is escaped). Returns
     * '' for an unknown/empty type.
     */
    private function badge_html( string $type ): string {
        $labels = array(
            self::IN_STORE => __( 'In Store', 'ffl-funnels-addons' ),
            self::ONLINE   => __( 'Online Only', 'ffl-funnels-addons' ),
            self::MIXED    => __( 'Mixed', 'ffl-funnels-addons' ),
        );
        if ( ! isset( $labels[ $type ] )) {
            return '';
        }

        return '<span class="ffla-os-badge ffla-os-badge--' . esc_attr( $type ) . '">'
            . esc_html( $labels[ $type ] )
            . '</span>';
    }

    /**
     * Badge styling — only on the order list and edit screens (HPOS + legacy).
     */
    public function print_styles(): void {
        $screen = get_current_screen();
        if ( ! $screen instanceof WP_Screen) {
            return;
        }
        $screens = array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' );
        if ( ! in_array( $screen->id, $screens, true )) {
            return;
        }
        ?>
        <style id="ffla-order-source-css">
            /* Mirror WooCommerce's own .order-status badges (soft tinted
                background, darker same-hue text, pill shape, subtle bottom
                border) so our labels sit consistently beside the Status column. */
            .ffla-os-badge {
                display: inline-flex;
                line-height: 2.5em;
                padding: 0 1em;
                margin: -0.25em 0;
                border-radius: 4px;
                border-bottom: 1px solid rgba(0, 0, 0, 0.05);
                font-weight: 400;
                white-space: nowrap;
                max-width: 100%;
                vertical-align: middle;
            }
            .ffla-os-badge--in_store { background: #f8d5d5; color: #8a1616; }
            .ffla-os-badge--online   { background: #cfeee8; color: #0a5f54; }
            .ffla-os-badge--mixed    { background: #e4d8fb; color: #4c1d95; }
            /* Orders list: uniform width so the Product Type column aligns neatly
                (fits the widest label, "Online Only"). Only in the column — the
                per-item badges on the order screen keep their natural width. */
            .column-ffla_order_source .ffla-os-badge {
                min-width: 8em;
                box-sizing: border-box;
                justify-content: center;
            }
            /* Edit Order: per-item badge sits under the line-item name/meta. */
            td.name .ffla-os-badge { margin-top: 4px; }
            /* Edit Order: order-level badge row. */
            .ffla-os-order-badge .ffla-os-order-badge__label {
                display: block;
                font-weight: 600;
                margin-bottom: 4px;
            }
        </style>
        <?php
    }
}
