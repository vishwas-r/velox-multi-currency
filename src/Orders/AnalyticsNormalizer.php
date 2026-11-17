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
		add_action( 'woocommerce_analytics_update_product', array( $this, 'normalize_product_lookup' ), 20, 2 );
		add_action( 'woocommerce_analytics_update_coupon', array( $this, 'normalize_coupon_lookup' ), 20, 2 );
		add_action( 'woocommerce_analytics_update_tax', array( $this, 'normalize_tax_lookup' ), 20, 2 );

		// Normalize subscription revenue in WooCommerce dashboard status widget to base currency.
		add_filter( 'woocommerce_subscription_dashboard_status_widget_signup_revenue_query', array( $this, 'normalize_dashboard_signup_revenue_query' ), 20, 1 );
		add_filter( 'woocommerce_subscription_dashboard_status_widget_renewal_revenue_query', array( $this, 'normalize_dashboard_renewal_revenue_query' ), 20, 1 );

		// Normalize subscription events sign up data in WooCommerce Subscriptions reports.
		add_filter( 'wcs_reports_subscription_events_sign_up_data', array( $this, 'normalize_subscription_events_sign_up_data' ), 20, 2 );

		// Clear subscription dashboard widget cache when orders are created or change status.
		add_action( 'woocommerce_order_status_changed', array( $this, 'clear_dashboard_widget_transient' ) );
		add_action( 'woocommerce_new_order', array( $this, 'clear_dashboard_widget_transient' ) );
	}

	/**
	 * Retrieve base-to-order exchange rate for an order.
	 *
	 * @param \WC_Order $order Order instance.
	 * @param string    $order_currency Order currency.
	 * @param string    $base_currency Base store currency.
	 * @return float
	 */
	protected function get_order_rate( $order, string $order_currency, string $base_currency ): float {
		$rate = (float) $order->get_meta( '_velox_exchange_rate' );
		if ( $rate <= 0 || ( 1.0 === $rate && $order_currency !== $base_currency ) ) {
			$enabled = $this->currency_registry->get_enabled_currencies();
			$rate    = (float) ( $enabled[ $order_currency ]['rate'] ?? 1.0 );
		}
		return $rate;
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

		// Allow external extensions to override or replace core analytics normalization.
		if ( ! apply_filters( 'velox_mc_enable_core_analytics_normalization', true ) ) {
			return $data;
		}

		$base_currency  = $this->currency_registry->get_base_currency();
		$order_currency = $order->get_currency();

		// If order is already in store base currency, no normalization needed.
		if ( strtoupper( $order_currency ) === $base_currency ) {
			return $data;
		}

		// Retrieve rate snapshot stored at checkout.
		$rate = $this->get_order_rate( $order, $order_currency, $base_currency );
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

	/**
	 * Normalize product lookup table data to store base currency.
	 *
	 * @param int $order_item_id Order item ID.
	 * @param int $order_id      Order ID.
	 */
	public function normalize_product_lookup( $order_item_id, $order_id ): void {
		if ( ! apply_filters( 'velox_mc_enable_core_analytics_normalization', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}

		$base_currency  = $this->currency_registry->get_base_currency();
		$order_currency = strtoupper( (string) $order->get_currency() );
		if ( $order_currency === $base_currency ) {
			return;
		}

		$rate = $this->get_order_rate( $order, $order_currency, $base_currency );
		if ( $rate <= 0 || 1.0 === $rate ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_item_id = %d", $order_item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return;
		}

		$item = $order->get_item( $order_item_id );
		if ( $item && 'yes' === $item->get_meta( '_velox_analytics_normalized', true ) ) {
			return;
		}

		$fields = array( 'product_net_revenue', 'product_gross_revenue', 'coupon_amount', 'tax_amount', 'shipping_amount', 'shipping_tax_amount' );
		$update = array();
		$format = array();
		foreach ( $fields as $f ) {
			if ( isset( $row[ $f ] ) ) {
				$update[ $f ] = round( (float) $row[ $f ] / $rate, 4 );
				$format[]     = '%f';
			}
		}

		if ( ! empty( $update ) ) {
			$wpdb->update( $table, $update, array( 'order_item_id' => $order_item_id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $item ) {
				$item->update_meta_data( '_velox_analytics_normalized', 'yes' );
				$item->save_meta_data();
			}
		}
	}

	/**
	 * Normalize coupon lookup table data to store base currency.
	 *
	 * @param int $coupon_id Coupon ID.
	 * @param int $order_id  Order ID.
	 */
	public function normalize_coupon_lookup( $coupon_id, $order_id ): void {
		if ( ! apply_filters( 'velox_mc_enable_core_analytics_normalization', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}

		$base_currency  = $this->currency_registry->get_base_currency();
		$order_currency = strtoupper( (string) $order->get_currency() );
		if ( $order_currency === $base_currency ) {
			return;
		}

		$rate = $this->get_order_rate( $order, $order_currency, $base_currency );
		if ( $rate <= 0 || 1.0 === $rate ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_coupon_lookup';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT discount_amount FROM {$table} WHERE order_id = %d AND coupon_id = %d", $order_id, $coupon_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return;
		}

		$normalized = round( (float) $row['discount_amount'] / $rate, 4 );
		$wpdb->update( $table, array( 'discount_amount' => $normalized ), array( 'order_id' => $order_id, 'coupon_id' => $coupon_id ), array( '%f' ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Normalize tax lookup table data to store base currency.
	 *
	 * @param int $rate_id  Tax rate ID.
	 * @param int $order_id Order ID.
	 */
	public function normalize_tax_lookup( $rate_id, $order_id ): void {
		if ( ! apply_filters( 'velox_mc_enable_core_analytics_normalization', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}

		$base_currency  = $this->currency_registry->get_base_currency();
		$order_currency = strtoupper( (string) $order->get_currency() );
		if ( $order_currency === $base_currency ) {
			return;
		}

		$rate = $this->get_order_rate( $order, $order_currency, $base_currency );
		if ( $rate <= 0 || 1.0 === $rate ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_tax_lookup';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT order_item_id, tax_amount, shipping_tax_amount FROM {$table} WHERE order_id = %d AND tax_rate_id = %d", $order_id, $rate_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $r ) {
			$wpdb->update(
				$table,
				array(
					'tax_amount'          => round( (float) $r['tax_amount'] / $rate, 4 ),
					'shipping_tax_amount' => round( (float) $r['shipping_tax_amount'] / $rate, 4 ),
				),
				array(
					'order_item_id' => $r['order_item_id'],
					'order_id'      => $order_id,
				),
				array( '%f', '%f' ),
				array( '%d', '%d' )
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Normalize subscription signup revenue query for dashboard widget.
	 *
	 * @param string $query Original SQL query.
	 * @return string Modified SQL query with normalized base revenue.
	 */
	public function normalize_dashboard_signup_revenue_query( string $query ): string {
		global $wpdb;

		if ( false !== strpos( $query, 'velox_stats' ) ) {
			return $query;
		}

		if ( false !== strpos( $query, 'parent_orders.total_amount' ) ) {
			$query = str_replace(
				'SELECT SUM(parent_orders.total_amount)',
				"SELECT SUM(COALESCE(velox_stats.total_sales, parent_orders.total_amount))",
				$query
			);
			$query = str_replace(
				"ON subscripitons.parent_order_id = parent_orders.ID",
				"ON subscripitons.parent_order_id = parent_orders.ID LEFT JOIN {$wpdb->prefix}wc_order_stats AS velox_stats ON parent_orders.ID = velox_stats.order_id",
				$query
			);
		} elseif ( false !== strpos( $query, 'order_total_meta.meta_value' ) ) {
			$query = str_replace(
				'SELECT SUM(order_total_meta.meta_value)',
				"SELECT SUM(COALESCE(velox_stats.total_sales, order_total_meta.meta_value))",
				$query
			);
			$query = str_replace(
				"WHERE order_total_meta.meta_key = '_order_total'",
				"LEFT JOIN {$wpdb->prefix}wc_order_stats AS velox_stats ON orders.ID = velox_stats.order_id WHERE order_total_meta.meta_key = '_order_total'",
				$query
			);
		}

		return $query;
	}

	/**
	 * Normalize subscription renewal revenue query for dashboard widget.
	 *
	 * @param string $query Original SQL query.
	 * @return string Modified SQL query with normalized base revenue.
	 */
	public function normalize_dashboard_renewal_revenue_query( string $query ): string {
		global $wpdb;

		if ( false !== strpos( $query, 'velox_stats' ) ) {
			return $query;
		}

		if ( false !== strpos( $query, 'wcorder.total_amount' ) ) {
			$query = str_replace(
				'SELECT SUM(wcorder.total_amount)',
				"SELECT SUM(COALESCE(velox_stats.total_sales, wcorder.total_amount))",
				$query
			);
			$query = str_replace(
				"FROM {$wpdb->prefix}wc_orders AS wcorder",
				"FROM {$wpdb->prefix}wc_orders AS wcorder LEFT JOIN {$wpdb->prefix}wc_order_stats AS velox_stats ON wcorder.id = velox_stats.order_id",
				$query
			);
		} elseif ( false !== strpos( $query, 'order_total_meta.meta_value' ) ) {
			$query = str_replace(
				'SELECT SUM(order_total_meta.meta_value)',
				"SELECT SUM(COALESCE(velox_stats.total_sales, order_total_meta.meta_value))",
				$query
			);
			$query = str_replace(
				"WHERE order_total_meta.meta_key = '_order_total'",
				"LEFT JOIN {$wpdb->prefix}wc_order_stats AS velox_stats ON orders.ID = velox_stats.order_id WHERE order_total_meta.meta_key = '_order_total'",
				$query
			);
		}

		return $query;
	}

	/**
	 * Clear subscription dashboard widget transient cache.
	 */
	public function clear_dashboard_widget_transient(): void {
		delete_transient( 'wcs_report_dashboard' );
	}

	/**
	 * Normalize subscription events sign up data in WooCommerce Subscriptions reports.
	 *
	 * @param array $query_results Query results.
	 * @param array $args          Arguments.
	 * @return array
	 */
	public function normalize_subscription_events_sign_up_data( $query_results, $args ) {
		if ( empty( $query_results ) || ! is_array( $query_results ) ) {
			return $query_results;
		}

		global $wpdb;
		$stats_table = $wpdb->prefix . 'wc_order_stats';

		foreach ( $query_results as &$row ) {
			if ( empty( $row->subscription_ids ) ) {
				continue;
			}
			$sub_ids = array_filter( array_map( 'absint', explode( ',', (string) $row->subscription_ids ) ) );
			if ( empty( $sub_ids ) ) {
				continue;
			}

			// Map subscriptions to parent orders (support both HPOS and legacy posts).
			$placeholders = implode( ',', array_fill( 0, count( $sub_ids ), '%d' ) );
			if ( function_exists( 'wcs_is_custom_order_tables_usage_enabled' ) && wcs_is_custom_order_tables_usage_enabled() ) {
				$parent_order_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT parent_order_id FROM {$wpdb->prefix}wc_orders WHERE id IN ($placeholders) AND parent_order_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						...$sub_ids
					)
				);
			} else {
				$parent_order_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_parent > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						...$sub_ids
					)
				);
			}

			if ( empty( $parent_order_ids ) ) {
				continue;
			}

			$order_placeholders = implode( ',', array_fill( 0, count( $parent_order_ids ), '%d' ) );
			$normalized_total    = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(total_sales) FROM {$stats_table} WHERE order_id IN ($order_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$parent_order_ids
				)
			);

			if ( $normalized_total > 0 ) {
				$row->signup_totals = $normalized_total;
			}
		}

		return $query_results;
	}
}
