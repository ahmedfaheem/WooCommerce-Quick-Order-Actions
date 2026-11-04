=== WooCommerce Quick Order Actions ===
Contributors: Ahmed Faheem
Tags: woocommerce, orders, quick edit, order status, hpos
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 7.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit order status, notes, addresses and billing phone straight from the WooCommerce Orders list.

== Description ==

Small inline edit icons on the Orders list let you:

* Change the order status (core and custom statuses).
* Edit the note provided by the customer.
* Edit billing and shipping address (address, city, state, postcode, country).
* Edit and validate the billing phone number.

Works with HPOS and legacy post storage. Assets load only on the orders list. Updates are AJAX only; just the edited cell is refreshed. RTL supported.

== Installation ==

1. Upload the `woocommerce-quick-order-actions` folder to `/wp-content/plugins/`.
2. Activate it under Plugins. WooCommerce must be active.
3. Open WooCommerce > Orders. Enable the "Billing" / "Ship to" columns in Screen Options to see address icons. A "Phone" column is added automatically.

== Testing ==

1. Status: click the icon next to a status, pick another, Save. The badge updates; an order note records the change.
2. Note: click the comment icon next to the order number, choose Private or Customer, Save. Check the order page notes (customer notes also email the customer).
3. Address: click the icon in the Billing / Ship to column, edit either tab, Save. Only the edited tab is sent. Try an invalid postcode to see the error.
4. Phone: click the icon in the Phone column; "abc" or "123" shows an error; "+20 100 123 4567" saves.
5. Permissions: a user without `edit_shop_orders` sees no icons and AJAX calls return 403.
6. Switch WooCommerce > Settings > Advanced > Features between HPOS and legacy storage and repeat.

== Changelog ==

= 1.0.0 =
* Initial release.

