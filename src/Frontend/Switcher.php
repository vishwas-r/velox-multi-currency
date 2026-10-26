<?php
/**
 * Frontend Switcher UI, Header Switcher, Floating Widget, and No-Reload AJAX Currency Switching.
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
	 * Option key for switcher display settings.
	 */
	const OPTION_SETTINGS = 'settlex_switcher_settings';

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
	 * Get switcher display settings with defaults.
	 *
	 * @return array{floating_enabled: string, floating_position: string, header_enabled: string}
	 */
	public function get_settings(): array {
		$defaults = array(
			'floating_enabled'  => 'yes',
			'floating_position' => 'bottom-right',
			'header_enabled'    => 'yes',
		);
		$saved    = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Save switcher display settings.
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public function save_settings( array $settings ): bool {
		return update_option( self::OPTION_SETTINGS, $settings );
	}

	/**
	 * Initialize switcher hooks.
	 */
	public function init(): void {
		// Shortcodes.
		add_shortcode( 'settlex_switcher', array( $this, 'render_shortcode' ) );
		add_shortcode( 'settlex_currency_switcher', array( $this, 'render_shortcode' ) );

		// Floating Widget (hooked into footer).
		add_action( 'wp_footer', array( $this, 'render_floating_widget' ) );

		// Header Switcher (hooked to nav menu items and body open).
		add_filter( 'wp_nav_menu_items', array( $this, 'append_to_nav_menu' ), 20, 2 );
		add_action( 'wp_body_open', array( $this, 'render_header_bar' ), 5 );

		// AJAX handler for seamless switching without reloading page.
		add_action( 'wp_ajax_settlex_switch_currency', array( $this, 'ajax_switch_currency' ) );
		add_action( 'wp_ajax_nopriv_settlex_switch_currency', array( $this, 'ajax_switch_currency' ) );

		// Enqueue frontend scripts & styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render standard switcher HTML (shortcode).
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
	 * Render Floating Widget in wp_footer.
	 */
	public function render_floating_widget(): void {
		$settings = $this->get_settings();
		if ( 'yes' !== $settings['floating_enabled'] ) {
			return;
		}

		$enabled         = $this->registry->get_enabled_currencies();
		$active_currency = $this->registry->get_active_currency();

		if ( count( $enabled ) <= 1 ) {
			return;
		}

		$pos_class   = 'settlex-pos-' . sanitize_html_class( $settings['floating_position'] );
		$active_data = $enabled[ $active_currency ] ?? reset( $enabled );
		$act_symbol  = html_entity_decode( (string) ( $active_data['symbol'] ?? '$' ), ENT_QUOTES, 'UTF-8' );

		?>
		<div class="settlex-floating-widget <?php echo esc_attr( $pos_class ); ?>" id="settlex-floating-widget">
			<button type="button" class="settlex-floating-trigger" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e( 'Change currency', 'settlex-multi-currency' ); ?>">
				<span class="settlex-trigger-symbol"><?php echo wp_kses_post( $act_symbol ); ?></span>
				<span class="settlex-trigger-code"><?php echo esc_html( $active_currency ); ?></span>
				<svg class="settlex-chevron" width="10" height="10" viewBox="0 0 16 16" fill="currentColor">
					<path d="M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z"/>
				</svg>
			</button>
			<div class="settlex-floating-dropdown" role="menu">
				<div class="settlex-floating-header">
					<?php esc_html_e( 'Select Currency', 'settlex-multi-currency' ); ?>
				</div>
				<ul class="settlex-floating-list">
					<?php foreach ( $enabled as $code => $data ) : ?>
						<?php
						$is_selected = ( $code === $active_currency );
						$item_symbol = html_entity_decode( (string) ( $data['symbol'] ?? '' ), ENT_QUOTES, 'UTF-8' );
						?>
						<li class="settlex-floating-item <?php echo $is_selected ? 'is-active' : ''; ?>" data-currency="<?php echo esc_attr( $code ); ?>">
							<span class="settlex-item-symbol"><?php echo wp_kses_post( $item_symbol ); ?></span>
							<span class="settlex-item-code"><strong><?php echo esc_html( $code ); ?></strong></span>
							<span class="settlex-item-name"><?php echo esc_html( $data['name'] ?? '' ); ?></span>
							<?php if ( $is_selected ) : ?>
								<svg class="settlex-item-check" width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
									<path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
								</svg>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Append currency switcher to header navigation menu.
	 *
	 * @param string    $items The HTML list content for the menu items.
	 * @param \stdClass $args  An object containing wp_nav_menu() arguments.
	 * @return string
	 */
	public function append_to_nav_menu( string $items, $args ): string {
		$settings = $this->get_settings();
		if ( 'yes' !== $settings['header_enabled'] ) {
			return $items;
		}

		$enabled         = $this->registry->get_enabled_currencies();
		$active_currency = $this->registry->get_active_currency();

		if ( count( $enabled ) <= 1 ) {
			return $items;
		}

		ob_start();
		?>
		<li class="menu-item settlex-menu-currency-item">
			<div class="settlex-header-inline-switcher">
				<select class="settlex-currency-select settlex-header-select" aria-label="<?php esc_attr_e( 'Select Currency', 'settlex-multi-currency' ); ?>">
					<?php foreach ( $enabled as $code => $data ) : ?>
						<?php $sym = html_entity_decode( (string) ( $data['symbol'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $active_currency, $code ); ?>>
							<?php echo esc_html( sprintf( '%s (%s)', $code, $sym ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</li>
		<?php
		$items .= ob_get_clean();
		return $items;
	}

	/**
	 * Render subtle top header bar if header enabled and no menu found.
	 */
	public function render_header_bar(): void {
		$settings = $this->get_settings();
		if ( 'yes' !== $settings['header_enabled'] ) {
			return;
		}

		$enabled         = $this->registry->get_enabled_currencies();
		$active_currency = $this->registry->get_active_currency();

		if ( count( $enabled ) <= 1 ) {
			return;
		}

		?>
		<div class="settlex-top-header-bar" id="settlex-top-header-bar">
			<div class="settlex-container">
				<span class="settlex-header-label"><?php esc_html_e( 'Currency:', 'settlex-multi-currency' ); ?></span>
				<div class="settlex-header-currencies">
					<?php foreach ( $enabled as $code => $data ) : ?>
						<?php
						$is_active = ( $code === $active_currency );
						$sym       = html_entity_decode( (string) ( $data['symbol'] ?? '' ), ENT_QUOTES, 'UTF-8' );
						?>
						<button type="button" class="settlex-header-curr-btn <?php echo $is_active ? 'is-active' : ''; ?>" data-currency="<?php echo esc_attr( $code ); ?>">
							<?php echo esc_html( sprintf( '%s %s', $sym, $code ) ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for switching currency WITHOUT page reload.
	 * Calculates real-time prices for visible products and refreshed cart/checkout fragments.
	 */
	public function ajax_switch_currency(): void {
		check_ajax_referer( 'settlex_nonce', 'nonce' );

		$currency    = sanitize_text_field( wp_unslash( $_POST['currency'] ?? '' ) );
		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();

		$success = $this->registry->set_active_currency( $currency );

		if ( ! $success ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or disabled currency.', 'settlex-multi-currency' ) ) );
		}

		// 1. Automatically include all items currently in WooCommerce cart.
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( ! empty( $cart_item['product_id'] ) ) {
					$product_ids[] = absint( $cart_item['product_id'] );
				}
				if ( ! empty( $cart_item['variation_id'] ) ) {
					$product_ids[] = absint( $cart_item['variation_id'] );
				}
			}
			WC()->cart->calculate_totals();
		}

		// 2. Compute updated HTML prices for all product IDs requested.
		$prices = array();
		if ( ! empty( $product_ids ) ) {
			foreach ( array_unique( $product_ids ) as $pid ) {
				$product = wc_get_product( $pid );
				if ( $product ) {
					$prices[ $pid ] = array(
						'price_html'    => $product->get_price_html(),
						'price'         => $product->get_price(),
						'formatted'     => wc_price( $product->get_price() ),
						'regular_price' => $product->get_regular_price(),
						'sale_price'    => $product->get_sale_price(),
					);
				}
			}
		}

		// 3. Cart subtotal & total amounts.
		$cart_subtotal = '';
		$cart_total    = '';
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$cart_subtotal = WC()->cart->get_cart_subtotal();
			$cart_total    = WC()->cart->get_total();
		}

		// 4. Mini-cart fragments if available.
		$fragments = array();
		if ( function_exists( 'WC_AJAX' ) && method_exists( 'WC_AJAX', 'get_refreshed_fragments' ) ) {
			ob_start();
			woocommerce_mini_cart();
			$mini_cart = ob_get_clean();

			$fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
		}

		$enabled = $this->registry->get_enabled_currencies();
		$config  = $enabled[ strtoupper( $currency ) ] ?? array();
		$symbol  = html_entity_decode( (string) ( $config['symbol'] ?? get_woocommerce_currency_symbol( strtoupper( $currency ) ) ), ENT_QUOTES, 'UTF-8' );

		wp_send_json_success(
			array(
				'currency'      => strtoupper( $currency ),
				'symbol'        => $symbol,
				'rate'          => (float) ( $config['rate'] ?? 1.0 ),
				'decimals'      => (int) ( $config['decimals'] ?? 2 ),
				'prices'        => $prices,
				'cart_subtotal' => $cart_subtotal,
				'cart_total'    => $cart_total,
				'fragments'     => $fragments,
				'message'       => __( 'Currency switched successfully.', 'settlex-multi-currency' ),
			)
		);
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
