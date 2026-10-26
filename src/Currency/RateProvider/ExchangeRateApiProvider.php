<?php
/**
 * ExchangeRate-API free open endpoint provider.
 *
 * @package NammaWP\MultiCurrency\Currency\RateProvider
 */

namespace NammaWP\MultiCurrency\Currency\RateProvider;

defined( 'ABSPATH' ) || exit;

/**
 * ExchangeRateApiProvider fetches daily exchange rates from the open ExchangeRate-API.
 * Free, zero-configuration, no API key needed. Supports 160+ world currencies.
 */
class ExchangeRateApiProvider implements RateProviderInterface {

	/**
	 * Open endpoint template (no key needed).
	 */
	const API_BASE = 'https://open.er-api.com/v6/latest/';

	/**
	 * Get provider key.
	 *
	 * @return string
	 */
	public function get_key(): string {
		return 'exchangerate_api';
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'ExchangeRate-API (Free, 160+ Currencies, No Key)', 'nammawp-multi-currency' );
	}

	/**
	 * Fetch exchange rates normalized to the given store base currency.
	 *
	 * @param string $base_currency Store base currency (e.g. 'USD', 'INR', 'EUR').
	 * @return array<string, float>|\WP_Error
	 */
	public function fetch_rates( string $base_currency ) {
		$base = strtoupper( trim( $base_currency ) );
		$url  = self::API_BASE . $base;

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent' => 'NammaWP-WooCommerce/' . ( defined( 'NAMMAWP_MC_VERSION' ) ? NAMMAWP_MC_VERSION : '1.0.0' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error(
				'nammawp_erapi_http_error',
				sprintf( 'ExchangeRate-API returned HTTP status %d', $status )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ( isset( $data['result'] ) && 'success' !== $data['result'] ) ) {
			$error_type = $data['error-type'] ?? 'unknown_error';
			return new \WP_Error(
				'nammawp_erapi_api_error',
				sprintf( 'ExchangeRate-API error: %s', sanitize_text_field( $error_type ) )
			);
		}

		$rates_raw = $data['rates'] ?? array();
		if ( empty( $rates_raw ) || ! is_array( $rates_raw ) ) {
			return new \WP_Error( 'nammawp_erapi_empty_rates', 'No exchange rates found in API response' );
		}

		$normalized = array( $base => 1.0 );
		foreach ( $rates_raw as $code => $rate ) {
			$clean_code = strtoupper( sanitize_text_field( $code ) );
			$clean_rate = (float) $rate;
			if ( ! empty( $clean_code ) && $clean_rate > 0 ) {
				$normalized[ $clean_code ] = $clean_rate;
			}
		}

		return $normalized;
	}
}

