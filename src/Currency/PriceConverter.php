<?php
/**
 * PriceConverter hooks into WooCommerce price calculation and formatting.
 *
 * @package VeloX\MultiCurrency\Currency
 */

namespace VeloX\MultiCurrency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * PriceConverter converts WooCommerce product, variation, cart, and shipping prices.
 */
class PriceConverter {

	/**
	 * CurrencyRegistry instance.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $registry;

	/**
	 * Cache of raw converted prices to avoid duplicate conversions in the same request.
	 *
	 * @var array<string, float>
	 */
	protected array $converted_cache = array();

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry $registry Currency registry.
	 */
	public function __construct( CurrencyRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Initialize price filters.
	 */
	public function init(): void {
		// General active price filters.
		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'filter_regular_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), 10, 2 );

		// Variations.
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'filter_regular_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'filter_sale_price' ), 10, 2 );

		// Variation price hash caching.
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_variation_hash' ), 10, 3 );

		// Shipping rates.
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_shipping_rates' ), 10, 2 );

		// Format currency args (symbol, decimals, position).
		add_filter( 'wc_price_args', array( $this, 'filter_wc_price_args' ), 10, 1 );

		// Core WooCommerce active currency and symbol filters.
		add_filter( 'woocommerce_currency', array( $this, 'filter_woocommerce_currency' ), 9999, 1 );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'filter_woocommerce_currency_symbol' ), 9999, 2 );

