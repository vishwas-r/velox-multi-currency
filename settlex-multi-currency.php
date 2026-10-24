<?php
/**
 * Plugin Name:          SettleX – Payment Gateway Router & Multi-Currency for WooCommerce
 * Plugin URI:           https://settlexwp.com
 * Description:          Cache-safe multi-currency switcher and smart payment gateway router for WooCommerce. Display local prices and route or convert currencies per gateway at checkout.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Author:               SettleX
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          settlex-multi-currency
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.5.0
 * WC tested up to:      9.5.0
 *
 * @package SettleX\MultiCurrency
 */

namespace SettleX\MultiCurrency;

defined( 'ABSPATH' ) || exit;

// Define core plugin constants.
define( 'SETTLEX_VERSION', '1.0.0' );
define( 'SETTLEX_PLUGIN_FILE', __FILE__ );
define( 'SETTLEX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SETTLEX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SETTLEX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Built-in PSR-4 Autoloader for SettleX\MultiCurrency\ namespace.
 * Allows zero-dependency execution without requiring composer install in production.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'SettleX\\MultiCurrency\\';
		$base_dir = SETTLEX_PLUGIN_DIR . 'src/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// If vendor/autoload.php exists (developer environment), load it.
if ( file_exists( SETTLEX_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SETTLEX_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Declare High-Performance Order Storage (HPOS) and Checkout Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SETTLEX_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SETTLEX_PLUGIN_FILE, true );
		}
	}
);

/**
 * Main instance accessor.
 *
 * @return Plugin
 */
function settlex() {
	return Plugin::instance();
}

/**
 * Initialize SettleX on plugins_loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		// Verify WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
					<div class="notice notice-error">
						<p>
							<?php
							echo esc_html__(
								'SettleX requires WooCommerce to be installed and active.',
								'settlex-multi-currency'
							);
							?>
						</p>
					</div>
					<?php
				}
			);
			return;
		}

		// Boot plugin.
		settlex()->boot();
	}
);
