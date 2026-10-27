<?php
/**
 * RateService manages exchange rate fetching, multi-provider scheduling, and caching.
 *
 * @package VeloX\MultiCurrency\Currency
 */

namespace VeloX\MultiCurrency\Currency;

use VeloX\MultiCurrency\Currency\RateProvider\EcbProvider;
use VeloX\MultiCurrency\Currency\RateProvider\ExchangeRateApiProvider;
use VeloX\MultiCurrency\Currency\RateProvider\FloatRatesProvider;
use VeloX\MultiCurrency\Currency\RateProvider\FrankfurterProvider;
use VeloX\MultiCurrency\Currency\RateProvider\RateProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * RateService class.
 */
class RateService {

	/**
	 * Action hook for scheduled sync.
	 */
	const CRON_HOOK = 'velox_mc_daily_rate_sync';

	/**
	 * Option key for last sync timestamp.
	 */
	const OPTION_LAST_SYNC = 'velox_mc_last_rate_sync';

	/**
	 * Option key for active rate provider.
	 */
	const OPTION_PROVIDER = 'velox_mc_rate_provider';

	/**
	 * Option key for auto-sync settings (enabled, interval_hours).
	 */
	const OPTION_AUTO_SYNC = 'velox_mc_auto_sync_settings';

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
		// European Central Bank is the official, zero-key free provider.
		$this->register_provider( new EcbProvider() );

		// Register additional multi-provider cascade in Pro mode or via filter.
		$extra_providers = (array) apply_filters( 'velox_mc_extra_rate_providers', array() );
		if ( velox_mc_is_pro() || ! empty( $extra_providers ) ) {
			$this->register_provider( new ExchangeRateApiProvider() );
			$this->register_provider( new FrankfurterProvider() );
			$this->register_provider( new FloatRatesProvider() );
		}
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
	 * @return string Default 'ecb'.
	 */
	public function get_active_provider_key(): string {
		$default = velox_mc_is_pro() ? 'exchangerate_api' : 'ecb';
		$saved   = get_option( self::OPTION_PROVIDER, $default );
		if ( isset( $this->providers[ $saved ] ) ) {
			return $saved;
		}
		return isset( $this->providers['ecb'] ) ? 'ecb' : (string) array_key_first( $this->providers );
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
	 * Get auto-sync settings with defaults.
	 *
	/**
	 * Get allowed auto-sync intervals in hours.
	 *
	 * Free tier: 12, 24 hours.
	 * Pro tier: 1, 2, 4, 6, 8, 12, 24, 48, 72, 168 hours, and custom hours.
	 *
	 * @return array<int>
	 */
	public function get_allowed_sync_intervals(): array {
		$default = velox_mc_is_pro() ? array( 1, 2, 4, 6, 8, 12, 24, 48, 72, 168 ) : array( 12, 24 );
		return (array) apply_filters( 'velox_mc_sync_intervals', $default );
	}

	/**
	 * Get auto-sync settings with defaults.
	 *
	 * @return array{enabled: string, interval_hours: int}
	 */
	public function get_auto_sync_settings(): array {
		$defaults = array(
			'enabled'        => 'yes',
			'interval_hours' => 12,
		);

		$saved = get_option( self::OPTION_AUTO_SYNC, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, $defaults );

		// Enforce Free tier limit if Pro is not active
		if ( ! velox_mc_is_pro() ) {
			$allowed = $this->get_allowed_sync_intervals();
			if ( ! in_array( (int) $settings['interval_hours'], $allowed, true ) ) {
				$settings['interval_hours'] = 12;
			}
		}

		return $settings;
	}

	/**
	 * Save auto-sync settings and synchronize WP Cron events.
	 *
	 * @param array $settings Settings array.
	 * @return bool True if updated or unchanged.
	 */
	public function save_auto_sync_settings( array $settings ): bool {
		$raw_hours = (int) ( $settings['interval_hours'] ?? 12 );
		$allowed   = $this->get_allowed_sync_intervals();

		if ( ! velox_mc_is_pro() && ! in_array( $raw_hours, $allowed, true ) ) {
			$raw_hours = 12;
		}

		$clean = array(
			'enabled'        => ( ! empty( $settings['enabled'] ) && in_array( $settings['enabled'], array( 'yes', '1', true, 1 ), true ) ) ? 'yes' : 'no',
			'interval_hours' => max( 1, min( 720, $raw_hours ) ),
		);

		$updated = update_option( self::OPTION_AUTO_SYNC, $clean );

		// Clear existing scheduled events for this hook
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}

		// Schedule fresh if enabled
		if ( 'yes' === $clean['enabled'] ) {
			$schedule_key = $this->get_schedule_key( $clean['interval_hours'] );
			wp_schedule_event( time() + ( $clean['interval_hours'] * HOUR_IN_SECONDS ), $schedule_key, self::CRON_HOOK );
		}

		return (bool) $updated;
	}

	/**
	 * Get schedule key for a given interval in hours.
	 *
	 * @param int $hours Hours interval.
	 * @return string Schedule identifier.
	 */
	public function get_schedule_key( int $hours ): string {
		return 'velox_every_' . max( 1, $hours ) . '_hours';
	}

