<?php
/**
 * Customer-provided note quick edit.
 *
 * @package WCQOA
 */

namespace WCQOA;

defined( 'ABSPATH' ) || exit;

/**
 * Customer note ("Note provided by the customer") operations.
 */
class Order_Notes {

	const MAX_LENGTH = 5000;

	/**
	 * Return the current customer-provided note.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $request Unslashed request data.
	 * @return array
	 */
	public static function get( \WC_Order $order, array $request ) {
		unset( $request );
		return array( 'note' => (string) $order->get_customer_note() );
	}

	/**
	 * Update the customer-provided note (empty value clears it).
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $request Unslashed request data.
	 * @return array|\WP_Error
	 */
	public static function update( \WC_Order $order, array $request ) {
		$note = isset( $request['note'] ) && is_scalar( $request['note'] ) ? trim( sanitize_textarea_field( (string) $request['note'] ) ) : '';

		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $note ) > self::MAX_LENGTH : strlen( $note ) > self::MAX_LENGTH ) {
			return new \WP_Error( 'wcqoa_note_too_long', __( 'The note is too long.', 'woocommerce-quick-order-actions' ) );
		}

		$order->set_customer_note( $note );
		$order->save();

		return array(
			'note'    => $order->get_customer_note(),
			'message' => __( 'Customer note updated.', 'woocommerce-quick-order-actions' ),
		);
	}
}
