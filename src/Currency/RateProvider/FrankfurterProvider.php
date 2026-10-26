<?php
/**
 * Frankfurter open-source exchange rate provider.
 *
 * @package SettleX\MultiCurrency\Currency\RateProvider
 */

namespace SettleX\MultiCurrency\Currency\RateProvider;

defined( 'ABSPATH' ) || exit;

/**
 * FrankfurterProvider fetches daily reference exchange rates from the Frankfurter open-source API.
 * Free, zero-configuration, no API key needed. Powered by ECB data with full cross-currency support.
 */
class FrankfurterProvider implements RateProviderInterface {

	/**
	 * Frankfurter API endpoint.
	 */
	const API_BASE = 'https://api.frankfurter.dev/v1/latest';

	/**
	 * Get provider key.
	 *
	 * @return string
	 */
	public function get_key(): string {
		return 'frankfurter';
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Frankfurter (Free, Open-Source ECB Triangulated)', 'settlex-multi-currency' );
	}

	/**
	 * Fetch exchange rates normalized to the given store base currency.
	 *
	 * @param string $base_currency Store base currency (e.g. 'USD', 'INR', 'EUR').
	 * @return array<string, float>|\WP_Error
	 */
	public function fetch_rates( string $base_currency ) {
		$base = strtoupper( trim( $base_currency ) );
		$url  = add_query_arg( 'base', $base, self::API_BASE );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent' => 'SettleX-WooCommerce/' . ( defined( 'SETTLEX_VERSION' ) ? SETTLEX_VERSION : '1.0.0' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error(
				'settlex_frankfurter_http_error',
				sprintf( 'Frankfurter returned HTTP status %d', $status )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['rates'] ) ) {
			return new \WP_Error( 'settlex_frankfurter_invalid_body', 'Invalid response from Frankfurter API' );
		}

		$normalized          = array( $base => 1.0 );
		$rates_raw           = (array) $data['rates'];

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
