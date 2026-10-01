<?php
/**
 * Order Badges module.
 *
 * Shows configurable, colour-coded product-tag badges on WooCommerce orders so
 * staff can tell at a glance what kind of products an order contains.
 *
 * Two settings (both choose from the site's real product tags):
 *   1. "Badge tags"    — tags to surface as badges, each with its own colour.
 *   2. "In-store tags" — tags that mean a product is physically in-store. A
 *                        product carrying none of them is treated as Online Only.
 *
 * Badges appear on the Orders list (a "Product Type" column, HPOS + legacy) and
 * the Edit Order screen (an order-level set in the details panel plus a per-item
 * set on each product line). Classification is derived live from product tags,
 * so it never goes stale when tags change.
 *
 * @package FFL_Funnels_Addons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Source_Module extends FFLA_Module {

	/** Settings page slug + option + save wiring. */
	private const PAGE_SLUG   = 'ffla-order-badges';
	private const OPTION      = 'ffla_order_source_settings';
	private const SAVE_ACTION = 'ffla_os_save_settings';
	private const NONCE       = 'ffla_os_settings';
	private const NONCE_FIELD = '_ffla_os_nonce';

	/** Orders-list column id. */
	private const COLUMN = 'ffla_order_source';

	/** Default colour for the "Online Only" badge. */
	private const DEFAULT_ONLINE_COLOR = '#0f766e';

	/** Default colour palette used when a selected tag has no colour yet. */
	private const PALETTE = array( '#2271b1', '#e02424', '#0f766e', '#7c3aed', '#b45309', '#be185d', '#0891b2', '#4d7c0f' );

	/** Per-request memo: product_id => array{tags: array<int,array>, online: bool}. */
	private $product_cache = array();

	/** Per-request memo of the parsed settings. */
	private $settings_cache = null;

	public function get_id(): string {
		return 'order-source';
	}

	public function get_name(): string {
		return __( 'Order Badges', 'ffl-funnels-addons' );
	}

	public function get_description(): string {
		return __( 'Show colour-coded product-tag badges on orders (In Store, Online Only, or any tags you choose) across the Orders list and the Edit Order screen.', 'ffl-funnels-addons' );
	}

	public function get_icon_svg(): string {
		return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M3 9h18"/><path d="M9 20v-6h6v6"/></svg>';
	}

	public function boot(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );

		// Orders list column — HPOS (custom orders table) and legacy (CPT).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Edit Order screen: order-level badges + a badge set on each product line.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_badge' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_item_badge' ), 10, 2 );

		add_action( 'admin_head', array( $this, 'print_badge_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
	}

	public function activate(): void {
		// Nothing to set up — classification is derived from product tags live.
	}

	public function deactivate(): void {
		// Nothing to clean up.
	}

	public function get_admin_pages(): array {
		return array(
			array(
				'slug'  => self::PAGE_SLUG,
				'title' => __( 'Settings', 'ffl-funnels-addons' ),
				'icon'  => $this->get_icon_svg(),
			),
		);
	}

	public function render_admin_page( string $page_slug ): void {
		if ( self::PAGE_SLUG === $page_slug ) {
			$this->render_settings();
		}
	}

	/* =====================================================================
	 * Settings
	 * ================================================================== */

	/**
	 * Parsed, validated settings.
	 *
	 * @return array{badge_tags: int[], colors: array<int,string>, instore_tags: int[], online_color: string}
	 */
	private function settings(): array {
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}

		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$badge_tags   = isset( $saved['badge_tags'] ) && is_array( $saved['badge_tags'] ) ? array_map( 'intval', $saved['badge_tags'] ) : array();
		$instore_tags = isset( $saved['instore_tags'] ) && is_array( $saved['instore_tags'] ) ? array_map( 'intval', $saved['instore_tags'] ) : array();
		$colors       = array();
		if ( isset( $saved['colors'] ) && is_array( $saved['colors'] ) ) {
			foreach ( $saved['colors'] as $id => $color ) {
				$hex = sanitize_hex_color( (string) $color );
				if ( $hex ) {
					$colors[ (int) $id ] = $hex;
				}
			}
		}
		$online_color = isset( $saved['online_color'] ) ? sanitize_hex_color( (string) $saved['online_color'] ) : '';

		$this->settings_cache = array(
			'badge_tags'   => $badge_tags,
			'colors'       => $colors,
			'instore_tags' => $instore_tags,
			'online_color' => $online_color ? $online_color : self::DEFAULT_ONLINE_COLOR,
		);

		return $this->settings_cache;
	}

	/**
	 * The colour configured for a badge tag, or a stable palette default.
	 */
	private function color_for( int $term_id ): string {
		$settings = $this->settings();
		if ( isset( $settings['colors'][ $term_id ] ) ) {
			return $settings['colors'][ $term_id ];
		}
		$index = array_search( $term_id, $settings['badge_tags'], true );
		$index = false === $index ? 0 : $index;

		return self::PALETTE[ $index % count( self::PALETTE ) ];
	}

	/**
	 * All product tags on this site, for the settings dropdowns.
	 *
	 * @return WP_Term[]
	 */
	private function all_product_tags(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Render the settings page body (inside the FFLA admin shell).
	 */
	private function render_settings(): void {
		$settings = $this->settings();
		$tags     = $this->all_product_tags();

		if ( isset( $_GET['ffla_os_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI flag.
			FFLA_Admin::render_notice( 'success', __( 'Settings saved.', 'ffl-funnels-addons' ) );
		}

		if ( empty( $tags ) ) {
			echo '<div class="wb-card"><div class="wb-card__body"><p>';
			echo esc_html__( 'No product tags exist yet. Add product tags in Products → Tags, then choose them here.', 'ffl-funnels-addons' );
			echo '</p></div></div>';
			return;
		}
		?>
		<form class="ffla-os-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>

			<div class="wb-card">
				<div class="wb-card__header"><h3><?php esc_html_e( 'Badge tags', 'ffl-funnels-addons' ); ?></h3></div>
				<div class="wb-card__body">
					<p class="wb-field__desc"><?php esc_html_e( 'Choose which product tags appear as badges on orders. Each product shows a badge for every one of its tags selected here. Pick a colour for each.', 'ffl-funnels-addons' ); ?></p>

					<select id="ffla-os-badge-tags" class="ffla-os-tags" name="ffla_os[badge_tags][]" multiple
						data-placeholder="<?php esc_attr_e( 'Select tags…', 'ffl-funnels-addons' ); ?>">
						<?php foreach ( $tags as $tag ) : ?>
							<option value="<?php echo esc_attr( (string) $tag->term_id ); ?>" <?php selected( in_array( (int) $tag->term_id, $settings['badge_tags'], true ) ); ?>>
								<?php echo esc_html( $tag->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<div id="ffla-os-color-rows" class="ffla-os-color-rows">
						<?php
						foreach ( $settings['badge_tags'] as $term_id ) {
							$term = get_term( $term_id, 'product_tag' );
							if ( ! $term instanceof WP_Term ) {
								continue;
							}
							$this->render_color_row( (int) $term_id, $term->name, $this->color_for( (int) $term_id ) );
						}
						?>
					</div>
				</div>
			</div>

			<div class="wb-card">
				<div class="wb-card__header"><h3><?php esc_html_e( 'In-store tags', 'ffl-funnels-addons' ); ?></h3></div>
				<div class="wb-card__body">
					<p class="wb-field__desc"><?php esc_html_e( 'Tags that mean a product is physically in-store. If a product has none of these tags, it is treated as "Online Only". Leave empty to not show the Online Only badge.', 'ffl-funnels-addons' ); ?></p>

					<select id="ffla-os-instore-tags" class="ffla-os-tags" name="ffla_os[instore_tags][]" multiple
						data-placeholder="<?php esc_attr_e( 'Select tags…', 'ffl-funnels-addons' ); ?>">
						<?php foreach ( $tags as $tag ) : ?>
							<option value="<?php echo esc_attr( (string) $tag->term_id ); ?>" <?php selected( in_array( (int) $tag->term_id, $settings['instore_tags'], true ) ); ?>>
								<?php echo esc_html( $tag->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<div class="ffla-os-online-color">
						<label class="ffla-os-color-row__name" for="ffla-os-online-color-input"><?php esc_html_e( 'Online Only badge colour', 'ffl-funnels-addons' ); ?></label>
						<input type="text" id="ffla-os-online-color-input" class="ffla-os-color"
							name="ffla_os[online_color]" value="<?php echo esc_attr( $settings['online_color'] ); ?>">
					</div>
				</div>
			</div>

			<div class="wb-actions-bar">
				<button type="submit" class="wb-btn wb-btn--primary"><?php esc_html_e( 'Save Settings', 'ffl-funnels-addons' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * One tag colour row (also cloned client-side when tags are selected).
	 */
	private function render_color_row( int $term_id, string $name, string $color ): void {
		?>
		<div class="ffla-os-color-row" data-id="<?php echo esc_attr( (string) $term_id ); ?>">
			<span class="ffla-os-color-row__name"><?php echo esc_html( $name ); ?></span>
			<input type="text" class="ffla-os-color" name="ffla_os[colors][<?php echo esc_attr( (string) $term_id ); ?>]" value="<?php echo esc_attr( $color ); ?>">
		</div>
		<?php
	}

	/**
	 * Persist the settings form.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'ffl-funnels-addons' ) );
		}
		check_admin_referer( self::NONCE, self::NONCE_FIELD );

		// Nonce verified above; the nested array is validated field-by-field below
		// (intval against existing tag IDs, sanitize_hex_color for colours).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['ffla_os'] ) && is_array( $_POST['ffla_os'] ) ? wp_unslash( $_POST['ffla_os'] ) : array();

		$valid_ids = array_map( 'intval', wp_list_pluck( $this->all_product_tags(), 'term_id' ) );

		$badge_tags = array();
		if ( isset( $raw['badge_tags'] ) && is_array( $raw['badge_tags'] ) ) {
			foreach ( $raw['badge_tags'] as $id ) {
				$id = (int) $id;
				if ( in_array( $id, $valid_ids, true ) ) {
					$badge_tags[] = $id;
				}
			}
		}

		$instore_tags = array();
		if ( isset( $raw['instore_tags'] ) && is_array( $raw['instore_tags'] ) ) {
			foreach ( $raw['instore_tags'] as $id ) {
				$id = (int) $id;
				if ( in_array( $id, $valid_ids, true ) ) {
					$instore_tags[] = $id;
				}
			}
		}

		$colors = array();
		if ( isset( $raw['colors'] ) && is_array( $raw['colors'] ) ) {
			foreach ( $raw['colors'] as $id => $color ) {
				$id  = (int) $id;
				$hex = sanitize_hex_color( (string) $color );
				if ( $hex && in_array( $id, $badge_tags, true ) ) {
					$colors[ $id ] = $hex;
				}
			}
		}

		$online_color = isset( $raw['online_color'] ) ? sanitize_hex_color( (string) $raw['online_color'] ) : '';

		update_option(
			self::OPTION,
			array(
				'badge_tags'   => array_values( array_unique( $badge_tags ) ),
				'colors'       => $colors,
				'instore_tags' => array_values( array_unique( $instore_tags ) ),
				'online_color' => $online_color ? $online_color : self::DEFAULT_ONLINE_COLOR,
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'ffla_os_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Enqueue the settings-page assets (select2 + the WP colour picker). The
	 * module's own JS/CSS are auto-loaded by FFLA_Admin on this page.
	 */
	public function enqueue_settings_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen || false === strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// WooCommerce bundles selectWoo (select2); use it when present for a nice
		// searchable multi-select, otherwise the native control still works.
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
		}
		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
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
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_number' === $key ) {
				$out[ self::COLUMN ] = __( 'Product Type', 'ffl-funnels-addons' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Product Type', 'ffl-funnels-addons' );
		}

		return $out;
	}

	/**
	 * Render the column cell. WooCommerce passes the order object on HPOS and the
	 * post ID on the legacy CPT screen, so accept either.
	 *
	 * @param string       $column
	 * @param int|WC_Order $order_or_id
	 */
	public function render_column( string $column, $order_or_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		echo $this->badges_html( $this->order_badge_set( $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/* =====================================================================
	 * Edit Order screen
	 * ================================================================== */

	/**
	 * Order-level badge set in the order details panel, under the General fields.
	 *
	 * @param WC_Order $order
	 */
	public function render_order_badge( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$badges = $this->badges_html( $this->order_badge_set( $order ) );
		if ( '' === $badges ) {
			return;
		}

		echo '<p class="form-field form-field-wide ffla-os-order-badge">'
			. '<span class="ffla-os-order-badge__label">' . esc_html__( 'Product Type', 'ffl-funnels-addons' ) . '</span>'
			. $badges // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			. '</p>';
	}

	/**
	 * Per-item badge set next to each product line on the Edit Order screen.
	 *
	 * @param int           $item_id
	 * @param WC_Order_Item $item
	 */
	public function render_item_badge( $item_id, $item ): void {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return; // Skip fees, shipping, etc.
		}

		echo $this->badges_html( $this->product_badge_set( (int) $item->get_product_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/* =====================================================================
	 * Classification
	 * ================================================================== */

	/**
	 * Badge set for a single product: matched badge tags + whether it is online.
	 *
	 * @return array{tags: array<int, array{name: string, color: string}>, online: bool}
	 */
	private function product_badge_set( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return array(
				'tags'   => array(),
				'online' => false,
			);
		}
		if ( isset( $this->product_cache[ $product_id ] ) ) {
			return $this->product_cache[ $product_id ];
		}

		$settings = $this->settings();
		$tags     = array();
		$terms    = get_the_terms( $product_id, 'product_tag' );
		$term_ids = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_ids[] = (int) $term->term_id;
				if ( in_array( (int) $term->term_id, $settings['badge_tags'], true ) ) {
					$tags[ (int) $term->term_id ] = array(
						'name'  => $term->name,
						'color' => $this->color_for( (int) $term->term_id ),
					);
				}
			}
		}

		// Online Only only applies once in-store tags are configured.
		$online = ! empty( $settings['instore_tags'] ) && empty( array_intersect( $term_ids, $settings['instore_tags'] ) );

		$result = array(
			'tags'   => $tags,
			'online' => $online,
		);

		$this->product_cache[ $product_id ] = $result;

		return $result;
	}

	/**
	 * Badge set for a whole order: the de-duplicated union of every badge on its
	 * product lines — the matched badge tags, plus Online Only when ANY product
	 * lacks a selected in-store tag.
	 *
	 * @return array{tags: array<int, array{name: string, color: string}>, online: bool}
	 */
	private function order_badge_set( WC_Order $order ): array {
		$items = $order->get_items();
		$this->prime_product_terms( $items );

		$tags       = array();
		$any_online = false;

		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$set = $this->product_badge_set( (int) $item->get_product_id() );
			foreach ( $set['tags'] as $id => $tag ) {
				$tags[ $id ] = $tag;
			}
			if ( $set['online'] ) {
				$any_online = true;
			}
		}

		return array(
			'tags'   => $tags,
			'online' => $any_online,
		);
	}

	/**
	 * Warm the product-tag cache for a set of line items in a single query, so
	 * the per-item get_the_terms() calls are cache hits instead of one query
	 * each. Only primes products not already memoised this request.
	 *
	 * @param array<int, WC_Order_Item> $items
	 */
	private function prime_product_terms( array $items ): void {
		$ids = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			if ( $product_id > 0 && ! isset( $this->product_cache[ $product_id ] ) ) {
				$ids[ $product_id ] = $product_id;
			}
		}
		if ( $ids ) {
			update_object_term_cache( array_values( $ids ), 'product' );
		}
	}

	/* =====================================================================
	 * Presentation
	 * ================================================================== */

	/**
	 * Render a badge set (tag badges + an optional Online Only badge) as HTML.
	 *
	 * @param array{tags: array<int, array{name: string, color: string}>, online: bool} $set
	 */
	private function badges_html( array $set ): string {
		if ( empty( $set['tags'] ) && empty( $set['online'] ) ) {
			return '';
		}

		$html = '<span class="ffla-os-badges">';
		foreach ( $set['tags'] as $tag ) {
			$html .= $this->badge_html( $tag['name'], $tag['color'] );
		}
		if ( ! empty( $set['online'] ) ) {
			$html .= $this->badge_html( __( 'Online Only', 'ffl-funnels-addons' ), $this->settings()['online_color'] );
		}
		$html .= '</span>';

		return $html;
	}

	/**
	 * One badge. The base colour drives a soft tinted background and a darker
	 * same-hue text (WooCommerce status-badge style) via color-mix, with a plain
	 * fallback for browsers without color-mix.
	 */
	private function badge_html( string $label, string $color ): string {
		return '<span class="ffla-os-badge" style="--ffla-c:' . esc_attr( $color ) . '">'
			. esc_html( $label )
			. '</span>';
	}

	/**
	 * Badge styling — only on the order list and edit screens (HPOS + legacy).
	 */
	public function print_badge_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}
		$screens = array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' );
		if ( ! in_array( $screen->id, $screens, true ) ) {
			return;
		}
		?>
		<style id="ffla-order-source-css">
			.ffla-os-badges {
				display: inline-flex;
				flex-wrap: wrap;
				gap: 4px;
				max-width: 100%;
				vertical-align: middle;
			}
			.ffla-os-badge {
				display: inline-flex;
				line-height: 2.5em;
				padding: 0 1em;
				border-radius: 4px;
				border-bottom: 1px solid rgba(0, 0, 0, 0.05);
				font-weight: 400;
				white-space: nowrap;
				max-width: 100%;
				/* Fallback for browsers without color-mix. */
				background: #ececec;
				color: #333;
				/* Soft tint + darker same-hue text from the per-badge base colour. */
				background: color-mix(in srgb, var(--ffla-c, #777) 16%, white);
				color: color-mix(in srgb, var(--ffla-c, #777) 72%, black);
			}
			td.name .ffla-os-badges { margin-top: 4px; }
			.ffla-os-order-badge .ffla-os-order-badge__label {
				display: block;
				font-weight: 600;
				margin-bottom: 4px;
			}
		</style>
		<?php
	}
}
