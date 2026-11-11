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
 * set on each product line).
 *
 * Each product's tags are FROZEN onto its order line at purchase time (stored as
 * line-item meta), so a later tag change/removal/deletion never re-labels past
 * orders. New orders are stamped as they're created (every creation path), and
 * existing orders are backfilled once via Action Scheduler. Which frozen tags
 * show as badges — and the colours — still follow the live settings.
 *
 * @package FFL_Funnels_Addons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Badges_Module extends FFLA_Module {

	/** Settings page slug + option + save wiring. */
	private const PAGE_SLUG   = 'ffla-order-badges';
	private const OPTION      = 'ffla_order_badges_settings';
	private const SAVE_ACTION = 'ffla_ob_save_settings';
	private const NONCE       = 'ffla_ob_settings';
	private const NONCE_FIELD = '_ffla_ob_nonce';

	/** Settings-page script handle (enqueued here, with its real dependencies). */
	private const SETTINGS_SCRIPT = 'ffla-order-badges-settings';

	/** Orders-list column id. */
	private const COLUMN = 'ffla_order_badges';

	/** Line-item meta: the product's product_tag terms frozen at purchase, as a
	 *  list of array{id:int,name:string}. Absent = not yet snapshotted. */
	private const SNAP_META = '_ffla_ob_tags';

	/** Action Scheduler hook + group for the backfill of un-snapshotted items. */
	private const BACKFILL_HOOK  = 'ffla_ob_backfill_batch';
	private const BACKFILL_GROUP = 'ffla-order-badges';

	/** Option set once every existing order line has a snapshot. */
	private const BACKFILL_DONE = 'ffla_ob_backfill_done';

	/** Option: order_item_id the running backfill has reached (resume point). */
	private const BACKFILL_CURSOR = 'ffla_ob_backfill_cursor';

	/** Option: highest order_item_id known to be covered once a pass finished.
	 *  Lines created while the module was off sit above it, so the periodic
	 *  gap check only has to look there. */
	private const BACKFILL_WATERMARK = 'ffla_ob_backfill_watermark';

	/** Autoloaded throttle timestamps (zero-query checks on page loads). */
	private const NEXT_QUEUE_CHECK = 'ffla_ob_next_queue_check';
	private const NEXT_GAP_CHECK   = 'ffla_ob_next_gap_check';

	/** Line items processed per backfill batch (filterable). */
	private const BACKFILL_BATCH = 200;

	/** Colour of the "Mixed" total in the orders-list summary. */
	private const MIXED_COLOR = '#b45309';

	/** Default colour for the "Online Only" badge. */
	private const DEFAULT_ONLINE_COLOR = '#0f766e';

	/** Default colour palette used when a selected tag has no colour yet. */
	private const PALETTE = array( '#2271b1', '#e02424', '#0f766e', '#7c3aed', '#b45309', '#be185d', '#0891b2', '#4d7c0f' );

	/** Per-request memo: product_id => list of {id,name} tag snapshots. */
	private $product_cache = array();

	/** Query args of the HPOS orders list being rendered (for "This view"). */
	private $list_args = null;

	/** Per-request memo of the parsed settings. */
	private $settings_cache = null;

	public function get_id(): string {
		return 'order-badges';
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
		// Freeze each product's tags onto its order line as the order is created.
		// Runs everywhere orders are made (checkout, admin, POS, REST, Store API),
		// so it must be registered outside the is_admin() gate below.
		add_action( 'woocommerce_new_order_item', array( $this, 'snapshot_new_item' ), 10, 2 );

		// One-time backfill of existing orders, via Action Scheduler.
		add_action( self::BACKFILL_HOOK, array( $this, 'run_backfill_batch' ) );
		$this->maybe_schedule_backfill();

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );

		// Orders list column — HPOS (custom orders table) and legacy (CPT).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		// In Store / Online Only / Mixed totals above the Orders table: capture the
		// list's exact query (HPOS), then print a full-width line just above the
		// status links via WordPress's views_{screen} filter (left-aligned with the table).
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'capture_list_args' ), PHP_INT_MAX );
		add_filter( 'views_woocommerce_page_wc-orders', array( $this, 'render_summary_hpos' ) );
		add_filter( 'views_edit-shop_order', array( $this, 'render_summary_legacy' ) );

		// Edit Order screen: order-level badges + a badge set on each product line.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_badge' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_item_badge' ), 10, 2 );

		add_action( 'admin_head', array( $this, 'print_badge_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
	}

	public function activate(): void {
		// (Re-)enabling: orders may have arrived while the module was off, so
		// re-run the backfill. It only touches lines that still lack a snapshot,
		// so after a short outage this is a single tiny batch.
		// Lines created while it was off sit above the high-water mark, so resume
		// from there instead of rescanning everything (0 on a first install).
		delete_option( self::BACKFILL_DONE );
		update_option( self::BACKFILL_CURSOR, (int) get_option( self::BACKFILL_WATERMARK, 0 ), false );
		update_option( self::NEXT_QUEUE_CHECK, 0, true );
		$this->maybe_schedule_backfill();
	}

	public function deactivate(): void {
		// Settings and the per-order snapshots are kept so re-activating restores
		// everything. Just stop any pending backfill work.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BACKFILL_HOOK, array(), self::BACKFILL_GROUP );
		}
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

		$badge_tags   = array_map( 'absint', self::scalar_list( $saved['badge_tags'] ?? array() ) );
		$instore_tags = array_map( 'absint', self::scalar_list( $saved['instore_tags'] ?? array() ) );
		$colors       = array();
		if ( isset( $saved['colors'] ) && is_array( $saved['colors'] ) ) {
			foreach ( $saved['colors'] as $id => $color ) {
				$hex = is_scalar( $color ) ? sanitize_hex_color( (string) $color ) : '';
				if ( $hex ) {
					$colors[ (int) $id ] = $hex;
				}
			}
		}
		$online_color = isset( $saved['online_color'] ) && is_scalar( $saved['online_color'] )
			? sanitize_hex_color( (string) $saved['online_color'] )
			: '';

		$this->settings_cache = array(
			'badge_tags'   => $badge_tags,
			'colors'       => $colors,
			'instore_tags' => $instore_tags,
			'online_color' => $online_color ? $online_color : self::DEFAULT_ONLINE_COLOR,
		);

		return $this->settings_cache;
	}

	/**
	 * The scalar values of a submitted/saved list. Anything else (a nested array
	 * from a crafted request, an object) is dropped rather than cast.
	 *
	 * @param mixed $value
	 * @return array<int, scalar>
	 */
	private static function scalar_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( $value, 'is_scalar' ) );
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
		$index = false === $index ? 0 : (int) $index;

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
				'orderby'    => 'name',
			)
		);

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * The subset of $ids that are real product tags, in submitted order.
	 *
	 * Looks up only the submitted IDs, so saving stays cheap on stores with
	 * thousands of tags.
	 *
	 * @param array<int, scalar> $ids
	 * @return int[]
	 */
	private function existing_tag_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array(); // An empty `include` would match every tag.
		}

		$found = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'include'    => $ids,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $found ) ) {
			return array();
		}
		$found = array_map( 'intval', $found );

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $found ) {
					return in_array( $id, $found, true );
				}
			)
		);
	}

	/**
	 * Render the settings page body (inside the FFLA admin shell).
	 */
	private function render_settings(): void {
		$settings = $this->settings();
		$tags     = $this->all_product_tags();

		if ( isset( $_GET['ffla_ob_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI flag.
			FFLA_Admin::render_notice( 'success', __( 'Settings saved.', 'ffl-funnels-addons' ) );
		}

		if ( empty( $tags ) ) {
			echo '<div class="wb-card"><div class="wb-card__body"><p>';
			echo esc_html__( 'No product tags exist yet. Add product tags in Products → Tags, then choose them here.', 'ffl-funnels-addons' );
			echo '</p></div></div>';
			return;
		}
		?>
		<form class="ffla-ob-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>

			<div class="wb-card">
				<div class="wb-card__header"><h3><?php esc_html_e( 'Badge tags', 'ffl-funnels-addons' ); ?></h3></div>
				<div class="wb-card__body">
					<p class="wb-field__desc"><?php esc_html_e( 'Choose which product tags appear as badges on orders. Each product shows a badge for every one of its tags selected here. Pick a colour for each.', 'ffl-funnels-addons' ); ?></p>
					<p class="wb-field__desc"><?php esc_html_e( 'Each product\'s tags are captured on its orders at purchase time, so later tag changes do not alter past orders. Changing which tags show here, or their colours, applies everywhere.', 'ffl-funnels-addons' ); ?></p>

					<select id="ffla-ob-badge-tags" class="ffla-ob-tags" name="ffla_ob[badge_tags][]" multiple
						data-placeholder="<?php esc_attr_e( 'Select tags…', 'ffl-funnels-addons' ); ?>">
						<?php foreach ( $tags as $tag ) : ?>
							<option value="<?php echo esc_attr( (string) $tag->term_id ); ?>" <?php selected( in_array( (int) $tag->term_id, $settings['badge_tags'], true ) ); ?>>
								<?php echo esc_html( $tag->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<div id="ffla-ob-color-rows" class="ffla-ob-color-rows">
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

					<select id="ffla-ob-instore-tags" class="ffla-ob-tags" name="ffla_ob[instore_tags][]" multiple
						data-placeholder="<?php esc_attr_e( 'Select tags…', 'ffl-funnels-addons' ); ?>">
						<?php foreach ( $tags as $tag ) : ?>
							<option value="<?php echo esc_attr( (string) $tag->term_id ); ?>" <?php selected( in_array( (int) $tag->term_id, $settings['instore_tags'], true ) ); ?>>
								<?php echo esc_html( $tag->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<div class="ffla-ob-online-color">
						<label class="ffla-ob-color-row__name" for="ffla-ob-online-color-input"><?php esc_html_e( 'Online Only badge colour', 'ffl-funnels-addons' ); ?></label>
						<input type="text" id="ffla-ob-online-color-input" class="ffla-ob-color"
							name="ffla_ob[online_color]" value="<?php echo esc_attr( $settings['online_color'] ); ?>">
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
		<div class="ffla-ob-color-row" data-id="<?php echo esc_attr( (string) $term_id ); ?>">
			<span class="ffla-ob-color-row__name"><?php echo esc_html( $name ); ?></span>
			<input type="text" class="ffla-ob-color" name="ffla_ob[colors][<?php echo esc_attr( (string) $term_id ); ?>]" value="<?php echo esc_attr( $color ); ?>">
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
		// (only scalars, IDs checked against real tags, sanitize_hex_color for colours).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['ffla_ob'] ) && is_array( $_POST['ffla_ob'] ) ? wp_unslash( $_POST['ffla_ob'] ) : array();

		$badge_tags   = $this->existing_tag_ids( self::scalar_list( $raw['badge_tags'] ?? array() ) );
		$instore_tags = $this->existing_tag_ids( self::scalar_list( $raw['instore_tags'] ?? array() ) );

		$colors = array();
		if ( isset( $raw['colors'] ) && is_array( $raw['colors'] ) ) {
			foreach ( $raw['colors'] as $id => $color ) {
				if ( ! is_scalar( $color ) ) {
					continue;
				}
				$id  = (int) $id;
				$hex = sanitize_hex_color( (string) $color );
				if ( $hex && in_array( $id, $badge_tags, true ) ) {
					$colors[ $id ] = $hex;
				}
			}
		}

		$online_color = isset( $raw['online_color'] ) && is_scalar( $raw['online_color'] )
			? sanitize_hex_color( (string) $raw['online_color'] )
			: '';

		update_option(
			self::OPTION,
			array(
				'badge_tags'   => $badge_tags,
				'colors'       => $colors,
				'instore_tags' => $instore_tags,
				'online_color' => $online_color ? $online_color : self::DEFAULT_ONLINE_COLOR,
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'ffla_ob_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Enqueue the settings-page assets: the WP colour picker, selectWoo (select2)
	 * when WooCommerce provides it, and the page script with those as declared
	 * dependencies. The module stylesheet is auto-loaded by FFLA_Admin.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_settings_assets( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		$deps = array( 'jquery', 'wp-color-picker' );
		wp_enqueue_style( 'wp-color-picker' );

		// WooCommerce bundles selectWoo (select2); use it when present for a nice
		// searchable multi-select, otherwise the native control still works.
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			$deps[] = 'selectWoo';
		}
		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		wp_enqueue_script(
			self::SETTINGS_SCRIPT,
			$this->get_url() . 'admin/js/order-badges-settings.js',
			$deps,
			FFLA_VERSION,
			true
		);
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
	public function render_column( $column, $order_or_id ): void {
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
	 * Orders-list summary: In Store / Online Only / Mixed totals
	 *
	 * Classification follows the settings only: a line is in-store when its
	 * frozen snapshot contains ANY configured in-store tag. All lines in-store =
	 * In Store, none = Online Only, otherwise Mixed. Counted with one SQL
	 * aggregation over the snapshots (no orders are loaded).
	 * ================================================================== */

	/**
	 * Remember the HPOS list table's query args so "This view" counts exactly
	 * what the table is showing (status tab, search, date, customer, channel…).
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function capture_list_args( $args ) {
		if ( is_array( $args ) && 'shop_order' === ( $args['type'] ?? 'shop_order' ) ) {
			$this->list_args = $args;
		}

		return $args;
	}

	/**
	 * HPOS orders screen. Hooked to the views filter purely for its position:
	 * prints the summary just above the status links and returns $views as-is.
	 *
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public function render_summary_hpos( $views ) {
		if ( ! is_array( $this->list_args ) ) {
			return $views;
		}

		$args = $this->list_args;
		foreach ( array( 'limit', 'page', 'paginate', 'no_found_rows', 'orderby', 'order' ) as $key ) {
			unset( $args[ $key ] );
		}

		$default  = $this->default_list_statuses();
		$statuses = array_values( (array) ( $args['status'] ?? array() ) );
		sort( $statuses );
		$filtered = $statuses !== $default || array_diff( array_keys( $args ), array( 'type', 'status' ) );

		$view = null;
		if ( $filtered ) {
			$view = $this->cached_counts(
				'view_' . md5( wp_json_encode( $args ) ),
				function () use ( $args ) {
					return $this->query_order_ids( $args );
				}
			);
		}

		$this->print_summary( $view );

		return $views;
	}

	/**
	 * Legacy (posts-based) orders screen; same views-filter placement.
	 *
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public function render_summary_legacy( $views ) {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query ) {
			return $views;
		}

		$filtered = false;
		foreach ( array( 'post_status', 's', 'm', '_customer_user', '_created_via' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
			if ( ! empty( $_GET[ $key ] ) && 'all' !== $_GET[ $key ] ) {
				$filtered = true;
			}
		}

		$view = null;
		if ( $filtered ) {
			$vars = array_merge(
				$wp_query->query_vars,
				array(
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'paged'          => 1,
					'no_found_rows'  => true,
				)
			);
			$view = $this->cached_counts(
				'view_' . md5( wp_json_encode( $vars ) ),
				static function () use ( $vars ) {
					$query = new WP_Query( $vars );
					return array_map( 'intval', (array) $query->posts );
				}
			);
		}

		$this->print_summary( $view );

		return $views;
	}

	/**
	 * Output the summary bar. Hidden until in-store tags are configured, since
	 * the classification is undefined without them.
	 *
	 * @param array{in_store:int,online:int,mixed:int}|null $view Counts for the current filtered view, or null when unfiltered.
	 */
	private function print_summary( ?array $view ): void {
		if ( empty( $this->settings()['instore_tags'] ) ) {
			return;
		}

		$overall = $this->cached_counts(
			'overall',
			function () {
				return $this->query_order_ids( array( 'status' => $this->default_list_statuses() ) );
			}
		);

		echo '<div class="ffla-ob-summary">';
		$this->print_summary_group( __( 'All orders', 'ffl-funnels-addons' ), $overall );
		if ( null !== $view ) {
			$this->print_summary_group( __( 'Orders shown below', 'ffl-funnels-addons' ), $view );
		}
		echo '</div>';
	}

	/**
	 * Counts for a set of orders, cached for a few minutes. The key includes the
	 * in-store tag settings, so changing them never serves stale numbers.
	 *
	 * @param string   $key     Distinguishes overall vs. a particular filter.
	 * @param callable $get_ids Returns the order IDs to classify (only called on a cache miss).
	 * @return array{in_store:int,online:int,mixed:int}
	 */
	private function cached_counts( string $key, callable $get_ids ): array {
		$cache_key = 'ffla_ob_sum_' . md5( $key . '|' . wp_json_encode( $this->settings()['instore_tags'] ) );
		$counts    = get_transient( $cache_key );
		if ( ! is_array( $counts ) ) {
			$counts = $this->classify_orders( (array) $get_ids() );
			set_transient( $cache_key, $counts, 3 * MINUTE_IN_SECONDS );
		}

		return $counts;
	}

	/**
	 * One labelled group of the three totals.
	 *
	 * @param array{in_store:int,online:int,mixed:int} $counts
	 */
	private function print_summary_group( string $label, array $counts ): void {
		$settings = $this->settings();
		$groups   = array(
			array( __( 'In Store', 'ffl-funnels-addons' ), $counts['in_store'], $this->color_for( (int) $settings['instore_tags'][0] ) ),
			array( __( 'Online Only', 'ffl-funnels-addons' ), $counts['online'], $settings['online_color'] ),
			array( __( 'Mixed', 'ffl-funnels-addons' ), $counts['mixed'], self::MIXED_COLOR ),
		);

		$parts = array();
		foreach ( $groups as $group ) {
			$parts[] = '<mark class="ffla-ob-summary__term" style="--ffla-c:' . esc_attr( $group[2] ) . '">'
				. esc_html( number_format_i18n( $group[1] ) . ' ' . $group[0] ) . '</mark>';
		}
		$total = $counts['in_store'] + $counts['online'] + $counts['mixed'];

		echo '<p class="ffla-ob-summary__line"><span class="ffla-ob-summary__label">'
			. esc_html( sprintf( '%s (%s):', $label, number_format_i18n( $total ) ) ) . '</span> '
			. implode( ', ', $parts ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			. '</p>';
	}

	/**
	 * The statuses WooCommerce's "All" orders view lists.
	 *
	 * @return string[]
	 */
	private function default_list_statuses(): array {
		$statuses = array_values(
			(array) apply_filters(
				'woocommerce_shop_order_list_table_default_statuses',
				array_intersect( array_keys( wc_get_order_statuses() ), get_post_stati( array( 'show_in_admin_all_list' => true ), 'names' ) )
			)
		);
		sort( $statuses );

		return $statuses;
	}

	/**
	 * Order IDs matching wc_get_orders() args (HPOS and legacy alike).
	 *
	 * @param array<string, mixed> $args
	 * @return int[]
	 */
	private function query_order_ids( array $args ): array {
		$ids = wc_get_orders(
			array_merge(
				array( 'type' => 'shop_order' ),
				$args,
				array(
					'limit'    => -1,
					'paginate' => false,
					'return'   => 'ids',
				)
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Count orders by type from their line snapshots, in chunks so the IN list
	 * stays bounded. Lines not yet snapshotted are left out until backfilled.
	 *
	 * @param int[] $order_ids
	 * @return array{in_store:int,online:int,mixed:int}
	 */
	private function classify_orders( array $order_ids ): array {
		global $wpdb;

		$counts  = array(
			'in_store' => 0,
			'online'   => 0,
			'mixed'    => 0,
		);
		$instore = $this->settings()['instore_tags'];
		if ( empty( $order_ids ) || empty( $instore ) ) {
			return $counts;
		}

		// A line is in-store when its serialized snapshot holds one of the in-store
		// term IDs. `s:2:"id";i:<id>;` is exact: the trailing ";" stops 36 matching 360.
		$likes  = array();
		$params = array();
		foreach ( $instore as $term_id ) {
			$likes[]  = 'm.meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( 's:2:"id";i:' . (int) $term_id . ';' ) . '%';
		}
		$is_instore = '(' . implode( ' OR ', $likes ) . ')';

		foreach ( array_chunk( $order_ids, 5000 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $is_instore/$in are generated placeholder tokens; every value is bound via prepare().
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COALESCE(SUM(all_in = 1), 0) AS in_store,
						COALESCE(SUM(any_in = 0), 0) AS online,
						COALESCE(SUM(any_in = 1 AND all_in = 0), 0) AS mixed
					FROM (
						SELECT oi.order_id,
							MAX(CASE WHEN $is_instore THEN 1 ELSE 0 END) AS any_in,
							MIN(CASE WHEN $is_instore THEN 1 ELSE 0 END) AS all_in
						FROM {$wpdb->prefix}woocommerce_order_items oi
						INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m
							ON m.order_item_id = oi.order_item_id AND m.meta_key = %s
						WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ($in)
						GROUP BY oi.order_id
					) t",
					array_merge( $params, $params, array( self::SNAP_META ), $chunk )
				)
			);
			// phpcs:enable
			if ( $row ) {
				$counts['in_store'] += (int) $row->in_store;
				$counts['online']   += (int) $row->online;
				$counts['mixed']    += (int) $row->mixed;
			}
		}

		return $counts;
	}

	/* =====================================================================
	 * Edit Order screen
	 * ================================================================== */

	/**
	 * Order-level badge set in the order details panel, under the General fields.
	 *
	 * @param WC_Order|int $order
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

		echo '<p class="form-field form-field-wide ffla-ob-order-badge">'
			. '<span class="ffla-ob-order-badge__label">' . esc_html__( 'Product Type', 'ffl-funnels-addons' ) . '</span>'
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

		echo $this->badges_html( $this->item_badge_set( $item ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/* =====================================================================
	 * Classification
	 * ================================================================== */

	/**
	 * Badge set for a single order line, from its FROZEN tag snapshot (taken at
	 * purchase). Matched badge tags + whether the line is online-only. The product
	 * no longer needs to exist — the snapshot is self-contained.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return array{tags: array<int, array{name: string, color: string}>, online: bool}
	 */
	private function item_badge_set( WC_Order_Item_Product $item ): array {
		return $this->badge_set_from_snapshot( $this->item_snapshot( $item ) );
	}

	/**
	 * Turn a frozen tag snapshot (list of {id,name}) into a badge set using the
	 * current settings. Matching and the online-only test are by term ID, so they
	 * keep working even if a tag was later renamed or deleted; the frozen name is
	 * what's displayed, the colour comes from settings.
	 *
	 * @param array<int, array{id:int,name:string}> $snapshot
	 * @return array{tags: array<int, array{name: string, color: string}>, online: bool}
	 */
	private function badge_set_from_snapshot( array $snapshot ): array {
		$settings = $this->settings();
		$tags     = array();
		$term_ids = array();

		foreach ( $snapshot as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}
			$id   = isset( $tag['id'] ) ? (int) $tag['id'] : 0;
			$name = isset( $tag['name'] ) ? (string) $tag['name'] : '';
			if ( $id <= 0 ) {
				continue;
			}
			$term_ids[] = $id;
			if ( in_array( $id, $settings['badge_tags'], true ) ) {
				$tags[ $id ] = array(
					'name'  => $name,
					'color' => $this->color_for( $id ),
				);
			}
		}

		// Online Only only applies once in-store tags are configured.
		$online = ! empty( $settings['instore_tags'] ) && empty( array_intersect( $term_ids, $settings['instore_tags'] ) );

		return array(
			'tags'   => $tags,
			'online' => $online,
		);
	}

	/**
	 * Badge set for a whole order: the de-duplicated union of every badge on its
	 * product lines — the matched badge tags, plus Online Only when ANY line lacks
	 * a selected in-store tag.
	 *
	 * @return array{tags: array<int, array{name: string, color: string}>, online: bool}
	 */
	private function order_badge_set( WC_Order $order ): array {
		$tags       = array();
		$any_online = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$set = $this->item_badge_set( $item );
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

	/* =====================================================================
	 * Tag snapshots (freeze at purchase, backfill, self-heal)
	 * ================================================================== */

	/**
	 * The frozen tag snapshot for a line item. If the item has none yet (an order
	 * created before this feature, or missed by the backfill), stamp it now from
	 * the product's current tags and use that — so every rendered order is frozen.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return array<int, array{id:int,name:string}>
	 */
	private function item_snapshot( WC_Order_Item_Product $item ): array {
		if ( $item->meta_exists( self::SNAP_META ) ) {
			$snap = $item->get_meta( self::SNAP_META );
			return is_array( $snap ) ? $snap : array();
		}

		return $this->stamp_item( $item );
	}

	/**
	 * Freeze the product's current tags onto a line item and persist them. Returns
	 * the snapshot. Never throws — a failure here must not break order creation or
	 * an admin screen.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return array<int, array{id:int,name:string}>
	 */
	private function stamp_item( WC_Order_Item_Product $item ): array {
		// Always read the product's tags fresh here: this runs as a line is created
		// (or self-heals), and a per-request memo could be stale if tags changed
		// earlier in the same request (imports, CLI, POS batches).
		$snapshot = $this->product_tag_snapshot( (int) $item->get_product_id(), false );
		try {
			$item->update_meta_data( self::SNAP_META, $snapshot );
			$item->save_meta_data();
		} catch ( \Throwable $e ) {
			// Persist failed; still return the computed snapshot so rendering works.
			$this->log( 'stamp_item failed: ' . $e->getMessage() );
		}

		return $snapshot;
	}

	/**
	 * The product's product_tag terms as a list of {id,name}. Memoised per request
	 * only when $memoize is true (the bulk backfill, where many lines share a
	 * product and tags can't change mid-batch).
	 * An empty list is a valid snapshot ("the product had no tags").
	 *
	 * @return array<int, array{id:int,name:string}>
	 */
	private function product_tag_snapshot( int $product_id, bool $memoize ): array {
		if ( $product_id <= 0 ) {
			return array();
		}
		if ( $memoize && isset( $this->product_cache[ $product_id ] ) ) {
			return $this->product_cache[ $product_id ];
		}

		$snapshot = array();
		$terms    = get_the_terms( $product_id, 'product_tag' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$snapshot[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
				);
			}
		}

		if ( $memoize ) {
			$this->product_cache[ $product_id ] = $snapshot;
		}

		return $snapshot;
	}

	/**
	 * Freeze tags onto a line item as the order is created (any creation path:
	 * checkout, admin, POS, REST, Store API). Products only; never throws.
	 *
	 * @param int           $item_id
	 * @param WC_Order_Item $item
	 */
	public function snapshot_new_item( $item_id, $item ): void {
		try {
			if ( $item instanceof WC_Order_Item_Product && ! $item->meta_exists( self::SNAP_META ) ) {
				$this->stamp_item( $item );
			}
		} catch ( \Throwable $e ) {
			$this->log( 'snapshot_new_item failed: ' . $e->getMessage() );
		}
	}

	/* =====================================================================
	 * Backfill of un-snapshotted order lines (Action Scheduler)
	 *
	 * Works on order LINE ITEMS with plain SQL — the order-items tables are the
	 * same for HPOS and the legacy store — so it never loads or re-saves whole
	 * orders (no save hooks, cache flushes or HPOS sync). Each batch is ~4
	 * queries for up to 200 lines, and runs in Action Scheduler's background
	 * requests, never in a visitor's page load.
	 * ================================================================== */

	/**
	 * Ensure the backfill is scheduled when there is work to do.
	 *
	 * Modules boot on `init` priority 0, but Action Scheduler only initialises on
	 * `init` priority 1 — enqueueing before then is a silent no-op. So when AS
	 * isn't ready yet, defer to `action_scheduler_init`.
	 */
	private function maybe_schedule_backfill(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( class_exists( 'ActionScheduler' ) && ! ActionScheduler::is_initialized() ) {
			if ( false === has_action( 'action_scheduler_init', array( $this, 'schedule_backfill' ) ) ) {
				add_action( 'action_scheduler_init', array( $this, 'schedule_backfill' ) );
			}
			return;
		}

		$this->schedule_backfill();
	}

	/**
	 * Queue a backfill batch if one is needed and none is pending.
	 *
	 * Once a pass has finished, re-check at most twice a day whether any lines
	 * appeared without a snapshot (e.g. orders placed while the plugin was
	 * switched off by FTP, where no deactivate/activate hooks fire). That check
	 * only scans lines above the high-water mark, so it is a tiny indexed query.
	 */
	public function schedule_backfill(): void {
		// Throttles use AUTOLOADED timestamp options rather than transients:
		// WordPress reads autoloaded options in its single startup query, so on
		// an ordinary page load this whole check costs zero extra DB queries.
		$now = time();

		if ( get_option( self::BACKFILL_DONE ) ) {
			if ( $now < (int) get_option( self::NEXT_GAP_CHECK, 0 ) ) {
				return;
			}
			update_option( self::NEXT_GAP_CHECK, $now + 12 * HOUR_IN_SECONDS, true );
			if ( empty( $this->unsnapshotted_item_ids( (int) get_option( self::BACKFILL_WATERMARK, 0 ), 1 ) ) ) {
				return;
			}
			// Gap found: resume from the watermark instead of the beginning.
			delete_option( self::BACKFILL_DONE );
			update_option( self::BACKFILL_CURSOR, (int) get_option( self::BACKFILL_WATERMARK, 0 ), false );
			update_option( self::NEXT_QUEUE_CHECK, 0, true );
		}

		if ( $now < (int) get_option( self::NEXT_QUEUE_CHECK, 0 ) ) {
			return;
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::BACKFILL_HOOK, array(), self::BACKFILL_GROUP )
			&& ! as_enqueue_async_action( self::BACKFILL_HOOK, array(), self::BACKFILL_GROUP ) ) {
			return; // Not queued; try again on a later request.
		}

		// Only throttle once an action actually exists, so a failed enqueue retries.
		update_option( self::NEXT_QUEUE_CHECK, $now + 5 * MINUTE_IN_SECONDS, true );
	}

	/**
	 * Stamp one batch of order lines that have no snapshot yet, then chain the
	 * next batch. Resumable (cursor), idempotent (only lines missing a snapshot
	 * are selected) and isolated (a failure stops this batch only; the chain is
	 * re-queued by schedule_backfill on a later request).
	 */
	public function run_backfill_batch(): void {
		if ( get_option( self::BACKFILL_DONE ) ) {
			return;
		}

		$limit  = max( 1, (int) apply_filters( 'ffla_ob_backfill_batch_size', self::BACKFILL_BATCH ) );
		$cursor = (int) get_option( self::BACKFILL_CURSOR, 0 );

		try {
			$item_ids = $this->unsnapshotted_item_ids( $cursor, $limit );

			if ( empty( $item_ids ) ) {
				$this->finish_backfill( $cursor );
				return;
			}

			$this->stamp_items_bulk( $item_ids );
			update_option( self::BACKFILL_CURSOR, max( $item_ids ), false );
		} catch ( \Throwable $e ) {
			$this->log( 'backfill batch failed after item ' . $cursor . ': ' . $e->getMessage() );
			return;
		}

		if ( count( $item_ids ) < $limit ) {
			$this->finish_backfill( max( $item_ids ) );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BACKFILL_HOOK, array(), self::BACKFILL_GROUP );
		}
	}

	/**
	 * Mark the pass complete and remember how far it got.
	 */
	private function finish_backfill( int $last_item_id ): void {
		global $wpdb;

		// Everything up to the newest line now has a snapshot (new lines are
		// stamped at creation), so the watermark is the current max item ID.
		$max = (int) $wpdb->get_var( "SELECT MAX(order_item_id) FROM {$wpdb->prefix}woocommerce_order_items" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( self::BACKFILL_WATERMARK, max( $max, $last_item_id ), false );
		update_option( self::BACKFILL_DONE, 1, true );
		delete_option( self::BACKFILL_CURSOR );
	}

	/**
	 * Product line items above $after_id that have no tag snapshot yet, oldest
	 * first. Index-friendly: a primary-key range walk joined on the indexed
	 * order_item_id of the item-meta table.
	 *
	 * @return int[]
	 */
	private function unsnapshotted_item_ids( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT oi.order_item_id
				FROM {$wpdb->prefix}woocommerce_order_items oi
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta snap
					ON snap.order_item_id = oi.order_item_id AND snap.meta_key = %s
				WHERE oi.order_item_type = 'line_item'
					AND oi.order_item_id > %d
					AND snap.meta_id IS NULL
				ORDER BY oi.order_item_id ASC
				LIMIT %d",
				self::SNAP_META,
				$after_id,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Write snapshots for many line items at once: read their product IDs in one
	 * query, warm the tag cache in one query, then insert every snapshot with a
	 * single multi-row INSERT. Clears each item's meta cache so a persistent
	 * object cache can't serve the pre-backfill (empty) meta.
	 *
	 * @param int[] $item_ids
	 */
	private function stamp_items_bulk( array $item_ids ): void {
		global $wpdb;

		// The IN (...) list and VALUES rows below are built from generated %d/%s
		// placeholder tokens only; every value is bound through prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_item_id, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE meta_key = '_product_id' AND order_item_id IN ($placeholders)",
				$item_ids
			)
		);

		$product_of = array();
		foreach ( (array) $rows as $row ) {
			$product_of[ (int) $row->order_item_id ] = (int) $row->meta_value;
		}

		$product_ids = array_values( array_unique( array_filter( $product_of ) ) );
		if ( $product_ids ) {
			update_object_term_cache( $product_ids, 'product' );
		}

		$values = array();
		$args   = array();
		foreach ( $item_ids as $item_id ) {
			$snapshot = $this->product_tag_snapshot( $product_of[ $item_id ] ?? 0, true );
			$values[] = '(%d, %s, %s)';
			array_push( $args, $item_id, self::SNAP_META, maybe_serialize( $snapshot ) );
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}woocommerce_order_itemmeta (order_item_id, meta_key, meta_value) VALUES " . implode( ',', $values ),
				$args
			)
		);
		// phpcs:enable

		// Same invalidation WC_Data::clear_cache() does for an order item, so a
		// persistent object cache can't serve the pre-backfill (no snapshot) meta.
		$has_helper = class_exists( 'WC_Cache_Helper' );
		foreach ( $item_ids as $item_id ) {
			wp_cache_delete( $item_id, 'order_item_meta' );
			wp_cache_delete( 'item-' . $item_id, 'order-items' );
			if ( $has_helper ) {
				WC_Cache_Helper::invalidate_cache_group( 'object_' . $item_id );
			}
		}
	}

	/**
	 * Debug log helper (only when WP_DEBUG is on); never surfaces to users.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FFLA Order Badges: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/* =====================================================================
	 * Presentation
	 * ================================================================== */

	/**
	 * Render a badge set (tag badges + an optional Online Only badge) as HTML.
	 *
	 * De-duplicates by normalised label, so the same badge never appears twice —
	 * e.g. an "Online only" badge tag and the synthetic "Online Only" status badge
	 * collapse into one.
	 *
	 * @param array{tags: array<int, array{name: string, color: string}>, online: bool} $set
	 */
	private function badges_html( array $set ): string {
		$seen   = array();
		$badges = array();

		foreach ( $set['tags'] as $tag ) {
			$key = $this->badge_key( $tag['name'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$badges[]     = $this->badge_html( $tag['name'], $tag['color'] );
		}

		if ( ! empty( $set['online'] ) ) {
			$label = __( 'Online Only', 'ffl-funnels-addons' );
			$key   = $this->badge_key( $label );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$badges[]     = $this->badge_html( $label, $this->settings()['online_color'] );
			}
		}

		if ( empty( $badges ) ) {
			return '';
		}

		return '<span class="ffla-ob-badges">' . implode( '', $badges ) . '</span>';
	}

	/**
	 * Normalised key for de-duplicating badges by label.
	 *
	 * Case-folded (multibyte-safe) with whitespace and punctuation removed, so
	 * "Online only", "Online Only" and "online-only" are the same badge. Letters,
	 * digits and symbols in any script are kept, so "Tienda Física", "🔥 Hot" vs
	 * "⭐ Hot", or non-Latin tag names stay distinct. If normalising leaves
	 * nothing (or the label is not valid UTF-8), the lowercased label itself is
	 * the key, so two different labels can never collapse into one.
	 */
	private function badge_key( string $label ): string {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
		$key   = preg_replace( '/[^\p{L}\p{N}\p{S}]+/u', '', $lower );

		if ( ! is_string( $key ) || '' === $key ) {
			return 'raw:' . trim( $lower );
		}

		return $key;
	}

	/**
	 * One badge. The base colour drives a soft tinted background and a darker
	 * same-hue text (WooCommerce status-badge style) via color-mix, with a plain
	 * fallback for browsers without color-mix.
	 */
	private function badge_html( string $label, string $color ): string {
		return '<span class="ffla-ob-badge" style="--ffla-c:' . esc_attr( $color ) . '">'
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
		<style id="ffla-order-badges-css">
			.ffla-ob-badges {
				display: inline-flex;
				flex-wrap: wrap;
				gap: 4px;
				max-width: 100%;
				vertical-align: middle;
			}
			.ffla-ob-badge {
				display: inline-flex;
				line-height: 2.5em;
				padding: 0 1em;
				border-radius: 4px;
				border-bottom: 1px solid rgba(0, 0, 0, 0.05);
				font-weight: 400;
				/* Tag names are shown exactly as written (no case change). */
				white-space: nowrap;
				max-width: 100%;
				/* Fallback for browsers without color-mix. */
				background: #ececec;
				color: #333;
				/* Soft tint + darker same-hue text from the per-badge base colour. */
				background: color-mix(in srgb, var(--ffla-c, #777) 16%, white);
				color: color-mix(in srgb, var(--ffla-c, #777) 72%, black);
			}
			td.name .ffla-ob-badges { margin-top: 4px; }
			/* Orders-list summary: one readable, left-aligned line in a soft box. */
			.ffla-ob-summary {
				clear: both;
				margin: 12px 0 8px;
				padding: 8px 12px;
				font-size: 13px;
				line-height: 1.8;
				color: #1d2327;
				background: #fff;
				border: 1px solid #dcdcde;
				border-left: 4px solid var(--wp-admin-theme-color, #2271b1);
				border-radius: 4px;
			}
			.ffla-ob-summary__label { font-weight: 600; }
			.ffla-ob-summary__term {
				padding: 1px 6px;
				border-radius: 3px;
				font-weight: 600;
				background: #f0f0f1;
				color: #1d2327;
				background: color-mix(in srgb, var(--ffla-c, #777) 14%, white);
				color: color-mix(in srgb, var(--ffla-c, #777) 72%, black);
			}
			.ffla-ob-summary__line { margin: 0; }
			.ffla-ob-order-badge .ffla-ob-order-badge__label {
				display: block;
				font-weight: 600;
				margin-bottom: 4px;
			}
		</style>
		<?php
	}
}
