<?php
/**
 * Interface for exchange rate providers.
 *
 * @package SettleX\MultiCurrency\Currency\RateProvider
 */

namespace SettleX\MultiCurrency\Currency\RateProvider;

defined( 'ABSPATH' ) || exit;

/**
 * RateProviderInterface.
 */
interface RateProviderInterface {

	/**
	 * Get the machine-readable provider key.
	 *
	 * @return string
	 */
	public function get_key(): string;

	/**
	 * Get the human-readable provider name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Fetch exchange rates for the given base currency.
	 *
	 * Returns an associative array of target_currency => rate float.
	 * (e.g. ['EUR' => 0.92, 'INR' => 84.15] when base is USD).
	 *
	 * @param string $base_currency 3-letter ISO code.
	 * @return array<string, float>|\WP_Error
	 */
	public function fetch_rates( string $base_currency );
}
