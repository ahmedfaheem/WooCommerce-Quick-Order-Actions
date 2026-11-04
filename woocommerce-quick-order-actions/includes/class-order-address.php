<?php
/**
 * Address quick edit: one form saved to both billing and shipping.
 *
 * @package WCQOA
 */

namespace WCQOA;

defined( 'ABSPATH' ) || exit;

/**
 * Address operations.
 */
class Order_Address {

	const TYPES  = array( 'billing', 'shipping' );
	const FIELDS = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * States for a country (empty array if the country has no state list).
	 *
	 * @param string $country Country code.
	 * @return array
	 */
	public static function get_states( $country ) {
		if ( '' === $country ) {
			return array();
		}
		$states = WC()->countries->get_states( $country );
		return is_array( $states ) ? $states : array();
	}

	/**
	 * Return the current address for the form (billing, or shipping when billing is empty).
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $request Unslashed request data.
	 * @return array
	 */
	public static function get( \WC_Order $order, array $request ) {
		unset( $request );

		$type = ( '' === $order->get_billing_address_1() && '' !== $order->get_shipping_address_1() ) ? 'shipping' : 'billing';
		$data = array();
		foreach ( self::FIELDS as $field ) {
			$data[ $field ] = (string) $order->{"get_{$type}_{$field}"}();
		}

		$states = array();
		if ( '' !== $data['country'] ) {
			$states[ $data['country'] ] = self::get_states( $data['country'] );
		}

		return array(
			'address' => $data,
			'states'  => (object) $states,
		);
	}

	/**
	 * Validate and save the address to both billing and shipping.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $request Unslashed request data.
	 * @return array|\WP_Error
	 */
	public static function update( \WC_Order $order, array $request ) {
		if ( ! isset( $request['address'] ) || ! is_array( $request['address'] ) ) {
			return new \WP_Error( 'wcqoa_no_address', __( 'No address data received.', 'woocommerce-quick-order-actions' ) );
		}

		$input = $request['address'];
		$row   = array();
		foreach ( self::FIELDS as $field ) {
			$row[ $field ] = isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ? sanitize_text_field( (string) $input[ $field ] ) : '';
		}

		$countries      = WC()->countries->get_countries();
		$row['country'] = wc_strtoupper( $row['country'] );
		if ( '' !== $row['country'] && ! isset( $countries[ $row['country'] ] ) ) {
			return new \WP_Error( 'wcqoa_invalid_country', __( 'Invalid country.', 'woocommerce-quick-order-actions' ) );
		}

		$states = self::get_states( $row['country'] );
		if ( ! empty( $states ) ) {
			$row['state'] = wc_strtoupper( $row['state'] );
			if ( '' !== $row['state'] && ! isset( $states[ $row['state'] ] ) ) {
				return new \WP_Error( 'wcqoa_invalid_state', __( 'Invalid state / county.', 'woocommerce-quick-order-actions' ) );
			}
		}

		if ( '' !== $row['postcode'] ) {
			$row['postcode'] = wc_format_postcode( $row['postcode'], $row['country'] );
			if ( '' !== $row['country'] && ! \WC_Validation::is_postcode( $row['postcode'], $row['country'] ) ) {
				return new \WP_Error( 'wcqoa_invalid_postcode', __( 'Invalid postal code for the selected country.', 'woocommerce-quick-order-actions' ) );
			}
		}

		foreach ( self::TYPES as $type ) {
			foreach ( $row as $field => $value ) {
				$order->{"set_{$type}_{$field}"}( $value );
			}
		}
		$order->save();

		return array(
			'billing'  => array(
				'text' => self::to_text( $order->get_formatted_billing_address() ),
				'html' => wp_kses_post( $order->get_formatted_billing_address() ),
			),
			'shipping' => array(
				'text'    => self::to_text( $order->get_formatted_shipping_address() ),
				'html'    => wp_kses_post( $order->get_formatted_shipping_address() ),
				'map_url' => esc_url_raw( $order->get_shipping_address_map_url() ),
			),
			'message'  => __( 'Address updated.', 'woocommerce-quick-order-actions' ),
		);
	}

	/**
	 * Convert a formatted (HTML) address into single-line text, like the core column.
	 *
	 * @param string $formatted Formatted address.
	 * @return string
	 */
	private static function to_text( $formatted ) {
		if ( '' === trim( (string) $formatted ) ) {
			return '–';
		}
		$text = preg_replace( '#<br\s*/?>#i', ', ', $formatted );
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
	}
}
