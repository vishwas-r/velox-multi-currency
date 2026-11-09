<?php
/**
 * SettlementResolver executes deterministic settlement calculations for cart/order checkout.
 *
 * @package VeloX\MultiCurrency\GatewayRouter
 */

namespace VeloX\MultiCurrency\GatewayRouter;

use VeloX\MultiCurrency\Currency\CurrencyRegistry;
use VeloX\MultiCurrency\Currency\Money;

defined( 'ABSPATH' ) || exit;

/**
 * SettlementResolver class.
 */
class SettlementResolver {

	/**
	 * GatewayCapabilityRegistry.
	 *
	 * @var GatewayCapabilityRegistry
	 */
	protected GatewayCapabilityRegistry $capability_registry;

	/**
	 * CurrencyRegistry.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $currency_registry;

	/**
	 * Constructor.
	 *
	 * @param GatewayCapabilityRegistry $capability_registry Registry.
	 * @param CurrencyRegistry          $currency_registry   Currency registry.
	 */
	public function __construct( GatewayCapabilityRegistry $capability_registry, CurrencyRegistry $currency_registry ) {
		$this->capability_registry = $capability_registry;
		$this->currency_registry   = $currency_registry;
	}

	/**
	 * Pure resolver: Given display amount, display currency, and gateway ID, returns SettlementResult.
	 *
	 * @param float|int|string $display_total    Total in display currency.
	 * @param string           $display_currency Customer's display currency.
	 * @param string           $gateway_id        Payment gateway ID (e.g. 'stripe', 'ppcp-gateway', 'razorpay').
	 * @return SettlementResult
	 */
	public function resolve( $display_total, string $display_currency, string $gateway_id ): SettlementResult {
		$base_currency    = $this->currency_registry->get_base_currency();
		$display_currency = strtoupper( trim( $display_currency ) );
		$rule             = $this->capability_registry->get_rule( $gateway_id, $display_currency, $base_currency );
		$mode             = $rule['mode'];
		$target_charge    = strtoupper( $rule['convert_to'] ?? $display_currency );

		$display_money = Money::from_major( $display_total, $display_currency );

		// 1. Mode: HIDE.
		if ( SettlementMode::HIDE === $mode ) {
			return new SettlementResult(
				$display_currency,
				$display_money,
				$target_charge,
				$display_money,
				1.0,
				0.0,
				SettlementMode::HIDE,
				false,
				''
			);
		}

		// 2. Mode: ALLOW_NATIVE (Charge directly in the display currency).
		$result = new SettlementResult(
			$display_currency,
			$display_money,
			$display_currency,
			$display_money,
			1.0,
			0.0,
			SettlementMode::ALLOW_NATIVE,
			false,
			''
		);

		/**
		 * Filter settlement result to allow add-ons or extensions to provide custom settlement modes.
		 *
		 * @param SettlementResult $result           Resolved result.
		 * @param float|int|string $display_total    Total in display currency.
		 * @param string           $display_currency Customer's display currency.
		 * @param string           $gateway_id       Payment gateway ID.
		 * @param array            $rule             Gateway rule array.
		 */
		return apply_filters( 'velox_mc_resolve_settlement', $result, $display_total, $display_currency, $gateway_id, $rule );
	}

	/**
	 * Format Money into localized string.
	 *
	 * @param Money $money Money.
	 * @return string
	 */
	public function format_amount( Money $money ): string {
		$symbol = get_woocommerce_currency_symbol( $money->get_currency() );
		return sprintf( '%s%s %s', $symbol, $money->to_major_string(), $money->get_currency() );
	}

	/**
	 * Get readable gateway title.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	public function get_gateway_title( string $gateway_id ): string {
		$gateways = ( function_exists( 'WC' ) && WC()->payment_gateways() ) ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $gateways[ $gateway_id ] ) && ! empty( $gateways[ $gateway_id ]->title ) ) {
			return $gateways[ $gateway_id ]->title;
		}

		$normalized = $this->capability_registry->normalize_gateway_id( $gateway_id );
		return ucfirst( $normalized );
	}
}
