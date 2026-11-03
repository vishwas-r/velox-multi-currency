<?php
/**
 * Admin Settings page for managing currencies, exchange rates, and the Gateway Matrix.
 *
 * @package VeloX\MultiCurrency\Admin
 */

namespace VeloX\MultiCurrency\Admin;

use VeloX\MultiCurrency\Currency\CurrencyRegistry;
use VeloX\MultiCurrency\Currency\RateService;
use VeloX\MultiCurrency\Frontend\Switcher;
use VeloX\MultiCurrency\GatewayRouter\GatewayCapabilityRegistry;
use VeloX\MultiCurrency\GatewayRouter\SettlementMode;

defined( 'ABSPATH' ) || exit;

/**
 * SettingsPage class.
 */
class SettingsPage {

	protected CurrencyRegistry $currency_registry;
	protected RateService $rate_service;
	protected GatewayCapabilityRegistry $capability_registry;
	protected ?Switcher $switcher;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry          $currency_registry   Currency registry.
	 * @param RateService               $rate_service        Rate service.
	 * @param GatewayCapabilityRegistry $capability_registry Capability registry.
	 * @param Switcher|null             $switcher            Switcher instance.
	 */
	public function __construct(
		CurrencyRegistry $currency_registry,
		RateService $rate_service,
		GatewayCapabilityRegistry $capability_registry,
		?Switcher $switcher = null
	) {
		$this->currency_registry   = $currency_registry;
		$this->rate_service        = $rate_service;
		$this->capability_registry = $capability_registry;
		$this->switcher            = $switcher;
	}

	/**
	 * Get switcher instance safely.
	 *
	 * @return Switcher
	 */
	protected function get_switcher(): Switcher {
		if ( null === $this->switcher ) {
			$this->switcher = new Switcher( $this->currency_registry );
		}
		return $this->switcher;
	}

