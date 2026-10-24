<?php
/**
 * RateService manages exchange rate fetching, scheduling, and caching.
 *
 * @package SettleX\MultiCurrency\Currency
 */

namespace SettleX\MultiCurrency\Currency;

use SettleX\MultiCurrency\Currency\RateProvider\EcbProvider;
use SettleX\MultiCurrency\Currency\RateProvider\RateProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * RateService class.
 */
class RateService {

	/**
	 * Action hook for scheduled sync.
	 */
	const CRON_HOOK = 'settlex_daily_rate_sync';

	/**
	 * Option key for last sync timestamp.
	 */
	const OPTION_LAST_SYNC = 'settlex_last_rate_sync';

	/**
	 * CurrencyRegistry instance.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $registry;

	/**
	 * Active rate provider.
	 *
	 * @var RateProviderInterface
	 */
	protected RateProviderInterface $provider;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry            $registry Registry.
	 * @param RateProviderInterface|null $provider Provider.
	 */
	public function __construct( CurrencyRegistry $registry, ?RateProviderInterface $provider = null ) {
		$this->registry = $registry;
		$this->provider = $provider ?? new EcbProvider();
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
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function sync_rates() {
		$base_currency = $this->registry->get_base_currency();
		$rates         = $this->provider->fetch_rates( $base_currency );

		if ( is_wp_error( $rates ) ) {
			return $rates;
		}

		$enabled = $this->registry->get_enabled_currencies();
		$updated = false;

		foreach ( $enabled as $code => $data ) {
			if ( $code === $base_currency ) {
				$enabled[ $code ]['rate'] = 1.0;
				continue;
			}

			if ( isset( $rates[ $code ] ) && $rates[ $code ] > 0 ) {
				$enabled[ $code ]['rate']      = (float) $rates[ $code ];
				$enabled[ $code ]['synced_at'] = time();
				$updated                       = true;
			}
		}

		if ( $updated ) {
			$this->registry->save_enabled_currencies( $enabled );
			update_option( self::OPTION_LAST_SYNC, time() );
		}

		return true;
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
