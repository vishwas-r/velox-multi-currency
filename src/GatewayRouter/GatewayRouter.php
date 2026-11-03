<?php
/**
 * GatewayRouter hooks into Classic and Block checkout to filter gateways and apply disclosures.
 *
 * @package VeloX\MultiCurrency\GatewayRouter
 */

namespace VeloX\MultiCurrency\GatewayRouter;

use VeloX\MultiCurrency\Currency\CurrencyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * GatewayRouter class.
 */
class GatewayRouter {

	/**
	 * GatewayCapabilityRegistry.
	 *
	 * @var GatewayCapabilityRegistry
	 */
	protected GatewayCapabilityRegistry $capability_registry;

	/**
	 * CurrencyRegistry.
	 *
	 * @var CurrencyRegistry
	 */
	protected CurrencyRegistry $currency_registry;

	/**
	 * SettlementResolver.
	 *
	 * @var SettlementResolver
	 */
	protected SettlementResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param GatewayCapabilityRegistry $capability_registry Registry.
	 * @param CurrencyRegistry          $currency_registry   Currency registry.
	 * @param SettlementResolver        $resolver            Resolver.
	 */
	public function __construct(
		GatewayCapabilityRegistry $capability_registry,
		CurrencyRegistry $currency_registry,
		SettlementResolver $resolver
	) {
		$this->capability_registry = $capability_registry;
		$this->currency_registry   = $currency_registry;
		$this->resolver            = $resolver;
	}

	/**
	 * Initialize gateway routing hooks.
	 */
	public function init(): void {
		// 1. Classic Checkout: filter available gateways.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_gateways' ), 20, 1 );

		// 2. Checkout Blocks: filter Store API payment methods.
		add_filter( 'woocommerce_store_api_disable_payment_methods', array( $this, 'filter_store_api_payment_methods' ), 20, 2 );

		// 3. Render settlement disclosure notice on checkout before payment button.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_disclosure' ) );

		// 4. AJAX handler for refreshing disclosure when payment method is toggled.
		add_action( 'wp_ajax_velox_get_gateway_disclosure', array( $this, 'ajax_get_disclosure' ) );
		add_action( 'wp_ajax_nopriv_velox_get_gateway_disclosure', array( $this, 'ajax_get_disclosure' ) );

		// 5. Ensure "Use a new payment method" radio or first token is checked by default if none is selected.
		add_filter( 'wc_payment_gateway_form_saved_payment_methods_html', array( $this, 'filter_saved_payment_methods_html' ), 10, 2 );
	}

	/**
	 * Filter gateways in classic checkout based on active currency.
	 *
	 * @param array $available_gateways Active gateways.
	 * @return array Filtered gateways.
	 */
	public function filter_available_gateways( $available_gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $available_gateways;
		}

		if ( ! is_array( $available_gateways ) || empty( $available_gateways ) ) {
			return $available_gateways;
		}

		$display_currency  = $this->currency_registry->get_active_currency();
		$base_currency     = $this->currency_registry->get_base_currency();
		$dedicated_routing = $this->capability_registry->get_dedicated_routing( $display_currency );

