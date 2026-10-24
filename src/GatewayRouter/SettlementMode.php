<?php
/**
 * SettlementMode constants.
 *
 * @package SettleX\MultiCurrency\GatewayRouter
 */

namespace SettleX\MultiCurrency\GatewayRouter;

defined( 'ABSPATH' ) || exit;

/**
 * SettlementMode defines the three gateway-currency routing behaviors.
 */
class SettlementMode {

	/**
	 * Charge the gateway natively in the customer's chosen display currency.
	 */
	const ALLOW_NATIVE = 'allow_native';

	/**
	 * Keep customer browsing in display currency, but settle & charge in a fixed
	 * gateway-supported currency (e.g. charge USD via PayPal while cart is EUR)
	 * with an explicit disclosure notice.
	 */
	const CONVERT_DISCLOSE = 'convert_disclose';

	/**
	 * Suppress and hide the gateway completely when this currency is active.
	 */
	const HIDE = 'hide';

	/**
	 * Check if a mode string is valid.
	 *
	 * @param string $mode Mode to check.
	 * @return bool
	 */
	public static function is_valid( string $mode ): bool {
		return in_array(
			$mode,
			array(
				self::ALLOW_NATIVE,
				self::CONVERT_DISCLOSE,
				self::HIDE,
			),
			true
		);
	}
}
