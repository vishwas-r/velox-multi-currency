<?php
/**
 * Money value object with ISO-4217 minor unit representation.
 *
 * @package NammaWP\MultiCurrency\Currency
 */

namespace NammaWP\MultiCurrency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Money class for safe currency math and zero-guard rounding.
 */
class Money {

	/**
	 * Amount in currency minor units (e.g. cents, pence, paise).
	 *
	 * @var int
	 */
	protected int $minor_amount;

	/**
	 * 3-letter ISO-4217 currency code.
	 *
	 * @var string
	 */
	protected string $currency;

	/**
	 * Minor unit multiplier lookup table (10^decimals).
	 *
	 * @var array<string, int>
	 */
	protected static array $currency_decimals = array(
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'MGA' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
	);

	/**
	 * Constructor.
	 *
	 * @param int    $minor_amount Subunit integer.
	 * @param string $currency     ISO-4217 uppercase currency code.
	 */
	public function __construct( int $minor_amount, string $currency ) {
		$this->minor_amount = $minor_amount;
		$this->currency     = strtoupper( trim( $currency ) );
	}

	/**
	 * Get decimal places for a currency.
	 *
	 * @param string $currency ISO-4217 code.
	 * @return int
	 */
	public static function get_decimals( string $currency ): int {
		$code = strtoupper( trim( $currency ) );
		return self::$currency_decimals[ $code ] ?? 2;
	}

	/**
	 * Get scale divisor (10^decimals).
	 *
	 * @param string $currency ISO-4217 code.
	 * @return int
	 */
	public static function get_multiplier( string $currency ): int {
		return (int) ( 10 ** self::get_decimals( $currency ) );
	}

	/**
	 * Create Money from major currency float/string (e.g. 19.99 USD -> 1999 minor).
	 *
	 * @param float|int|string $amount   Major unit amount.
	 * @param string           $currency ISO-4217 code.
	 * @return self
	 */
	public static function from_major( $amount, string $currency ): self {
		$multiplier   = self::get_multiplier( $currency );
		$float_amount = (float) $amount;
		$minor        = (int) round( $float_amount * $multiplier );
		return new self( $minor, $currency );
	}

	/**
	 * Create Money from minor unit integer (e.g. 1999 cents -> 19.99 USD).
	 *
	 * @param int    $minor_amount Minor units.
	 * @param string $currency     ISO-4217 code.
	 * @return self
	 */
	public static function from_minor( int $minor_amount, string $currency ): self {
		return new self( $minor_amount, $currency );
	}

	/**
	 * Get raw minor amount.
	 *
	 * @return int
	 */
	public function get_minor(): int {
		return $this->minor_amount;
	}

	/**
	 * Get ISO currency code.
	 *
	 * @return string
	 */
	public function get_currency(): string {
		return $this->currency;
	}

	/**
	 * Convert to major unit float (e.g. 19.99).
	 *
	 * @return float
	 */
	public function to_major(): float {
		return $this->minor_amount / self::get_multiplier( $this->currency );
	}

	/**
	 * Convert to formatted decimal string (e.g. "19.99").
	 *
	 * @return string
	 */
	public function to_major_string(): string {
		$decimals = self::get_decimals( $this->currency );
		return number_format( $this->to_major(), $decimals, '.', '' );
	}

	/**
	 * Add money of the same currency.
	 *
	 * @param self $other Money instance.
	 * @return self
	 * @throws \InvalidArgumentException When currencies do not match.
	 */
	public function add( self $other ): self {
		$this->assert_same_currency( $other );
		return new self( $this->minor_amount + $other->get_minor(), $this->currency );
	}

	/**
	 * Subtract money of the same currency.
	 *
	 * @param self $other Money instance.
	 * @return self
	 * @throws \InvalidArgumentException When currencies do not match.
	 */
	public function subtract( self $other ): self {
		$this->assert_same_currency( $other );
		return new self( $this->minor_amount - $other->get_minor(), $this->currency );
	}

