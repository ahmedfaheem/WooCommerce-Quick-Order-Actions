<?php
/**
 * Central AJAX endpoint dispatcher (nonce, capability, order checks).
 *
 * @package WCQOA
 */

namespace WCQOA;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler.
 */
class Ajax_Handler {

	const NONCE_ACTION = 'wcqoa_quick_actions';

	/**
	 * Map of AJAX action => callable( WC_Order, array ).
	 *
	 * @return array
	 */
	private static function routes() {
		return array(
			'wcqoa_update_status'  => array( 'WCQOA\Order_Status', 'update' ),
			'wcqoa_get_note'       => array( 'WCQOA\Order_Notes', 'get' ),
			'wcqoa_update_note'    => array( 'WCQOA\Order_Notes', 'update' ),
			'wcqoa_get_address'    => array( 'WCQOA\Order_Address', 'get' ),
			'wcqoa_update_address' => array( 'WCQOA\Order_Address', 'update' ),
			'wcqoa_get_phone'      => array( 'WCQOA\Order_Phone', 'get' ),
			'wcqoa_update_phone'   => array( 'WCQOA\Order_Phone', 'update' ),
		);
	}

	/**
	 * Register AJAX hooks (logged-in users only).
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		foreach ( array_keys( self::routes() ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'dispatch' ) );
		}
		add_action( 'wp_ajax_wcqoa_get_states', array( __CLASS__, 'get_states' ) );
	}

	/**
	 * Verify nonce and base capability; terminate with JSON error on failure.
	 *
	 * @return void
	 */
	private static function authorize() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'woocommerce-quick-order-actions' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit orders.', 'woocommerce-quick-order-actions' ) ), 403 );
		}
	}

	/**
	 * Dispatch a routed action.
	 *
	 * @return void
	 */
	public static function dispatch() {
		self::authorize();

		$action  = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$routes  = self::routes();
		$request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; each handler sanitizes its own fields.

		if ( ! isset( $routes[ $action ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'woocommerce-quick-order-actions' ) ), 400 );
		}

		$order_id = isset( $request['order_id'] ) ? absint( $request['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'woocommerce-quick-order-actions' ) ), 404 );
		}

		/**
		 * Filter whether the current user may quick-edit this order.
		 *
		 * @param bool      $allowed Default: user has edit_shop_orders.
		 * @param \WC_Order $order   Order.
		 */
		if ( ! apply_filters( 'wcqoa_user_can_edit_order', current_user_can( 'edit_shop_orders' ), $order ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this order.', 'woocommerce-quick-order-actions' ) ), 403 );
		}

		try {
			$result = call_user_func( $routes[ $action ], $order, is_array( $request ) ? $request : array() );
		} catch ( \Exception $e ) {
			$result = new \WP_Error( 'wcqoa_exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Return the states list for a country.
	 *
	 * @return void
	 */
	public static function get_states() {
		self::authorize();

		$country = isset( $_POST['country'] ) ? wc_strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().

		wp_send_json_success( array( 'states' => Order_Address::get_states( $country ) ) );
	}
}

