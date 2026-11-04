<?php
/**
 * Phone quick edit (billing + shipping phone and an optional second phone stored as order meta).
 *
 * @package WCQOA
 */

namespace WCQOA;

defined( 'ABSPATH' ) || exit;

/**
 * Phone operations.
 */
class Order_Phone {

	/**
	 * Order meta key of the secondary phone field.
	 *
	 * @return string
	 */
	public static function phone2_key() {
		return (string) apply_filters( 'wcqoa_phone2_meta_key', 'phone2_' );
	}

	/**
	 * Secondary phone value of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function get_phone2( \WC_Order $order ) {
		$key = self::phone2_key();
		return '' === $key ? '' : (string) $order->get_meta( $key, true );
	}

	/**
	 * Current phone values for the form.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $request Unslashed request data.
	 * @return array
	 */
	public static function get( \WC_Order $order, array $request ) {
		unset( $request );
		return array(
			'phone'  => $order->get_billing_phone(),
			'phone2' => self::get_phone2( $order ),
		);
	}

	/**
	 * Update billing + shipping phone and the secondary phone.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $request Unslashed request data.
	 * @return array|\WP_Error
	 */
	public static function update( \WC_Order $order, array $request ) {
		$phone  = isset( $request['phone'] ) && is_scalar( $request['phone'] ) ? trim( sanitize_text_field( (string) $request['phone'] ) ) : '';
		$phone2 = isset( $request['phone2'] ) && is_scalar( $request['phone2'] ) ? trim( sanitize_text_field( (string) $request['phone2'] ) ) : '';

		if ( ! self::is_valid( $phone ) || ( '' !== $phone2 && ! self::is_valid( $phone2 ) ) ) {
			return new \WP_Error( 'wcqoa_invalid_phone', __( 'Please enter a valid phone number (6–15 digits).', 'woocommerce-quick-order-actions' ) );
		}

		$order->set_billing_phone( $phone );
		if ( method_exists( $order, 'set_shipping_phone' ) ) {
			$order->set_shipping_phone( $phone );
		}

		$key = self::phone2_key();
		if ( '' !== $key ) {
			if ( '' === $phone2 ) {
				$order->delete_meta_data( $key );
			} else {
				$order->update_meta_data( $key, $phone2 );
			}
		}

		$order->save();

		return array(
			'phone'   => $order->get_billing_phone(),
			'phone2'  => self::get_phone2( $order ),
			'message' => __( 'Phone number updated.', 'woocommerce-quick-order-actions' ),
		);
	}

	/**
	 * Validate a phone number: optional leading +, digits and common separators, 6–15 digits.
	 *
	 * @param string $phone Phone.
	 * @return bool
	 */
	public static function is_valid( $phone ) {
		if ( ! preg_match( '/^\+?[0-9\s\-\.\(\)\/]+$/', $phone ) ) {
			return false;
		}
		if ( class_exists( 'WC_Validation' ) && ! \WC_Validation::is_phone( $phone ) ) {
			return false;
		}
		$digits = strlen( preg_replace( '/\D/', '', $phone ) );
		return $digits >= 6 && $digits <= 15;
	}
}
