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
			wp_send_json_error( 'Permission denied.' );
		}

		$result = $this->rate_service->sync_rates();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Exchange rates synced successfully from European Central Bank!', 'settlex-multi-currency' ),
				'time'    => current_time( 'mysql' ),
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
					<?php esc_html_e( 'Currencies & Rates', 'settlex-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=settlex-settings&tab=gateway_matrix' ) ); ?>" class="nav-tab <?php echo 'gateway_matrix' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Payment Gateway Matrix', 'settlex-multi-currency' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=settlex-settings&tab=diagnostics' ) ); ?>" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'System & Diagnostics', 'settlex-multi-currency' ); ?>
				</a>
			</nav>

			<div class="settlex-tab-content">
				<?php
				if ( 'currencies' === $active_tab ) {
					$this->render_currencies_tab();
				} elseif ( 'gateway_matrix' === $active_tab ) {
					$this->render_gateway_matrix_tab();
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
		$enabled   = $this->currency_registry->get_enabled_currencies();
		$base      = $this->currency_registry->get_base_currency();
		$last_sync = $this->rate_service->get_last_sync_timestamp();
		$all       = $this->currency_registry->get_all_currencies();

		?>
		<div class="settlex-panel">
			<div class="settlex-panel-header">
				<h2><?php esc_html_e( 'Configured Currencies', 'settlex-multi-currency' ); ?></h2>
				<div class="settlex-sync-actions">
					<span class="settlex-last-sync">
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
					<button type="button" class="button button-secondary" id="settlex-sync-rates-btn">
						🔄 <?php esc_html_e( 'Sync Rates Now (ECB)', 'settlex-multi-currency' ); ?>
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
							<th style="width: 60px;"><?php esc_html_e( 'Action', 'settlex-multi-currency' ); ?></th>
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
								<td>
									<?php if ( ! $is_base ) : ?>
										<button type="button" class="button-link delete-currency-row text-danger" title="Remove">&times;</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="settlex-add-row" style="margin-top: 15px;">
					<label for="new_currency_select"><strong><?php esc_html_e( 'Add Currency:', 'settlex-multi-currency' ); ?></strong></label>
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
					<button type="button" class="button button-secondary" id="settlex-add-currency-btn">
						<?php esc_html_e( '+ Add to List', 'settlex-multi-currency' ); ?>
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
								<span style="color: #2e7d32; font-weight:bold;">✔ <?php esc_html_e( 'Enabled & SettleX Declared Compatible', 'settlex-multi-currency' ); ?></span>
							<?php else : ?>
								<span><?php esc_html_e( 'Using classic post meta storage', 'settlex-multi-currency' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Exchange Rate Provider', 'settlex-multi-currency' ); ?></strong></td>
						<td>European Central Bank (Free, Daily XML)</td>
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
}
