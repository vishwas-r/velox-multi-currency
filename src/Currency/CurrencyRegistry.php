<?php
/**
 * Currency Registry for managing supported, enabled, and active currencies.
 *
 * @package VeloX\MultiCurrency\Currency
 */

namespace VeloX\MultiCurrency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * CurrencyRegistry manages ISO-4217 definitions and active customer currency.
 */
class CurrencyRegistry {

	/**
	 * Cookie name for storing active visitor currency.
	 */
	const COOKIE_NAME = 'velox_currency';

	/**
	 * Option name for enabled currencies configuration.
	 */
	const OPTION_ENABLED = 'velox_enabled_currencies';

	/**
	 * Option name for GeoIP country auto-detection.
	 */
	const OPTION_AUTO_GEO = 'velox_auto_detect_geo';

	/**
	 * Currently active currency code in request.
	 *
	 * @var string|null
	 */
	protected ?string $active_currency = null;

	/**
	 * Base store currency code.
	 *
	 * @var string|null
	 */
	protected ?string $base_currency = null;

	/**
	 * Get the store base currency.
	 *
	 * @return string 3-letter ISO code.
	 */
	public function get_base_currency(): string {
		if ( null === $this->base_currency ) {
			$this->base_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		}
		return $this->base_currency;
	}

	/**
	 * Get standard ISO country code to ISO currency code mapping.
	 *
	 * @return array<string, string>
	 */
	public static function get_country_currency_map(): array {
		$map = array(
			// North America
			'US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN',
			// Europe - Eurozone
			'AT' => 'EUR', 'BE' => 'EUR', 'CY' => 'EUR', 'EE' => 'EUR', 'FI' => 'EUR',
			'FR' => 'EUR', 'DE' => 'EUR', 'GR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR',
			'LV' => 'EUR', 'LT' => 'EUR', 'LU' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR',
			'PT' => 'EUR', 'SK' => 'EUR', 'SI' => 'EUR', 'ES' => 'EUR', 'HR' => 'EUR',
			'ME' => 'EUR', 'XK' => 'EUR', 'AD' => 'EUR', 'MC' => 'EUR', 'SM' => 'EUR', 'VA' => 'EUR',
			// Europe - Non-Euro
			'GB' => 'GBP', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK',
			'PL' => 'PLN', 'CZ' => 'CZK', 'HU' => 'HUF', 'RO' => 'RON', 'BG' => 'BGN',
			'IS' => 'ISK', 'RS' => 'RSD', 'AL' => 'ALL', 'BA' => 'BAM', 'MK' => 'MKD',
			'TR' => 'TRY', 'UA' => 'UAH', 'GE' => 'GEL',
			// Asia & Pacific
			'IN' => 'INR', 'JP' => 'JPY', 'CN' => 'CNY', 'HK' => 'HKD', 'TW' => 'TWD',
			'SG' => 'SGD', 'MY' => 'MYR', 'ID' => 'IDR', 'TH' => 'THB', 'PH' => 'PHP',
			'VN' => 'VND', 'KR' => 'KRW', 'AU' => 'AUD', 'NZ' => 'NZD', 'PK' => 'PKR',
			'BD' => 'BDT', 'LK' => 'LKR', 'NP' => 'NPR',
			// Middle East
			'AE' => 'AED', 'SA' => 'SAR', 'QA' => 'QAR', 'KW' => 'KWD', 'BH' => 'BHD',
			'OM' => 'OMR', 'IL' => 'ILS', 'JO' => 'JOD', 'LB' => 'LBP',
			// Africa
			'ZA' => 'ZAR', 'EG' => 'EGP', 'NG' => 'NGN', 'KE' => 'KES', 'GH' => 'GHS',
			'MA' => 'MAD', 'TZ' => 'TZS', 'UG' => 'UGX', 'DZ' => 'DZD',
			// South & Central America
			'BR' => 'BRL', 'AR' => 'ARS', 'CL' => 'CLP', 'CO' => 'COP', 'PE' => 'PEN',
			'UY' => 'UYU', 'CR' => 'CRC', 'PA' => 'PAB', 'GT' => 'GTQ', 'DO' => 'DOP',
		);

		return (array) apply_filters( 'velox_country_currency_map', $map );
	}

