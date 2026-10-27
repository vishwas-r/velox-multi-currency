<?php
/**
 * OrderRecorder stores settlement snapshots and HPOS metadata on orders.
 *
 * @package VeloX\MultiCurrency\Orders
 */

namespace VeloX\MultiCurrency\Orders;

use VeloX\MultiCurrency\Currency\CurrencyRegistry;
use VeloX\MultiCurrency\Currency\Money;
use VeloX\MultiCurrency\GatewayRouter\SettlementResolver;

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
		// Hook into order creation to record settlement metadata and adjust charge currency.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'record_order_settlement' ), 20, 2 );

		// Hook into payment retry on order-pay screen.
		add_action( 'woocommerce_before_pay_action', array( $this, 'revalidate_order_on_payment_retry' ), 10, 1 );

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

		// If in Convert and Disclose mode, update order currency, line items, and total to the charge settlement.
		if ( $settlement->charge_currency !== $display_currency ) {
			$this->convert_order_line_items(
				$order,
				$display_currency,
				$settlement->charge_currency,
				$settlement->exchange_rate,
				$settlement->rate_markup
			);
			$order->set_currency( $settlement->charge_currency );
			$order->set_total( $settlement->charge_amount->to_major() );
		} else {
			$order->set_currency( $display_currency );
		}

		// Store HPOS metadata snapshot on order object.
		$order->update_meta_data( '_velox_display_currency', $settlement->display_currency );
		$order->update_meta_data( '_velox_display_total', $settlement->display_amount->to_major_string() );
		$order->update_meta_data( '_velox_charge_currency', $settlement->charge_currency );
		$order->update_meta_data( '_velox_charge_total', $settlement->charge_amount->to_major_string() );
		$order->update_meta_data( '_velox_base_currency', $base_currency );
		$order->update_meta_data( '_velox_exchange_rate', $settlement->exchange_rate );
		$order->update_meta_data( '_velox_rate_markup', $settlement->rate_markup );
		$order->update_meta_data( '_velox_settlement_mode', $settlement->mode );

		if ( $settlement->disclosure_required ) {
			$order->update_meta_data( '_velox_disclosure_notice', $settlement->disclosure_message );
		}
	}

	/**
	 * Re-verify and update settlement if customer changes payment gateway during retry.
	 *
	 * @param \WC_Order $order Order instance.
	 */
	public function revalidate_order_on_payment_retry( \WC_Order $order ): void {
		$gateway_id = $order->get_payment_method();
		if ( empty( $gateway_id ) ) {
			return;
		}

		$display_currency = $order->get_meta( '_velox_display_currency' ) ?: $order->get_currency();
		$display_total    = $order->get_meta( '_velox_display_total' ) ?: $order->get_total();

		$settlement = $this->resolver->resolve( $display_total, $display_currency, $gateway_id );

		if ( $settlement->charge_currency !== $order->get_currency() ) {
			$this->convert_order_line_items(
				$order,
				$display_currency,
				$settlement->charge_currency,
				$settlement->exchange_rate,
				$settlement->rate_markup
			);
			$order->set_currency( $settlement->charge_currency );
			$order->set_total( $settlement->charge_amount->to_major() );
			$order->update_meta_data( '_velox_charge_currency', $settlement->charge_currency );
			$order->update_meta_data( '_velox_charge_total', $settlement->charge_amount->to_major_string() );
			$order->update_meta_data( '_velox_settlement_mode', $settlement->mode );
			$order->save();
		}
	}

	/**
	 * Convert all order line items, shipping, fees, and taxes to the charge currency.
	 *
	 * @param \WC_Order $order            Order instance.
	 * @param string    $display_currency Shopper browsing currency.
	 * @param string    $charge_currency  Gateway settlement currency.
	 * @param float     $cross_rate       Cross-currency exchange rate.
	 * @param float     $markup           Markup buffer percentage.
	 */
	protected function convert_order_line_items(
		\WC_Order $order,
		string $display_currency,
		string $charge_currency,
		float $cross_rate,
		float $markup
	): void {
		// 1. Line items.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			$subtotal = (float) $item->get_subtotal();
			$total    = (float) $item->get_total();

			$item->set_subtotal(
				Money::from_major( $subtotal, $display_currency )
					->convert_to( $charge_currency, $cross_rate, $markup )
					->to_major()
			);
			$item->set_total(
				Money::from_major( $total, $display_currency )
					->convert_to( $charge_currency, $cross_rate, $markup )
					->to_major()
			);

			$taxes = $item->get_taxes();
			if ( ! empty( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
				foreach ( $taxes['total'] as $id => $amt ) {
					$taxes['total'][ $id ] = Money::from_major( $amt, $display_currency )
						->convert_to( $charge_currency, $cross_rate, $markup )
						->to_major();
				}
			}
			if ( ! empty( $taxes['subtotal'] ) && is_array( $taxes['subtotal'] ) ) {
				foreach ( $taxes['subtotal'] as $id => $amt ) {
					$taxes['subtotal'][ $id ] = Money::from_major( $amt, $display_currency )
						->convert_to( $charge_currency, $cross_rate, $markup )
						->to_major();
				}
			}
			$item->set_taxes( $taxes );
		}

		// 2. Shipping items.
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			if ( ! ( $shipping instanceof \WC_Order_Item_Shipping ) ) {
				continue;
			}
			$cost = (float) $shipping->get_total();
			$shipping->set_total(
				Money::from_major( $cost, $display_currency )
					->convert_to( $charge_currency, $cross_rate, $markup )
					->to_major()
			);

			$taxes = $shipping->get_taxes();
			if ( ! empty( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
				foreach ( $taxes['total'] as $id => $amt ) {
					$taxes['total'][ $id ] = Money::from_major( $amt, $display_currency )
						->convert_to( $charge_currency, $cross_rate, $markup )
						->to_major();
				}
			}
			$shipping->set_taxes( $taxes );
		}

		// 3. Fee items.
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( ! ( $fee instanceof \WC_Order_Item_Fee ) ) {
				continue;
			}
			$amount = (float) $fee->get_total();
			$fee->set_total(
				Money::from_major( $amount, $display_currency )
					->convert_to( $charge_currency, $cross_rate, $markup )
					->to_major()
			);

			$taxes = $fee->get_taxes();
			if ( ! empty( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
				foreach ( $taxes['total'] as $id => $amt ) {
					$taxes['total'][ $id ] = Money::from_major( $amt, $display_currency )
						->convert_to( $charge_currency, $cross_rate, $markup )
						->to_major();
				}
			}
			$fee->set_taxes( $taxes );
		}

		// 4. Tax items.
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( ! ( $tax instanceof \WC_Order_Item_Tax ) ) {
				continue;
			}
			$tax_amt  = (float) $tax->get_tax_total();
			$ship_tax = (float) $tax->get_shipping_tax_total();

			$tax->set_tax_total(
				Money::from_major( $tax_amt, $display_currency )
					->convert_to( $charge_currency, $cross_rate, $markup )
					->to_major()
			);
			$tax->set_shipping_tax_total(
				Money::from_major( $ship_tax, $display_currency )
					->convert_to( $charge_currency, $cross_rate, $markup )
					->to_major()
			);
		}
	}

	/**
	 * Add VeloX diagnostics metabox on order details screen.
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
			'velox_order_details',
			__( 'VeloX Multi-Currency & Routing', 'velox-multi-currency' ),
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

		$display_curr  = $order->get_meta( '_velox_display_currency' ) ?: $order->get_currency();
		$display_total = $order->get_meta( '_velox_display_total' ) ?: $order->get_total();
		$charge_curr   = $order->get_meta( '_velox_charge_currency' ) ?: $order->get_currency();
		$charge_total  = $order->get_meta( '_velox_charge_total' ) ?: $order->get_total();
		$base_curr     = $order->get_meta( '_velox_base_currency' ) ?: get_woocommerce_currency();
		$rate          = $order->get_meta( '_velox_exchange_rate' ) ?: '1.0';
		$mode          = $order->get_meta( '_velox_settlement_mode' ) ?: 'allow_native';
		$disclosure    = $order->get_meta( '_velox_disclosure_notice' );

		?>
		<div class="velox-order-details" style="font-size: 13px; line-height: 1.6;">
			<p><strong><?php esc_html_e( 'Display (Shopper):', 'velox-multi-currency' ); ?></strong><br>
				<code><?php echo esc_html( $display_curr . ' ' . $display_total ); ?></code>
			</p>
			<p><strong><?php esc_html_e( 'Settlement (Charged):', 'velox-multi-currency' ); ?></strong><br>
				<code style="color: #2e7d32;"><?php echo esc_html( $charge_curr . ' ' . $charge_total ); ?></code>
			</p>
			<p><strong><?php esc_html_e( 'Store Base Currency:', 'velox-multi-currency' ); ?></strong><br>
				<?php echo esc_html( $base_curr ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Snapshot Rate:', 'velox-multi-currency' ); ?></strong><br>
				1 <?php echo esc_html( $base_curr ); ?> = <?php echo esc_html( $rate . ' ' . $display_curr ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Routing Mode:', 'velox-multi-currency' ); ?></strong><br>
				<span class="badge" style="background:#e0e0e0; padding:2px 6px; border-radius:3px;">
					<?php echo esc_html( strtoupper( str_replace( '_', ' ', $mode ) ) ); ?>
				</span>
			</p>
			<?php if ( ! empty( $disclosure ) ) : ?>
				<p><strong><?php esc_html_e( 'Disclosure Displayed:', 'velox-multi-currency' ); ?></strong><br>
					<em style="font-size: 11px;"><?php echo esc_html( $disclosure ); ?></em>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
