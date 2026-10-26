<?php
/**
 * Main Plugin Orchestrator and Service Container for NammaWP.
 *
 * @package NammaWP\MultiCurrency
 */

namespace NammaWP\MultiCurrency;

use NammaWP\MultiCurrency\Admin\ProductPricing;
use NammaWP\MultiCurrency\Admin\SettingsPage;
use NammaWP\MultiCurrency\Currency\CurrencyRegistry;
use NammaWP\MultiCurrency\Currency\DiscountShippingConverter;
use NammaWP\MultiCurrency\Currency\PriceConverter;
use NammaWP\MultiCurrency\Currency\RateService;
use NammaWP\MultiCurrency\Frontend\Switcher;
use NammaWP\MultiCurrency\GatewayRouter\GatewayCapabilityRegistry;
use NammaWP\MultiCurrency\GatewayRouter\GatewayRouter;
use NammaWP\MultiCurrency\GatewayRouter\SettlementResolver;
use NammaWP\MultiCurrency\Orders\AnalyticsNormalizer;
use NammaWP\MultiCurrency\Orders\OrderColumns;
use NammaWP\MultiCurrency\Orders\OrderRecorder;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton class.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static ?self $instance = null;

	/**
	 * Services.
	 */
	public CurrencyRegistry $currency_registry;
	public RateService $rate_service;
	public PriceConverter $price_converter;
	public DiscountShippingConverter $discount_shipping_converter;
	public GatewayCapabilityRegistry $capability_registry;
	public SettlementResolver $settlement_resolver;
	public GatewayRouter $gateway_router;
	public OrderRecorder $order_recorder;
	public AnalyticsNormalizer $analytics_normalizer;
	public OrderColumns $order_columns;
	public Switcher $switcher;
	public SettingsPage $settings_page;
	public ProductPricing $product_pricing;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Protected constructor.
	 */
	protected function __construct() {
		// Initialize service container dependencies.
		$this->currency_registry           = new CurrencyRegistry();
		$this->rate_service                = new RateService( $this->currency_registry );
		$this->price_converter             = new PriceConverter( $this->currency_registry );
		$this->discount_shipping_converter = new DiscountShippingConverter( $this->currency_registry );
		$this->capability_registry         = new GatewayCapabilityRegistry();
		$this->settlement_resolver         = new SettlementResolver( $this->capability_registry, $this->currency_registry );
		$this->gateway_router              = new GatewayRouter( $this->capability_registry, $this->currency_registry, $this->settlement_resolver );
		$this->order_recorder              = new OrderRecorder( $this->currency_registry, $this->settlement_resolver );
		$this->analytics_normalizer        = new AnalyticsNormalizer( $this->currency_registry );
		$this->order_columns               = new OrderColumns();
		$this->switcher                    = new Switcher( $this->currency_registry );
		$this->settings_page               = new SettingsPage( $this->currency_registry, $this->rate_service, $this->capability_registry, $this->switcher );
		$this->product_pricing             = new ProductPricing( $this->currency_registry );
	}

	/**
	 * Boot all plugin modules.
	 */
	public function boot(): void {
		$this->rate_service->init();
		$this->price_converter->init();
		$this->discount_shipping_converter->init();
		$this->gateway_router->init();
		$this->order_recorder->init();
		$this->analytics_normalizer->init();
		$this->order_columns->init();
		$this->switcher->init();

		if ( is_admin() ) {
			$this->settings_page->init();
			$this->product_pricing->init();
		}
	}

	public function get_currency_registry(): CurrencyRegistry {
		return $this->currency_registry;
	}

	public function get_rate_service(): RateService {
		return $this->rate_service;
	}

	public function get_capability_registry(): GatewayCapabilityRegistry {
		return $this->capability_registry;
	}

	public function get_switcher(): Switcher {
		return $this->switcher;
	}

	public function get_gateway_router(): GatewayRouter {
		return $this->gateway_router;
	}
}
