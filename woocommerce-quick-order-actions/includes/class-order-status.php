<?php
/**
 * Order status quick edit.
 *
 * @package WCQOA
 */

namespace WCQOA;

defined( 'ABSPATH' ) || exit;

/**
 * Order status operations.
 */
class Order_Status {

	/**
	 * All registered statuses (core + custom), keyed by slug without the "wc-" prefix.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		/**
		 * Filter the status slugs (without "wc-") that must not be offered in the quick editor.
		 *
		 * @param array $excluded Slugs to hide. Default: on-hold.
		 */
		$excluded = (array) apply_filters( 'wcqoa_excluded_statuses', array( 'on-hold' ) );

		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;

			// Hide "on-hold" and any status whose label is simply "HOLD".
			if ( in_array( $slug, $excluded, true ) || 'hold' === strtolower( trim( wp_strip_all_tags( $label ) ) ) ) {
				continue;
			}
			$statuses[ $slug ] = $label;
		}
		return $statuses;
	}

	/**
	 * Update the status of an order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $request Unslashed request data.
	 * @return array|\WP_Error
	 */
	public static function update( \WC_Order $order, array $request ) {
		$new_status = isset( $request['status'] ) ? sanitize_key( $request['status'] ) : '';
		$statuses   = self::get_statuses();

		if ( '' === $new_status || ! isset( $statuses[ $new_status ] ) ) {
			return new \WP_Error( 'wcqoa_invalid_status', __( 'Invalid order status.', 'woocommerce-quick-order-actions' ) );
		}

		if ( $order->get_status() !== $new_status ) {
			$order->set_status( $new_status, __( 'Order status changed via Quick Order Actions.', 'woocommerce-quick-order-actions' ), true );
			$order->save();
		}

		return array(
			'status'  => $order->get_status(),
			'label'   => wc_get_order_status_name( $order->get_status() ),
			'message' => __( 'Order status updated.', 'woocommerce-quick-order-actions' ),
		);
	}
}

