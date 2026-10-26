<?php
/**
 * RateService manages exchange rate fetching, multi-provider scheduling, and caching.
 *
 * @package NammaWP\MultiCurrency\Currency
 */

namespace NammaWP\MultiCurrency\Currency;

use NammaWP\MultiCurrency\Currency\RateProvider\EcbProvider;
use NammaWP\MultiCurrency\Currency\RateProvider\ExchangeRateApiProvider;
use NammaWP\MultiCurrency\Currency\RateProvider\FloatRatesProvider;
use NammaWP\MultiCurrency\Currency\RateProvider\FrankfurterProvider;
use NammaWP\MultiCurrency\Currency\RateProvider\RateProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * RateService class.
 */
class RateService {

	/**
	 * Action hook for scheduled sync.
	 */
	const CRON_HOOK = 'nammawp_mc_daily_rate_sync';

	/**
	 * Option key for last sync timestamp.
	 */
	const OPTION_LAST_SYNC = 'nammawp_mc_last_rate_sync';

	/**
	 * Option key for active rate provider.
	 */
	const OPTION_PROVIDER = 'nammawp_mc_rate_provider';

	/**
	 * CurrencyRegistry instance.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $registry;

	/**
	 * Registered rate providers.
	 *
	 * @var array<string, RateProviderInterface>
	 */
	protected array $providers = array();

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry $registry Registry.
	 */
	public function __construct( CurrencyRegistry $registry ) {
		$this->registry = $registry;
		$this->register_default_providers();
	}

	/**
	 * Register built-in providers.
	 */
	protected function register_default_providers(): void {
		$this->register_provider( new ExchangeRateApiProvider() );
		$this->register_provider( new FrankfurterProvider() );
		$this->register_provider( new FloatRatesProvider() );
		$this->register_provider( new EcbProvider() );
	}

	/**
	 * Register a provider.
	 *
	 * @param RateProviderInterface $provider Provider instance.
	 */
	public function register_provider( RateProviderInterface $provider ): void {
		$this->providers[ $provider->get_key() ] = $provider;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array<string, RateProviderInterface>
	 */
	public function get_available_providers(): array {
		return $this->providers;
	}

	/**
	 * Get the currently active provider key.
	 *
	 * @return string Default 'exchangerate_api'.
	 */
	public function get_active_provider_key(): string {
		$saved = get_option( self::OPTION_PROVIDER, 'exchangerate_api' );
		if ( isset( $this->providers[ $saved ] ) ) {
			return $saved;
		}
		return 'exchangerate_api';
	}

	/**
	 * Get a provider by key.
	 *
	 * @param string $key Provider key.
	 * @return RateProviderInterface|null
	 */
	public function get_provider( string $key ): ?RateProviderInterface {
		return $this->providers[ $key ] ?? null;
	}

	/**
	 * Get the currently active provider instance.
	 *
	 * @return RateProviderInterface
	 */
	public function get_active_provider(): RateProviderInterface {
		$key = $this->get_active_provider_key();
		return $this->providers[ $key ] ?? new ExchangeRateApiProvider();
	}

	/**
	 * Set active provider key.
	 *
	 * @param string $key Provider key.
	 * @return bool True if saved.
	 */
	public function set_active_provider( string $key ): bool {
		if ( ! isset( $this->providers[ $key ] ) ) {
			return false;
		}
		return update_option( self::OPTION_PROVIDER, $key );
	}

	/**
	 * Initialize schedule hooks.
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, array( $this, 'sync_rates' ) );

		// Register schedule if not already registered.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Sync rates from the provider and update enabled currencies.
	 *
	 * @param string|null $provider_key Optional specific provider to sync from.
	 * @return array{count: int, provider: string}|\WP_Error Returns summary or WP_Error.
	 */
	public function sync_rates( ?string $provider_key = null ) {
		if ( ! empty( $provider_key ) && isset( $this->providers[ $provider_key ] ) ) {
			$provider = $this->providers[ $provider_key ];
			$this->set_active_provider( $provider_key );
		} else {
			$provider = $this->get_active_provider();
		}

		$base_currency = $this->registry->get_base_currency();
		$rates         = $provider->fetch_rates( $base_currency );

		if ( is_wp_error( $rates ) ) {
			return $rates;
		}

		$enabled       = $this->registry->get_enabled_currencies();
		$updated_count = 0;

		foreach ( $enabled as $code => $data ) {
			if ( $code === $base_currency ) {
				$enabled[ $code ]['rate'] = 1.0;
				continue;
			}

			if ( isset( $rates[ $code ] ) && $rates[ $code ] > 0 ) {
				$enabled[ $code ]['rate']          = (float) $rates[ $code ];
				$enabled[ $code ]['synced_at']     = time();
				$enabled[ $code ]['rate_provider'] = $provider->get_key();
				$updated_count++;
			}
		}

		$this->registry->save_enabled_currencies( $enabled );
		update_option( self::OPTION_LAST_SYNC, time() );

		return array(
			'count'    => $updated_count,
			'provider' => $provider->get_name(),
		);
	}

	/**
	 * Get timestamp of last successful sync.
	 *
	 * @return int 0 if never synced.
	 */
	public function get_last_sync_timestamp(): int {
		return (int) get_option( self::OPTION_LAST_SYNC, 0 );
	}

	/**
	 * Check if rates are stale (older than 48 hours).
	 *
	 * @return bool
	 */
	public function are_rates_stale(): bool {
		$last = $this->get_last_sync_timestamp();
		if ( 0 === $last ) {
			return true;
		}
		return ( time() - $last ) > ( 48 * HOUR_IN_SECONDS );
	}
}
