<?php
/**
 * PriceConverter hooks into WooCommerce price calculation and formatting.
 *
 * @package SettleX\MultiCurrency\Currency
 */

namespace SettleX\MultiCurrency\Currency;

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
	}

	/**
	 * Convert a raw price float to the currently active currency.
	 *
	 * @param mixed                $price   Base price.
	 * @param \WC_Product|null $product Product object if available.
	 * @return mixed Converted price string or float.
	 */
	public function filter_price( $price, $product = null ) {
		if ( '' === $price || null === $price || false === $price ) {
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
		$active_currency = $this->registry->get_active_currency();
		$enabled         = $this->registry->get_enabled_currencies();
		$config          = $enabled[ $active_currency ] ?? null;

		if ( $config ) {
			$args['currency']           = $active_currency;
			$args['decimals']           = isset( $config['decimals'] ) ? (int) $config['decimals'] : Money::get_decimals( $active_currency );
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
		if ( '' === $price || null === $price || false === $price ) {
			return $price;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $price;
		}

		if ( $product instanceof \WC_Product ) {
			$reg_fixed = $product->get_meta( '_settlex_regular_price_' . strtoupper( $active_currency ), true );
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
		if ( '' === $price || null === $price || false === $price ) {
			return $price;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $price;
		}

		if ( $product instanceof \WC_Product ) {
			$sale_fixed = $product->get_meta( '_settlex_sale_price_' . strtoupper( $active_currency ), true );
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
		$sale_fixed = $product->get_meta( '_settlex_sale_price_' . $curr, true );
		if ( '' !== $sale_fixed && false !== $sale_fixed && (float) $sale_fixed > 0 ) {
			return (string) $sale_fixed;
		}

		// 2. Check regular price override.
		$reg_fixed = $product->get_meta( '_settlex_regular_price_' . $curr, true );
		if ( '' !== $reg_fixed && false !== $reg_fixed ) {
			return (string) $reg_fixed;
		}

		// 3. Backward-compatibility fixed price.
		$val = $product->get_meta( '_settlex_fixed_price_' . $curr, true );
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
}