	/**
	 * Multiply by rate or quantity with zero-total guard.
	 *
	 * @param float|int $multiplier Rate or quantity.
	 * @param bool      $zero_guard If true, non-zero original price will never round down to 0 minor units.
	 * @return self
	 */
	public function multiply( $multiplier, bool $zero_guard = true ): self {
		$result = (int) round( $this->minor_amount * (float) $multiplier );

		// Strict zero-guard: if original amount was positive and multiplier > 0, never round to 0!
		if ( $zero_guard && $this->minor_amount > 0 && (float) $multiplier > 0 && $result <= 0 ) {
			$result = 1; // Minimum 1 minor unit (e.g. 0.01).
		}

		return new self( $result, $this->currency );
	}

	/**
	 * Convert this Money object to another currency using an exchange rate.
	 *
	 * @param string    $target_currency Target ISO code.
	 * @param float|int $exchange_rate   Rate (1 unit of current currency = X units of target).
	 * @param float     $markup_percent  Optional buffer markup (e.g. 2.5 for +2.5%).
	 * @return self
	 */
	public function convert_to( string $target_currency, $exchange_rate, float $markup_percent = 0.0 ): self {
		$effective_rate = (float) $exchange_rate;
		if ( $markup_percent > 0 ) {
			$effective_rate *= ( 1 + ( $markup_percent / 100 ) );
		}

		$major_amount = $this->to_major();
		$target_major = $major_amount * $effective_rate;

		// Convert directly to target currency with zero-guard.
		$target_money = self::from_major( $target_major, $target_currency );
		if ( $this->minor_amount > 0 && $target_money->get_minor() <= 0 ) {
			return new self( 1, $target_currency );
		}

		return $target_money;
	}

	/**
	 * Apply psychological or charm rounding rules.
	 *
	 * Supported rules:
	 * - 'none': Standard decimal rounding.
	 * - 'charm_99': Round to nearest .99 (e.g. 19.45 -> 19.99).
	 * - 'charm_95': Round to nearest .95 (e.g. 19.45 -> 19.95).
	 * - 'round_5': Round to nearest 5 units (e.g. 12 -> 15).
	 * - 'round_10': Round to nearest 10 units (e.g. 92 -> 90 or 100).
	 * - 'ceil_int': Round up to integer (e.g. 19.20 -> 20.00).
	 *
	 * @param string $rule Rounding rule name.
	 * @return self
	 */
	public function apply_rounding( string $rule ): self {
		if ( 'none' === $rule || empty( $rule ) ) {
			return $this;
		}

		$major    = $this->to_major();
		$decimals = self::get_decimals( $this->currency );

		// If 0-decimal currency, charm cents do not apply; use integer rounding.
		if ( 0 === $decimals ) {
			switch ( $rule ) {
				case 'round_5':
					$major = round( $major / 5 ) * 5;
					break;
				case 'round_10':
					$major = round( $major / 10 ) * 10;
					break;
				case 'ceil_int':
				default:
					$major = ceil( $major );
					break;
			}
			return self::from_major( max( 1, $major ), $this->currency );
		}

		switch ( $rule ) {
			case 'charm_99':
				$floor = floor( $major );
				$major = ( $major - $floor > 0.0 ) ? $floor + 0.99 : $major;
				break;
			case 'charm_95':
				$floor = floor( $major );
				$major = ( $major - $floor > 0.0 ) ? $floor + 0.95 : $major;
				break;
			case 'ceil_int':
				$major = ceil( $major );
				break;
			case 'round_5':
				$major = round( $major / 5 ) * 5;
				break;
			case 'round_10':
				$major = round( $major / 10 ) * 10;
				break;
		}

		$result = self::from_major( $major, $this->currency );
		if ( $this->minor_amount > 0 && $result->get_minor() <= 0 ) {
			return new self( 1, $this->currency );
		}

		return $result;
	}

	/**
	 * Assert currencies match before arithmetic.
	 *
	 * @param self $other Other Money.
	 * @throws \InvalidArgumentException If currencies differ.
	 */
	protected function assert_same_currency( self $other ): void {
		if ( $this->currency !== $other->get_currency() ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Cannot operate on mismatched currencies: %s and %s',
					$this->currency,
					$other->get_currency()
				)
			);
		}
	}
}
