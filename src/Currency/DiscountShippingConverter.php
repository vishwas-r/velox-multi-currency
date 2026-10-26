<?php
/**
 * DiscountShippingConverter handles coupon amounts and free shipping threshold conversions.
 *
 * @package NammaWP\MultiCurrency\Currency
 */

namespace NammaWP\MultiCurrency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * DiscountShippingConverter class.
 */
class DiscountShippingConverter {

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
	 * Initialize coupon and shipping threshold filters.
	 */
	public function init(): void {
		// Coupon amount conversion (only for fixed discount types).
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'filter_coupon_amount' ), 10, 2 );

		// Coupon min & max spend restrictions.
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'filter_coupon_spend_limit' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( $this, 'filter_coupon_spend_limit' ), 10, 2 );

		// Free shipping min-spend threshold conversion.
		add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'filter_free_shipping_availability' ), 10, 3 );
	}

	/**
	 * Convert fixed coupon amount to active currency.
	 *
	 * @param float       $amount Coupon discount amount.
	 * @param \WC_Coupon  $coupon Coupon object.
	 * @return float Converted amount.
	 */
	public function filter_coupon_amount( $amount, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || $amount <= 0 ) {
			return $amount;
		}

		// Only convert fixed coupons (percentages apply equally to any currency).
		$type = $coupon->get_discount_type();
		if ( ! in_array( $type, array( 'fixed_cart', 'fixed_product' ), true ) ) {
			return $amount;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $amount;
		}

		$enabled = $this->registry->get_enabled_currencies();
		$rate    = (float) ( $enabled[ $active_currency ]['rate'] ?? 1.0 );
		if ( $rate <= 0 || 1.0 === $rate ) {
			return $amount;
		}

		$money     = Money::from_major( $amount, $base_currency );
		$converted = $money->convert_to( $active_currency, $rate );

		return $converted->to_major();
	}

	/**
	 * Convert coupon minimum/maximum spend thresholds.
	 *
	 * @param float      $amount Spend threshold.
	 * @param \WC_Coupon $coupon Coupon object.
	 * @return float Converted threshold.
	 */
	public function filter_coupon_spend_limit( $amount, $coupon ) {
		if ( $amount <= 0 ) {
			return $amount;
		}

		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $amount;
		}

		$enabled = $this->registry->get_enabled_currencies();
		$rate    = (float) ( $enabled[ $active_currency ]['rate'] ?? 1.0 );
		if ( $rate <= 0 || 1.0 === $rate ) {
			return $amount;
		}

		$money     = Money::from_major( $amount, $base_currency );
		$converted = $money->convert_to( $active_currency, $rate );

		return $converted->to_major();
	}

	/**
	 * Recalculate Free Shipping eligibility with converted min-amount threshold.
	 *
	 * @param bool                         $is_available Original availability.
	 * @param array                        $package      Package data.
	 * @param \WC_Shipping_Free_Shipping  $shipping     Free shipping method instance.
	 * @return bool
	 */
	public function filter_free_shipping_availability( bool $is_available, array $package, $shipping ): bool {
		$base_currency   = $this->registry->get_base_currency();
		$active_currency = $this->registry->get_active_currency();

		if ( $active_currency === $base_currency ) {
			return $is_available;
		}

		$min_amount = (float) $shipping->get_option( 'min_amount', 0 );
		if ( $min_amount <= 0 ) {
			return $is_available;
		}

		$enabled = $this->registry->get_enabled_currencies();
		$rate    = (float) ( $enabled[ $active_currency ]['rate'] ?? 1.0 );
		if ( $rate <= 0 ) {
			return $is_available;
		}

		// Convert required min amount to active currency.
		$converted_min = Money::from_major( $min_amount, $base_currency )
			->convert_to( $active_currency, $rate )
			->to_major();

		$cart_total = WC()->cart ? (float) WC()->cart->get_displayed_subtotal() : 0.0;
		$requires   = $shipping->get_option( 'requires' );

		switch ( $requires ) {
			case 'min_amount':
				return $cart_total >= $converted_min;
			case 'either':
				return ( WC()->cart && WC()->cart->has_discount( $shipping->get_option( 'coupon_code' ) ) ) || ( $cart_total >= $converted_min );
			case 'both':
				return ( WC()->cart && WC()->cart->has_discount( $shipping->get_option( 'coupon_code' ) ) ) && ( $cart_total >= $converted_min );
			default:
				return $is_available;
		}
	}
}
