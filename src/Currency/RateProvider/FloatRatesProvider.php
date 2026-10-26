<?php
/**
 * FloatRates daily exchange rate provider.
 *
 * @package NammaWP\MultiCurrency\Currency\RateProvider
 */

namespace NammaWP\MultiCurrency\Currency\RateProvider;

defined( 'ABSPATH' ) || exit;

/**
 * FloatRatesProvider fetches daily exchange rates from FloatRates JSON feeds.
 * Free, zero-configuration, no API key needed. Supports 140+ world currencies.
 */
class FloatRatesProvider implements RateProviderInterface {

	/**
	 * FloatRates daily JSON template.
	 */
	const FEED_BASE = 'https://www.floatrates.com/daily/';

	/**
	 * Get provider key.
	 *
	 * @return string
	 */
	public function get_key(): string {
		return 'floatrates';
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'FloatRates (Free Daily Feed, 140+ Currencies)', 'nammawp-multi-currency' );
	}

	/**
	 * Fetch exchange rates normalized to the given store base currency.
	 *
	 * @param string $base_currency Store base currency (e.g. 'USD', 'INR', 'EUR').
	 * @return array<string, float>|\WP_Error
	 */
	public function fetch_rates( string $base_currency ) {
		$base = strtoupper( trim( $base_currency ) );
		$url  = self::FEED_BASE . strtolower( $base ) . '.json';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'sslverify'  => (bool) apply_filters( 'nammawp_mc_ssl_verify', true ),
				'user-agent' => 'NammaWP-WooCommerce/' . ( defined( 'NAMMAWP_MC_VERSION' ) ? NAMMAWP_MC_VERSION : '1.0.0' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error(
				'nammawp_floatrates_http_error',
				sprintf( 'FloatRates returned HTTP status %d', $status )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data ) ) {
			return new \WP_Error( 'nammawp_floatrates_empty', 'FloatRates returned empty or invalid data' );
		}

		$normalized = array( $base => 1.0 );

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$code = strtoupper( sanitize_text_field( $entry['code'] ?? '' ) );
			$rate = (float) ( $entry['rate'] ?? 0 );
			if ( ! empty( $code ) && $rate > 0 ) {
				$normalized[ $code ] = $rate;
			}
		}

		return $normalized;
	}
}