		// 1. Dedicated Exclusive Gateway Routing (takes precedence if configured).
		if ( ! empty( $dedicated_routing ) && 'exclusive' === ( $dedicated_routing['mode'] ?? '' ) ) {
			$allowed = (array) ( $dedicated_routing['allowed_gateways'] ?? array() );
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				$norm_id = $this->capability_registry->normalize_gateway_id( $gateway_id );
				if ( ! in_array( $gateway_id, $allowed, true ) && ! in_array( $norm_id, $allowed, true ) ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		} else {
			// 2. Matrix Routing Rules.
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				$rule = $this->capability_registry->get_rule( $gateway_id, $display_currency, $base_currency );
				if ( SettlementMode::HIDE === $rule['mode'] ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		}

		// 3. Auto-select first available gateway if current chosen gateway is not available in active currency.
		if ( ! empty( $available_gateways ) && function_exists( 'WC' ) && WC()->session ) {
			$current_chosen = WC()->session->get( 'chosen_payment_method' );
			if ( empty( $current_chosen ) || ! isset( $available_gateways[ $current_chosen ] ) ) {
				$default_id = array_key_first( $available_gateways );
				WC()->session->set( 'chosen_payment_method', $default_id );
				foreach ( $available_gateways as $gid => $ginstance ) {
					$ginstance->chosen = ( $gid === $default_id );
				}
			}
		}

		return $available_gateways;
	}

	/**
	 * Auto-select "Use a new payment method" radio button if no saved payment method is checked.
	 *
	 * @param string              $html    Saved payment methods list HTML.
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 * @return string Modified HTML.
	 */
	public function filter_saved_payment_methods_html( string $html, $gateway ): string {
		if ( empty( $html ) ) {
			return $html;
		}

		// If no token radio button is checked, auto-check the "new" payment method option.
		if ( false === strpos( $html, 'checked' ) ) {
			$html = preg_replace(
				'/(<input[^>]*value="new"[^>]*?)(\/?>)/i',
				'$1 checked="checked"$2',
				$html,
				1
			);
		}

		return $html;
	}

	/**
	 * Disable payment methods for WooCommerce Checkout Blocks via Store API.
	 *
	 * @param bool                $disable        Whether to disable.
	 * @param \WC_Payment_Gateway $payment_method Gateway instance.
	 * @return bool
	 */
	public function filter_store_api_payment_methods( bool $disable, $payment_method ): bool {
		if ( $disable ) {
			return true;
		}

		if ( ! is_object( $payment_method ) || empty( $payment_method->id ) ) {
			return $disable;
		}

		$display_currency  = $this->currency_registry->get_active_currency();
		$base_currency     = $this->currency_registry->get_base_currency();
		$dedicated_routing = $this->capability_registry->get_dedicated_routing( $display_currency );

		// 1. Dedicated Exclusive Gateway Routing.
		if ( ! empty( $dedicated_routing ) && 'exclusive' === ( $dedicated_routing['mode'] ?? '' ) ) {
			$allowed = (array) ( $dedicated_routing['allowed_gateways'] ?? array() );
			$norm_id = $this->capability_registry->normalize_gateway_id( $payment_method->id );
			if ( ! in_array( $payment_method->id, $allowed, true ) && ! in_array( $norm_id, $allowed, true ) ) {
				return true; // Disable this gateway in the Checkout Block.
			}
			return $disable;
		}

		// 2. Matrix Routing Rules.
		$rule = $this->capability_registry->get_rule( $payment_method->id, $display_currency, $base_currency );

		if ( SettlementMode::HIDE === $rule['mode'] ) {
			return true; // Disable this gateway in the Checkout Block.
		}

		return $disable;
	}

	/**
	 * Render dynamic disclosure message in classic checkout.
	 */
	public function render_checkout_disclosure(): void {
		if ( ! is_checkout() || ! WC()->cart ) {
			return;
		}

		$chosen_gateway   = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
		$display_currency = $this->currency_registry->get_active_currency();
		$cart_total       = WC()->cart->get_total( 'edit' );

		if ( empty( $chosen_gateway ) ) {
			return;
		}

		$settlement = $this->resolver->resolve( $cart_total, $display_currency, $chosen_gateway );

		echo '<div id="velox-disclosure-wrapper" style="' . ( $settlement->disclosure_required ? '' : 'display:none;' ) . '">';
		echo '<div class="woocommerce-info velox-disclosure-notice" style="margin: 15px 0; font-size: 0.9em;">';
		echo esc_html( $settlement->disclosure_message );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * AJAX endpoint to return disclosure info when customer clicks a payment radio button.
	 */
	public function ajax_get_disclosure(): void {
		check_ajax_referer( 'velox_nonce', 'nonce' );

		$gateway_id = sanitize_text_field( wp_unslash( $_POST['gateway_id'] ?? '' ) );
		if ( empty( $gateway_id ) || ! WC()->cart ) {
			wp_send_json_error( 'Invalid request' );
		}

		$display_currency = $this->currency_registry->get_active_currency();
		$cart_total       = WC()->cart->get_total( 'edit' );

		$settlement = $this->resolver->resolve( $cart_total, $display_currency, $gateway_id );

		wp_send_json_success(
			array(
				'required' => $settlement->disclosure_required,
				'message'  => $settlement->disclosure_message,
				'charge'   => $settlement->charge_currency,
			)
		);
	}
}
