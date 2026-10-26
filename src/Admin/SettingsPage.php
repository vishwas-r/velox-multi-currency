<?php
/**
 * Admin Settings page for managing currencies, exchange rates, and the Gateway Matrix.
 *
 * @package NammaWP\MultiCurrency\Admin
 */

namespace NammaWP\MultiCurrency\Admin;

use NammaWP\MultiCurrency\Currency\CurrencyRegistry;
use NammaWP\MultiCurrency\Currency\RateService;
use NammaWP\MultiCurrency\Frontend\Switcher;
use NammaWP\MultiCurrency\GatewayRouter\GatewayCapabilityRegistry;
use NammaWP\MultiCurrency\GatewayRouter\SettlementMode;

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
		add_action( 'wp_ajax_nammawp_admin_sync_rates', array( $this, 'ajax_sync_rates' ) );
	}

	/**
	 * Add NammaWP menu item under WooCommerce.
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'NammaWP Multi-Currency & Gateway Router', 'nammawp-multi-currency' ),
			__( 'NammaWP', 'nammawp-multi-currency' ),
			'manage_woocommerce',
			'nammawp-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'nammawp-settings' ) ) {
			return;
		}

		wp_enqueue_style(
			'nammawp-admin',
			NAMMAWP_MC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			NAMMAWP_MC_VERSION
		);

		wp_enqueue_script(
			'nammawp-admin',
			NAMMAWP_MC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			NAMMAWP_MC_VERSION,
			true
		);

		wp_localize_script(
			'nammawp-admin',
			'nammawp_admin_params',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'nammawp_admin_nonce' ),
			)
		);
	}

	/**
	 * AJAX manual rate sync handler.
	 */
	public function ajax_sync_rates(): void {
		check_ajax_referer( 'nammawp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'nammawp-multi-currency' ) );
		}

		$provider_key = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : null;
		$result       = $this->rate_service->sync_rates( $provider_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: 1: Count of updated currencies, 2: Provider name */
					__( 'Success! Exchange rates for %1$d currencies updated from %2$s.', 'nammawp-multi-currency' ),
					$result['count'],
					$result['provider']
				),
				'time'     => current_time( 'mysql' ),
				'provider' => $result['provider'],
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
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'nammawp_save_settings', 'nammawp_nonce' ) ) {
			if ( 'currencies' === $active_tab ) {
				$this->save_currencies_tab();
			} elseif ( 'switcher' === $active_tab ) {
				$this->save_switcher_tab();
			} elseif ( in_array( $active_tab, array( 'gateway_routing', 'gateway_matrix' ), true ) ) {
				$this->save_gateway_routing_tab();
			}
		}

		?>
		<div class="wrap nammawp-settings-wrap">
			<h1>
				<?php echo esc_html__( 'NammaWP – Gateway Router & Multi-Currency', 'nammawp-multi-currency' ); ?>
				<span class="nammawp-badge">v<?php echo esc_html( NAMMAWP_MC_VERSION ); ?></span>
			</h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nammawp-settings&tab=currencies' ) ); ?>" class="nav-tab <?php echo 'currencies' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-cash-stack nammawp-tab-icon" viewBox="0 0 16 16"><path d="M1 3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1zm7 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4"/><path d="M0 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1zm3 0a2 2 0 0 1-2 2v4a2 2 0 0 1 2 2h10a2 2 0 0 1 2-2V7a2 2 0 0 1-2-2z"/></svg>
					<?php esc_html_e( 'Currencies & Rates', 'nammawp-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nammawp-settings&tab=switcher' ) ); ?>" class="nav-tab <?php echo 'switcher' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-toggles nammawp-tab-icon" viewBox="0 0 16 16"><path d="M4.5 9a3.5 3.5 0 1 0 0 7h7a3.5 3.5 0 1 0 0-7zM8 11.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m3 3a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3M4.5 7a3.5 3.5 0 0 0 0-7h7a3.5 3.5 0 0 0 0 7zM8 4.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m3 3a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3"/></svg>
					<?php esc_html_e( 'Switcher & Display', 'nammawp-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nammawp-settings&tab=gateway_routing' ) ); ?>" class="nav-tab <?php echo in_array( $active_tab, array( 'gateway_routing', 'gateway_matrix' ), true ) ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-diagram-3 nammawp-tab-icon" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M6 3.5A1.5 1.5 0 0 1 7.5 2h1A1.5 1.5 0 0 1 10 3.5v1A1.5 1.5 0 0 1 8.5 6v1H14a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-1 0V8h-5v.5A1.5 1.5 0 0 1 10 10v1a1.5 1.5 0 0 1-1.5 1.5h-1A1.5 1.5 0 0 1 6 11v-1a1.5 1.5 0 0 1 1.5-1.5V8h-5v.5a.5.5 0 0 1-1 0v-1A.5.5 0 0 1 2 7h5.5V6A1.5 1.5 0 0 1 6 4.5zM8.5 3h-1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5m1.5 7h-1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5m-7 0h-1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5"/></svg>
					<?php esc_html_e( 'Gateway Routing', 'nammawp-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nammawp-settings&tab=sales_by_currency' ) ); ?>" class="nav-tab <?php echo 'sales_by_currency' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-bar-chart-line nammawp-tab-icon" viewBox="0 0 16 16"><path d="M11 2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12h.5a.5.5 0 0 1 0 1H.5a.5.5 0 0 1 0-1H1v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h1V7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7h1zm1 12h2V2h-2zm-3 0V7H7v7zm-5 0v-3H2v3z"/></svg>
					<?php esc_html_e( 'Sales by Currency', 'nammawp-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nammawp-settings&tab=diagnostics' ) ); ?>" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-gear nammawp-tab-icon" viewBox="0 0 16 16"><path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492M5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0"/><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115z"/></svg>
					<?php esc_html_e( 'System & Diagnostics', 'nammawp-multi-currency' ); ?>
				</a>
			</nav>

			<div class="nammawp-tab-content">
				<?php
				if ( 'currencies' === $active_tab ) {
					$this->render_currencies_tab();
				} elseif ( 'switcher' === $active_tab ) {
					$this->render_switcher_tab();
				} elseif ( in_array( $active_tab, array( 'gateway_routing', 'gateway_matrix' ), true ) ) {
					$this->render_gateway_routing_tab();
				} elseif ( 'sales_by_currency' === $active_tab ) {
					$this->render_sales_by_currency_tab();
				} else {
					$this->render_diagnostics_tab();
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
		<div class="nammawp-panel">
			<div class="nammawp-panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
				<h2><?php esc_html_e( 'Configured Currencies', 'nammawp-multi-currency' ); ?></h2>
				<div class="nammawp-sync-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
					<span class="nammawp-last-sync" style="font-size:12px; color:#666;">
						<?php
						if ( $last_sync > 0 ) {
							printf(
								/* translators: %s: Relative human time */
								esc_html__( 'Last synced: %s ago', 'nammawp-multi-currency' ),
								esc_html( human_time_diff( $last_sync, time() ) )
							);
						} else {
							esc_html_e( 'Rates not synced yet.', 'nammawp-multi-currency' );
						}
						?>
					</span>
					<label for="nammawp-rate-provider-select" style="font-weight:600; font-size:12px; margin-left:8px;">
						<?php esc_html_e( 'Exchange:', 'nammawp-multi-currency' ); ?>
					</label>
					<select id="nammawp-rate-provider-select" name="nammawp_rate_provider" style="font-size:12px; max-width:280px; height:32px;">
						<?php foreach ( $providers as $key => $prov ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active_provider, $key ); ?>>
								<?php echo esc_html( $prov->get_name() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-primary nammawp-sync-btn" id="nammawp-sync-rates-btn">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-arrow-repeat" viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
						<span><?php esc_html_e( 'Sync Exchange Rates', 'nammawp-multi-currency' ); ?></span>
					</button>
				</div>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'nammawp_save_settings', 'nammawp_nonce' ); ?>
				<table class="widefat fixed striped nammawp-table" id="nammawp-currencies-table">
					<thead>
						<tr>
							<th style="width: 80px;"><?php esc_html_e( 'Currency', 'nammawp-multi-currency' ); ?></th>
							<th><?php esc_html_e( 'Name', 'nammawp-multi-currency' ); ?></th>
							<th style="width: 80px;"><?php esc_html_e( 'Symbol', 'nammawp-multi-currency' ); ?></th>
							<th style="width: 110px;"><?php esc_html_e( 'Rate', 'nammawp-multi-currency' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Markup %', 'nammawp-multi-currency' ); ?></th>
							<th style="width: 140px;"><?php esc_html_e( 'Rounding Rule', 'nammawp-multi-currency' ); ?></th>
							<th style="width: 70px; text-align: center;"><?php esc_html_e( 'Action', 'nammawp-multi-currency' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $enabled as $code => $curr ) : ?>
							<?php $is_base = ( $code === $base ); ?>
							<tr data-currency="<?php echo esc_attr( $code ); ?>">
								<td>
									<strong><?php echo esc_html( $code ); ?></strong>
									<?php if ( $is_base ) : ?>
										<span class="nammawp-tag-base"><?php esc_html_e( 'Base', 'nammawp-multi-currency' ); ?></span>
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
										<option value="none" <?php selected( $curr['rounding'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None (Exact)', 'nammawp-multi-currency' ); ?></option>
										<option value="charm_99" <?php selected( $curr['rounding'] ?? '', 'charm_99' ); ?>>.99 (Charm)</option>
										<option value="charm_95" <?php selected( $curr['rounding'] ?? '', 'charm_95' ); ?>>.95 (Charm)</option>
										<option value="ceil_int" <?php selected( $curr['rounding'] ?? '', 'ceil_int' ); ?>><?php esc_html_e( 'Round Up to Integer', 'nammawp-multi-currency' ); ?></option>
										<option value="round_5" <?php selected( $curr['rounding'] ?? '', 'round_5' ); ?>><?php esc_html_e( 'Nearest 5', 'nammawp-multi-currency' ); ?></option>
										<option value="round_10" <?php selected( $curr['rounding'] ?? '', 'round_10' ); ?>><?php esc_html_e( 'Nearest 10', 'nammawp-multi-currency' ); ?></option>
									</select>
								</td>
								<td style="text-align: center; vertical-align: middle;">
									<?php if ( ! $is_base ) : ?>
										<button type="button" class="nammawp-btn-delete delete-currency-row" title="<?php esc_attr_e( 'Remove currency', 'nammawp-multi-currency' ); ?>" aria-label="<?php esc_attr_e( 'Remove', 'nammawp-multi-currency' ); ?>">
											<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash3" viewBox="0 0 16 16"><path d="M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5M11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H1.5a.5.5 0 0 0 0 1h.538l.853 10.66A2 2 0 0 0 4.885 16h6.23a2 2 0 0 0 1.994-1.84l.853-10.66h.538a.5.5 0 0 0 0-1zm1.958 1-.846 10.58a1 1 0 0 1-.997.92h-6.23a1 1 0 0 1-.997-.92L3.042 3.5zm-7.487 1a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L5 5a.5.5 0 0 1 .47-.528M8 4.5a.5.5 0 0 1 .5.5v8.5a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5m2.472.5a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L10 5a.5.5 0 0 1 .47-.528"/></svg>
										</button>
									<?php else : ?>
										<span class="nammawp-badge-locked" title="<?php esc_attr_e( 'Store base currency cannot be removed', 'nammawp-multi-currency' ); ?>">&mdash;</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="nammawp-add-row">
					<label for="new_currency_select"><?php esc_html_e( 'Add Currency:', 'nammawp-multi-currency' ); ?></label>
					<select id="new_currency_select">
						<option value=""><?php esc_html_e( '-- Choose Currency --', 'nammawp-multi-currency' ); ?></option>
						<?php foreach ( $all as $c_code => $c_data ) : ?>
							<?php if ( ! isset( $enabled[ $c_code ] ) ) : ?>
								<option value="<?php echo esc_attr( $c_code ); ?>" data-name="<?php echo esc_attr( $c_data['name'] ); ?>" data-symbol="<?php echo esc_attr( $c_data['symbol'] ); ?>">
									<?php echo esc_html( $c_code . ' - ' . $c_data['name'] ); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-secondary nammawp-btn-add" id="nammawp-add-currency-btn">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="bi bi-plus-lg" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/></svg>
						<?php esc_html_e( 'Add to List', 'nammawp-multi-currency' ); ?>
					</button>
				</div>

				<p class="submit" style="margin-top:20px; margin-bottom:0;">
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Currency Settings', 'nammawp-multi-currency' ); ?>">
				</p>
			</form>
		</div>

		<!-- Auto-Sync Exchange Rate Card -->
		<?php
		$auto_sync = $this->rate_service->get_auto_sync_settings();
		$next_sync = $this->rate_service->get_next_sync_timestamp();
		?>
		<div class="nammawp-panel nammawp-auto-sync-card" style="margin-top:20px;">
			<div class="nammawp-panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
				<div style="display:flex; align-items:center; gap:10px;">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#4f46e5" class="bi bi-clock-history" viewBox="0 0 16 16"><path d="m8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022zm2.004.45a7 7 0 0 0-.985-.299l.219-.976q.576.129 1.126.342zm1.37.71a7 7 0 0 0-.439-.27l.493-.87a8 8 0 0 1 .979.654l-.615.789a7 7 0 0 0-.418-.302zm1.834 1.79a7 7 0 0 0-.653-.796l.724-.69q.406.429.747.91zm.744 1.352a7 7 0 0 0-.214-.468l.893-.45a8 8 0 0 1 .45 1.088l-.95.313a7 7 0 0 0-.179-.483m.53 2.507a7 7 0 0 0-.1-1.025l.985-.17q.1.586.113 1.196l-1 .009a7 7 0 0 0 .002-.01m-.182 2.553a7 7 0 0 0 .285-.985l.976.219a8 8 0 0 1-.342 1.126zM9 13l3-3-1.414-1.414L9 10.172V5H7v6.828l-1.586-1.586L4 11.657 7.657 15.314a2 2 0 0 0 2.828 0z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0"/></svg>
					<h2 style="margin:0; font-size:1.25rem; font-weight:600;">
						<?php esc_html_e( 'Automatic Exchange Rate Sync', 'nammawp-multi-currency' ); ?>
					</h2>
					<?php if ( 'yes' === $auto_sync['enabled'] ) : ?>
						<span class="nammawp-badge" style="background:#10b981; font-weight:600; padding:3px 10px; border-radius:9999px;">
							<?php
							/* translators: %d: Number of hours */
							printf( esc_html__( 'Active: Every %d Hours', 'nammawp-multi-currency' ), (int) $auto_sync['interval_hours'] );
							?>
						</span>
					<?php else : ?>
						<span class="nammawp-badge" style="background:#64748b; font-weight:600; padding:3px 10px; border-radius:9999px;">
							<?php esc_html_e( 'Auto-Sync Disabled', 'nammawp-multi-currency' ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div style="font-size:12px; color:#64748b;">
					<?php esc_html_e( 'Powered by WordPress Cron scheduler (no external server cron job required)', 'nammawp-multi-currency' ); ?>
				</div>
			</div>

			<!-- Status Metric Tiles -->
			<div class="nammawp-sync-stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:20px;">
				<!-- Tile 1: Last Sync -->
				<div class="nammawp-stat-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;">
					<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
						<?php esc_html_e( 'Last Successful Sync', 'nammawp-multi-currency' ); ?>
					</div>
					<div style="font-size:15px; font-weight:700; color:#0f172a;">
						<?php
						if ( $last_sync > 0 ) {
							echo esc_html( human_time_diff( $last_sync, time() ) . ' ' . __( 'ago', 'nammawp-multi-currency' ) );
						} else {
							esc_html_e( 'Never', 'nammawp-multi-currency' );
						}
						?>
					</div>
					<div style="font-size:11px; color:#94a3b8; margin-top:3px;">
						<?php echo $last_sync > 0 ? esc_html( wp_date( 'M j, Y H:i:s', $last_sync ) ) : esc_html__( 'Click Sync Rates to initialize', 'nammawp-multi-currency' ); ?>
					</div>
				</div>

				<!-- Tile 2: Next Scheduled Sync -->
				<div class="nammawp-stat-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;">
					<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
						<?php esc_html_e( 'Next Scheduled Sync', 'nammawp-multi-currency' ); ?>
					</div>
					<div style="font-size:15px; font-weight:700; color:<?php echo ( 'yes' === $auto_sync['enabled'] && $next_sync ) ? '#10b981' : '#64748b'; ?>;">
						<?php
						if ( 'yes' === $auto_sync['enabled'] && $next_sync ) {
							echo esc_html( 'In ' . human_time_diff( time(), $next_sync ) );
						} else {
							esc_html_e( 'Paused / Disabled', 'nammawp-multi-currency' );
						}
						?>
					</div>
					<div style="font-size:11px; color:#94a3b8; margin-top:3px;">
						<?php echo ( 'yes' === $auto_sync['enabled'] && $next_sync ) ? esc_html( wp_date( 'M j, Y H:i:s', $next_sync ) ) : esc_html__( 'Enable auto-sync below to activate schedule', 'nammawp-multi-currency' ); ?>
					</div>
				</div>

				<!-- Tile 3: Active Provider -->
				<div class="nammawp-stat-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;">
					<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
						<?php esc_html_e( 'Active Exchange Provider', 'nammawp-multi-currency' ); ?>
					</div>
					<div style="font-size:15px; font-weight:700; color:#4f46e5;">
						<?php echo esc_html( isset( $providers[ $active_provider ] ) ? $providers[ $active_provider ]->get_name() : $active_provider ); ?>
					</div>
					<div style="font-size:11px; color:#94a3b8; margin-top:3px;">
						<?php esc_html_e( 'Free zero-key provider', 'nammawp-multi-currency' ); ?>
					</div>
				</div>
			</div>

			<!-- Form Controls for Auto-Sync -->
			<form method="post" action="">
				<?php wp_nonce_field( 'nammawp_save_settings', 'nammawp_nonce' ); ?>
				<input type="hidden" name="nammawp_action" value="save_auto_sync">

				<table class="form-table nammawp-form-table" style="margin-top:0;">
					<tbody>
						<!-- Enable/Disable Auto-Sync -->
						<tr>
							<th scope="row" style="width:220px;">
								<label for="auto_sync_enabled"><?php esc_html_e( 'Auto-Sync Exchange Rates', 'nammawp-multi-currency' ); ?></label>
							</th>
							<td>
								<label class="nammawp-checkbox-label" style="font-size:14px;">
									<input type="checkbox" name="auto_sync_enabled" id="auto_sync_enabled" value="yes" <?php checked( 'yes', $auto_sync['enabled'] ); ?>>
									<strong><?php esc_html_e( 'Enable automatic background exchange rate synchronization', 'nammawp-multi-currency' ); ?></strong>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, NammaWP periodically fetches live exchange rates in the background for all enabled currencies without manual intervention.', 'nammawp-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<!-- Sync Frequency (Every X Hours) -->
						<tr>
							<th scope="row">
								<label for="auto_sync_interval_select"><?php esc_html_e( 'Sync Duration (Every X Hours)', 'nammawp-multi-currency' ); ?></label>
							</th>
							<td>
								<?php
								$current_hours    = (int) $auto_sync['interval_hours'];
								$standard_presets = array( 1, 2, 4, 6, 8, 12, 24, 48, 72, 168 );
								$is_custom        = ! in_array( $current_hours, $standard_presets, true );
								?>
								<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
									<select name="auto_sync_interval_select" id="auto_sync_interval_select" style="min-width:240px; height:34px;">
										<option value="1" <?php selected( 1, $current_hours ); ?>><?php esc_html_e( 'Every 1 Hour', 'nammawp-multi-currency' ); ?></option>
										<option value="2" <?php selected( 2, $current_hours ); ?>><?php esc_html_e( 'Every 2 Hours', 'nammawp-multi-currency' ); ?></option>
										<option value="4" <?php selected( 4, $current_hours ); ?>><?php esc_html_e( 'Every 4 Hours', 'nammawp-multi-currency' ); ?></option>
										<option value="6" <?php selected( 6, $current_hours ); ?>><?php esc_html_e( 'Every 6 Hours', 'nammawp-multi-currency' ); ?></option>
										<option value="8" <?php selected( 8, $current_hours ); ?>><?php esc_html_e( 'Every 8 Hours', 'nammawp-multi-currency' ); ?></option>
										<option value="12" <?php selected( 12, $current_hours ); ?>><?php esc_html_e( 'Every 12 Hours (Twice daily)', 'nammawp-multi-currency' ); ?></option>
										<option value="24" <?php selected( 24, $current_hours ); ?>><?php esc_html_e( 'Every 24 Hours (Once daily)', 'nammawp-multi-currency' ); ?></option>
										<option value="48" <?php selected( 48, $current_hours ); ?>><?php esc_html_e( 'Every 48 Hours (Every 2 days)', 'nammawp-multi-currency' ); ?></option>
										<option value="168" <?php selected( 168, $current_hours ); ?>><?php esc_html_e( 'Every 168 Hours (Weekly)', 'nammawp-multi-currency' ); ?></option>
										<option value="custom" <?php selected( true, $is_custom ); ?>><?php esc_html_e( 'Custom Duration...', 'nammawp-multi-currency' ); ?></option>
									</select>

									<div id="nammawp-custom-hours-wrapper" style="display:<?php echo $is_custom ? 'inline-flex' : 'none'; ?>; align-items:center; gap:6px;">
										<label for="auto_sync_interval_custom" style="font-weight:500; font-size:13px;"><?php esc_html_e( 'Every', 'nammawp-multi-currency' ); ?></label>
										<input type="number" min="1" max="720" step="1" name="auto_sync_interval_custom" id="auto_sync_interval_custom" value="<?php echo esc_attr( $current_hours ); ?>" style="width:80px; height:34px;">
										<span style="font-size:13px; color:#475569;"><?php esc_html_e( 'Hours', 'nammawp-multi-currency' ); ?></span>
									</div>
								</div>
								<p class="description">
									<?php esc_html_e( 'Choose how often exchange rates are updated. Common choices: 6, 12, or 24 hours.', 'nammawp-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<!-- Rate Provider Option -->
						<tr>
							<th scope="row">
								<label for="nammawp_rate_provider_card"><?php esc_html_e( 'Exchange Rate Provider', 'nammawp-multi-currency' ); ?></label>
							</th>
							<td>
								<select id="nammawp_rate_provider_card" name="nammawp_rate_provider" style="min-width:240px; height:34px;">
									<?php foreach ( $providers as $key => $prov ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active_provider, $key ); ?>>
											<?php echo esc_html( $prov->get_name() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'The financial exchange service used during automatic updates.', 'nammawp-multi-currency' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<div style="display:flex; align-items:center; gap:12px; margin-top:20px;">
					<input type="submit" name="submit_auto_sync" class="button button-primary" value="<?php esc_attr_e( 'Save Auto-Sync Settings', 'nammawp-multi-currency' ); ?>" style="height:34px; padding:0 20px;">
					<button type="button" class="button button-secondary nammawp-sync-btn" id="nammawp-sync-rates-btn-2" style="height:34px;">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-arrow-repeat" viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
						<span><?php esc_html_e( 'Sync Rates Now', 'nammawp-multi-currency' ); ?></span>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Save Currencies Tab.
	 */
	protected function save_currencies_tab(): void {
		check_admin_referer( 'nammawp_save_settings', 'nammawp_nonce' );

		// 1. Handle Auto-Sync Settings Save
		if ( ( isset( $_POST['nammawp_action'] ) && 'save_auto_sync' === $_POST['nammawp_action'] ) || isset( $_POST['submit_auto_sync'] ) ) {
			$enabled    = ( ! empty( $_POST['auto_sync_enabled'] ) && 'yes' === $_POST['auto_sync_enabled'] ) ? 'yes' : 'no';
			$select_val = sanitize_text_field( wp_unslash( $_POST['auto_sync_interval_select'] ?? '12' ) );

			if ( 'custom' === $select_val ) {
				$interval_hours = max( 1, absint( wp_unslash( $_POST['auto_sync_interval_custom'] ?? 12 ) ) );
			} else {
				$interval_hours = max( 1, absint( $select_val ) );
			}

			$this->rate_service->save_auto_sync_settings(
				array(
					'enabled'        => $enabled,
					'interval_hours' => $interval_hours,
				)
			);

			if ( isset( $_POST['nammawp_rate_provider'] ) ) {
				$this->rate_service->set_active_provider( sanitize_key( wp_unslash( $_POST['nammawp_rate_provider'] ) ) );
			}

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Auto-sync settings updated successfully.', 'nammawp-multi-currency' ) . '</p></div>';
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

		$this->currency_registry->save_enabled_currencies( $updated );

		if ( isset( $_POST['nammawp_rate_provider'] ) ) {
			$this->rate_service->set_active_provider( sanitize_key( wp_unslash( $_POST['nammawp_rate_provider'] ) ) );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Currency settings saved.', 'nammawp-multi-currency' ) . '</p></div>';
	}

	/**
	 * Render Switcher & Display Settings Tab.
	 */
	protected function render_switcher_tab(): void {
		$switcher = $this->get_switcher();
		$settings = $switcher->get_settings();
		?>
		<div class="nammawp-panel">
			<h2><?php esc_html_e( 'Currency Switcher & Display Options', 'nammawp-multi-currency' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure how currency switchers appear to shoppers across your store. All currency switches happen instantly via AJAX with zero page reload, seamlessly updating product prices across Shop, Archive, Single Product, Cart, and Checkout pages.', 'nammawp-multi-currency' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'nammawp_save_settings', 'nammawp_nonce' ); ?>

				<table class="form-table nammawp-form-table">
					<tbody>
						<!-- Floating Widget Section -->
						<tr>
							<th scope="row">
								<label for="floating_enabled"><?php esc_html_e( 'Floating Widget', 'nammawp-multi-currency' ); ?></label>
							</th>
							<td>
								<label class="nammawp-checkbox-label">
									<input type="checkbox" name="floating_enabled" id="floating_enabled" value="yes" <?php checked( 'yes', $settings['floating_enabled'] ); ?>>
									<strong><?php esc_html_e( 'Enable floating currency switcher widget on all pages', 'nammawp-multi-currency' ); ?></strong>
								</label>
								<p class="description">
									<?php esc_html_e( 'Renders a modern, floating pill widget fixed to the viewport with instant dropdown selection.', 'nammawp-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="floating_position"><?php esc_html_e( 'Floating Widget Position', 'nammawp-multi-currency' ); ?></label>
							</th>
							<td>
								<select name="floating_position" id="floating_position" style="min-width: 260px;">
									<option value="bottom-right" <?php selected( 'bottom-right', $settings['floating_position'] ); ?>><?php esc_html_e( 'Bottom Right (Recommended)', 'nammawp-multi-currency' ); ?></option>
									<option value="bottom-left" <?php selected( 'bottom-left', $settings['floating_position'] ); ?>><?php esc_html_e( 'Bottom Left', 'nammawp-multi-currency' ); ?></option>
									<option value="top-right" <?php selected( 'top-right', $settings['floating_position'] ); ?>><?php esc_html_e( 'Top Right', 'nammawp-multi-currency' ); ?></option>
									<option value="top-left" <?php selected( 'top-left', $settings['floating_position'] ); ?>><?php esc_html_e( 'Top Left', 'nammawp-multi-currency' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Pin position of the floating widget on the user screen.', 'nammawp-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<!-- Header Switcher Section -->
						<tr>
							<th scope="row">
								<label for="header_enabled"><?php esc_html_e( 'Header Switcher', 'nammawp-multi-currency' ); ?></label>
							</th>
							<td>
								<label class="nammawp-checkbox-label">
									<input type="checkbox" name="header_enabled" id="header_enabled" value="yes" <?php checked( 'yes', $settings['header_enabled'] ); ?>>
									<strong><?php esc_html_e( 'Append currency switcher to Header Navigation Menu & Top Bar', 'nammawp-multi-currency' ); ?></strong>
								</label>
								<p class="description">
									<?php esc_html_e( 'Adds an integrated currency dropdown item to the main header navigation menu and top header strip.', 'nammawp-multi-currency' ); ?>
								</p>
							</td>
						</tr>

						<!-- Shortcode Section -->
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Shortcode & Blocks', 'nammawp-multi-currency' ); ?>
							</th>
							<td>
								<code>[nammawp_switcher]</code>
								<p class="description">
									<?php esc_html_e( 'Insert anywhere in pages, widgets, sidebars, or header templates to display a standalone currency switcher.', 'nammawp-multi-currency' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Switcher Settings', 'nammawp-multi-currency' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save Switcher Tab.
	 */
	protected function save_switcher_tab(): void {
		check_admin_referer( 'nammawp_save_settings', 'nammawp_nonce' );

		$settings = array(
			'floating_enabled'  => isset( $_POST['floating_enabled'] ) ? 'yes' : 'no',
			'floating_position' => sanitize_key( wp_unslash( $_POST['floating_position'] ?? 'bottom-right' ) ),
			'header_enabled'    => isset( $_POST['header_enabled'] ) ? 'yes' : 'no',
		);

		$this->get_switcher()->save_settings( $settings );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Switcher and display settings saved successfully.', 'nammawp-multi-currency' ) . '</p></div>';
	}

	/**
	 * Render Dedicated Gateway Routing & Matrix Tab.
	 */
	protected function render_gateway_routing_tab(): void {
		$enabled         = $this->currency_registry->get_enabled_currencies();
		$base            = $this->currency_registry->get_base_currency();
		$gateways        = WC()->payment_gateways->payment_gateways();
		$dedicated_rules = $this->capability_registry->get_dedicated_routing_rules();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'nammawp_save_settings', 'nammawp_nonce' ); ?>

			<div class="nammawp-panel">
				<h2><?php esc_html_e( 'Dedicated Gateway Routing (Currency-to-Gateway Locking)', 'nammawp-multi-currency' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure dedicated payment gateways per currency. When exclusive mode is enabled for a currency, ONLY your selected gateway(s) will be presented at checkout and cart when that currency is active. All other payment methods will be completely hidden.', 'nammawp-multi-currency' ); ?>
					<br>
					<strong><?php esc_html_e( 'Example:', 'nammawp-multi-currency' ); ?></strong> <?php esc_html_e( 'Lock INR to Razorpay only so Indian buyers cannot choose PayPal/Stripe. Lock USD to PayPal only so global buyers only see PayPal.', 'nammawp-multi-currency' ); ?>
				</p>

				<div class="nammawp-dedicated-grid">
					<?php foreach ( $enabled as $code => $data ) : ?>
						<?php
						$rule             = $dedicated_rules[ $code ] ?? array();
						$mode             = $rule['mode'] ?? 'all';
						$allowed_gateways = (array) ( $rule['allowed_gateways'] ?? array() );
						$is_exclusive     = ( 'exclusive' === $mode );
						?>
						<div class="nammawp-routing-card <?php echo $is_exclusive ? 'is-exclusive' : ''; ?>">
							<div class="nammawp-routing-card-header">
								<div class="nammawp-currency-badge">
									<span class="nammawp-badge-symbol"><?php echo esc_html( $data['symbol'] ); ?></span>
									<span class="nammawp-badge-code"><?php echo esc_html( $code ); ?></span>
									<?php if ( $code === $base ) : ?>
										<span class="nammawp-tag-base"><?php esc_html_e( 'Base', 'nammawp-multi-currency' ); ?></span>
									<?php endif; ?>
								</div>
								<span class="nammawp-currency-title"><?php echo esc_html( $data['name'] ?? $code ); ?></span>
							</div>

							<div class="nammawp-routing-card-body">
								<div class="nammawp-routing-mode-selector">
									<label class="nammawp-mode-radio">
										<input type="radio" name="dedicated_routing[<?php echo esc_attr( $code ); ?>][mode]" value="all" <?php checked( ! $is_exclusive ); ?> class="nammawp-routing-mode-toggle" data-currency="<?php echo esc_attr( $code ); ?>">
										<span><?php esc_html_e( 'Standard Routing (Matrix rules below)', 'nammawp-multi-currency' ); ?></span>
									</label>
									<label class="nammawp-mode-radio">
										<input type="radio" name="dedicated_routing[<?php echo esc_attr( $code ); ?>][mode]" value="exclusive" <?php checked( $is_exclusive ); ?> class="nammawp-routing-mode-toggle" data-currency="<?php echo esc_attr( $code ); ?>">
										<span><strong><?php esc_html_e( 'Dedicated Gateway Only (Exclusive)', 'nammawp-multi-currency' ); ?></strong></span>
									</label>
								</div>

								<div class="nammawp-exclusive-gateways" id="exclusive-gateways-<?php echo esc_attr( $code ); ?>" style="<?php echo $is_exclusive ? '' : 'display:none;'; ?>">
									<div class="nammawp-gateways-header">
										<?php esc_html_e( 'Select Allowed Gateway(s) for', 'nammawp-multi-currency' ); ?> <strong><?php echo esc_html( $code ); ?></strong>:
									</div>
									<div class="nammawp-gateways-checkboxes">
										<?php if ( empty( $gateways ) ) : ?>
											<p class="description"><?php esc_html_e( 'No payment gateways configured in WooCommerce.', 'nammawp-multi-currency' ); ?></p>
										<?php else : ?>
											<?php foreach ( $gateways as $gw_id => $gw ) : ?>
												<?php
												$is_checked = in_array( $gw_id, $allowed_gateways, true ) || in_array( $this->capability_registry->normalize_gateway_id( $gw_id ), $allowed_gateways, true );
												?>
												<label class="nammawp-gw-checkbox">
													<input type="checkbox" name="dedicated_routing[<?php echo esc_attr( $code ); ?>][allowed_gateways][]" value="<?php echo esc_attr( $gw_id ); ?>" <?php checked( $is_checked ); ?>>
													<span class="nammawp-gw-name"><?php echo esc_html( $gw->get_title() ?: $gw_id ); ?></span>
													<code class="nammawp-gw-id"><?php echo esc_html( $gw_id ); ?></code>
												</label>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Settlement & Conversion Matrix Panel -->
			<div class="nammawp-panel" style="margin-top: 24px;">
				<h2><?php esc_html_e( 'Payment Gateway & Settlement Matrix', 'nammawp-multi-currency' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Fine-tune how each payment gateway processes transactions when dedicated exclusive mode is not locked. Controls whether a gateway charges natively in customer currency, converts to a preferred currency with checkout disclosure, or is completely hidden.', 'nammawp-multi-currency' ); ?>
				</p>

				<div class="nammawp-matrix-scroll">
					<table class="widefat fixed striped nammawp-matrix-table">
						<thead>
							<tr>
								<th style="width: 200px;"><?php esc_html_e( 'Payment Gateway', 'nammawp-multi-currency' ); ?></th>
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
										<?php esc_html_e( 'No active payment gateways found in WooCommerce.', 'nammawp-multi-currency' ); ?>
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
												<select name="matrix[<?php echo esc_attr( $gateway_id ); ?>][<?php echo esc_attr( $code ); ?>][mode]" class="nammawp-mode-select">
													<option value="allow_native" <?php selected( $mode, 'allow_native' ); ?>><?php esc_html_e( 'Allow Native', 'nammawp-multi-currency' ); ?></option>
													<option value="convert_disclose" <?php selected( $mode, 'convert_disclose' ); ?>><?php esc_html_e( 'Convert & Disclose', 'nammawp-multi-currency' ); ?></option>
													<option value="hide" <?php selected( $mode, 'hide' ); ?>><?php esc_html_e( 'Hide Gateway', 'nammawp-multi-currency' ); ?></option>
												</select>
												<div class="nammawp-convert-to-wrap" style="margin-top: 5px; <?php echo ( 'convert_disclose' === $mode ) ? '' : 'display:none;'; ?>">
													<span style="font-size: 10px; color:#666;"><?php esc_html_e( 'Charge in:', 'nammawp-multi-currency' ); ?></span>
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
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Gateway Routing & Matrix Rules', 'nammawp-multi-currency' ); ?>">
				</p>
			</div>
		</form>
		<?php
	}

	/**
	 * Save Dedicated Gateway Routing & Matrix Tab.
	 */
	protected function save_gateway_routing_tab(): void {
		check_admin_referer( 'nammawp_save_settings', 'nammawp_nonce' );

		// 1. Process Dedicated Routing Rules.
		$raw_dedicated   = isset( $_POST['dedicated_routing'] ) ? (array) wp_unslash( $_POST['dedicated_routing'] ) : array();
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

		// 2. Process Matrix Rules.
		$matrix       = isset( $_POST['matrix'] ) ? (array) wp_unslash( $_POST['matrix'] ) : array();
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

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Gateway routing and matrix rules saved successfully.', 'nammawp-multi-currency' ) . '</p></div>';
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
		<div class="nammawp-panel">
			<h2><?php esc_html_e( 'NammaWP System & Health Diagnostics', 'nammawp-multi-currency' ); ?></h2>
			<table class="widefat fixed striped">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Store Base Currency', 'nammawp-multi-currency' ); ?></strong></td>
						<td><code><?php echo esc_html( $base ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Enabled Currencies Count', 'nammawp-multi-currency' ); ?></strong></td>
						<td><?php echo esc_html( (string) count( $enabled ) ); ?> (<?php echo esc_html( implode( ', ', array_keys( $enabled ) ) ); ?>)</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'High-Performance Order Storage (HPOS)', 'nammawp-multi-currency' ); ?></strong></td>
						<td>
							<?php if ( $hpos ) : ?>
								<span style="color: #16a34a; font-weight:600; display:inline-flex; align-items:center;">
									<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#16a34a" viewBox="0 0 16 16" style="vertical-align:-1px; margin-right:5px;"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>
									<?php esc_html_e( 'Enabled & NammaWP Declared Compatible', 'nammawp-multi-currency' ); ?>
								</span>
							<?php else : ?>
								<span><?php esc_html_e( 'Using classic post meta storage', 'nammawp-multi-currency' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Exchange Rate Provider', 'nammawp-multi-currency' ); ?></strong></td>
						<td><?php echo esc_html( $this->rate_service->get_active_provider()->get_name() ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Rates Sync Status', 'nammawp-multi-currency' ); ?></strong></td>
						<td>
							<?php
							if ( $last_sync > 0 ) {
								echo esc_html( date_i18n( 'Y-m-d H:i:s', $last_sync ) );
							} else {
								esc_html_e( 'Not yet synced', 'nammawp-multi-currency' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render Sales by Currency Report Tab.
	 */
	protected function render_sales_by_currency_tab(): void {
		$base_curr = $this->currency_registry->get_base_currency();
		$orders    = wc_get_orders(
			array(
				'limit'  => 250,
				'status' => array( 'wc-completed', 'wc-processing' ),
			)
		);

		$stats = array();
		$total_store_revenue_base = 0.0;
		$total_order_count        = count( $orders );

		foreach ( $orders as $order ) {
			$charge_curr   = $order->get_currency();
			$charge_total  = (float) $order->get_total();
			$rate          = (float) $order->get_meta( '_nammawp_exchange_rate' );
			$order_base_eq = ( $rate > 0 && 1.0 !== $rate ) ? ( $charge_total / $rate ) : $charge_total;

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
		<div class="nammawp-panel">
			<h2><?php esc_html_e( 'Sales & Revenue Breakdown by Currency', 'nammawp-multi-currency' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Analyzes your last 250 completed/processing orders and calculates true normalized revenue in your store base currency.', 'nammawp-multi-currency' ); ?>
			</p>

			<div class="nammawp-stat-cards">
				<div class="nammawp-stat-card">
					<span class="nammawp-stat-label"><?php esc_html_e( 'Total Orders Sampled', 'nammawp-multi-currency' ); ?></span>
					<div class="nammawp-stat-value"><?php echo esc_html( (string) $total_order_count ); ?></div>
				</div>
				<div class="nammawp-stat-card nammawp-card-primary">
					<span class="nammawp-stat-label">
						<?php
						/* translators: %s: Base currency code */
						printf( esc_html__( 'Total Normalized Revenue (%s)', 'nammawp-multi-currency' ), esc_html( $base_curr ) );
						?>
					</span>
					<div class="nammawp-stat-value">
						<?php echo wp_kses_post( wc_price( $total_store_revenue_base, array( 'currency' => $base_curr ) ) ); ?>
					</div>
				</div>
			</div>

			<table class="widefat fixed striped nammawp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Settlement Currency', 'nammawp-multi-currency' ); ?></th>
						<th style="width: 120px; text-align: center;"><?php esc_html_e( 'Orders Count', 'nammawp-multi-currency' ); ?></th>
						<th style="text-align: right;"><?php esc_html_e( 'Revenue in Currency', 'nammawp-multi-currency' ); ?></th>
						<th style="text-align: right;">
							<?php
							/* translators: %s: Base currency code */
							printf( esc_html__( 'Normalized Total (%s)', 'nammawp-multi-currency' ), esc_html( $base_curr ) );
							?>
						</th>
						<th style="width: 120px; text-align: right;"><?php esc_html_e( 'Share of Sales', 'nammawp-multi-currency' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $stats ) ) : ?>
						<tr>
							<td colspan="5" style="text-align:center; padding: 24px; color: #64748b;">
								<?php esc_html_e( 'No completed or processing orders found yet.', 'nammawp-multi-currency' ); ?>
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
										<span class="nammawp-tag-base"><?php esc_html_e( 'Base', 'nammawp-multi-currency' ); ?></span>
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

