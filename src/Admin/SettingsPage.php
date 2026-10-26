<?php
/**
 * Admin Settings page for managing currencies, exchange rates, and the Gateway Matrix.
 *
 * @package SettleX\MultiCurrency\Admin
 */

namespace SettleX\MultiCurrency\Admin;

use SettleX\MultiCurrency\Currency\CurrencyRegistry;
use SettleX\MultiCurrency\Currency\RateService;
use SettleX\MultiCurrency\GatewayRouter\GatewayCapabilityRegistry;
use SettleX\MultiCurrency\GatewayRouter\SettlementMode;

defined( 'ABSPATH' ) || exit;

/**
 * SettingsPage class.
 */
class SettingsPage {

	protected CurrencyRegistry $currency_registry;
	protected RateService $rate_service;
	protected GatewayCapabilityRegistry $capability_registry;

	/**
	 * Constructor.
	 *
	 * @param CurrencyRegistry          $currency_registry   Currency registry.
	 * @param RateService               $rate_service        Rate service.
	 * @param GatewayCapabilityRegistry $capability_registry Capability registry.
	 */
	public function __construct(
		CurrencyRegistry $currency_registry,
		RateService $rate_service,
		GatewayCapabilityRegistry $capability_registry
	) {
		$this->currency_registry   = $currency_registry;
		$this->rate_service        = $rate_service;
		$this->capability_registry = $capability_registry;
	}

	/**
	 * Initialize admin hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_settlex_admin_sync_rates', array( $this, 'ajax_sync_rates' ) );
	}

	/**
	 * Add SettleX menu item under WooCommerce.
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'SettleX Multi-Currency & Gateway Router', 'settlex-multi-currency' ),
			__( 'SettleX', 'settlex-multi-currency' ),
			'manage_woocommerce',
			'settlex-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'settlex-settings' ) ) {
			return;
		}

		wp_enqueue_style(
			'settlex-admin',
			SETTLEX_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SETTLEX_VERSION
		);

		wp_enqueue_script(
			'settlex-admin',
			SETTLEX_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			SETTLEX_VERSION,
			true
		);

		wp_localize_script(
			'settlex-admin',
			'settlex_admin_params',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'settlex_admin_nonce' ),
			)
		);
	}

	/**
	 * AJAX manual rate sync handler.
	 */
	public function ajax_sync_rates(): void {
		check_ajax_referer( 'settlex_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'settlex-multi-currency' ) );
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
					__( 'Success! Exchange rates for %1$d currencies updated from %2$s.', 'settlex-multi-currency' ),
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
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'settlex_save_settings', 'settlex_nonce' ) ) {
			if ( 'currencies' === $active_tab ) {
				$this->save_currencies_tab();
			} elseif ( 'gateway_matrix' === $active_tab ) {
				$this->save_matrix_tab();
			}
		}