		// Inject data-velox-base and currency attributes into formatted price tags for cache-safe client updates.
		add_filter( 'wc_price', array( $this, 'filter_wc_price_html' ), 10, 5 );
	}

	/**
	 * Recursion guards.
	 */
	protected static bool $filtering_currency = false;
	protected static bool $filtering_symbol   = false;

	/**
	 * Determine if current request is within an admin screen, admin AJAX, or reporting/analytics context.
	 *
	 * @return bool
	 */
	public function is_admin_or_reporting_context(): bool {
		// 1. Standard WP Admin screen (non-AJAX).
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}

		// 2. Admin AJAX requests (order editing, dashboard widget loads, etc.).
		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ?? '' ) );
			if ( ! empty( $action ) && (
				0 === strpos( $action, 'woocommerce_load_' ) ||
				0 === strpos( $action, 'woocommerce_admin_' ) ||
				0 === strpos( $action, 'woocommerce_dashboard_' ) ||
				0 === strpos( $action, 'woocommerce_status_' ) ||
				'woocommerce_calc_line_taxes' === $action
			) ) {
				return true;
			}

			// If authenticated user can view reports or manage WooCommerce, and it is NOT a customer cart/checkout AJAX action.
			if ( function_exists( 'current_user_can' ) && ( current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' ) ) ) {
				$frontend_actions = array( 'woocommerce_add_to_cart', 'woocommerce_get_refreshed_fragments', 'woocommerce_checkout', 'velox_set_currency' );
				if ( ! in_array( $action, $frontend_actions, true ) && ! isset( $_GET['wc-ajax'] ) ) {
					return true;
				}
			}
		}

		// 3. REST API Requests for Analytics and Reports (WC Admin widgets, WC Analytics, Mobile App).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
			if ( empty( $rest_route ) && isset( $_SERVER['REQUEST_URI'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$rest_route = wp_unslash( $_SERVER['REQUEST_URI'] );
			}
			if ( is_string( $rest_route ) && (
				false !== strpos( $rest_route, '/wc-analytics' ) ||
				false !== strpos( $rest_route, '/wc/v3/reports' )
			) ) {
				return true;
			}
		}

		// 4. CLI / Cron requests.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Override the active WooCommerce currency for customer requests.
	 *
	 * @param string $currency Base store currency.
	 * @return string Active customer currency.
	 */
	public function filter_woocommerce_currency( $currency ) {
		if ( self::$filtering_currency || $this->is_admin_or_reporting_context() ) {
			return $currency;
		}

		self::$filtering_currency = true;
		$active = $this->registry->get_active_currency();
		self::$filtering_currency = false;

		return $active;
	}

	/**
	 * Return clean decoded symbol for active currency.
	 *
	 * @param string $currency_symbol Default symbol.
	 * @param string $currency Currency code.
	 * @return string Decoded symbol.
	 */
	public function filter_woocommerce_currency_symbol( $currency_symbol, $currency = '' ) {
		if ( self::$filtering_symbol ) {
			return $currency_symbol;
		}

		self::$filtering_symbol = true;
		if ( ! empty( $currency ) ) {
			$code = strtoupper( (string) $currency );
		} elseif ( $this->is_admin_or_reporting_context() ) {
			$code = $this->registry->get_base_currency();
		} else {
			$code = $this->registry->get_active_currency();
		}
		$enabled = $this->registry->get_enabled_currencies();

		$result = $currency_symbol;
		if ( isset( $enabled[ $code ]['symbol'] ) && ! empty( $enabled[ $code ]['symbol'] ) ) {
			$result = (string) $enabled[ $code ]['symbol'];
		}

		self::$filtering_symbol = false;
		return html_entity_decode( (string) $result, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Convert a raw price float to the currently active currency.
	 *
	 * @param mixed                $price   Base price.
	 * @param \WC_Product|null $product Product object if available.
	 * @return mixed Converted price string or float.
	 */
	public function filter_price( $price, $product = null ) {
		if ( '' === $price || null === $price || false === $price || $this->is_admin_or_reporting_context() ) {
			return $price;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		// If active is base currency, return unaltered.
		if ( $active_currency === $base_currency ) {
			return $price;
		}

		// Check if product has a fixed price override for this currency.
		if ( $product instanceof \WC_Product ) {
			$fixed_price = $this->get_fixed_price( $product, $active_currency );
			if ( null !== $fixed_price && '' !== $fixed_price ) {
				return (float) $fixed_price;
			}
		}

		$enabled = $this->registry->get_enabled_currencies();
		$config  = $enabled[ $active_currency ] ?? null;
		if ( ! $config || empty( $config['rate'] ) ) {
			return $price;
		}

		$cache_key = (string) $price . '_' . $active_currency;
		if ( isset( $this->converted_cache[ $cache_key ] ) ) {
			return $this->converted_cache[ $cache_key ];
		}

		$rate     = (float) $config['rate'];
		$markup   = (float) ( $config['markup'] ?? 0.0 );
		$rounding = (string) ( $config['rounding'] ?? 'none' );

		$money     = Money::from_major( $price, $base_currency );
		$converted = $money->convert_to( $active_currency, $rate, $markup );
		$rounded   = $converted->apply_rounding( $rounding );

		$final_price = $rounded->to_major();
		$this->converted_cache[ $cache_key ] = $final_price;

		return $final_price;
	}

	/**
	 * Invalidate variation price cache based on the active currency.
	 *
	 * @param array       $hash     Variation cache hash.
	 * @param \WC_Product $product  Product.
	 * @param bool        $display  Whether for display.
	 * @return array
	 */
	public function filter_variation_hash( array $hash, $product, bool $display ): array {
		$hash[] = $this->registry->get_active_currency();
		return $hash;
	}

	/**
	 * Convert shipping rates to the active currency.
	 *
	 * @param array $rates   Array of WC_Shipping_Rate objects.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function filter_shipping_rates( array $rates, array $package ): array {
		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $rates;
		}

		$enabled = $this->registry->get_enabled_currencies();
		$config  = $enabled[ $active_currency ] ?? null;
		if ( ! $config || empty( $config['rate'] ) ) {
			return $rates;
		}

		$rate   = (float) $config['rate'];
		$markup = (float) ( $config['markup'] ?? 0.0 );

		foreach ( $rates as $key => $shipping_rate ) {
			if ( $shipping_rate->get_cost() > 0 ) {
				$money          = Money::from_major( $shipping_rate->get_cost(), $base_currency );
				$converted_cost = $money->convert_to( $active_currency, $rate, $markup );
				$shipping_rate->set_cost( $converted_cost->to_major() );
			}
		}

		return $rates;
	}

	/**
	 * Adjust wc_price formatting parameters (symbol, decimals) for the active currency.
	 *
	 * @param array $args Format arguments.
	 * @return array
	 */
	public function filter_wc_price_args( array $args ): array {
		if ( $this->is_admin_or_reporting_context() ) {
			// In admin or reporting contexts, if no explicit currency is specified, format using store base currency.
			if ( empty( $args['currency'] ) ) {
				$base_currency = $this->registry->get_base_currency();
				$enabled       = $this->registry->get_enabled_currencies();
				$config        = $enabled[ $base_currency ] ?? null;

				$args['currency'] = $base_currency;
				if ( $config ) {
					$args['decimals']           = isset( $config['decimals'] ) ? (int) $config['decimals'] : Money::get_decimals( $base_currency );
					$args['price_format']       = $this->get_price_format_string( $config['position'] ?? (string) get_option( 'woocommerce_currency_pos', 'left' ) );
					$args['decimal_separator']  = $config['decimal_sep'] ?? (string) get_option( 'woocommerce_price_decimal_sep', '.' );
					$args['thousand_separator'] = $config['thousand_sep'] ?? (string) get_option( 'woocommerce_price_thousand_sep', ',' );
				}
				return $args;
			}
		}

		$target_currency = ! empty( $args['currency'] ) ? $args['currency'] : $this->registry->get_active_currency();
		$enabled         = $this->registry->get_enabled_currencies();
		$config          = $enabled[ $target_currency ] ?? null;

		if ( $config ) {
			$args['currency']           = $target_currency;
			$args['decimals']           = isset( $config['decimals'] ) ? (int) $config['decimals'] : Money::get_decimals( $target_currency );
			$args['price_format']       = $this->get_price_format_string( $config['position'] ?? 'left' );
			$args['decimal_separator']  = $config['decimal_sep'] ?? '.';
			$args['thousand_separator'] = $config['thousand_sep'] ?? ',';
		}

		return $args;
	}

	/**
	 * Convert regular price or return fixed regular price override.
	 *
	 * @param mixed                $price   Base price.
	 * @param \WC_Product|null $product Product object.
	 * @return mixed
	 */
	public function filter_regular_price( $price, $product = null ) {
		if ( '' === $price || null === $price || false === $price || $this->is_admin_or_reporting_context() ) {
			return $price;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $price;
		}

		if ( $product instanceof \WC_Product ) {
			$reg_fixed = $product->get_meta( '_velox_regular_price_' . strtoupper( $active_currency ), true );
			if ( '' !== $reg_fixed && false !== $reg_fixed ) {
				return (float) $reg_fixed;
			}
		}

		return $this->convert_raw_price( $price, $base_currency, $active_currency );
	}

	/**
	 * Convert sale price or return fixed sale price override.
	 *
	 * @param mixed                $price   Base price.
	 * @param \WC_Product|null $product Product object.
	 * @return mixed
	 */
	public function filter_sale_price( $price, $product = null ) {
		if ( '' === $price || null === $price || false === $price || $this->is_admin_or_reporting_context() ) {
			return $price;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $price;
		}

		if ( $product instanceof \WC_Product ) {
			$sale_fixed = $product->get_meta( '_velox_sale_price_' . strtoupper( $active_currency ), true );
			if ( '' !== $sale_fixed && false !== $sale_fixed ) {
				return (float) $sale_fixed;
			}
		}

		return $this->convert_raw_price( $price, $base_currency, $active_currency );
	}

	/**
	 * Convert a raw price float to target currency.
	 *
	 * @param mixed  $price           Amount.
	 * @param string $base_currency   Base currency code.
	 * @param string $active_currency Active currency code.
	 * @return float
	 */
	protected function convert_raw_price( $price, string $base_currency, string $active_currency ): float {
		$enabled = $this->registry->get_enabled_currencies();
		$config  = $enabled[ $active_currency ] ?? null;
		if ( ! $config || empty( $config['rate'] ) ) {
			return (float) $price;
		}

		$cache_key = (string) $price . '_' . $active_currency;
		if ( isset( $this->converted_cache[ $cache_key ] ) ) {
			return $this->converted_cache[ $cache_key ];
		}

		$rate     = (float) $config['rate'];
		$markup   = (float) ( $config['markup'] ?? 0.0 );
		$rounding = (string) ( $config['rounding'] ?? 'none' );

		$money     = Money::from_major( $price, $base_currency );
		$converted = $money->convert_to( $active_currency, $rate, $markup );
		$rounded   = $converted->apply_rounding( $rounding );

		$final_price = $rounded->to_major();
		$this->converted_cache[ $cache_key ] = $final_price;

		return $final_price;
	}

	/**
	 * Get fixed price override for a product in a currency if configured.
	 * Checks sale price first if product is on sale, then regular price.
	 *
	 * @param \WC_Product $product  Product.
	 * @param string      $currency Target currency.
	 * @return string|null
	 */
	protected function get_fixed_price( \WC_Product $product, string $currency ): ?string {
		$curr = strtoupper( $currency );

		// 1. Check if sale price is explicitly set for this currency.
		$sale_fixed = $product->get_meta( '_velox_sale_price_' . $curr, true );
		if ( '' !== $sale_fixed && false !== $sale_fixed && (float) $sale_fixed > 0 ) {
			return (string) $sale_fixed;
		}

		// 2. Check regular price override.
		$reg_fixed = $product->get_meta( '_velox_regular_price_' . $curr, true );
		if ( '' !== $reg_fixed && false !== $reg_fixed ) {
			return (string) $reg_fixed;
		}

		// 3. Fallback fixed price.
		$val = $product->get_meta( '_velox_fixed_price_' . $curr, true );
		return ( '' !== $val && false !== $val ) ? (string) $val : null;
	}

	/**
	 * Get WooCommerce price format pattern based on position string.
	 *
	 * @param string $position 'left', 'right', 'left_space', 'right_space'.
	 * @return string
	 */
	protected function get_price_format_string( string $position ): string {
		switch ( $position ) {
			case 'right':
				return '%2$s%1$s';
			case 'left_space':
				return '%1$s&nbsp;%2$s';
			case 'right_space':
				return '%2$s&nbsp;%1$s';
			case 'left':
			default:
				return '%1$s%2$s';
		}
	}

	/**
	 * Inject data-velox-base and data-velox-currency attributes into wc_price HTML wrapper.
	 * Guarantees zero calculation distortion on statically cached pages.
	 *
	 * @param string $return           Formatted price HTML.
	 * @param float  $price            Numeric price amount.
	 * @param array  $args             Formatting arguments.
	 * @param float  $unformatted_price Raw unformatted amount.
	 * @param float  $original_price   Original unmodified price.
	 * @return string Modified HTML.
	 */
	public function filter_wc_price_html( $return, $price, $args = array(), $unformatted_price = 0.0, $original_price = 0.0 ) {
		if ( empty( $return ) || ! is_string( $return ) || $this->is_admin_or_reporting_context() ) {
			return $return;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		// Determine base amount.
		$base_amount = (float) ( $original_price > 0 ? $original_price : $price );
		if ( $active_currency !== $base_currency && $original_price <= 0 ) {
			$enabled        = $this->registry->get_enabled_currencies();
			$config         = $enabled[ $active_currency ] ?? array();
			$rate           = (float) ( $config['rate'] ?? 1.0 );
			$markup         = (float) ( $config['markup'] ?? 0.0 );
			$effective_rate = ( $markup > 0 ) ? ( $rate * ( 1 + ( $markup / 100 ) ) ) : $rate;
			if ( $effective_rate > 0 && 1.0 !== $effective_rate ) {
				$base_amount = (float) $price / $effective_rate;
			}
		}

		$attr = sprintf(
			' data-velox-base="%1$s" data-velox-currency="%2$s"',
			esc_attr( (string) round( $base_amount, 4 ) ),
			esc_attr( $active_currency )
		);

		return preg_replace(
			'/<span class="([^"]*woocommerce-Price-amount[^"]*)"/i',
			'<span class="$1"' . $attr,
			$return,
			1
		);
	}
}