	/**
	 * Initialize admin hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_velox_admin_sync_rates', array( $this, 'ajax_sync_rates' ) );
	}

	/**
	 * Add VeloX menu item under WooCommerce.
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'VeloX Multi-Currency & Gateway Router', 'velox-multi-currency' ),
			__( 'VeloX', 'velox-multi-currency' ),
			'manage_woocommerce',
			'velox-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'velox-settings' ) ) {
			return;
		}

		wp_enqueue_style(
			'velox-admin',
			VELOX_MC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			VELOX_MC_VERSION
		);

		wp_enqueue_script(
			'velox-admin',
			VELOX_MC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			VELOX_MC_VERSION,
			true
		);

		wp_localize_script(
			'velox-admin',
			'velox_admin_params',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'velox_admin_nonce' ),
				'max_currencies' => absint( $this->currency_registry->get_max_currencies() ),
				'is_pro'         => (bool) velox_mc_is_pro(),
			)
		);
	}

	/**
	 * AJAX manual rate sync handler.
	 */
	public function ajax_sync_rates(): void {
		check_ajax_referer( 'velox_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'velox-multi-currency' ) );
		}

		$provider_key = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : null;
		$result       = $this->rate_service->sync_rates( $provider_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		if ( ! empty( $result['is_fallback'] ) ) {
			$message = sprintf(
				/* translators: 1: Count of updated currencies, 2: Fallback provider name, 3: Primary provider name */
				__( 'Success! Primary provider (%3$s) was unreachable; %1$d currencies were updated using fallback provider %2$s.', 'velox-multi-currency' ),
				$result['count'],
				$result['provider'],
				$result['primary_provider'] ?? 'primary'
			);
		} else {
			$message = sprintf(
				/* translators: 1: Count of updated currencies, 2: Provider name */
				__( 'Success! Exchange rates for %1$d currencies updated from %2$s.', 'velox-multi-currency' ),
				$result['count'],
				$result['provider']
			);
		}

		wp_send_json_success(
			array(
				'message'     => $message,
				'time'        => current_time( 'mysql' ),
				'provider'    => $result['provider'],
				'is_fallback' => ! empty( $result['is_fallback'] ),
			)
		);
	}

	/**
	 * Render admin settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'currencies'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Handle form saves.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'velox_save_settings', 'velox_nonce' ) ) {
			if ( 'currencies' === $active_tab ) {
				$this->save_currencies_tab();
			} elseif ( 'switcher' === $active_tab ) {
				$this->save_switcher_tab();
			} elseif ( in_array( $active_tab, array( 'gateway_routing', 'gateway_matrix' ), true ) ) {
				$this->save_gateway_routing_tab();
			}
		}

		?>
		<div class="wrap velox-settings-wrap">
			<h1>
				<?php echo esc_html__( 'VeloX – Gateway Router & Multi-Currency', 'velox-multi-currency' ); ?>
				<span class="velox-badge">v<?php echo esc_html( VELOX_MC_VERSION ); ?></span>
			</h1>

			<?php do_action( 'velox_mc_admin_settings_notices' ); ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=velox-settings&tab=currencies' ) ); ?>" class="nav-tab <?php echo 'currencies' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-cash-stack velox-tab-icon" viewBox="0 0 16 16"><path d="M1 3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1zm7 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4"/><path d="M0 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1zm3 0a2 2 0 0 1-2 2v4a2 2 0 0 1 2 2h10a2 2 0 0 1 2-2V7a2 2 0 0 1-2-2z"/></svg>
					<?php esc_html_e( 'Currencies & Rates', 'velox-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=velox-settings&tab=switcher' ) ); ?>" class="nav-tab <?php echo 'switcher' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-arrow-left-right velox-tab-icon" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1 11.5a.5.5 0 0 0 .5.5h11.793l-3.147 3.146a.5.5 0 0 0 .708.708l4-4a.5.5 0 0 0 0-.708l-4-4a.5.5 0 0 0-.708.708L13.293 11H1.5a.5.5 0 0 0-.5.5m14-7a.5.5 0 0 1-.5.5H2.707l3.147 3.146a.5.5 0 1 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 4H14.5a.5.5 0 0 1 .5.5"/></svg>
					<?php esc_html_e( 'Switcher & Display', 'velox-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=velox-settings&tab=gateway_routing' ) ); ?>" class="nav-tab <?php echo in_array( $active_tab, array( 'gateway_routing', 'gateway_matrix' ), true ) ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-credit-card velox-tab-icon" viewBox="0 0 16 16"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v1h14V4a1 1 0 0 0-1-1zm13 4H1v5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1z"/><path d="M2 10a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1z"/></svg>
					<?php esc_html_e( 'Gateway Routing', 'velox-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=velox-settings&tab=sales_by_currency' ) ); ?>" class="nav-tab <?php echo 'sales_by_currency' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-bar-chart-line velox-tab-icon" viewBox="0 0 16 16"><path d="M11 2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12h.5a.5.5 0 0 1 0 1H.5a.5.5 0 0 1 0-1H1v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h1V7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7h1zm1 12h2V2h-2zm-3 0V7H7v7zm-5 0v-3H2v3z"/></svg>
					<?php esc_html_e( 'Sales by Currency', 'velox-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=velox-settings&tab=diagnostics' ) ); ?>" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-gear velox-tab-icon" viewBox="0 0 16 16"><path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492M5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0"/><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115z"/></svg>
					<?php esc_html_e( 'System & Diagnostics', 'velox-multi-currency' ); ?>
				</a>
				<?php do_action( 'velox_mc_admin_nav_tabs', $active_tab ); ?>
			</nav>

			<div class="velox-tab-content">
				<?php
				if ( 'currencies' === $active_tab ) {
					$this->render_currencies_tab();
				} elseif ( 'switcher' === $active_tab ) {
					$this->render_switcher_tab();
				} elseif ( in_array( $active_tab, array( 'gateway_routing', 'gateway_matrix' ), true ) ) {
					$this->render_gateway_routing_tab();
				} elseif ( 'sales_by_currency' === $active_tab ) {
					$this->render_sales_by_currency_tab();
				} elseif ( 'diagnostics' === $active_tab ) {
					$this->render_diagnostics_tab();
				} else {
					do_action( 'velox_mc_admin_tab_content', $active_tab );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Currencies Tab.
	 */
	protected function render_currencies_tab(): void {
		$enabled         = $this->currency_registry->get_enabled_currencies();
		$base            = $this->currency_registry->get_base_currency();
		$last_sync       = $this->rate_service->get_last_sync_timestamp();
		$all             = $this->currency_registry->get_all_currencies();
		$providers       = $this->rate_service->get_available_providers();
		$active_provider = $this->rate_service->get_active_provider_key();

		?>
		<div class="velox-panel">
			<div class="velox-panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
				<h2><?php esc_html_e( 'Configured Currencies', 'velox-multi-currency' ); ?></h2>
				<?php
				$max_currencies = $this->currency_registry->get_max_currencies();
				$enabled_count  = count( $enabled );
				$is_at_limit    = ( ! velox_mc_is_pro() && $enabled_count >= $max_currencies );
				if ( ! velox_mc_is_pro() && $enabled_count >= $max_currencies ) :
					?>
					<div class="notice notice-info inline" style="margin:10px 0; width:100%;">
						<p>
							<strong>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: Configured currencies count, 2: Maximum currencies count */
										__( 'You have configured %1$d of %2$d currencies (Free limit).', 'velox-multi-currency' ),
										absint( $enabled_count ),
										absint( $max_currencies )
									)
								);
								?>
							</strong>
							<?php esc_html_e( 'Upgrade to VeloX Pro to unlock unlimited currencies.', 'velox-multi-currency' ); ?>
							<a href="https://vishwas.me/" target="_blank" style="font-weight:600; text-decoration:underline; margin-left:4px;"><?php esc_html_e( 'Unlock Unlimited Currencies &rarr;', 'velox-multi-currency' ); ?></a>
						</p>
					</div>
				<?php endif; ?>
				<div class="velox-sync-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
					<span class="velox-last-sync" style="font-size:12px; color:#666;">
						<?php
						if ( $last_sync > 0 ) {
							printf(
								/* translators: %s: Relative human time */
								esc_html__( 'Last synced: %s ago', 'velox-multi-currency' ),
								esc_html( human_time_diff( $last_sync, time() ) )
							);
						} else {
							esc_html_e( 'Rates not synced yet.', 'velox-multi-currency' );
						}
						?>
					</span>
					<span style="font-weight:600; font-size:12px; margin-left:8px; color:#475569;">
						<?php esc_html_e( 'Exchange:', 'velox-multi-currency' ); ?>
					</span>
					<?php if ( count( $providers ) > 1 ) : ?>
						<select id="velox-rate-provider-select" name="velox_rate_provider" style="font-size:12px; max-width:280px; height:32px;">
							<?php foreach ( $providers as $key => $prov ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active_provider, $key ); ?>>
									<?php echo esc_html( $prov->get_name() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php $single_prov = reset( $providers ); ?>
						<span class="velox-provider-badge" style="font-size:12px; font-weight:600; color:#334155; background:#f1f5f9; padding:4px 10px; border-radius:4px; border:1px solid #cbd5e1; display:inline-flex; align-items:center; gap:6px;">
							<span style="width:7px; height:7px; border-radius:50%; background:#10b981; display:inline-block;"></span>
							<?php echo esc_html( $single_prov ? $single_prov->get_name() : 'European Central Bank (Official Feed, Free)' ); ?>
						</span>
						<input type="hidden" id="velox-rate-provider-select" name="velox_rate_provider" value="<?php echo esc_attr( $single_prov ? $single_prov->get_key() : 'ecb' ); ?>">
					<?php endif; ?>
					<button type="button" class="button button-primary velox-sync-btn" id="velox-sync-rates-btn">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-arrow-repeat" viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
						<span><?php esc_html_e( 'Sync Exchange Rates', 'velox-multi-currency' ); ?></span>
					</button>
				</div>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'velox_save_settings', 'velox_nonce' ); ?>
				<table class="widefat fixed striped velox-table" id="velox-currencies-table">
					<thead>
						<tr>
							<th style="width: 80px;"><?php esc_html_e( 'Currency', 'velox-multi-currency' ); ?></th>
							<th><?php esc_html_e( 'Name', 'velox-multi-currency' ); ?></th>
							<th style="width: 80px;"><?php esc_html_e( 'Symbol', 'velox-multi-currency' ); ?></th>
							<th style="width: 110px;"><?php esc_html_e( 'Rate', 'velox-multi-currency' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Markup %', 'velox-multi-currency' ); ?></th>
							<th style="width: 140px;"><?php esc_html_e( 'Rounding Rule', 'velox-multi-currency' ); ?></th>
							<th style="width: 70px; text-align: center;"><?php esc_html_e( 'Action', 'velox-multi-currency' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $enabled as $code => $curr ) : ?>
							<?php $is_base = ( $code === $base ); ?>
							<tr data-currency="<?php echo esc_attr( $code ); ?>">
								<td>
									<strong><?php echo esc_html( $code ); ?></strong>
									<?php if ( $is_base ) : ?>
										<span class="velox-tag-base"><?php esc_html_e( 'Base', 'velox-multi-currency' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $curr['name'] ?? $code ); ?></td>
								<td>
									<input type="text" name="currencies[<?php echo esc_attr( $code ); ?>][symbol]" value="<?php echo esc_attr( $curr['symbol'] ?? '' ); ?>" class="small-text">
								</td>
								<td>
									<input type="number" step="0.000001" min="0" name="currencies[<?php echo esc_attr( $code ); ?>][rate]" value="<?php echo esc_attr( $curr['rate'] ?? 1.0 ); ?>" <?php echo $is_base ? 'readonly' : ''; ?> class="regular-text" style="width:100px;">
								</td>
								<td>
									<input type="number" step="0.1" min="0" name="currencies[<?php echo esc_attr( $code ); ?>][markup]" value="<?php echo esc_attr( $curr['markup'] ?? 0.0 ); ?>" <?php echo $is_base ? 'disabled' : ''; ?> class="small-text" style="width:70px;"> %
								</td>
								<td>
									<select name="currencies[<?php echo esc_attr( $code ); ?>][rounding]" <?php echo $is_base ? 'disabled' : ''; ?>>
										<option value="none" <?php selected( $curr['rounding'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None (Exact)', 'velox-multi-currency' ); ?></option>
										<option value="charm_99" <?php selected( $curr['rounding'] ?? '', 'charm_99' ); ?>>.99 (Charm)</option>
										<option value="charm_95" <?php selected( $curr['rounding'] ?? '', 'charm_95' ); ?>>.95 (Charm)</option>
										<option value="ceil_int" <?php selected( $curr['rounding'] ?? '', 'ceil_int' ); ?>><?php esc_html_e( 'Round Up to Integer', 'velox-multi-currency' ); ?></option>
										<option value="round_5" <?php selected( $curr['rounding'] ?? '', 'round_5' ); ?>><?php esc_html_e( 'Nearest 5', 'velox-multi-currency' ); ?></option>
										<option value="round_10" <?php selected( $curr['rounding'] ?? '', 'round_10' ); ?>><?php esc_html_e( 'Nearest 10', 'velox-multi-currency' ); ?></option>
									</select>
								</td>
								<td style="text-align: center; vertical-align: middle;">
									<?php if ( ! $is_base ) : ?>
										<button type="button" class="velox-btn-delete delete-currency-row" title="<?php esc_attr_e( 'Remove currency', 'velox-multi-currency' ); ?>" aria-label="<?php esc_attr_e( 'Remove', 'velox-multi-currency' ); ?>">
											<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash3" viewBox="0 0 16 16"><path d="M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5M11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H1.5a.5.5 0 0 0 0 1h.538l.853 10.66A2 2 0 0 0 4.885 16h6.23a2 2 0 0 0 1.994-1.84l.853-10.66h.538a.5.5 0 0 0 0-1zm1.958 1-.846 10.58a1 1 0 0 1-.997.92h-6.23a1 1 0 0 1-.997-.92L3.042 3.5zm-7.487 1a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L5 5a.5.5 0 0 1 .47-.528M8 4.5a.5.5 0 0 1 .5.5v8.5a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5m2.472.5a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L10 5a.5.5 0 0 1 .47-.528"/></svg>
										</button>
									<?php else : ?>
										<span class="velox-badge-locked" title="<?php esc_attr_e( 'Store base currency cannot be removed', 'velox-multi-currency' ); ?>">&mdash;</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="velox-add-row" id="velox-add-currency-wrapper">
					<label for="new_currency_select"><?php esc_html_e( 'Add Currency:', 'velox-multi-currency' ); ?></label>
					<select id="new_currency_select" <?php echo $is_at_limit ? 'disabled' : ''; ?>>
						<option value=""><?php echo $is_at_limit ? esc_html__( '-- Free Limit Reached (3/3) --', 'velox-multi-currency' ) : esc_html__( '-- Choose Currency --', 'velox-multi-currency' ); ?></option>
						<?php foreach ( $all as $c_code => $c_data ) : ?>
							<?php if ( ! isset( $enabled[ $c_code ] ) ) : ?>
								<option value="<?php echo esc_attr( $c_code ); ?>" data-name="<?php echo esc_attr( $c_data['name'] ); ?>" data-symbol="<?php echo esc_attr( $c_data['symbol'] ); ?>">
									<?php echo esc_html( $c_code . ' - ' . $c_data['name'] ); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-secondary velox-btn-add" id="velox-add-currency-btn" <?php echo $is_at_limit ? 'disabled' : ''; ?>>
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="bi bi-plus-lg" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/></svg>
						<?php esc_html_e( 'Add to List', 'velox-multi-currency' ); ?>
					</button>
					<span id="velox-limit-message" style="display:<?php echo $is_at_limit ? 'inline-block' : 'none'; ?>; margin-left:12px; font-size:12px; color:#e11d48; font-weight:600;">
						<?php esc_html_e( 'Free limit reached (3 of 3 currencies).', 'velox-multi-currency' ); ?>
						<a href="https://vishwas.me/" target="_blank" style="color:#4f46e5; text-decoration:underline; margin-left:4px;"><?php esc_html_e( 'Upgrade to Pro for unlimited &rarr;', 'velox-multi-currency' ); ?></a>
					</span>
				</div>

				<p class="submit" style="margin-top:20px; margin-bottom:0;">
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Currency Settings', 'velox-multi-currency' ); ?>">
				</p>
			</form>
		</div>

		<!-- Auto-Sync Exchange Rate Card -->
		<?php
		$auto_sync = $this->rate_service->get_auto_sync_settings();
		$next_sync = $this->rate_service->get_next_sync_timestamp();
		?>
		<div class="velox-panel velox-auto-sync-card" style="margin-top:20px;">
			<div class="velox-panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
				<div style="display:flex; align-items:center; gap:10px;">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#4f46e5" class="bi bi-arrow-repeat" viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
					<h2 style="margin:0; font-size:1.25rem; font-weight:600;">
						<?php esc_html_e( 'Automatic Exchange Rate Sync', 'velox-multi-currency' ); ?>
					</h2>
					<?php if ( 'yes' === $auto_sync['enabled'] ) : ?>
						<span class="velox-badge" style="background:#10b981; font-weight:600; padding:3px 10px; border-radius:9999px;">
							<?php
							/* translators: %d: Number of hours */
							printf( esc_html__( 'Active: Every %d Hours', 'velox-multi-currency' ), (int) $auto_sync['interval_hours'] );
							?>
						</span>
					<?php else : ?>
						<span class="velox-badge" style="background:#64748b; font-weight:600; padding:3px 10px; border-radius:9999px;">
							<?php esc_html_e( 'Auto-Sync Disabled', 'velox-multi-currency' ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div style="font-size:12px; color:#64748b;">
					<?php esc_html_e( 'Powered by WordPress Cron scheduler (no external server cron job required)', 'velox-multi-currency' ); ?>
				</div>
			</div>

			<!-- Status Metric Tiles -->
			<div class="velox-sync-stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:20px;">
				<!-- Tile 1: Last Sync -->
				<div class="velox-stat-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;">
					<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
						<?php esc_html_e( 'Last Successful Sync', 'velox-multi-currency' ); ?>
					</div>
					<div style="font-size:15px; font-weight:700; color:#0f172a;">
						<?php
						if ( $last_sync > 0 ) {
							echo esc_html( human_time_diff( $last_sync, time() ) . ' ' . __( 'ago', 'velox-multi-currency' ) );
						} else {
							esc_html_e( 'Never', 'velox-multi-currency' );
						}
						?>
					</div>
					<div style="font-size:11px; color:#94a3b8; margin-top:3px;">
						<?php echo $last_sync > 0 ? esc_html( wp_date( 'M j, Y H:i:s', $last_sync ) ) : esc_html__( 'Click Sync Rates to initialize', 'velox-multi-currency' ); ?>
					</div>
				</div>

				<!-- Tile 2: Next Scheduled Sync -->
				<div class="velox-stat-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;">
					<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
						<?php esc_html_e( 'Next Scheduled Sync', 'velox-multi-currency' ); ?>
					</div>
					<div style="font-size:15px; font-weight:700; color:<?php echo ( 'yes' === $auto_sync['enabled'] && $next_sync ) ? '#10b981' : '#64748b'; ?>;">
						<?php
						if ( 'yes' === $auto_sync['enabled'] && $next_sync ) {
							echo esc_html( 'In ' . human_time_diff( time(), $next_sync ) );
						} else {
							esc_html_e( 'Paused / Disabled', 'velox-multi-currency' );
						}
						?>
					</div>
					<div style="font-size:11px; color:#94a3b8; margin-top:3px;">
						<?php echo ( 'yes' === $auto_sync['enabled'] && $next_sync ) ? esc_html( wp_date( 'M j, Y H:i:s', $next_sync ) ) : esc_html__( 'Enable auto-sync below to activate schedule', 'velox-multi-currency' ); ?>
					</div>
				</div>

				<!-- Tile 3: Active Provider -->
				<div class="velox-stat-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;">
					<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
						<?php esc_html_e( 'Active Exchange Provider', 'velox-multi-currency' ); ?>
					</div>
					<div style="font-size:15px; font-weight:700; color:#4f46e5;">
						<?php echo esc_html( isset( $providers[ $active_provider ] ) ? $providers[ $active_provider ]->get_name() : $active_provider ); ?>
					</div>
					<div style="font-size:11px; color:#94a3b8; margin-top:3px;">
						<?php esc_html_e( 'Free zero-key provider', 'velox-multi-currency' ); ?>
					</div>
				</div>
			</div>

			<!-- Form Controls for Auto-Sync -->
			<form method="post" action="">
				<?php wp_nonce_field( 'velox_save_settings', 'velox_nonce' ); ?>
				<input type="hidden" name="velox_action" value="save_auto_sync">

				<table class="form-table velox-form-table" style="margin-top:0;">
					<tbody>
						<!-- Enable/Disable Auto-Sync -->
						<tr>
							<th scope="row" style="width:220px;">
								<label for="auto_sync_enabled"><?php esc_html_e( 'Auto-Sync Exchange Rates', 'velox-multi-currency' ); ?></label>
							</th>
							<td>
								<label class="velox-checkbox-label" style="font-size:14px;">
									<input type="checkbox" name="auto_sync_enabled" id="auto_sync_enabled" value="yes" <?php checked( 'yes', $auto_sync['enabled'] ); ?>>
									<strong><?php esc_html_e( 'Enable automatic background exchange rate synchronization', 'velox-multi-currency' ); ?></strong>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, VeloX periodically fetches live exchange rates in the background for all enabled currencies without manual intervention.', 'velox-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<!-- Sync Frequency (Every X Hours) -->
						<tr>
							<th scope="row">
								<label for="auto_sync_interval_select"><?php esc_html_e( 'Sync Duration (Every X Hours)', 'velox-multi-currency' ); ?></label>
							</th>
							<td>
								<?php
								$current_hours = (int) $auto_sync['interval_hours'];
								$is_pro        = (bool) velox_mc_is_pro();
								$presets       = array(
									1   => array(
										'label' => __( 'Every 1 Hour', 'velox-multi-currency' ),
										'pro'   => true,
									),
									2   => array(
										'label' => __( 'Every 2 Hours', 'velox-multi-currency' ),
										'pro'   => true,
									),
									4   => array(
										'label' => __( 'Every 4 Hours', 'velox-multi-currency' ),
										'pro'   => true,
									),
									6   => array(
										'label' => __( 'Every 6 Hours', 'velox-multi-currency' ),
										'pro'   => true,
									),
									8   => array(
										'label' => __( 'Every 8 Hours', 'velox-multi-currency' ),
										'pro'   => true,
									),
									12  => array(
										'label' => __( 'Every 12 Hours (Twice daily)', 'velox-multi-currency' ),
										'pro'   => true,
									),
									24  => array(
										'label' => __( 'Every 24 Hours (Once daily)', 'velox-multi-currency' ),
										'pro'   => false,
									),
									48  => array(
										'label' => __( 'Every 48 Hours (Every 2 days)', 'velox-multi-currency' ),
										'pro'   => false,
									),
									72  => array(
										'label' => __( 'Every 72 Hours (Every 3 days)', 'velox-multi-currency' ),
										'pro'   => false,
									),
									168 => array(
										'label' => __( 'Every 168 Hours (Weekly)', 'velox-multi-currency' ),
										'pro'   => false,
									),
								);
								$is_custom     = $is_pro && ! array_key_exists( $current_hours, $presets );
								?>
								<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
									<select name="auto_sync_interval_select" id="auto_sync_interval_select" style="min-width:260px; height:34px;">
										<?php foreach ( $presets as $hours => $info ) : ?>
											<?php
											$disabled = ( ! $is_pro && $info['pro'] );
											$label    = $info['label'] . ( $disabled ? ' [PRO]' : '' );
											?>
											<option value="<?php echo esc_attr( (string) $hours ); ?>" <?php selected( $hours, $current_hours ); ?> <?php echo $disabled ? 'disabled' : ''; ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
										<option value="custom" <?php selected( true, $is_custom ); ?> <?php echo ( ! $is_pro ) ? 'disabled' : ''; ?>>
											<?php echo esc_html( __( 'Custom Duration...', 'velox-multi-currency' ) . ( ! $is_pro ? ' [PRO]' : '' ) ); ?>
										</option>
									</select>

									<?php if ( $is_pro ) : ?>
										<div id="velox-custom-hours-wrapper" style="display:<?php echo $is_custom ? 'inline-flex' : 'none'; ?>; align-items:center; gap:6px;">
											<label for="auto_sync_interval_custom" style="font-weight:500; font-size:13px;"><?php esc_html_e( 'Every', 'velox-multi-currency' ); ?></label>
											<input type="number" min="1" max="720" step="1" name="auto_sync_interval_custom" id="auto_sync_interval_custom" value="<?php echo esc_attr( (string) $current_hours ); ?>" style="width:80px; height:34px;">
											<span style="font-size:13px; color:#475569;"><?php esc_html_e( 'Hours', 'velox-multi-currency' ); ?></span>
										</div>
									<?php endif; ?>
								</div>
								<p class="description">
									<?php esc_html_e( 'Choose how often exchange rates are updated. Standard daily & weekly intervals (24h, 48h, 72h, 168h) are free.', 'velox-multi-currency' ); ?>
									<?php if ( ! $is_pro ) : ?>
										<span style="display:block; margin-top:4px; color:#4f46e5; font-size:12.5px;">
											<strong><?php esc_html_e( 'High-Frequency Sync (< 24h: 1h, 2h, 4h, 6h, 8h, 12h, or custom hours):', 'velox-multi-currency' ); ?></strong>
											<?php esc_html_e( 'Available with', 'velox-multi-currency' ); ?>
											<a href="https://vishwas.me/" target="_blank" style="color:#4338ca; font-weight:600; text-decoration:underline;"><?php esc_html_e( 'VeloX Pro &rarr;', 'velox-multi-currency' ); ?></a>
										</span>
									<?php endif; ?>
								</p>
							</td>
						</tr>

						<!-- Rate Provider Option -->
						<tr>
							<th scope="row">
								<label for="velox_rate_provider_card"><?php esc_html_e( 'Exchange Rate Provider', 'velox-multi-currency' ); ?></label>
							</th>
							<td>
								<?php if ( count( $providers ) > 1 ) : ?>
									<select id="velox_rate_provider_card" name="velox_rate_provider" style="min-width:240px; height:34px;">
										<?php foreach ( $providers as $key => $prov ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active_provider, $key ); ?>>
												<?php echo esc_html( $prov->get_name() ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<?php $single_prov = reset( $providers ); ?>
									<div style="display:flex; align-items:center; gap:8px;">
										<span class="velox-provider-badge" style="font-size:13px; font-weight:600; color:#334155; background:#f1f5f9; padding:6px 12px; border-radius:6px; border:1px solid #cbd5e1; display:inline-flex; align-items:center; gap:6px;">
											<span style="width:8px; height:8px; border-radius:50%; background:#10b981; display:inline-block;"></span>
											<?php echo esc_html( $single_prov ? $single_prov->get_name() : 'European Central Bank (Official Feed, Free)' ); ?>
										</span>
										<input type="hidden" id="velox_rate_provider_card" name="velox_rate_provider" value="<?php echo esc_attr( $single_prov ? $single_prov->get_key() : 'ecb' ); ?>">
									</div>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'The financial exchange service used during automatic updates.', 'velox-multi-currency' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<div style="display:flex; align-items:center; gap:12px; margin-top:20px;">
					<input type="submit" name="submit_auto_sync" class="button button-primary" value="<?php esc_attr_e( 'Save Auto-Sync Settings', 'velox-multi-currency' ); ?>" style="height:34px; padding:0 20px;">
					<button type="button" class="button button-secondary velox-sync-btn" id="velox-sync-rates-btn-2" style="height:34px;">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-arrow-repeat" viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
						<span><?php esc_html_e( 'Sync Rates Now', 'velox-multi-currency' ); ?></span>
					</button>
				</div>
			</form>
		</div>

		<!-- GeoIP Customer Country Auto-Detection Section -->
		<?php
		if ( velox_mc_is_pro() ) {
			do_action( 'velox_mc_admin_geoip_settings' );
		} else {
			?>
			<div class="velox-panel velox-geo-card" style="margin-top:20px; border:1px dashed #c3c4c7; background:#fafafa;">
				<div class="velox-panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
					<div style="display:flex; align-items:center; gap:10px;">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#6366f1" class="bi bi-geo-alt" viewBox="0 0 16 16"><path d="M12.166 8.94c-.524 1.062-1.234 2.12-1.96 3.07A32 32 0 0 1 8 14.58a32 32 0 0 1-2.206-2.57c-.726-.95-1.436-2.008-1.96-3.07C3.304 7.867 3 6.862 3 6a5 5 0 0 1 10 0c0 .862-.305 1.867-.834 2.94M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10"/><path d="M8 8a2 2 0 1 1 0-4 2 2 0 0 1 0 4m0 1a3 3 0 1 0 0-6 3 3 0 0 0 0 6"/></svg>
						<h2 style="margin:0; font-size:1.25rem; font-weight:600;">
							<?php esc_html_e( 'Customer Country Auto-Detection (GeoIP)', 'velox-multi-currency' ); ?>
						</h2>
						<span class="velox-pro-badge" style="background:#6366f1; color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; text-transform:uppercase;">
							<?php esc_html_e( 'PRO', 'velox-multi-currency' ); ?>
						</span>
					</div>
					<div>
						<a href="https://vishwas.me/" target="_blank" class="button button-secondary" style="font-size:12px; font-weight:600;">
							<?php esc_html_e( 'Unlock with VeloX Pro &rarr;', 'velox-multi-currency' ); ?>
						</a>
					</div>
				</div>
				<p style="color:#64748b; font-size:13px; margin:10px 0 0;">
					<?php esc_html_e( 'Automatically detect your customer\'s country and switch to their domestic currency on their first visit using built-in MaxMind geolocation. Upgrade to VeloX Pro to enable.', 'velox-multi-currency' ); ?>
				</p>
			</div>
			<?php
		}
		?>
		<?php
	}

	/**
	 * Save Currencies Tab.
	 */
	protected function save_currencies_tab(): void {
		check_admin_referer( 'velox_save_settings', 'velox_nonce' );

		// 1. Handle GeoIP Settings Save (Pro feature)
		if ( ( isset( $_POST['velox_action'] ) && 'save_geo_settings' === $_POST['velox_action'] ) || isset( $_POST['submit_geo'] ) ) {
			if ( velox_mc_is_pro() ) {
				$geo_val = ( ! empty( $_POST['velox_auto_detect_geo'] ) && 'yes' === $_POST['velox_auto_detect_geo'] ) ? 'yes' : 'no';
				update_option( CurrencyRegistry::OPTION_AUTO_GEO, $geo_val );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'GeoIP auto-detection settings updated successfully.', 'velox-multi-currency' ) . '</p></div>';
			}
			return;
		}

		// 2. Handle Auto-Sync Settings Save
		if ( ( isset( $_POST['velox_action'] ) && 'save_auto_sync' === $_POST['velox_action'] ) || isset( $_POST['submit_auto_sync'] ) ) {
			$enabled    = ( ! empty( $_POST['auto_sync_enabled'] ) && 'yes' === $_POST['auto_sync_enabled'] ) ? 'yes' : 'no';
			$select_val = sanitize_text_field( wp_unslash( $_POST['auto_sync_interval_select'] ?? '24' ) );
			$is_pro     = (bool) velox_mc_is_pro();

			if ( 'custom' === $select_val && $is_pro ) {
				$interval_hours = max( 1, absint( wp_unslash( $_POST['auto_sync_interval_custom'] ?? 24 ) ) );
			} elseif ( ! $is_pro ) {
				// In Free, strictly allow >= 24h intervals (24, 48, 72, 168)
				$val            = absint( $select_val );
				$interval_hours = ( $val >= 24 && in_array( $val, array( 24, 48, 72, 168 ), true ) ) ? $val : 24;
			} else {
				$interval_hours = max( 1, absint( $select_val ) );
			}

			$this->rate_service->save_auto_sync_settings(
				array(
					'enabled'        => $enabled,
					'interval_hours' => $interval_hours,
				)
			);

			if ( isset( $_POST['velox_rate_provider'] ) ) {
				$provider_key = sanitize_key( wp_unslash( $_POST['velox_rate_provider'] ) );
				if ( ! $is_pro ) {
					$provider_key = 'ecb';
				}
				$this->rate_service->set_active_provider( $provider_key );
			}

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Auto-sync settings updated successfully.', 'velox-multi-currency' ) . '</p></div>';
			return;
		}

		// 2. Handle Currencies Table Save
		$raw_currencies = isset( $_POST['currencies'] ) ? (array) wp_unslash( $_POST['currencies'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$existing       = $this->currency_registry->get_enabled_currencies();
		$base           = $this->currency_registry->get_base_currency();
		$all            = $this->currency_registry->get_all_currencies();
		$updated        = array();

		// Always ensure base is present.
		$updated[ $base ] = $existing[ $base ];

		foreach ( $raw_currencies as $code => $data ) {
			$clean_code = strtoupper( sanitize_text_field( $code ) );
			if ( $clean_code === $base ) {
				$updated[ $base ]['symbol'] = sanitize_text_field( $data['symbol'] ?? $existing[ $base ]['symbol'] );
				continue;
			}

			$updated[ $clean_code ] = array(
				'code'         => $clean_code,
				'name'         => $all[ $clean_code ]['name'] ?? ( $existing[ $clean_code ]['name'] ?? $clean_code ),
				'symbol'       => sanitize_text_field( $data['symbol'] ?? '$' ),
				'rate'         => max( 0.000001, (float) ( $data['rate'] ?? 1.0 ) ),
				'markup'       => max( 0.0, (float) ( $data['markup'] ?? 0.0 ) ),
				'rounding'     => sanitize_key( $data['rounding'] ?? 'none' ),
				'position'     => $existing[ $clean_code ]['position'] ?? 'left',
				'decimals'     => $all[ $clean_code ]['decimals'] ?? 2,
				'decimal_sep'  => '.',
				'thousand_sep' => ',',
			);
		}

		$max_currencies = $this->currency_registry->get_max_currencies();
		if ( ! velox_mc_is_pro() && count( $updated ) > $max_currencies ) {
			$updated = array_slice( $updated, 0, $max_currencies, true );
		}

		$this->currency_registry->save_enabled_currencies( $updated );

		if ( isset( $_POST['velox_rate_provider'] ) ) {
			$provider_key = sanitize_key( wp_unslash( $_POST['velox_rate_provider'] ) );
			if ( ! velox_mc_is_pro() ) {
				$provider_key = 'ecb';
			}
			$this->rate_service->set_active_provider( $provider_key );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Currency settings saved.', 'velox-multi-currency' ) . '</p></div>';
	}

	/**
	 * Render Switcher & Display Settings Tab.
	 */
	protected function render_switcher_tab(): void {
		$switcher = $this->get_switcher();
		$settings = $switcher->get_settings();
		?>
		<div class="velox-panel">
			<h2><?php esc_html_e( 'Currency Switcher & Display Options', 'velox-multi-currency' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure how currency switchers appear to shoppers across your store. All currency switches happen instantly via AJAX with zero page reload, seamlessly updating product prices across Shop, Archive, Single Product, Cart, and Checkout pages.', 'velox-multi-currency' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'velox_save_settings', 'velox_nonce' ); ?>

				<table class="form-table velox-form-table">
					<tbody>
						<!-- Floating Widget Section -->
						<tr>
							<th scope="row">
								<label for="floating_enabled"><?php esc_html_e( 'Floating Widget', 'velox-multi-currency' ); ?></label>
							</th>
							<td>
								<label class="velox-checkbox-label">
									<input type="checkbox" name="floating_enabled" id="floating_enabled" value="yes" <?php checked( 'yes', $settings['floating_enabled'] ); ?>>
									<strong><?php esc_html_e( 'Enable floating currency switcher widget on all pages', 'velox-multi-currency' ); ?></strong>
								</label>
								<p class="description">
									<?php esc_html_e( 'Renders a modern, floating pill widget fixed to the viewport with instant dropdown selection.', 'velox-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="floating_position"><?php esc_html_e( 'Floating Widget Position', 'velox-multi-currency' ); ?></label>
							</th>
							<td>
								<select name="floating_position" id="floating_position" style="min-width: 260px;">
									<option value="bottom-right" <?php selected( 'bottom-right', $settings['floating_position'] ); ?>><?php esc_html_e( 'Bottom Right (Recommended)', 'velox-multi-currency' ); ?></option>
									<option value="bottom-left" <?php selected( 'bottom-left', $settings['floating_position'] ); ?>><?php esc_html_e( 'Bottom Left', 'velox-multi-currency' ); ?></option>
									<option value="top-right" <?php selected( 'top-right', $settings['floating_position'] ); ?>><?php esc_html_e( 'Top Right', 'velox-multi-currency' ); ?></option>
									<option value="top-left" <?php selected( 'top-left', $settings['floating_position'] ); ?>><?php esc_html_e( 'Top Left', 'velox-multi-currency' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Pin position of the floating widget on the user screen.', 'velox-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<!-- Header Switcher Section -->
						<tr>
							<th scope="row">
								<label for="header_enabled"><?php esc_html_e( 'Header Switcher', 'velox-multi-currency' ); ?></label>
							</th>
							<td>
								<label class="velox-checkbox-label">
									<input type="checkbox" name="header_enabled" id="header_enabled" value="yes" <?php checked( 'yes', $settings['header_enabled'] ); ?>>
									<strong><?php esc_html_e( 'Append currency switcher to Header Navigation Menu & Top Bar', 'velox-multi-currency' ); ?></strong>
								</label>
								<p class="description">
									<?php esc_html_e( 'Adds an integrated currency dropdown item to the main header navigation menu and top header strip.', 'velox-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<!-- Shortcode Section -->
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Shortcode & Blocks', 'velox-multi-currency' ); ?>
							</th>
							<td>
								<code>[velox_switcher]</code>
								<p class="description">
									<?php esc_html_e( 'Insert anywhere in pages, widgets, sidebars, or header templates to display a standalone currency switcher.', 'velox-multi-currency' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Switcher Settings', 'velox-multi-currency' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save Switcher Tab.
	 */
	protected function save_switcher_tab(): void {
		check_admin_referer( 'velox_save_settings', 'velox_nonce' );

		$settings = array(
			'floating_enabled'  => isset( $_POST['floating_enabled'] ) ? 'yes' : 'no',
			'floating_position' => sanitize_key( wp_unslash( $_POST['floating_position'] ?? 'bottom-right' ) ),
			'header_enabled'    => isset( $_POST['header_enabled'] ) ? 'yes' : 'no',
		);

		$this->get_switcher()->save_settings( $settings );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Switcher and display settings saved successfully.', 'velox-multi-currency' ) . '</p></div>';
	}

	/**
	 * Render Dedicated Gateway Routing & Matrix Tab.
	 */
	protected function render_gateway_routing_tab(): void {
		$enabled         = $this->currency_registry->get_enabled_currencies();
		$base            = $this->currency_registry->get_base_currency();
		$gateways        = ( function_exists( 'WC' ) && WC()->payment_gateways() ) ? WC()->payment_gateways()->payment_gateways() : array();
		$dedicated_rules = $this->capability_registry->get_dedicated_routing_rules();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'velox_save_settings', 'velox_nonce' ); ?>

			<div class="velox-panel">
				<h2><?php esc_html_e( 'Dedicated Gateway Routing (Currency-to-Gateway Locking)', 'velox-multi-currency' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure dedicated payment gateways per currency. When exclusive mode is enabled for a currency, ONLY your selected gateway(s) will be presented at checkout and cart when that currency is active. All other payment methods will be completely hidden.', 'velox-multi-currency' ); ?>
					<br>
					<strong><?php esc_html_e( 'Example:', 'velox-multi-currency' ); ?></strong> <?php esc_html_e( 'Lock INR to Razorpay only so Indian buyers cannot choose PayPal/Stripe. Lock USD to PayPal only so global buyers only see PayPal.', 'velox-multi-currency' ); ?>
				</p>

				<div class="velox-dedicated-grid">
					<?php foreach ( $enabled as $code => $data ) : ?>
						<?php
						$rule             = $dedicated_rules[ $code ] ?? array();
						$mode             = $rule['mode'] ?? 'all';
						$allowed_gateways = (array) ( $rule['allowed_gateways'] ?? array() );
						$is_exclusive     = ( 'exclusive' === $mode );
						?>
						<div class="velox-routing-card <?php echo $is_exclusive ? 'is-exclusive' : ''; ?>">
							<div class="velox-routing-card-header">
								<div class="velox-currency-badge">
									<span class="velox-badge-symbol"><?php echo esc_html( $data['symbol'] ); ?></span>
									<span class="velox-badge-code"><?php echo esc_html( $code ); ?></span>
									<?php if ( $code === $base ) : ?>
										<span class="velox-tag-base"><?php esc_html_e( 'Base', 'velox-multi-currency' ); ?></span>
									<?php endif; ?>
								</div>
								<span class="velox-currency-title"><?php echo esc_html( $data['name'] ?? $code ); ?></span>
							</div>

							<div class="velox-routing-card-body">
								<div class="velox-routing-mode-selector">
									<label class="velox-mode-radio">
										<input type="radio" name="dedicated_routing[<?php echo esc_attr( $code ); ?>][mode]" value="all" <?php checked( ! $is_exclusive ); ?> class="velox-routing-mode-toggle" data-currency="<?php echo esc_attr( $code ); ?>">
										<span><?php esc_html_e( 'Standard Routing (Matrix rules below)', 'velox-multi-currency' ); ?></span>
									</label>
									<label class="velox-mode-radio">
										<input type="radio" name="dedicated_routing[<?php echo esc_attr( $code ); ?>][mode]" value="exclusive" <?php checked( $is_exclusive ); ?> class="velox-routing-mode-toggle" data-currency="<?php echo esc_attr( $code ); ?>">
										<span><strong><?php esc_html_e( 'Dedicated Gateway Only (Exclusive)', 'velox-multi-currency' ); ?></strong></span>
									</label>
								</div>

								<div class="velox-exclusive-gateways" id="exclusive-gateways-<?php echo esc_attr( $code ); ?>" style="<?php echo $is_exclusive ? '' : 'display:none;'; ?>">
									<div class="velox-gateways-header">
										<?php esc_html_e( 'Select Allowed Gateway(s) for', 'velox-multi-currency' ); ?> <strong><?php echo esc_html( $code ); ?></strong>:
									</div>
									<div class="velox-gateways-checkboxes">
										<?php if ( empty( $gateways ) ) : ?>
											<p class="description"><?php esc_html_e( 'No payment gateways configured in WooCommerce.', 'velox-multi-currency' ); ?></p>
										<?php else : ?>
											<?php foreach ( $gateways as $gw_id => $gw ) : ?>
												<?php
												$is_checked = in_array( $gw_id, $allowed_gateways, true ) || in_array( $this->capability_registry->normalize_gateway_id( $gw_id ), $allowed_gateways, true );
												?>
												<label class="velox-gw-checkbox">
													<input type="checkbox" name="dedicated_routing[<?php echo esc_attr( $code ); ?>][allowed_gateways][]" value="<?php echo esc_attr( $gw_id ); ?>" <?php checked( $is_checked ); ?>>
													<span class="velox-gw-name"><?php echo esc_html( $gw->get_title() ?: $gw_id ); ?></span>
													<code class="velox-gw-id"><?php echo esc_html( $gw_id ); ?></code>
												</label>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="submit" style="margin-top:20px; margin-bottom:0;">
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Dedicated Gateway Routing', 'velox-multi-currency' ); ?>">
				</p>
			</div>

			<?php if ( velox_mc_is_pro() ) : ?>
				<!-- Settlement & Conversion Matrix Panel (PRO) -->
				<div class="velox-panel" style="margin-top: 24px;">
					<h2><?php esc_html_e( 'Payment Gateway & Settlement Matrix', 'velox-multi-currency' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Fine-tune how each payment gateway processes transactions when dedicated exclusive mode is not locked. Controls whether a gateway charges natively in customer currency, converts to a preferred currency with checkout disclosure, or is completely hidden.', 'velox-multi-currency' ); ?>
					</p>

					<div class="velox-matrix-scroll">
						<table class="widefat fixed striped velox-matrix-table">
							<thead>
								<tr>
									<th style="width: 200px;"><?php esc_html_e( 'Payment Gateway', 'velox-multi-currency' ); ?></th>
									<?php foreach ( $enabled as $code => $data ) : ?>
										<th>
											<strong><?php echo esc_html( $code ); ?></strong>
											<span style="font-size:11px; font-weight:normal; display:block; color:#666;">
												<?php echo esc_html( $data['symbol'] ); ?>
											</span>
										</th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $gateways ) ) : ?>
									<tr>
										<td colspan="<?php echo esc_attr( count( $enabled ) + 1 ); ?>">
											<?php esc_html_e( 'No active payment gateways found in WooCommerce.', 'velox-multi-currency' ); ?>
										</td>
									</tr>
								<?php else : ?>
									<?php foreach ( $gateways as $gateway_id => $gateway ) : ?>
										<tr>
											<td>
												<strong><?php echo esc_html( $gateway->get_title() ?: $gateway_id ); ?></strong>
												<br>
												<code style="font-size:11px;"><?php echo esc_html( $gateway_id ); ?></code>
											</td>
											<?php foreach ( $enabled as $code => $data ) : ?>
												<?php
												$rule = $this->capability_registry->get_rule( $gateway_id, $code, $base );
												$mode = $rule['mode'];
												?>
												<td>
													<select name="matrix[<?php echo esc_attr( $gateway_id ); ?>][<?php echo esc_attr( $code ); ?>][mode]" class="velox-mode-select">
														<option value="allow_native" <?php selected( $mode, 'allow_native' ); ?>><?php esc_html_e( 'Allow Native', 'velox-multi-currency' ); ?></option>
														<option value="convert_disclose" <?php selected( $mode, 'convert_disclose' ); ?>><?php esc_html_e( 'Convert & Disclose', 'velox-multi-currency' ); ?></option>
														<option value="hide" <?php selected( $mode, 'hide' ); ?>><?php esc_html_e( 'Hide Gateway', 'velox-multi-currency' ); ?></option>
													</select>
													<div class="velox-convert-to-wrap" style="margin-top: 5px; <?php echo ( 'convert_disclose' === $mode ) ? '' : 'display:none;'; ?>">
														<span style="font-size: 10px; color:#666;"><?php esc_html_e( 'Charge in:', 'velox-multi-currency' ); ?></span>
														<select name="matrix[<?php echo esc_attr( $gateway_id ); ?>][<?php echo esc_attr( $code ); ?>][convert_to]" style="font-size:11px; width:100%;">
															<?php foreach ( $enabled as $charge_code => $c_data ) : ?>
																<option value="<?php echo esc_attr( $charge_code ); ?>" <?php selected( $rule['convert_to'] ?? $base, $charge_code ); ?>>
																	<?php echo esc_html( $charge_code ); ?>
																</option>
															<?php endforeach; ?>
														</select>
													</div>
												</td>
											<?php endforeach; ?>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<p class="submit">
						<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Gateway Routing & Matrix Rules', 'velox-multi-currency' ); ?>">
					</p>
				</div>
			<?php else : ?>
				<!-- Option A: VeloX Pro Showcase Card -->
				<div class="velox-panel velox-pro-matrix-card" style="margin-top: 24px; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff; padding: 24px;">
					<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
						<div style="display:flex; align-items:center; gap:10px;">
							<span class="velox-badge" style="background:#4f46e5; color:#fff; font-size:11px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:4px;">
								<?php esc_html_e( 'PRO EXTENSION', 'velox-multi-currency' ); ?>
							</span>
							<h2 style="margin:0; font-size:1.25rem; font-weight:700; color:#0f172a;">
								<?php esc_html_e( 'Payment Gateway & Settlement Matrix', 'velox-multi-currency' ); ?>
							</h2>
						</div>
					</div>
					<p style="color:#475569; font-size:13.5px; line-height:1.6; margin:0 0 16px; max-width:850px;">
						<?php esc_html_e( 'Looking to fine-tune individual gateways per currency? VeloX Pro unlocks the full Payment Gateway Matrix with the killer "Convert & Disclose" mode. Customers browse in their domestic currency (e.g. INR or EUR), while your store automatically settles in your domestic merchant account currency (e.g. USD via PayPal or Stripe) with exact proportional item and tax scaling.', 'velox-multi-currency' ); ?>
					</p>
					<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px; margin-bottom:20px;">
						<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:14px;">
							<strong style="color:#0f172a; font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#4f46e5" class="bi bi-arrow-left-right" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1 11.5a.5.5 0 0 0 .5.5h11.793l-3.147 3.146a.5.5 0 0 0 .708.708l4-4a.5.5 0 0 0 0-.708l-4-4a.5.5 0 0 0-.708.708L13.293 11H1.5a.5.5 0 0 0-.5.5m14-7a.5.5 0 0 1-.5.5H2.707l3.147 3.146a.5.5 0 1 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 4H14.5a.5.5 0 0 1 .5.5"/></svg>
								<?php esc_html_e( 'Convert & Disclose Checkout', 'velox-multi-currency' ); ?>
							</strong>
							<span style="font-size:12px; color:#64748b;"><?php esc_html_e( 'Accept international buyers even if your domestic gateway only settles in a single currency.', 'velox-multi-currency' ); ?></span>
						</div>
						<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:14px;">
							<strong style="color:#0f172a; font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#4f46e5" class="bi bi-calculator" viewBox="0 0 16 16"><path d="M12 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z"/><path d="M4 2.5a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.5.5h-7a.5.5 0 0 1-.5-.5zm0 4a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm0 3a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm0 3a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm3-6a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm0 3a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm0 3a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm3-6a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zm0 3a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5z"/></svg>
								<?php esc_html_e( 'Proportional Order Scaling', 'velox-multi-currency' ); ?>
							</strong>
							<span style="font-size:12px; color:#64748b;"><?php esc_html_e( 'Scales line items, shipping fees, custom fees, and tax lines down to the exact fraction of a cent.', 'velox-multi-currency' ); ?></span>
						</div>
						<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:14px;">
							<strong style="color:#0f172a; font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#4f46e5" class="bi bi-shield-check" viewBox="0 0 16 16"><path d="M5.338 1.59a61 61 0 0 0-2.837.856.48.48 0 0 0-.328.39c-.554 4.157.726 7.19 2.253 9.188a10.7 10.7 0 0 0 2.287 2.233c.346.244.652.42.893.533q.18.085.293.118a1 1 0 0 0 .101.025 1 1 0 0 0 .1-.025q.114-.034.294-.118c.24-.113.547-.29.893-.533a10.7 10.7 0 0 0 2.287-2.233c1.527-1.997 2.807-5.031 2.253-9.188a.48.48 0 0 0-.328-.39c-.651-.213-1.75-.56-2.837-.855C9.552 1.29 8.531 1.067 8 1.067c-.53 0-1.552.223-2.662.524zM5.072.56C6.157.265 7.31 0 8 0s1.843.265 2.928.56c1.11.3 2.229.655 2.887.87a1.54 1.54 0 0 1 1.044 1.262c.596 4.477-.787 7.795-2.465 9.99a11.8 11.8 0 0 1-2.517 2.453 7 7 0 0 1-1.048.625c-.28.132-.581.24-.829.24s-.548-.108-.829-.24a7 7 0 0 1-1.048-.625 11.8 11.8 0 0 1-2.517-2.453C1.928 10.487.545 7.169 1.141 2.692A1.54 1.54 0 0 1 2.185 1.43 63 63 0 0 1 5.072.56"/><path d="M10.854 5.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 7.793l2.646-2.647a.5.5 0 0 1 .708 0"/></svg>
								<?php esc_html_e( 'Zero Gateway Conflicts', 'velox-multi-currency' ); ?>
							</strong>
							<span style="font-size:12px; color:#64748b;"><?php esc_html_e( 'Completely hide unsupported gateways per currency to avoid payment declines.', 'velox-multi-currency' ); ?></span>
						</div>
					</div>
					<div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
						<a href="https://vishwas.me/" target="_blank" class="button button-primary" style="background:#4f46e5; border-color:#4338ca; height:36px; line-height:34px; padding:0 22px; font-weight:600; font-size:13px;">
							<?php esc_html_e( 'Unlock Gateway Matrix in VeloX Pro &rarr;', 'velox-multi-currency' ); ?>
						</a>
						<span style="font-size:12px; color:#64748b;">
							<?php esc_html_e( 'Includes unlimited currencies, GeoIP auto-detect & 4-tier failover.', 'velox-multi-currency' ); ?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Save Dedicated Gateway Routing & Matrix Tab.
	 */
	protected function save_gateway_routing_tab(): void {
		check_admin_referer( 'velox_save_settings', 'velox_nonce' );

		// 1. Process Dedicated Routing Rules.
		$raw_dedicated   = isset( $_POST['dedicated_routing'] ) ? (array) wp_unslash( $_POST['dedicated_routing'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clean_dedicated = array();

		foreach ( $raw_dedicated as $curr => $data ) {
			$clean_curr = strtoupper( sanitize_text_field( $curr ) );
			$mode       = ( isset( $data['mode'] ) && 'exclusive' === $data['mode'] ) ? 'exclusive' : 'all';
			$allowed    = array();

			if ( ! empty( $data['allowed_gateways'] ) && is_array( $data['allowed_gateways'] ) ) {
				$allowed = array_map( 'sanitize_text_field', $data['allowed_gateways'] );
			}

			$clean_dedicated[ $clean_curr ] = array(
				'mode'             => $mode,
				'allowed_gateways' => $allowed,
			);
		}
		$this->capability_registry->save_dedicated_routing_rules( $clean_dedicated );

		// 2. Process Matrix Rules (Pro feature).
		if ( velox_mc_is_pro() && ! empty( $_POST['matrix'] ) ) {
			$matrix       = (array) wp_unslash( $_POST['matrix'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$clean_matrix = array();

			foreach ( $matrix as $gateway_id => $currencies ) {
				$clean_gw = sanitize_text_field( $gateway_id );
				foreach ( (array) $currencies as $curr => $rule ) {
					$clean_curr = strtoupper( sanitize_text_field( $curr ) );
					$mode       = sanitize_key( $rule['mode'] ?? 'allow_native' );
					$convert_to = strtoupper( sanitize_text_field( $rule['convert_to'] ?? 'USD' ) );

					if ( SettlementMode::is_valid( $mode ) ) {
						$clean_matrix[ $clean_gw ][ $clean_curr ] = array(
							'mode'       => $mode,
							'convert_to' => $convert_to,
						);
					}
				}
			}
			$this->capability_registry->save_matrix( $clean_matrix );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Gateway routing and matrix rules saved successfully.', 'velox-multi-currency' ) . '</p></div>';
	}

	/**
	 * Render Diagnostics & System Report Tab.
	 */
	protected function render_diagnostics_tab(): void {
		$base      = $this->currency_registry->get_base_currency();
		$enabled   = $this->currency_registry->get_enabled_currencies();
		$hpos      = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$last_sync = $this->rate_service->get_last_sync_timestamp();

		?>
		<div class="velox-panel">
			<h2><?php esc_html_e( 'VeloX System & Health Diagnostics', 'velox-multi-currency' ); ?></h2>
			<table class="widefat fixed striped">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Store Base Currency', 'velox-multi-currency' ); ?></strong></td>
						<td><code><?php echo esc_html( $base ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Enabled Currencies Count', 'velox-multi-currency' ); ?></strong></td>
						<td><?php echo esc_html( (string) count( $enabled ) ); ?> (<?php echo esc_html( implode( ', ', array_keys( $enabled ) ) ); ?>)</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'High-Performance Order Storage (HPOS)', 'velox-multi-currency' ); ?></strong></td>
						<td>
							<?php if ( $hpos ) : ?>
								<span style="color: #16a34a; font-weight:600; display:inline-flex; align-items:center;">
									<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#16a34a" viewBox="0 0 16 16" style="vertical-align:-1px; margin-right:5px;"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>
									<?php esc_html_e( 'Enabled & VeloX Declared Compatible', 'velox-multi-currency' ); ?>
								</span>
							<?php else : ?>
								<span><?php esc_html_e( 'Using classic post meta storage', 'velox-multi-currency' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Exchange Rate Provider', 'velox-multi-currency' ); ?></strong></td>
						<td><?php echo esc_html( $this->rate_service->get_active_provider()->get_name() ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Rates Sync Status', 'velox-multi-currency' ); ?></strong></td>
						<td>
							<?php
							if ( $last_sync > 0 ) {
								echo esc_html( date_i18n( 'Y-m-d H:i:s', $last_sync ) );
							} else {
								esc_html_e( 'Not yet synced', 'velox-multi-currency' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php if ( velox_mc_is_pro() ) : ?>
			<div class="velox-panel" style="margin-top:20px; border:1px solid #c7d2fe; background:#f5f3ff; padding:20px; border-radius:8px;">
				<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
					<div style="display:flex; align-items:center; gap:10px;">
						<span class="dashicons dashicons-shield-alt" style="color:#6366f1; font-size:24px;"></span>
						<h3 style="margin:0; font-size:15px; font-weight:600; color:#0f172a;">
							<?php esc_html_e( 'VeloX Pro Active', 'velox-multi-currency' ); ?>
						</h3>
						<span class="velox-pro-badge" style="background:#10b981; color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px;">
							<?php esc_html_e( 'ACTIVE & UNLOCKED', 'velox-multi-currency' ); ?>
						</span>
					</div>
				</div>
				<p style="color:#475569; font-size:13px; margin:10px 0 0; line-height:1.5;">
					<?php esc_html_e( 'All enterprise features are fully unlocked: Unlimited Currencies, Convert & Disclose checkout mode, MaxMind GeoIP detection, 4-tier autonomous rate failover, and Analytics normalization.', 'velox-multi-currency' ); ?>
				</p>
			</div>
		<?php else : ?>
			<div class="velox-panel" style="margin-top:20px; border:1px dashed #cbd5e1; background:#f8fafc; padding:20px; border-radius:8px;">
				<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
					<div style="display:flex; align-items:center; gap:10px;">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#4f46e5" class="bi bi-shield-check" viewBox="0 0 16 16"><path d="M5.338 1.59a61 61 0 0 0-2.837.856.48.48 0 0 0-.328.39c-.554 4.157.726 7.19 2.253 9.188a10.7 10.7 0 0 0 2.287 2.233c.346.244.652.42.893.533q.18.085.293.118a1 1 0 0 0 .101.025 1 1 0 0 0 .1-.025q.114-.034.294-.118c.24-.113.547-.29.893-.533a10.7 10.7 0 0 0 2.287-2.233c1.527-1.997 2.807-5.031 2.253-9.188a.48.48 0 0 0-.328-.39c-.651-.213-1.75-.56-2.837-.855C9.552 1.29 8.531 1.067 8 1.067c-.53 0-1.552.223-2.662.524zM5.072.56C6.157.265 7.31 0 8 0s1.843.265 2.928.56c1.11.3 2.229.655 2.887.87a1.54 1.54 0 0 1 1.044 1.262c.596 4.477-.787 7.795-2.465 9.99a11.8 11.8 0 0 1-2.517 2.453 7 7 0 0 1-1.048.625c-.28.132-.581.24-.829.24s-.548-.108-.829-.24a7 7 0 0 1-1.048-.625 11.8 11.8 0 0 1-2.517-2.453C1.928 10.487.545 7.169 1.141 2.692A1.54 1.54 0 0 1 2.185 1.43 63 63 0 0 1 5.072.56"/><path d="M10.854 5.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 7.793l2.646-2.647a.5.5 0 0 1 .708 0"/></svg>
						<h3 style="margin:0; font-size:15px; font-weight:600; color:#0f172a;">
							<?php esc_html_e( 'VeloX Pro Commercial Extension', 'velox-multi-currency' ); ?>
						</h3>
						<span class="velox-badge" style="background:#64748b; font-size:10px; font-weight:700; text-transform:uppercase; padding:2px 7px; border-radius:10px;">
							<?php esc_html_e( 'FREE TIER ACTIVE', 'velox-multi-currency' ); ?>
						</span>
					</div>
					<div>
						<a href="https://vishwas.me/" target="_blank" class="button button-secondary" style="font-size:12px; font-weight:600;">
							<?php esc_html_e( 'Upgrade to Pro &rarr;', 'velox-multi-currency' ); ?>
						</a>
					</div>
				</div>
				<p style="color:#64748b; font-size:13px; margin:10px 0 0; line-height:1.5;">
					<?php esc_html_e( 'Unlock Unlimited Currencies, Customer GeoIP Detection, High-Frequency Rate Updates (1h-8h), Payment Gateway Matrix with Convert & Disclose, and WooCommerce Analytics Normalization.', 'velox-multi-currency' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php do_action( 'velox_mc_admin_diagnostics_after' ); ?>
		<?php
	}

	/**
	 * Render Sales by Currency Report Tab.
	 */
	protected function render_sales_by_currency_tab(): void {
		$base_curr = $this->currency_registry->get_base_currency();
		$order_ids = wc_get_orders(
			array(
				'limit'  => 200,
				'status' => array( 'wc-completed', 'wc-processing' ),
				'return' => 'ids',
			)
		);

		// Preload WooCommerce Analytics order stats to use canonical conversions recorded on each order's date.
		global $wpdb;

		$stats_by_id = array();
		if ( ! empty( $order_ids ) ) {
			$clean_ids    = array_map( 'absint', $order_ids );
			$placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '%d' ) );
			$results      = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT order_id, total_sales, net_total FROM {$wpdb->prefix}wc_order_stats WHERE order_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$clean_ids
				)
			);
			if ( ! empty( $results ) ) {
				foreach ( $results as $row ) {
					$stats_by_id[ (int) $row->order_id ] = (float) $row->total_sales;
				}
			}
		}

		$stats                    = array();
		$total_store_revenue_base = 0.0;
		$total_order_count        = count( $order_ids );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$charge_curr  = $order->get_currency();
			$charge_total = (float) $order->get_total();

			// 1. If order was charged in base currency, no conversion needed.
			if ( $charge_curr === $base_curr ) {
				$order_base_eq = $charge_total;
			} elseif ( isset( $stats_by_id[ $order_id ] ) && $stats_by_id[ $order_id ] > 0 ) {
				// 2. Use exact conversion per order recorded during the order date in Analytics.
				$order_base_eq = $stats_by_id[ $order_id ];
			} else {
				// 3. Fallback: retrieve historical exchange rate snapshot recorded on the order at checkout.
				$rate = (float) $order->get_meta( '_velox_exchange_rate' );
				if ( $rate <= 0 ) {
					$gw_rate = (float) $order->get_meta( '_gateway_currency_rate' );
					if ( $gw_rate > 0 ) {
						$rate = $gw_rate > 1.0 ? ( 1.0 / $gw_rate ) : $gw_rate;
					}
				}

				if ( $rate > 0 && 1.0 !== $rate ) {
					$order_base_eq = $charge_total / $rate;
				} else {
					$order_base_eq = $charge_total;
				}
			}

			if ( ! isset( $stats[ $charge_curr ] ) ) {
				$stats[ $charge_curr ] = array(
					'orders_count'       => 0,
					'native_total'       => 0.0,
					'base_eq_total'      => 0.0,
				);
			}

			$stats[ $charge_curr ]['orders_count']  += 1;
			$stats[ $charge_curr ]['native_total']  += $charge_total;
			$stats[ $charge_curr ]['base_eq_total'] += $order_base_eq;
			$total_store_revenue_base               += $order_base_eq;
		}

		?>
		<div class="velox-panel">
			<h2><?php esc_html_e( 'Sales & Revenue Breakdown by Currency', 'velox-multi-currency' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Analyzes your last 250 completed/processing orders and calculates true normalized revenue in your store base currency.', 'velox-multi-currency' ); ?>
				<?php if ( ! velox_mc_is_pro() ) : ?>
					<span style="display:block; margin-top:6px; color:#4f46e5; font-size:12.5px;">
						<strong><?php esc_html_e( 'WooCommerce Analytics Dashboard Integration:', 'velox-multi-currency' ); ?></strong>
						<?php esc_html_e( 'VeloX Pro automatically normalizes multi-currency sales inside core WooCommerce Analytics reports (Revenue, Orders, Taxes).', 'velox-multi-currency' ); ?>
						<a href="https://vishwas.me/" target="_blank" style="color:#4338ca; font-weight:600; text-decoration:underline;"><?php esc_html_e( 'Learn more &rarr;', 'velox-multi-currency' ); ?></a>
					</span>
				<?php endif; ?>
			</p>

			<div class="velox-stat-cards">
				<div class="velox-stat-card">
					<span class="velox-stat-label"><?php esc_html_e( 'Total Orders Sampled', 'velox-multi-currency' ); ?></span>
					<div class="velox-stat-value"><?php echo esc_html( (string) $total_order_count ); ?></div>
				</div>
				<div class="velox-stat-card velox-card-primary">
					<span class="velox-stat-label">
						<?php
						/* translators: %s: Base currency code */
						printf( esc_html__( 'Total Normalized Revenue (%s)', 'velox-multi-currency' ), esc_html( $base_curr ) );
						?>
					</span>
					<div class="velox-stat-value">
						<?php echo wp_kses_post( wc_price( $total_store_revenue_base, array( 'currency' => $base_curr ) ) ); ?>
					</div>
				</div>
			</div>

			<table class="widefat fixed striped velox-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Settlement Currency', 'velox-multi-currency' ); ?></th>
						<th style="width: 120px; text-align: center;"><?php esc_html_e( 'Orders Count', 'velox-multi-currency' ); ?></th>
						<th style="text-align: right;"><?php esc_html_e( 'Revenue in Currency', 'velox-multi-currency' ); ?></th>
						<th style="text-align: right;">
							<?php
							/* translators: %s: Base currency code */
							printf( esc_html__( 'Normalized Total (%s)', 'velox-multi-currency' ), esc_html( $base_curr ) );
							?>
						</th>
						<th style="width: 120px; text-align: right;"><?php esc_html_e( 'Share of Sales', 'velox-multi-currency' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $stats ) ) : ?>
						<tr>
							<td colspan="5" style="text-align:center; padding: 24px; color: #64748b;">
								<?php esc_html_e( 'No completed or processing orders found yet.', 'velox-multi-currency' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $stats as $curr => $data ) : ?>
							<?php
							$pct = $total_store_revenue_base > 0 ? ( ( $data['base_eq_total'] / $total_store_revenue_base ) * 100 ) : 0.0;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $curr ); ?></strong>
									<?php if ( $curr === $base_curr ) : ?>
										<span class="velox-tag-base"><?php esc_html_e( 'Base', 'velox-multi-currency' ); ?></span>
									<?php endif; ?>
								</td>
								<td style="text-align: center;"><?php echo esc_html( (string) $data['orders_count'] ); ?></td>
								<td style="text-align: right; font-weight: 600;">
									<?php echo wp_kses_post( wc_price( $data['native_total'], array( 'currency' => $curr ) ) ); ?>
								</td>
								<td style="text-align: right; color: #16a34a; font-weight: 600;">
									<?php echo wp_kses_post( wc_price( $data['base_eq_total'], array( 'currency' => $base_curr ) ) ); ?>
								</td>
								<td style="text-align: right;">
									<div style="font-weight: 600;"><?php echo esc_html( number_format( $pct, 1 ) ); ?>%</div>
									<div style="background: #e2e8f0; height: 4px; border-radius: 2px; width: 100%; margin-top: 4px;">
										<div style="background: #4f46e5; height: 4px; border-radius: 2px; width: <?php echo esc_attr( min( 100, $pct ) ); ?>%;"></div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