	/**
	 * Detect customer currency based on WooCommerce MaxMind Geolocation.
	 *
	 * @return string|null Detected currency code or null.
	 */
	public function detect_currency_from_geolocation(): ?string {
		if ( ! class_exists( '\WC_Geolocation' ) ) {
			return null;
		}

		$ip = \WC_Geolocation::get_ip_address();
		if ( empty( $ip ) ) {
			return null;
		}

		$location = \WC_Geolocation::geolocate_ip( $ip, true, false );
		$country  = ! empty( $location['country'] ) ? strtoupper( trim( $location['country'] ) ) : '';

		if ( empty( $country ) ) {
			return null;
		}

		$map             = self::get_country_currency_map();
		$target_currency = $map[ $country ] ?? null;

		if ( ! empty( $target_currency ) && $this->is_currency_enabled( $target_currency ) ) {
			return strtoupper( $target_currency );
		}

		return null;
	}

	/**
	 * Get the customer's currently active display currency.
	 *
	 * @return string
	 */
	public function get_active_currency(): string {
		if ( null !== $this->active_currency ) {
			return $this->active_currency;
		}

		$base = $this->get_base_currency();

		// 1. Check WooCommerce session first if available.
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_curr = WC()->session->get( 'velox_currency' );
			if ( ! empty( $session_curr ) && $this->is_currency_enabled( (string) $session_curr ) ) {
				$this->active_currency = strtoupper( (string) $session_curr );
				return $this->active_currency;
			}
		}