		?>
		<div class="wrap settlex-settings-wrap">
			<h1>
				<?php echo esc_html__( 'SettleX – Gateway Router & Multi-Currency', 'settlex-multi-currency' ); ?>
				<span class="settlex-badge">v<?php echo esc_html( SETTLEX_VERSION ); ?></span>
			</h1>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=settlex-settings&tab=currencies' ) ); ?>" class="nav-tab <?php echo 'currencies' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-cash-stack settlex-tab-icon" viewBox="0 0 16 16"><path d="M1 3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1zm7 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4"/><path d="M0 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1zm3 0a2 2 0 0 1-2 2v4a2 2 0 0 1 2 2h10a2 2 0 0 1 2-2V7a2 2 0 0 1-2-2z"/></svg>
					<?php esc_html_e( 'Currencies & Rates', 'settlex-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=settlex-settings&tab=gateway_matrix' ) ); ?>" class="nav-tab <?php echo 'gateway_matrix' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-diagram-3 settlex-tab-icon" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M6 3.5A1.5 1.5 0 0 1 7.5 2h1A1.5 1.5 0 0 1 10 3.5v1A1.5 1.5 0 0 1 8.5 6v1H14a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-1 0V8h-5v.5A1.5 1.5 0 0 1 10 10v1a1.5 1.5 0 0 1-1.5 1.5h-1A1.5 1.5 0 0 1 6 11v-1a1.5 1.5 0 0 1 1.5-1.5V8h-5v.5a.5.5 0 0 1-1 0v-1A.5.5 0 0 1 2 7h5.5V6A1.5 1.5 0 0 1 6 4.5zM8.5 3h-1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5m1.5 7h-1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5m-7 0h-1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5"/></svg>
					<?php esc_html_e( 'Payment Gateway Matrix', 'settlex-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=settlex-settings&tab=sales_by_currency' ) ); ?>" class="nav-tab <?php echo 'sales_by_currency' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-bar-chart-line settlex-tab-icon" viewBox="0 0 16 16"><path d="M11 2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12h.5a.5.5 0 0 1 0 1H.5a.5.5 0 0 1 0-1H1v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h1V7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7h1zm1 12h2V2h-2zm-3 0V7H7v7zm-5 0v-3H2v3z"/></svg>
					<?php esc_html_e( 'Sales by Currency', 'settlex-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=settlex-settings&tab=diagnostics' ) ); ?>" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-gear settlex-tab-icon" viewBox="0 0 16 16"><path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492M5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0"/><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115z"/></svg>
					<?php esc_html_e( 'System & Diagnostics', 'settlex-multi-currency' ); ?>
				</a>
			</nav>

			<div class="settlex-tab-content">
				<?php
				if ( 'currencies' === $active_tab ) {
					$this->render_currencies_tab();
				} elseif ( 'gateway_matrix' === $active_tab ) {
					$this->render_gateway_matrix_tab();
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
		<div class="settlex-panel">
			<div class="settlex-panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
				<h2><?php esc_html_e( 'Configured Currencies', 'settlex-multi-currency' ); ?></h2>
				<div class="settlex-sync-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
					<span class="settlex-last-sync" style="font-size:12px; color:#666;">
						<?php
						if ( $last_sync > 0 ) {
							printf(
								/* translators: %s: Relative human time */
								esc_html__( 'Last synced: %s ago', 'settlex-multi-currency' ),
								esc_html( human_time_diff( $last_sync, time() ) )
							);
						} else {
							esc_html_e( 'Rates not synced yet.', 'settlex-multi-currency' );
						}
						?>
					</span>
					<label for="settlex-rate-provider-select" style="font-weight:600; font-size:12px; margin-left:8px;">
						<?php esc_html_e( 'Exchange:', 'settlex-multi-currency' ); ?>
					</label>
					<select id="settlex-rate-provider-select" name="settlex_rate_provider" style="font-size:12px; max-width:280px; height:32px;">
						<?php foreach ( $providers as $key => $prov ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active_provider, $key ); ?>>
								<?php echo esc_html( $prov->get_name() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-primary" id="settlex-sync-rates-btn" style="height:32px; display:inline-flex; align-items:center; gap:6px;">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-arrow-repeat" viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
						<?php esc_html_e( 'Sync Exchange Rates', 'settlex-multi-currency' ); ?>
					</button>
				</div>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'settlex_save_settings', 'settlex_nonce' ); ?>
				<table class="widefat fixed striped settlex-table" id="settlex-currencies-table">
					<thead>
						<tr>
							<th style="width: 80px;"><?php esc_html_e( 'Currency', 'settlex-multi-currency' ); ?></th>
							<th><?php esc_html_e( 'Name', 'settlex-multi-currency' ); ?></th>
							<th style="width: 80px;"><?php esc_html_e( 'Symbol', 'settlex-multi-currency' ); ?></th>
							<th style="width: 110px;"><?php esc_html_e( 'Rate', 'settlex-multi-currency' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Markup %', 'settlex-multi-currency' ); ?></th>
							<th style="width: 140px;"><?php esc_html_e( 'Rounding Rule', 'settlex-multi-currency' ); ?></th>
							<th style="width: 70px; text-align: center;"><?php esc_html_e( 'Action', 'settlex-multi-currency' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $enabled as $code => $curr ) : ?>
							<?php $is_base = ( $code === $base ); ?>
							<tr data-currency="<?php echo esc_attr( $code ); ?>">
								<td>
									<strong><?php echo esc_html( $code ); ?></strong>
									<?php if ( $is_base ) : ?>
										<span class="settlex-tag-base"><?php esc_html_e( 'Base', 'settlex-multi-currency' ); ?></span>
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
										<option value="none" <?php selected( $curr['rounding'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None (Exact)', 'settlex-multi-currency' ); ?></option>
										<option value="charm_99" <?php selected( $curr['rounding'] ?? '', 'charm_99' ); ?>>.99 (Charm)</option>
										<option value="charm_95" <?php selected( $curr['rounding'] ?? '', 'charm_95' ); ?>>.95 (Charm)</option>
										<option value="ceil_int" <?php selected( $curr['rounding'] ?? '', 'ceil_int' ); ?>><?php esc_html_e( 'Round Up to Integer', 'settlex-multi-currency' ); ?></option>
										<option value="round_5" <?php selected( $curr['rounding'] ?? '', 'round_5' ); ?>><?php esc_html_e( 'Nearest 5', 'settlex-multi-currency' ); ?></option>
										<option value="round_10" <?php selected( $curr['rounding'] ?? '', 'round_10' ); ?>><?php esc_html_e( 'Nearest 10', 'settlex-multi-currency' ); ?></option>
									</select>
								</td>
								<td style="text-align: center; vertical-align: middle;">
									<?php if ( ! $is_base ) : ?>
										<button type="button" class="settlex-btn-delete delete-currency-row" title="<?php esc_attr_e( 'Remove currency', 'settlex-multi-currency' ); ?>" aria-label="<?php esc_attr_e( 'Remove', 'settlex-multi-currency' ); ?>">
											<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash3" viewBox="0 0 16 16"><path d="M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5M11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H1.5a.5.5 0 0 0 0 1h.538l.853 10.66A2 2 0 0 0 4.885 16h6.23a2 2 0 0 0 1.994-1.84l.853-10.66h.538a.5.5 0 0 0 0-1zm1.958 1-.846 10.58a1 1 0 0 1-.997.92h-6.23a1 1 0 0 1-.997-.92L3.042 3.5zm-7.487 1a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L5 5a.5.5 0 0 1 .47-.528M8 4.5a.5.5 0 0 1 .5.5v8.5a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5m2.472.5a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L10 5a.5.5 0 0 1 .47-.528"/></svg>
										</button>
									<?php else : ?>
										<span class="settlex-badge-locked" title="<?php esc_attr_e( 'Store base currency cannot be removed', 'settlex-multi-currency' ); ?>">&mdash;</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="settlex-add-row">
					<label for="new_currency_select"><?php esc_html_e( 'Add Currency:', 'settlex-multi-currency' ); ?></label>
					<select id="new_currency_select">
						<option value=""><?php esc_html_e( '-- Choose Currency --', 'settlex-multi-currency' ); ?></option>
						<?php foreach ( $all as $c_code => $c_data ) : ?>
							<?php if ( ! isset( $enabled[ $c_code ] ) ) : ?>
								<option value="<?php echo esc_attr( $c_code ); ?>" data-name="<?php echo esc_attr( $c_data['name'] ); ?>" data-symbol="<?php echo esc_attr( $c_data['symbol'] ); ?>">
									<?php echo esc_html( $c_code . ' - ' . $c_data['name'] ); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-secondary settlex-btn-add" id="settlex-add-currency-btn">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="bi bi-plus-lg" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/></svg>
						<?php esc_html_e( 'Add to List', 'settlex-multi-currency' ); ?>
					</button>
				</div>

				<p class="submit">
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Currency Settings', 'settlex-multi-currency' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save Currencies Tab.
	 */
	protected function save_currencies_tab(): void {
		$raw_currencies = isset( $_POST['currencies'] ) ? (array) $_POST['currencies'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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

		if ( isset( $_POST['settlex_rate_provider'] ) ) {
			$this->rate_service->set_active_provider( sanitize_key( wp_unslash( $_POST['settlex_rate_provider'] ) ) );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Currency settings saved.', 'settlex-multi-currency' ) . '</p></div>';
	}

	/**
	 * Render Payment Gateway Matrix Tab (The Hero Feature!).
	 */
	protected function render_gateway_matrix_tab(): void {
		$enabled  = $this->currency_registry->get_enabled_currencies();
		$base     = $this->currency_registry->get_base_currency();
		$gateways = WC()->payment_gateways->payment_gateways();

		// Filter for active gateways or highlight our trio (Stripe, PayPal, Razorpay).
		?>
		<div class="settlex-panel">
			<h2><?php esc_html_e( 'Payment Gateway & Currency Routing Matrix', 'settlex-multi-currency' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure how each payment gateway settles transactions for each customer currency.', 'settlex-multi-currency' ); ?>
				<br>
				<strong><?php esc_html_e( 'Allow Native:', 'settlex-multi-currency' ); ?></strong> <?php esc_html_e( 'Charge gateway directly in the customer\'s currency.', 'settlex-multi-currency' ); ?> |
				<strong><?php esc_html_e( 'Convert & Disclose:', 'settlex-multi-currency' ); ?></strong> <?php esc_html_e( 'Customer browses in their currency, but gets charged in your chosen gateway currency with a clear checkout disclosure.', 'settlex-multi-currency' ); ?> |
				<strong><?php esc_html_e( 'Hide:', 'settlex-multi-currency' ); ?></strong> <?php esc_html_e( 'Disable gateway for this currency.', 'settlex-multi-currency' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'settlex_save_settings', 'settlex_nonce' ); ?>
				<div class="settlex-matrix-scroll">
					<table class="widefat fixed striped settlex-matrix-table">
						<thead>
							<tr>
								<th style="width: 200px;"><?php esc_html_e( 'Payment Gateway', 'settlex-multi-currency' ); ?></th>
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
										<?php esc_html_e( 'No active payment gateways found in WooCommerce. Please configure Stripe, PayPal, or Razorpay in WooCommerce Settings -> Payments.', 'settlex-multi-currency' ); ?>
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
												<select name="matrix[<?php echo esc_attr( $gateway_id ); ?>][<?php echo esc_attr( $code ); ?>][mode]" class="settlex-mode-select">
													<option value="allow_native" <?php selected( $mode, 'allow_native' ); ?>><?php esc_html_e( 'Allow Native', 'settlex-multi-currency' ); ?></option>
													<option value="convert_disclose" <?php selected( $mode, 'convert_disclose' ); ?>><?php esc_html_e( 'Convert & Disclose', 'settlex-multi-currency' ); ?></option>
													<option value="hide" <?php selected( $mode, 'hide' ); ?>><?php esc_html_e( 'Hide Gateway', 'settlex-multi-currency' ); ?></option>
												</select>
												<div class="settlex-convert-to-wrap" style="margin-top: 5px; <?php echo ( 'convert_disclose' === $mode ) ? '' : 'display:none;'; ?>">
													<span style="font-size: 10px; color:#666;"><?php esc_html_e( 'Charge in:', 'settlex-multi-currency' ); ?></span>
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
					<input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Gateway Matrix Rules', 'settlex-multi-currency' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save Gateway Matrix Tab.
	 */
	protected function save_matrix_tab(): void {
		$matrix = isset( $_POST['matrix'] ) ? (array) $_POST['matrix'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clean  = array();

		foreach ( $matrix as $gateway_id => $currencies ) {
			$clean_gw = sanitize_text_field( $gateway_id );
			foreach ( $currencies as $curr => $rule ) {
				$clean_curr = strtoupper( sanitize_text_field( $curr ) );
				$mode       = sanitize_key( $rule['mode'] ?? 'allow_native' );
				$convert_to = strtoupper( sanitize_text_field( $rule['convert_to'] ?? 'USD' ) );

				if ( SettlementMode::is_valid( $mode ) ) {
					$clean[ $clean_gw ][ $clean_curr ] = array(
						'mode'       => $mode,
						'convert_to' => $convert_to,
					);
				}
			}
		}

		$this->capability_registry->save_matrix( $clean );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Payment Gateway Matrix rules saved successfully.', 'settlex-multi-currency' ) . '</p></div>';
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
		<div class="settlex-panel">
			<h2><?php esc_html_e( 'SettleX System & Health Diagnostics', 'settlex-multi-currency' ); ?></h2>
			<table class="widefat fixed striped">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Store Base Currency', 'settlex-multi-currency' ); ?></strong></td>
						<td><code><?php echo esc_html( $base ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Enabled Currencies Count', 'settlex-multi-currency' ); ?></strong></td>
						<td><?php echo esc_html( (string) count( $enabled ) ); ?> (<?php echo esc_html( implode( ', ', array_keys( $enabled ) ) ); ?>)</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'High-Performance Order Storage (HPOS)', 'settlex-multi-currency' ); ?></strong></td>
						<td>
							<?php if ( $hpos ) : ?>
								<span style="color: #16a34a; font-weight:600; display:inline-flex; align-items:center;">
									<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#16a34a" viewBox="0 0 16 16" style="vertical-align:-1px; margin-right:5px;"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>
									<?php esc_html_e( 'Enabled & SettleX Declared Compatible', 'settlex-multi-currency' ); ?>
								</span>
							<?php else : ?>
								<span><?php esc_html_e( 'Using classic post meta storage', 'settlex-multi-currency' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Exchange Rate Provider', 'settlex-multi-currency' ); ?></strong></td>
						<td><?php echo esc_html( $this->rate_service->get_active_provider()->get_name() ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Rates Sync Status', 'settlex-multi-currency' ); ?></strong></td>
						<td>
							<?php
							if ( $last_sync > 0 ) {
								echo esc_html( date_i18n( 'Y-m-d H:i:s', $last_sync ) );
							} else {
								esc_html_e( 'Not yet synced', 'settlex-multi-currency' );
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
			$rate          = (float) $order->get_meta( '_settlex_exchange_rate' );
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
		<div class="settlex-panel">
			<h2><?php esc_html_e( 'Sales & Revenue Breakdown by Currency', 'settlex-multi-currency' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Analyzes your last 250 completed/processing orders and calculates true normalized revenue in your store base currency.', 'settlex-multi-currency' ); ?>
			</p>

			<div class="settlex-stat-cards">
				<div class="settlex-stat-card">
					<span class="settlex-stat-label"><?php esc_html_e( 'Total Orders Sampled', 'settlex-multi-currency' ); ?></span>
					<div class="settlex-stat-value"><?php echo esc_html( (string) $total_order_count ); ?></div>
				</div>
				<div class="settlex-stat-card settlex-card-primary">
					<span class="settlex-stat-label"><?php printf( esc_html__( 'Total Normalized Revenue (%s)', 'settlex-multi-currency' ), esc_html( $base_curr ) ); ?></span>
					<div class="settlex-stat-value">
						<?php echo wp_kses_post( wc_price( $total_store_revenue_base, array( 'currency' => $base_curr ) ) ); ?>
					</div>
				</div>
			</div>

			<table class="widefat fixed striped settlex-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Settlement Currency', 'settlex-multi-currency' ); ?></th>
						<th style="width: 120px; text-align: center;"><?php esc_html_e( 'Orders Count', 'settlex-multi-currency' ); ?></th>
						<th style="text-align: right;"><?php esc_html_e( 'Revenue in Currency', 'settlex-multi-currency' ); ?></th>
						<th style="text-align: right;"><?php printf( esc_html__( 'Normalized Total (%s)', 'settlex-multi-currency' ), esc_html( $base_curr ) ); ?></th>
						<th style="width: 120px; text-align: right;"><?php esc_html_e( 'Share of Sales', 'settlex-multi-currency' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $stats ) ) : ?>
						<tr>
							<td colspan="5" style="text-align:center; padding: 24px; color: #64748b;">
								<?php esc_html_e( 'No completed or processing orders found yet.', 'settlex-multi-currency' ); ?>
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
										<span class="settlex-tag-base"><?php esc_html_e( 'Base', 'settlex-multi-currency' ); ?></span>
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
