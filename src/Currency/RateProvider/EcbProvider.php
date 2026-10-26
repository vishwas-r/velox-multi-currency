<?php
/**
 * European Central Bank (ECB) exchange rate provider.
 *
 * @package VeloX\MultiCurrency\Currency\RateProvider
 */

namespace VeloX\MultiCurrency\Currency\RateProvider;

defined( 'ABSPATH' ) || exit;

/**
 * EcbProvider fetches daily reference exchange rates from the European Central Bank.
 * Free, zero-configuration, no API key needed. Supports cross-rate triangulation for all published currencies.
 */
class EcbProvider implements RateProviderInterface {

	/**
	 * ECB daily XML endpoint.
	 */
	const FEED_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/**
	 * Get provider identifier key.
	 *
	 * @return string
	 */
	public function get_key(): string {
		return 'ecb';
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'European Central Bank (Official Feed, Free)', 'velox-multi-currency' );
	}

	/**
	 * Fetch exchange rates normalized to the given store base currency.
	 *
	 * @param string $base_currency Store base currency (e.g. 'USD', 'EUR', 'INR').
	 * @return array<string, float>|\WP_Error
	 */
	public function fetch_rates( string $base_currency ) {
		$response = wp_remote_get(
			self::FEED_URL,
			array(
				'timeout'    => 15,
				'sslverify'  => (bool) apply_filters( 'velox_mc_ssl_verify', true ),
				'user-agent' => 'VeloX-WooCommerce/' . ( defined( 'VELOX_MC_VERSION' ) ? VELOX_MC_VERSION : '1.0.0' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error(
				'velox_ecb_http_error',
				sprintf( 'ECB returned HTTP status code %d', $status )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new \WP_Error( 'velox_ecb_empty_body', 'ECB response was empty' );
		}

		// Disable external entity loading for XML safety.
		$prev_entity_loader = libxml_disable_entity_loader( true ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		$xml                = simplexml_load_string( $body );
		if ( false !== $prev_entity_loader ) {
			libxml_disable_entity_loader( $prev_entity_loader ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		}

		if ( ! $xml ) {
			return new \WP_Error( 'velox_ecb_xml_error', 'Failed to parse ECB XML' );
		}

		// Rates from ECB are relative to EUR (1 EUR = rate).
		$eur_rates = array( 'EUR' => 1.0 );

		// Register namespace for reliable XPath extraction.
		$xml->registerXPathNamespace( 'ecb', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref' );
		$cubes = $xml->xpath( '//ecb:Cube[@currency]' );

		if ( is_array( $cubes ) ) {
			foreach ( $cubes as $cube ) {
				$code = strtoupper( (string) $cube['currency'] );
				$rate = (float) $cube['rate'];
				if ( ! empty( $code ) && $rate > 0 ) {
					$eur_rates[ $code ] = $rate;
				}
			}
		}

		$base = strtoupper( trim( $base_currency ) );

		// If base is EUR, return directly.
		if ( 'EUR' === $base ) {
			return $eur_rates;
		}

		// Cross-rate calculation if base is not EUR:
		// 1 EUR = $base_rate BASE => 1 BASE = 1 / $base_rate EUR.
		if ( empty( $eur_rates[ $base ] ) || $eur_rates[ $base ] <= 0 ) {
			return new \WP_Error(
				'velox_base_not_in_ecb',
				sprintf(
					/* translators: %s: Currency code */
					__( 'Base currency %s is not in the ECB daily reference feed. Please select ExchangeRate-API or Frankfurter which support all world currencies.', 'velox-multi-currency' ),
					$base
				)
			);
		}

		$base_eur_rate = $eur_rates[ $base ];
		$normalized    = array( $base => 1.0 );

		foreach ( $eur_rates as $target => $eur_rate ) {
			if ( $target === $base ) {
				continue;
			}
			// 1 BASE = (eur_rate / base_eur_rate) TARGET.
			$normalized[ $target ] = (float) ( $eur_rate / $base_eur_rate );
		}

		return $normalized;
	}
}