		// 2. Check for cookie (user's explicit selection).
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$cookie_val = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $this->is_currency_enabled( $cookie_val ) ) {
				$this->active_currency = strtoupper( $cookie_val );
				return $this->active_currency;
			}
		}

		// 3. Built-in GeoIP Country-to-Currency detection (initial visit without cookie/session).
		if ( 'yes' === get_option( self::OPTION_AUTO_GEO, 'yes' ) ) {
			$geo_curr = $this->detect_currency_from_geolocation();
			if ( ! empty( $geo_curr ) && $this->is_currency_enabled( $geo_curr ) ) {
				$this->set_active_currency( $geo_curr );
				return $this->active_currency;
			}
		}

		// 4. Custom filter hook fallback.
		$detected = apply_filters( 'velox_detected_currency', $base );
		if ( $this->is_currency_enabled( $detected ) ) {
			$this->active_currency = strtoupper( $detected );
			return $this->active_currency;
		}

		$this->active_currency = $base;
		return $this->active_currency;
	}

	/**
	 * Set active currency for current session & cookie.
	 *
	 * @param string $currency ISO-4217 currency code.
	 * @return bool True if set successfully.
	 */
	public function set_active_currency( string $currency ): bool {
		$code = strtoupper( trim( $currency ) );
		if ( ! $this->is_currency_enabled( $code ) ) {
			return false;
		}

		$this->active_currency = $code;
		$_COOKIE[ self::COOKIE_NAME ] = $code;

		// Persist in WooCommerce customer session.
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'velox_currency', $code );
		}

		// Set cookie if headers not sent.
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$code,
				time() + ( 30 * DAY_IN_SECONDS ),
				COOKIEPATH ? COOKIEPATH : '/',
				COOKIE_DOMAIN,
				is_ssl(),
				false
			);
		}

		return true;
	}

	/**
	 * Cached enabled currencies.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	protected ?array $enabled_currencies = null;

	/**
	 * Check if a currency code is enabled in VeloX settings.
	 *
	 * @param string $currency ISO code.
	 * @return bool
	 */
	public function is_currency_enabled( string $currency ): bool {
		$code = strtoupper( trim( $currency ) );
		if ( $code === $this->get_base_currency() ) {
			return true;
		}

		$enabled = $this->get_enabled_currencies();
		return isset( $enabled[ $code ] );
	}

	/**
	 * Get array of all enabled currencies with their configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_enabled_currencies(): array {
		if ( null !== $this->enabled_currencies ) {
			return $this->enabled_currencies;
		}

		$base = $this->get_base_currency();
		$all  = $this->get_all_currencies();

		$defaults = array(
			$base => array(
				'code'          => $base,
				'name'          => $all[ $base ]['name'] ?? $base,
				'symbol'        => $all[ $base ]['symbol'] ?? '$',
				'rate'          => 1.0,
				'markup'        => 0.0,
				'rounding'      => 'none',
				'position'      => get_option( 'woocommerce_currency_pos', 'left' ),
				'decimals'      => wc_get_price_decimals(),
				'decimal_sep'   => wc_get_price_decimal_separator(),
				'thousand_sep'  => wc_get_price_thousand_separator(),
				'is_base'       => true,
			),
		);

		$saved = get_option( self::OPTION_ENABLED, array() );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			$this->enabled_currencies = $defaults;
			return $this->enabled_currencies;
		}

		// Ensure clean decoded symbols across all enabled currencies.
		foreach ( $saved as $code => &$data ) {
			if ( ! empty( $data['symbol'] ) ) {
				$data['symbol'] = html_entity_decode( (string) $data['symbol'], ENT_QUOTES, 'UTF-8' );
			}
		}
		unset( $data );

		// Ensure base currency is always present and marked as base.
		$saved[ $base ]            = array_merge( $defaults[ $base ], $saved[ $base ] ?? array() );
		$saved[ $base ]['rate']    = 1.0;
		$saved[ $base ]['is_base'] = true;

		$this->enabled_currencies = $saved;
		return $this->enabled_currencies;
	}

	/**
	 * Save enabled currencies array.
	 *
	 * @param array $currencies Configured currencies.
	 * @return bool
	 */
	public function save_enabled_currencies( array $currencies ): bool {
		$this->enabled_currencies = null;
		return update_option( self::OPTION_ENABLED, $currencies );
	}

	/**
	 * Get full reference list of world currencies (ISO-4217).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_currencies(): array {
		return array(
			'USD' => array( 'name' => 'United States Dollar', 'symbol' => '$', 'decimals' => 2 ),
			'EUR' => array( 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2 ),
			'GBP' => array( 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2 ),
			'INR' => array( 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2 ),
			'CAD' => array( 'name' => 'Canadian Dollar', 'symbol' => 'CA$', 'decimals' => 2 ),
			'AUD' => array( 'name' => 'Australian Dollar', 'symbol' => 'AU$', 'decimals' => 2 ),
			'JPY' => array( 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0 ),
			'SGD' => array( 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimals' => 2 ),
			'AED' => array( 'name' => 'United Arab Emirates Dirham', 'symbol' => 'AED', 'decimals' => 2 ),
			'SAR' => array( 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimals' => 2 ),
			'CHF' => array( 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2 ),
			'CNY' => array( 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimals' => 2 ),
			'HKD' => array( 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'decimals' => 2 ),
			'NZD' => array( 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'decimals' => 2 ),
			'SEK' => array( 'name' => 'Swedish Krona', 'symbol' => 'kr', 'decimals' => 2 ),
			'NOK' => array( 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'decimals' => 2 ),
			'DKK' => array( 'name' => 'Danish Krone', 'symbol' => 'kr', 'decimals' => 2 ),
			'PLN' => array( 'name' => 'Polish Zloty', 'symbol' => 'zł', 'decimals' => 2 ),
			'BRL' => array( 'name' => 'Brazilian Real', 'symbol' => 'R$', 'decimals' => 2 ),
			'MXN' => array( 'name' => 'Mexican Peso', 'symbol' => 'MX$', 'decimals' => 2 ),
			'ZAR' => array( 'name' => 'South African Rand', 'symbol' => 'R', 'decimals' => 2 ),
			'THB' => array( 'name' => 'Thai Baht', 'symbol' => '฿', 'decimals' => 2 ),
			'MYR' => array( 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimals' => 2 ),
			'IDR' => array( 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'decimals' => 2 ),
			'PHP' => array( 'name' => 'Philippine Peso', 'symbol' => '₱', 'decimals' => 2 ),
			'TRY' => array( 'name' => 'Turkish Lira', 'symbol' => '₺', 'decimals' => 2 ),
			'KRW' => array( 'name' => 'South Korean Won', 'symbol' => '₩', 'decimals' => 0 ),
			'KWD' => array( 'name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'decimals' => 3 ),
			'BHD' => array( 'name' => 'Bahraini Dinar', 'symbol' => 'BD', 'decimals' => 3 ),
			'OMR' => array( 'name' => 'Omani Rial', 'symbol' => 'OMR', 'decimals' => 3 ),
		);
	}
}