	/**
	 * Register custom WP Cron schedules for every X hours.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Cron schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function filter_cron_schedules( array $schedules ): array {
		$settings       = $this->get_auto_sync_settings();
		$interval_hours = max( 1, (int) $settings['interval_hours'] );
		$active_key     = $this->get_schedule_key( $interval_hours );

		$is_init = function_exists( 'did_action' ) && did_action( 'init' );

		$schedules[ $active_key ] = array(
			'interval' => $interval_hours * HOUR_IN_SECONDS,
			'display'  => $is_init
				? sprintf(
					/* translators: %d: Number of hours */
					__( 'Every %d Hours (VeloX)', 'velox-multi-currency' ),
					$interval_hours
				)
				: "Every {$interval_hours} Hours (VeloX)",
		);

		// Common intervals for selector compatibility
		$common = array( 1, 2, 4, 6, 8, 12, 24, 48, 72, 168 );
		foreach ( $common as $h ) {
			$k = 'velox_every_' . $h . '_hours';
			if ( ! isset( $schedules[ $k ] ) ) {
				$schedules[ $k ] = array(
					'interval' => $h * HOUR_IN_SECONDS,
					'display'  => $is_init
						? sprintf(
							/* translators: %d: Number of hours */
							__( 'Every %d Hours (VeloX)', 'velox-multi-currency' ),
							$h
						)
						: "Every {$h} Hours (VeloX)",
				);
			}
		}

		return $schedules;
	}

	/**
	 * Initialize schedule hooks and ensure active schedule is registered.
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'sync_rates' ) );

		$settings = $this->get_auto_sync_settings();

		if ( 'yes' === $settings['enabled'] ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				$interval_hours = max( 1, (int) $settings['interval_hours'] );
				$schedule_key   = $this->get_schedule_key( $interval_hours );
				wp_schedule_event( time() + ( $interval_hours * HOUR_IN_SECONDS ), $schedule_key, self::CRON_HOOK );
			}
		} else {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
		}
	}

	/**
	 * Get timestamp of the next scheduled sync, or null if none.
	 *
	 * @return int|null
	 */
	public function get_next_sync_timestamp(): ?int {
		$next = wp_next_scheduled( self::CRON_HOOK );
		return $next ? (int) $next : null;
	}

	/**
	 * Sync rates from the provider and update enabled currencies with automatic failover chain.
	 *
	 * If the primary provider encounters network issues, rate limits, or empty responses,
	 * VeloX automatically tries the remaining providers in sequence.
	 *
	 * @param string|null $provider_key Optional specific provider to sync from.
	 * @return array{count: int, provider: string, is_fallback?: bool, primary_provider?: string}|\WP_Error Returns summary or WP_Error.
	 */
	public function sync_rates( ?string $provider_key = null ) {
		if ( ! empty( $provider_key ) && isset( $this->providers[ $provider_key ] ) ) {
			$primary_provider = $this->providers[ $provider_key ];
			$this->set_active_provider( $provider_key );
		} else {
			$primary_provider = $this->get_active_provider();
		}

		$base_currency = $this->registry->get_base_currency();

		// Check if multi-provider failover chain is enabled (unlocked by VeloX Pro)
		$enable_failover = (bool) apply_filters( 'velox_mc_enable_failover', velox_mc_is_pro() );

		// Build execution chain: primary provider first, then fallbacks if Pro is active
		$chain = array( $primary_provider->get_key() => $primary_provider );
		if ( $enable_failover ) {
			foreach ( $this->providers as $key => $inst ) {
				if ( $key !== $primary_provider->get_key() ) {
					$chain[ $key ] = $inst;
				}
			}
		}

		$rates               = null;
		$successful_provider = null;
		$errors              = array();
		$is_fallback         = false;

		foreach ( $chain as $key => $provider ) {
			$result = $provider->fetch_rates( $base_currency );

			if ( is_wp_error( $result ) ) {
				$errors[ $provider->get_name() ] = $result->get_error_message();
				$is_fallback                     = true;
				continue;
			}

			if ( ! is_array( $result ) || empty( $result ) ) {
				$errors[ $provider->get_name() ] = __( 'Received empty rate payload.', 'velox-multi-currency' );
				$is_fallback                     = true;
				continue;
			}

			// Provider succeeded
			$rates               = $result;
			$successful_provider = $provider;
			break;
		}

		// If provider(s) failed, return error
		if ( empty( $rates ) || ! $successful_provider ) {
			if ( ! $enable_failover ) {
				$msg = reset( $errors ) ?: __( 'Failed to fetch exchange rates from provider.', 'velox-multi-currency' );
				return new \WP_Error( 'velox_rate_provider_failed', $msg );
			}

			$err_details = array();
			foreach ( $errors as $prov_name => $msg ) {
				$err_details[] = "{$prov_name}: {$msg}";
			}
			return new \WP_Error(
				'velox_all_rate_providers_failed',
				sprintf(
					/* translators: %s: Comma-separated list of failure reasons */
					__( 'All exchange rate providers failed to respond. Errors: %s', 'velox-multi-currency' ),
					implode( '; ', $err_details )
				)
			);
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
				$enabled[ $code ]['rate_provider'] = $successful_provider->get_key();
				$updated_count++;
			}
		}

		$this->registry->save_enabled_currencies( $enabled );
		update_option( self::OPTION_LAST_SYNC, time() );
		update_option( 'velox_mc_last_sync_provider', $successful_provider->get_key() );

		$response = array(
			'count'    => $updated_count,
			'provider' => $successful_provider->get_name(),
		);

		if ( $is_fallback ) {
			$response['is_fallback']      = true;
			$response['primary_provider'] = $primary_provider->get_name();
		}

		return $response;
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
