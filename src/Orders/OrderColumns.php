<?php
/**
 * OrderColumns adds currency and settlement info to WooCommerce Orders list tables (HPOS & Classic).
 *
 * @package NammaWP\MultiCurrency\Orders
 */

namespace NammaWP\MultiCurrency\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * OrderColumns class.
 */
class OrderColumns {

	/**
	 * Initialize order column hooks.
	 */
	public function init(): void {
		// HPOS table hooks.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_column' ), 10, 2 );

		// Classic CPT table hooks.
		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_cpt_order_column' ), 10, 2 );
	}

	/**
	 * Add custom column header.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_column( array $columns ): array {
		$new_columns = array();
		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;
			// Place after order_total.
			if ( 'order_total' === $key ) {
				$new_columns['nammawp_currency'] = __( 'NammaWP Currency', 'nammawp-multi-currency' );
			}
		}

		if ( ! isset( $new_columns['nammawp_currency'] ) ) {
			$new_columns['nammawp_currency'] = __( 'NammaWP Currency', 'nammawp-multi-currency' );
		}

		return $new_columns;
	}

	/**
	 * Render HPOS column content.
	 *
	 * @param string    $column Column name.
	 * @param \WC_Order $order  Order object.
	 */
	public function render_order_column( string $column, $order ): void {
		if ( 'nammawp_currency' !== $column || ! $order instanceof \WC_Order ) {
			return;
		}

		$this->output_column_content( $order );
	}

	/**
	 * Render Classic CPT column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Order post ID.
	 */
	public function render_cpt_order_column( string $column, int $post_id ): void {
		if ( 'nammawp_currency' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( $order ) {
			$this->output_column_content( $order );
		}
	}

	/**
	 * Output formatted column content.
	 *
	 * @param \WC_Order $order Order object.
	 */
	protected function output_column_content( \WC_Order $order ): void {
		$display_curr  = $order->get_meta( '_nammawp_display_currency' ) ?: $order->get_currency();
		$display_total = $order->get_meta( '_nammawp_display_total' );
		$charge_curr   = $order->get_meta( '_nammawp_charge_currency' ) ?: $order->get_currency();
		$mode          = $order->get_meta( '_nammawp_settlement_mode' ) ?: 'allow_native';

		if ( ! empty( $display_total ) && $display_curr !== $charge_curr ) {
			echo '<div><span style="font-weight:600;">' . esc_html( $display_curr . ' ' . $display_total ) . '</span></div>';
			echo '<div style="font-size:11px; color:#16a34a;">' . esc_html( 'Charged: ' . $charge_curr . ' ' . $order->get_total() ) . '</div>';
			echo '<span class="badge" style="background:#fef3c7; color:#92400e; font-size:10px; padding:1px 5px; border-radius:3px;">' . esc_html__( 'Converted', 'nammawp-multi-currency' ) . '</span>';
		} else {
			echo '<div><span style="font-weight:600;">' . esc_html( $order->get_currency() ) . '</span></div>';
			echo '<span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:10px; padding:1px 5px; border-radius:3px;">' . esc_html__( 'Native', 'nammawp-multi-currency' ) . '</span>';
		}
	}
}
