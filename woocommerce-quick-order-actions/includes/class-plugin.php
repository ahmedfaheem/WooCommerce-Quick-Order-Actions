<?php
/**
 * Plugin bootstrap: columns and conditional asset loading.
 *
 * @package WCQOA
 */

namespace WCQOA;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin once all plugins are loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Admin notice shown when WooCommerce is inactive.
	 *
	 * @return void
	 */
	public static function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce Quick Order Actions requires WooCommerce to be installed and active.', 'woocommerce-quick-order-actions' ) . '</p></div>';
	}

	/**
	 * Constructor: register hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( ! is_admin() ) {
			return;
		}

		Ajax_Handler::init();

		// HPOS list table and legacy (post based) list table. Only one of them is ever rendered.
		$hpos_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';

		add_filter( "manage_{$hpos_screen}_columns", array( $this, 'add_columns' ), 20 );
		add_action( "manage_{$hpos_screen}_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Order preview modal integration.
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( $this, 'preview_data' ), 10, 2 );
		add_action( 'woocommerce_admin_order_preview_end', array( $this, 'render_preview_panel' ) );
	}

	/**
	 * Expose the minimal data our panel needs to the preview template.
	 *
	 * @param array     $data  Preview data.
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public function preview_data( $data, $order ) {
		if ( $order instanceof \WC_Order && 'shop_order' === $order->get_type() && current_user_can( 'edit_shop_orders' ) && apply_filters( 'wcqoa_user_can_edit_order', true, $order ) ) {
			$data['wcqoa_id']     = $order->get_id();
			$data['wcqoa_status'] = $order->get_status();
			$data['wcqoa_phone']  = $order->get_billing_phone();
		}
		return $data;
	}

	/**
	 * Output the panel placeholder inside the (Underscore.js) preview template.
	 *
	 * @return void
	 */
	public function render_preview_panel() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		?>
		<# if ( data.wcqoa_id ) { #>
		<div class="wcqoa-preview" data-order-id="{{ data.wcqoa_id }}" data-status="{{ data.wcqoa_status }}" data-phone="{{ data.wcqoa_phone }}"></div>
		<# } #>
		<?php
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'woocommerce-quick-order-actions', false, dirname( plugin_basename( WCQOA_FILE ) ) . '/languages' );

		if ( 0 === strpos( determine_locale(), 'ar' ) ) {
			add_filter( 'gettext', array( $this, 'translate_arabic' ), 10, 3 );
		}
	}

	/**
	 * Built-in Arabic fallback for this plugin's strings when no .mo file provides one.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_arabic( $translation, $text, $domain ) {
		if ( 'woocommerce-quick-order-actions' !== $domain || $translation !== $text ) {
			return $translation;
		}

		static $map = null;
		if ( null === $map ) {
			$map = require WCQOA_PATH . 'includes/translations-ar.php';
		}

		return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
	}

	/**
	 * Add a phone column right after the billing address column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $columns;
		}

		$label  = __( 'Phone', 'woocommerce-quick-order-actions' );
		$result = array();
		$added  = false;

		foreach ( $columns as $key => $value ) {
			$result[ $key ] = $value;
			if ( 'billing_address' === $key ) {
				$result['wcqoa_phone'] = $label;
				$added                 = true;
			}
		}

		if ( ! $added ) {
			$result = array();
			foreach ( $columns as $key => $value ) {
				$result[ $key ] = $value;
				if ( 'order_status' === $key ) {
					$result['wcqoa_phone'] = $label;
					$added                 = true;
				}
			}
		}

		if ( ! $added ) {
			$result['wcqoa_phone'] = $label;
		}

		return $result;
	}

	/**
	 * Render the phone column.
	 *
	 * @param string          $column Column key.
	 * @param \WC_Order|int   $order  Order object (HPOS) or post ID (legacy).
	 * @return void
	 */
	public function render_column( $column, $order ) {
		if ( 'wcqoa_phone' !== $column ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order_id = absint( $order );
			// Reuse the order the legacy list table already loaded.
			if ( isset( $GLOBALS['the_order'] ) && $GLOBALS['the_order'] instanceof \WC_Order && $GLOBALS['the_order']->get_id() === $order_id ) {
				$order = $GLOBALS['the_order'];
			} else {
				$order = wc_get_order( $order_id );
			}
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$phone  = $order->get_billing_phone();
		$phone2 = Order_Phone::get_phone2( $order );
		echo '<span class="wcqoa-phone-value">' . ( '' !== $phone ? esc_html( $phone ) : '&ndash;' ) . '</span>';
		if ( '' !== $phone2 ) {
			echo '<span class="wcqoa-phone2-value">' . esc_html( $phone2 ) . '</span>';
		}
	}

	/**
	 * Whether the current screen is the orders list (HPOS or legacy).
	 *
	 * @return bool
	 */
	private function is_orders_list_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		if ( 'edit-shop_order' === $screen->id ) {
			return true;
		}

		if ( wc_get_page_screen_id( 'shop-order' ) === $screen->id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
			return ! in_array( $action, array( 'edit', 'new' ), true );
		}

		return false;
	}

	/**
	 * Enqueue CSS/JS on the orders list only.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $this->is_orders_list_screen() ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'wcqoa-admin', WCQOA_URL . 'assets/css/admin.css', array( 'dashicons' ), WCQOA_VERSION );
		wp_enqueue_script(
			'wcqoa-admin',
			WCQOA_URL . 'assets/js/admin.js',
			array(),
			WCQOA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'wcqoa-admin',
			'wcqoaData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Ajax_Handler::NONCE_ACTION ),
				'statuses'  => Order_Status::get_statuses(),
				/**
				 * Filter the list-table column key that shows the customer note (e.g. "order_notes").
				 * Leave empty to auto-detect from the column header.
				 */
				'notesColumn' => sanitize_html_class( (string) apply_filters( 'wcqoa_notes_column', '' ) ),
				'countries' => WC()->countries->get_countries(),
				'i18n'      => array(
					'changeStatus'    => __( 'Change order status', 'woocommerce-quick-order-actions' ),
					'quickActions'    => __( 'Quick actions', 'woocommerce-quick-order-actions' ),
					'address'         => __( 'Address', 'woocommerce-quick-order-actions' ),
					'addNote'         => __( 'Note provided by the customer', 'woocommerce-quick-order-actions' ),
					'editAddress'     => __( 'Edit address', 'woocommerce-quick-order-actions' ),
					'editPhone'       => __( 'Edit billing phone', 'woocommerce-quick-order-actions' ),
					'status'          => __( 'Status', 'woocommerce-quick-order-actions' ),
					'note'            => __( 'Note provided by the customer', 'woocommerce-quick-order-actions' ),
					'noteTab'         => __( 'Customer note', 'woocommerce-quick-order-actions' ),
					'noteType'        => __( 'Note type', 'woocommerce-quick-order-actions' ),
					'notePrivate'     => __( 'Private note', 'woocommerce-quick-order-actions' ),
					'noteCustomer'    => __( 'Note to customer', 'woocommerce-quick-order-actions' ),
					'phone'           => __( 'Phone', 'woocommerce-quick-order-actions' ),
					'phone2'          => __( 'Second phone', 'woocommerce-quick-order-actions' ),
					'addressHint'     => __( 'Saved to both billing and shipping.', 'woocommerce-quick-order-actions' ),
					'billing'         => __( 'Billing', 'woocommerce-quick-order-actions' ),
					'shipping'        => __( 'Shipping', 'woocommerce-quick-order-actions' ),
					'address1'        => __( 'Address line 1', 'woocommerce-quick-order-actions' ),
					'address2'        => __( 'Address line 2', 'woocommerce-quick-order-actions' ),
					'city'            => __( 'City', 'woocommerce-quick-order-actions' ),
					'state'           => __( 'State / County', 'woocommerce-quick-order-actions' ),
					'postcode'        => __( 'Postal code', 'woocommerce-quick-order-actions' ),
					'country'         => __( 'Country / Region', 'woocommerce-quick-order-actions' ),
					'selectOption'    => __( 'Select…', 'woocommerce-quick-order-actions' ),
					'save'            => __( 'Save', 'woocommerce-quick-order-actions' ),
					'cancel'          => __( 'Cancel', 'woocommerce-quick-order-actions' ),
					'loading'         => __( 'Loading…', 'woocommerce-quick-order-actions' ),
					'saving'          => __( 'Saving…', 'woocommerce-quick-order-actions' ),
					'error'           => __( 'Something went wrong. Please try again.', 'woocommerce-quick-order-actions' ),
					'noteRequired'    => __( 'Please enter a note.', 'woocommerce-quick-order-actions' ),
					'phoneInvalid'    => __( 'Please enter a valid phone number (6–15 digits).', 'woocommerce-quick-order-actions' ),
					'nothingToSave'   => __( 'No changes to save.', 'woocommerce-quick-order-actions' ),
					'statusUpdated'   => __( 'Order status updated.', 'woocommerce-quick-order-actions' ),
				),
			)
		);
	}
}

