<?php
/**
 * OrderRecorder stores settlement snapshots and HPOS metadata on orders.
 *
 * @package SettleX\MultiCurrency\Orders
 */

namespace SettleX\MultiCurrency\Orders;

use SettleX\MultiCurrency\Currency\CurrencyRegistry;
use SettleX\MultiCurrency\GatewayRouter\SettlementResolver;

defined( 'ABSPATH' ) || exit;

/**
 * OrderRecorder class.
 */
class OrderRecorder {

	/**
	 * CurrencyRegistry.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $currency_registry;

	/**
	 * SettlementResolver.
	 *
	 * @var SettlementResolver
	 */
	protected SettlementResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry   $currency_registry Registry.
	 * @param SettlementResolver $resolver          Resolver.
	 */
	public function __construct( CurrencyRegistry $currency_registry, SettlementResolver $resolver ) {
		$this->currency_registry = $currency_registry;
		$this->resolver          = $resolver;
	}

	/**
	 * Initialize order recording hooks.
	 */
	public function init(): void {
		// Hook into order creation to record settlement metadata and adjust charge currency.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'record_order_settlement' ), 20, 2 );

		// Admin order screen metabox (HPOS compatible).
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ), 20, 2 );
	}

	/**
	 * Record settlement snapshot when order is created at checkout.
	 *
	 * @param \WC_Order $order Order instance.
	 * @param array     $data  Posted checkout data.
	 */
	public function record_order_settlement( \WC_Order $order, array $data ): void {
		$display_currency = $this->currency_registry->get_active_currency();
		$base_currency    = $this->currency_registry->get_base_currency();
		$gateway_id       = $order->get_payment_method();
		$current_total    = $order->get_total();

		$settlement = $this->resolver->resolve( $current_total, $display_currency, $gateway_id );

		// If in Convert and Disclose mode, update order currency and total to the charge settlement.
		if ( $settlement->charge_currency !== $display_currency ) {
			$order->set_currency( $settlement->charge_currency );
			$order->set_total( $settlement->charge_amount->to_major() );
		} else {
			$order->set_currency( $display_currency );
		}

		// Store HPOS metadata snapshot on order object.
		$order->update_meta_data( '_settlex_display_currency', $settlement->display_currency );
		$order->update_meta_data( '_settlex_display_total', $settlement->display_amount->to_major_string() );
		$order->update_meta_data( '_settlex_charge_currency', $settlement->charge_currency );
		$order->update_meta_data( '_settlex_charge_total', $settlement->charge_amount->to_major_string() );
		$order->update_meta_data( '_settlex_base_currency', $base_currency );
		$order->update_meta_data( '_settlex_exchange_rate', $settlement->exchange_rate );
		$order->update_meta_data( '_settlex_rate_markup', $settlement->rate_markup );
		$order->update_meta_data( '_settlex_settlement_mode', $settlement->mode );

		if ( $settlement->disclosure_required ) {
			$order->update_meta_data( '_settlex_disclosure_notice', $settlement->disclosure_message );
		}
	}

	/**
	 * Add SettleX diagnostics metabox on order details screen.
	 *
	 * @param string            $post_type_or_screen Screen ID or post type.
	 * @param \WC_Order|\WP_Post $order_or_post       Order or Post object.
	 */
	public function add_order_metabox( $post_type_or_screen, $order_or_post ): void {
		$screen = ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'settlex_order_details',
			__( 'SettleX Multi-Currency & Routing', 'settlex-multi-currency' ),
			array( $this, 'render_order_metabox' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or Order.
	 */
	public function render_order_metabox( $post_or_order ): void {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$display_curr  = $order->get_meta( '_settlex_display_currency' ) ?: $order->get_currency();
		$display_total = $order->get_meta( '_settlex_display_total' ) ?: $order->get_total();
		$charge_curr   = $order->get_meta( '_settlex_charge_currency' ) ?: $order->get_currency();
		$charge_total  = $order->get_meta( '_settlex_charge_total' ) ?: $order->get_total();
		$base_curr     = $order->get_meta( '_settlex_base_currency' ) ?: get_woocommerce_currency();
		$rate          = $order->get_meta( '_settlex_exchange_rate' ) ?: '1.0';
		$mode          = $order->get_meta( '_settlex_settlement_mode' ) ?: 'allow_native';
		$disclosure    = $order->get_meta( '_settlex_disclosure_notice' );

		?>
		<div class="settlex-order-details" style="font-size: 13px; line-height: 1.6;">
			<p><strong><?php esc_html_e( 'Display (Shopper):', 'settlex-multi-currency' ); ?></strong><br>
				<code><?php echo esc_html( $display_curr . ' ' . $display_total ); ?></code>
			</p>
			<p><strong><?php esc_html_e( 'Settlement (Charged):', 'settlex-multi-currency' ); ?></strong><br>
				<code style="color: #2e7d32;"><?php echo esc_html( $charge_curr . ' ' . $charge_total ); ?></code>
			</p>
			<p><strong><?php esc_html_e( 'Store Base Currency:', 'settlex-multi-currency' ); ?></strong><br>
				<?php echo esc_html( $base_curr ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Snapshot Rate:', 'settlex-multi-currency' ); ?></strong><br>
				1 <?php echo esc_html( $base_curr ); ?> = <?php echo esc_html( $rate . ' ' . $display_curr ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Routing Mode:', 'settlex-multi-currency' ); ?></strong><br>
				<span class="badge" style="background:#e0e0e0; padding:2px 6px; border-radius:3px;">
					<?php echo esc_html( strtoupper( str_replace( '_', ' ', $mode ) ) ); ?>
				</span>
			</p>
			<?php if ( ! empty( $disclosure ) ) : ?>
				<p><strong><?php esc_html_e( 'Disclosure Displayed:', 'settlex-multi-currency' ); ?></strong><br>
					<em style="font-size: 11px;"><?php echo esc_html( $disclosure ); ?></em>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
