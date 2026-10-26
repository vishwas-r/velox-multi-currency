<?php
/**
 * Gateway capability registry with built-in profiles for Stripe, PayPal, and Razorpay.
 *
 * @package NammaWP\MultiCurrency\GatewayRouter
 */

namespace NammaWP\MultiCurrency\GatewayRouter;

defined( 'ABSPATH' ) || exit;

/**
 * GatewayCapabilityRegistry manages supported currencies and custom admin rules for gateways.
 */
class GatewayCapabilityRegistry {

	/**
	 * Option name for storing admin matrix rules.
	 */
	const OPTION_MATRIX = 'nammawp_mc_gateway_matrix';

	/**
	 * Option name for dedicated currency to gateway mapping.
	 */
	const OPTION_DEDICATED_ROUTING = 'nammawp_mc_dedicated_gateway_routing';

	/**
	 * PayPal official 25 supported currencies list.
	 *
	 * @var array<string>
	 */
	protected static array $paypal_supported_currencies = array(
		'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF',
		'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN',
		'GBP', 'RUB', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
	);

	/**
	 * Currencies with 3 decimal places (rejected by PayPal and certain card processors).
	 *
	 * @var array<string>
	 */
	protected static array $three_decimal_currencies = array(
		'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
	);

	/**
	 * Get the routing rule for a specific gateway and currency.
	 *
	 * @param string $gateway_id Gateway ID (e.g. 'stripe', 'ppcp-gateway', 'razorpay').
	 * @param string $currency   3-letter ISO code.
	 * @param string $base_currency Store base currency fallback.
	 * @return array{mode: string, convert_to: string}
	 */
	public function get_rule( string $gateway_id, string $currency, string $base_currency = 'USD' ): array {
		$currency = strtoupper( trim( $currency ) );
		$matrix   = $this->get_saved_matrix();

		// Check if admin has explicitly configured this gateway + currency pair.
		if ( isset( $matrix[ $gateway_id ][ $currency ] ) ) {
			$rule = $matrix[ $gateway_id ][ $currency ];
			if ( ! empty( $rule['mode'] ) && SettlementMode::is_valid( $rule['mode'] ) ) {
				return array(
					'mode'       => $rule['mode'],
					'convert_to' => ! empty( $rule['convert_to'] ) ? strtoupper( $rule['convert_to'] ) : $base_currency,
				);
			}
		}

		// Fall back to intelligent gateway capability defaults.
		return $this->get_default_gateway_rule( $gateway_id, $currency, $base_currency );
	}

	/**
	 * Get intelligent default routing rule for a gateway based on its capability profile.
	 *
	 * @param string $gateway_id    Gateway ID.
	 * @param string $currency      Active currency.
	 * @param string $base_currency Base store currency.
	 * @return array{mode: string, convert_to: string}
	 */
	public function get_default_gateway_rule( string $gateway_id, string $currency, string $base_currency ): array {
		$clean_id = $this->normalize_gateway_id( $gateway_id );

		switch ( $clean_id ) {
			case 'stripe':
				// Stripe supports 135+ currencies natively.
				// However, if currency is a 3-decimal currency, Stripe rejects or requires rounding.
				if ( in_array( $currency, self::$three_decimal_currencies, true ) ) {
					return array(
						'mode'       => SettlementMode::CONVERT_DISCLOSE,
						'convert_to' => 'USD',
					);
				}
				return array(
					'mode'       => SettlementMode::ALLOW_NATIVE,
					'convert_to' => $currency,
				);

			case 'paypal':
				// PayPal: Only the 25 official currencies are supported natively.
				// Explicitly NO domestic INR and NO 3-decimal currencies!
				if ( in_array( $currency, self::$paypal_supported_currencies, true ) ) {
					return array(
						'mode'       => SettlementMode::ALLOW_NATIVE,
						'convert_to' => $currency,
					);
				}
				// If unsupported (e.g. INR), default to Hide to avoid checkout failure.
				return array(
					'mode'       => SettlementMode::HIDE,
					'convert_to' => in_array( $base_currency, self::$paypal_supported_currencies, true ) ? $base_currency : 'USD',
				);

			case 'razorpay':
				// Razorpay: Native and primary settlement currency is INR.
				if ( 'INR' === $currency ) {
					return array(
						'mode'       => SettlementMode::ALLOW_NATIVE,
						'convert_to' => 'INR',
					);
				}
				// For non-INR, default to Convert and Disclose into INR so Indian merchants can still receive funds.
				return array(
					'mode'       => SettlementMode::CONVERT_DISCLOSE,
					'convert_to' => 'INR',
				);

			default:
				// For other generic gateways, allow native if same as base currency, otherwise allow native by default.
				return array(
					'mode'       => SettlementMode::ALLOW_NATIVE,
					'convert_to' => $currency,
				);
		}
	}

	/**
	 * Normalize gateway ID to identify primary gateway families (Stripe, PayPal, Razorpay).
	 *
	 * @param string $gateway_id WooCommerce payment method ID.
	 * @return string Normalized key.
	 */
	public function normalize_gateway_id( string $gateway_id ): string {
		$id = strtolower( trim( $gateway_id ) );

		if ( 0 === strpos( $id, 'stripe' ) ) {
			return 'stripe';
		}

		if ( 0 === strpos( $id, 'ppcp' ) || 0 === strpos( $id, 'paypal' ) ) {
			return 'paypal';
		}

		if ( 0 === strpos( $id, 'razorpay' ) ) {
			return 'razorpay';
		}

		return $id;
	}

	/**
	 * Get stored admin matrix from options.
	 *
	 * @return array<string, array<string, array{mode: string, convert_to?: string}>>
	 */
	public function get_saved_matrix(): array {
		$matrix = get_option( self::OPTION_MATRIX, array() );
		return is_array( $matrix ) ? $matrix : array();
	}

	/**
	 * Save updated matrix configuration.
	 *
	 * @param array $matrix Matrix rules.
	 * @return bool
	 */
	public function save_matrix( array $matrix ): bool {
		return update_option( self::OPTION_MATRIX, $matrix );
	}

	/**
	 * Get saved dedicated gateway routing rules.
	 *
	 * @return array<string, array{mode: string, allowed_gateways: array<string>}>
	 */
	public function get_dedicated_routing_rules(): array {
		$rules = get_option( self::OPTION_DEDICATED_ROUTING, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Get dedicated routing rule for a specific currency.
	 *
	 * @param string $currency Currency code.
	 * @return array{mode: string, allowed_gateways: array<string>}|null
	 */
	public function get_dedicated_routing( string $currency ): ?array {
		$rules = $this->get_dedicated_routing_rules();
		$code  = strtoupper( trim( $currency ) );
		return $rules[ $code ] ?? null;
	}

	/**
	 * Save dedicated gateway routing rules.
	 *
	 * @param array $rules Dedicated rules.
	 * @return bool
	 */
	public function save_dedicated_routing_rules( array $rules ): bool {
		return update_option( self::OPTION_DEDICATED_ROUTING, $rules );
	}
}
