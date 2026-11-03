<?php
/**
 * AnalyticsNormalizer ensures WooCommerce Analytics reports are normalized to the store base currency.
 *
 * @package VeloX\MultiCurrency\Orders
 */

namespace VeloX\MultiCurrency\Orders;

use VeloX\MultiCurrency\Currency\CurrencyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * AnalyticsNormalizer class.
 */
class AnalyticsNormalizer {

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
	 * Initialize analytics filter hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_analytics_update_order_stats_data', array( $this, 'normalize_order_stats_data' ), 20, 2 );
	}

	/**
	 * Normalize order stats data to the store's base currency before inserting into wp_wc_order_stats.
	 *
	 * @param array                  $data  Stats row data for wp_wc_order_stats.
	 * @param \WC_Order|\WC_Order_Refund $order Order or refund object.
	 * @return array Normalized stats data.
	 */
	public function normalize_order_stats_data( array $data, $order ): array {
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return $data;
		}

		// If VeloX Pro is active, let Pro handle comprehensive normalization to prevent double division.
		if ( function_exists( 'velox_mc_is_pro' ) && velox_mc_is_pro() ) {
			return $data;
		}

		$base_currency  = $this->currency_registry->get_base_currency();
		$order_currency = $order->get_currency();

		// If order is already in store base currency, no normalization needed.
		if ( strtoupper( $order_currency ) === $base_currency ) {
			return $data;
		}

		// Retrieve rate snapshot stored at checkout.
		$rate = (float) $order->get_meta( '_velox_exchange_rate' );
		if ( $rate <= 0 || ( 1.0 === $rate && $order_currency !== $base_currency ) ) {
			// Fallback to active registry rate if order has no snapshot.
			$enabled = $this->currency_registry->get_enabled_currencies();
			$rate    = (float) ( $enabled[ $order_currency ]['rate'] ?? 1.0 );
		}

		if ( $rate <= 0 || 1.0 === $rate ) {
			return $data;
		}

		// 1 Base = $rate Target. Therefore: Base Amount = Target Amount / $rate.
		if ( isset( $data['total_sales'] ) ) {
			$data['total_sales'] = round( (float) $data['total_sales'] / $rate, 4 );
		}

		if ( isset( $data['net_total'] ) ) {
			$data['net_total'] = round( (float) $data['net_total'] / $rate, 4 );
		}

		if ( isset( $data['tax_total'] ) ) {
			$data['tax_total'] = round( (float) $data['tax_total'] / $rate, 4 );
		}

		if ( isset( $data['shipping_total'] ) ) {
			$data['shipping_total'] = round( (float) $data['shipping_total'] / $rate, 4 );
		}

		return $data;
	}
}
