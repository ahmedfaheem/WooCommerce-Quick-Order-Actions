<?php
/**
 * Plugin Name:       WooCommerce Quick Order Actions
 * Plugin URI:        https://example.com/woocommerce-quick-order-actions
 * Description:       Edit order status, notes, addresses and billing phone directly from the WooCommerce Orders list, without opening the order page. HPOS compatible.
 * Version:           1.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-quick-order-actions
 * Domain Path:       /languages
 * WC requires at least: 7.1
 *
 * @package WCQOA
 */

defined( 'ABSPATH' ) || exit;

define( 'WCQOA_VERSION', '1.7.0' );
define( 'WCQOA_FILE', __FILE__ );
define( 'WCQOA_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCQOA_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCQOA_FILE, true );
		}
	}
);

require_once WCQOA_PATH . 'includes/class-plugin.php';
require_once WCQOA_PATH . 'includes/class-ajax-handler.php';
require_once WCQOA_PATH . 'includes/class-order-status.php';
require_once WCQOA_PATH . 'includes/class-order-notes.php';
require_once WCQOA_PATH . 'includes/class-order-address.php';
require_once WCQOA_PATH . 'includes/class-order-phone.php';

add_action( 'plugins_loaded', array( 'WCQOA\Plugin', 'boot' ), 20 );

