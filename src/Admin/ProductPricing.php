<?php
/**
 * ProductPricing provides UI inputs for per-product and per-variation fixed pricing per currency.
 *
 * @package SettleX\MultiCurrency\Admin
 */

namespace SettleX\MultiCurrency\Admin;

use SettleX\MultiCurrency\Currency\CurrencyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * ProductPricing class.
 */
class ProductPricing {

	/**
	 * CurrencyRegistry.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $currency_registry;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry $currency_registry Registry.
	 */
	public function __construct( CurrencyRegistry $currency_registry ) {
		$this->currency_registry = $currency_registry;
	}

	/**
	 * Initialize product pricing hooks.
	 */
	public function init(): void {
		// Simple product pricing fields.
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_product_pricing_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple_product_pricing' ) );

		// Variable product pricing fields.
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_pricing_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_pricing' ), 10, 2 );
	}

	/**
	 * Render fixed price input fields for simple products.
	 */
	public function render_simple_product_pricing_fields(): void {
		global $post;
		if ( ! $post ) {
			return;
		}

		$enabled = $this->currency_registry->get_enabled_currencies();
		$base    = $this->currency_registry->get_base_currency();

		// Only show secondary currencies (base currency already uses default Woo fields).
		$secondary = array_filter(
			$enabled,
			function ( $data, $code ) use ( $base ) {
				return $code !== $base;
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $secondary ) ) {
			return;
		}

		echo '<div class="options_group settlex-product-pricing-group" style="background:#f8fafc; padding: 10px 15px; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; margin-top: 10px;">';
		echo '<p><strong>' . esc_html__( 'SettleX Multi-Currency Fixed Pricing (Optional)', 'settlex-multi-currency' ) . '</strong><br>';
		echo '<span class="description" style="font-size: 11px;">' . esc_html__( 'Set fixed prices for specific currencies. Leave blank to automatically convert using live exchange rates.', 'settlex-multi-currency' ) . '</span></p>';

		foreach ( $secondary as $code => $data ) {
			$reg_meta  = get_post_meta( $post->ID, '_settlex_regular_price_' . $code, true );
			$sale_meta = get_post_meta( $post->ID, '_settlex_sale_price_' . $code, true );
			$symbol    = $data['symbol'] ?? $code;

			woocommerce_wp_text_input(
				array(
					'id'          => '_settlex_regular_price_' . $code,
					'label'       => sprintf( __( '%s Regular Price (%s)', 'settlex-multi-currency' ), $code, $symbol ),
					'placeholder' => __( 'Auto-convert', 'settlex-multi-currency' ),
					'value'       => $reg_meta,
					'data_type'   => 'price',
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'          => '_settlex_sale_price_' . $code,
					'label'       => sprintf( __( '%s Sale Price (%s)', 'settlex-multi-currency' ), $code, $symbol ),
					'placeholder' => __( 'Optional sale price', 'settlex-multi-currency' ),
					'value'       => $sale_meta,
					'data_type'   => 'price',
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Save fixed pricing for simple products.
	 *
	 * @param int $post_id Product post ID.
	 */
	public function save_simple_product_pricing( int $post_id ): void {
		$enabled = $this->currency_registry->get_enabled_currencies();
		$base    = $this->currency_registry->get_base_currency();

		foreach ( $enabled as $code => $data ) {
			if ( $code === $base ) {
				continue;
			}

			// Regular price.
			$reg_key = '_settlex_regular_price_' . $code;
			if ( isset( $_POST[ $reg_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$val = wc_clean( wp_unslash( $_POST[ $reg_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( '' !== $val ) {
					update_post_meta( $post_id, $reg_key, wc_format_decimal( $val ) );
				} else {
					delete_post_meta( $post_id, $reg_key );
				}
			}

			// Sale price.
			$sale_key = '_settlex_sale_price_' . $code;
			if ( isset( $_POST[ $sale_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$val = wc_clean( wp_unslash( $_POST[ $sale_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( '' !== $val ) {
					update_post_meta( $post_id, $sale_key, wc_format_decimal( $val ) );
				} else {
					delete_post_meta( $post_id, $sale_key );
				}
			}
		}
	}

	/**
	 * Render fixed pricing inputs inside variation edit panels.
	 *
	 * @param int      $loop           Variation loop index.
	 * @param array    $variation_data Variation data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public function render_variation_pricing_fields( int $loop, array $variation_data, $variation ): void {
		$enabled = $this->currency_registry->get_enabled_currencies();
		$base    = $this->currency_registry->get_base_currency();

		$secondary = array_filter(
			$enabled,
			function ( $data, $code ) use ( $base ) {
				return $code !== $base;
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $secondary ) ) {
			return;
		}

		echo '<div class="settlex-variation-pricing-wrap" style="width: 100%; clear: both; padding: 10px 0; border-top: 1px dashed #cbd5e1; margin-top: 10px;">';
		echo '<p style="margin: 0 0 5px 0;"><strong>' . esc_html__( 'SettleX Multi-Currency Fixed Prices', 'settlex-multi-currency' ) . '</strong></p>';

		foreach ( $secondary as $code => $data ) {
			$reg_meta  = get_post_meta( $variation->ID, '_settlex_regular_price_' . $code, true );
			$sale_meta = get_post_meta( $variation->ID, '_settlex_sale_price_' . $code, true );
			$symbol    = $data['symbol'] ?? $code;

			?>
			<div class="settlex-var-row" style="display: flex; gap: 10px; margin-bottom: 6px;">
				<div style="flex: 1;">
					<label style="font-size: 11px;"><?php echo esc_html( sprintf( '%s Regular (%s)', $code, $symbol ) ); ?></label>
					<input type="text"
						name="_settlex_var_reg_price_<?php echo esc_attr( $code ); ?>[<?php echo esc_attr( $loop ); ?>]"
						value="<?php echo esc_attr( $reg_meta ); ?>"
						placeholder="<?php esc_attr_e( 'Auto-convert', 'settlex-multi-currency' ); ?>"
						style="width: 100%; font-size: 12px;">
				</div>
				<div style="flex: 1;">
					<label style="font-size: 11px;"><?php echo esc_html( sprintf( '%s Sale (%s)', $code, $symbol ) ); ?></label>
					<input type="text"
						name="_settlex_var_sale_price_<?php echo esc_attr( $code ); ?>[<?php echo esc_attr( $loop ); ?>]"
						value="<?php echo esc_attr( $sale_meta ); ?>"
						placeholder="<?php esc_attr_e( 'Optional sale', 'settlex-multi-currency' ); ?>"
						style="width: 100%; font-size: 12px;">
				</div>
			</div>
			<?php
		}

		echo '</div>';
	}

	/**
	 * Save variation fixed pricing.
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $i            Variation index.
	 */
	public function save_variation_pricing( int $variation_id, int $i ): void {
		$enabled = $this->currency_registry->get_enabled_currencies();
		$base    = $this->currency_registry->get_base_currency();

		foreach ( $enabled as $code => $data ) {
			if ( $code === $base ) {
				continue;
			}

			// Regular price.
			$reg_field = '_settlex_var_reg_price_' . $code;
			if ( isset( $_POST[ $reg_field ][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$val = wc_clean( wp_unslash( $_POST[ $reg_field ][ $i ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( '' !== $val ) {
					update_post_meta( $variation_id, '_settlex_regular_price_' . $code, wc_format_decimal( $val ) );
				} else {
					delete_post_meta( $variation_id, '_settlex_regular_price_' . $code );
				}
			}

			// Sale price.
			$sale_field = '_settlex_var_sale_price_' . $code;
			if ( isset( $_POST[ $sale_field ][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$val = wc_clean( wp_unslash( $_POST[ $sale_field ][ $i ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( '' !== $val ) {
					update_post_meta( $variation_id, '_settlex_sale_price_' . $code, wc_format_decimal( $val ) );
				} else {
					delete_post_meta( $variation_id, '_settlex_sale_price_' . $code );
				}
			}
		}
	}
}
