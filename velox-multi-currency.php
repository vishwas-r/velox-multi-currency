<?php
/**
 * Plugin Name:          VeloX - Multi-Currency & Payment Gateway for WooCommerce
 * Plugin URI:           https://velox.dev/plugins/velox-multi-currency
 * Description:          Cache-safe multi-currency switcher, automatic live exchange rates, and dedicated payment gateway routing for WooCommerce.
 * Version:              1.0.3
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Author:               Vishwas R
 * Author URI:           https://vishwas.me/
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          velox-multi-currency
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.5.0
 * WC tested up to:      9.5.0
 *
 * @package VeloX\MultiCurrency
 */

use VeloX\MultiCurrency\Plugin;

defined( 'ABSPATH' ) || exit;

// Define core plugin constants.
define( 'VELOX_MC_VERSION', '1.0.3' );
define( 'VELOX_MC_PLUGIN_FILE', __FILE__ );
define( 'VELOX_MC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VELOX_MC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VELOX_MC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Built-in PSR-4 Autoloader for VeloX\MultiCurrency\ namespace.
 * Allows zero-dependency execution without requiring composer install in production.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'VeloX\\MultiCurrency\\';
		$base_dir = VELOX_MC_PLUGIN_DIR . 'src/';

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
if ( file_exists( VELOX_MC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once VELOX_MC_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Declare High-Performance Order Storage (HPOS) and Checkout Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VELOX_MC_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VELOX_MC_PLUGIN_FILE, true );
		}
	}
);

if ( ! function_exists( 'velox_mc' ) ) {
	/**
	 * Main instance accessor.
	 *
	 * @return Plugin
	 */
	function velox_mc(): Plugin {
		return Plugin::instance();
	}
}

/**
 * Initialize VeloX on plugins_loaded.
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
								'VeloX Multi-Currency requires WooCommerce to be installed and active.',
								'velox-multi-currency'
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
		velox_mc()->boot();
	}
);
