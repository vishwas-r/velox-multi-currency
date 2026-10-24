<?php
/**
 * Frontend Switcher UI and AJAX currency switching handler.
 *
 * @package SettleX\MultiCurrency\Frontend
 */

namespace SettleX\MultiCurrency\Frontend;

use SettleX\MultiCurrency\Currency\CurrencyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Switcher class.
 */
class Switcher {

	/**
	 * CurrencyRegistry instance.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $registry;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry $registry Currency registry.
	 */
	public function __construct( CurrencyRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Initialize switcher hooks.
	 */
	public function init(): void {
		// Shortcodes.
		add_shortcode( 'settlex_switcher', array( $this, 'render_shortcode' ) );
		add_shortcode( 'settlex_currency_switcher', array( $this, 'render_shortcode' ) );

		// AJAX handler for seamless switching without query parameters.
		add_action( 'wp_ajax_settlex_switch_currency', array( $this, 'ajax_switch_currency' ) );
		add_action( 'wp_ajax_nopriv_settlex_switch_currency', array( $this, 'ajax_switch_currency' ) );

		// Enqueue frontend scripts & styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render switcher HTML.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts = array() ): string {
		$enabled         = $this->registry->get_enabled_currencies();
		$active_currency = $this->registry->get_active_currency();

		if ( count( $enabled ) <= 1 ) {
			return '';
		}

		ob_start();
		?>
		<div class="settlex-currency-switcher-wrap" data-active="<?php echo esc_attr( $active_currency ); ?>">
			<select class="settlex-currency-select" aria-label="<?php esc_attr_e( 'Select Currency', 'settlex-multi-currency' ); ?>">
				<?php foreach ( $enabled as $code => $data ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $active_currency, $code ); ?>>
						<?php echo esc_html( sprintf( '%s (%s) - %s', $code, $data['symbol'] ?? '', $data['name'] ?? '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for switching currency.
	 */
	public function ajax_switch_currency(): void {
		check_ajax_referer( 'settlex_nonce', 'nonce' );

		$currency = sanitize_text_field( wp_unslash( $_POST['currency'] ?? '' ) );
		$success  = $this->registry->set_active_currency( $currency );

		if ( $success ) {
			// Invalidate cart shipping & totals if cart is present.
			if ( WC()->cart ) {
				WC()->cart->calculate_totals();
			}

			wp_send_json_success(
				array(
					'currency' => strtoupper( $currency ),
					'message'  => __( 'Currency switched successfully.', 'settlex-multi-currency' ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Invalid or disabled currency.', 'settlex-multi-currency' ) ) );
		}
	}

	/**
	 * Enqueue frontend CSS and JS.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'settlex-frontend',
			SETTLEX_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			SETTLEX_VERSION
		);

		wp_enqueue_script(
			'settlex-frontend',
			SETTLEX_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			SETTLEX_VERSION,
			true
		);

		wp_localize_script(
			'settlex-frontend',
			'settlex_params',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'settlex_nonce' ),
				'active_currency' => $this->registry->get_active_currency(),
			)
		);
	}
}
