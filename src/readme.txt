=== VeloX - Multi-Currency & Payment Gateway for WooCommerce ===
Contributors: vishwas-r
Tags: currency switcher, multi currency, payment gateway, woocommerce, currency
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-currency switcher and dedicated payment gateway router for WooCommerce. Display local prices and route checkout gateways by currency.

== Description ==

**VeloX Multi-Currency** is a high-performance, precision multi-currency switcher and payment gateway routing engine built natively for modern WooCommerce.

Show prices in customer currencies, eliminate floating-point rounding errors with deterministic minor-unit math, and route checkouts through compatible payment gateways.

### Why VeloX?

Most multi-currency plugins only convert visual numbers on product pages, creating serious friction and failures at checkout:
* **The "Gateway by Currency" Paywall:** Other plugins lock basic payment gateway filtering behind expensive annual subscriptions ($99 to $149/yr).
* **Gateway Incompatibilities:** Gateways reject unsupported currencies (e.g. PayPal rejecting domestic currencies or 3-decimal currencies like KWD; Razorpay primarily settling in INR).
* **Static Cache Inversion:** Plugins that reverse-divide formatted prices (`price / rate`) break on stores using WP Rocket, LiteSpeed Cache, or Cloudflare, displaying distorted numbers to international visitors.
* **WooCommerce Checkout Blocks Ready:** Engineered from the ground up for modern WooCommerce Cart & Checkout Blocks (Store API), as well as Classic Checkout and High-Performance Order Storage (HPOS).

### Free Version Features

* **Up to 3 Configured Currencies:** Configure your store base currency plus two additional international currencies (e.g. USD + EUR + GBP) with zero subscription fees.
* **Dedicated Payment Gateway Routing:** Lock any currency to specific payment methods (e.g. INR -> Razorpay only, USD -> PayPal only).
* **Gateway Matrix (Allow Native & Hide):** Enable compatible payment gateways or completely hide incompatible gateways for any specific currency.
* **Cache-Safe Price Rendering:** Automatically embeds `data-velox-base` HTML attributes and emits `Vary: Cookie` headers so caching engines never serve frozen currency prices.
* **Deterministic Integer Money Math:** Stores and calculates values in ISO-4217 minor units with an anti-zero guard to prevent small exchange rates from ever rounding down to 0.00.
* **Real-Time AJAX Switching:** Instant price updates across shop, single product, cart, and checkout without full page reloads.
* **Floating Switcher, Shortcodes & Header Bar:** Built-in floating widget with placement controls and top navigation bar integration, plus the `[velox_currency_switcher]` and `[velox_price id="..."]` shortcodes.
* **Automated Background Rate Sync:** WordPress Cron background scheduler with configurable 12-hour and 24-hour intervals, plus on-demand manual sync.
* **100% HPOS & Blocks Compatible:** Fully compatible with High-Performance Order Storage (Custom Order Tables) and Cart/Checkout Blocks.

### Need Enterprise Features? Upgrade to VeloX Pro

Looking for advanced cross-border automation? **VeloX Pro** unlocks:
* **Unlimited Currencies:** Remove the 3-currency limit and sell across dozens of global currencies.
* **"Convert & Disclose" Gateway Mode:** Browse in customer currency, but charge via your domestic gateway in a fixed settlement currency (e.g. charge USD via PayPal for an INR cart) with automatic proportional line-item, fee, shipping, and tax scaling.
* **Zero-Dependency GeoIP Auto-Detection:** Automatically detect customer country on first visit via built-in MaxMind geolocation and switch to their domestic currency with zero external API calls.
* **4-Tier Autonomous Rate Failover Chain:** Zero-downtime exchange rates cascading through ExchangeRate-API, Frankfurter, FloatRates, and the European Central Bank (ECB).
* **High-Frequency Auto-Sync:** Automated rate updates every 1, 2, 4, or 6 hours.
* **Analytics Normalization:** Normalize multi-currency orders back to store base currency in WooCommerce Analytics reports.

Visit [vishwas.me](https://vishwas.me/) to learn more about VeloX Pro.

== Installation ==

1. Upload the `velox-multi-currency` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **WooCommerce -> VeloX** to configure your currencies and set your gateway routing rules.
4. Place the switcher on your storefront using the floating widget, header bar, or `[velox_currency_switcher]` shortcode.

== Frequently Asked Questions ==

= Does VeloX work with WooCommerce Checkout Blocks? =
Yes! VeloX integrates directly with the modern WooCommerce Store API to disable unsupported gateways and update settlement amounts reactively in the Checkout Block.

= How does VeloX protect against static page caching? =
VeloX injects immutable `data-velox-base` HTML attributes into `wc_price` output and sends `Vary: Cookie` headers. The frontend JavaScript reads the base attribute directly rather than reverse-dividing formatted text.

= How are exchange rates fetched? =
Rates are fetched automatically via WordPress Cron or on-demand from trusted public exchange rate providers with zero API keys required.

= Can I set manual exchange rates? =
Yes. You can edit the exchange rate and buffer markup percentage (e.g. +2.5%) for any currency directly in the settings table.

= What is the currency limit in the free version? =
The free version allows up to 3 active currencies simultaneously. For unlimited currencies, upgrade to VeloX Pro.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Support for up to 3 configured currencies with zero fees.
* Dedicated Payment Gateway Routing (Allow Native & Hide per currency).
* Deterministic ISO 4217 minor-unit money math with anti-zero rounding guard.
* Edge-cache safe price rendering via `data-velox-base` HTML attributes.
* Real-time zero-reload AJAX currency switching.
* Floating switcher widget and top header bar switcher.
* Automated background exchange rate sync via WordPress Cron (12h & 24h).
* Declared full compatibility with High-Performance Order Storage (HPOS) and Checkout Blocks.
* 100% compliant with WordPress Plugin Check (PCP) across 29 static checks.
