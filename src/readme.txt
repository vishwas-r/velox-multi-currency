=== VeloX - Multi-Currency & Payment Gateway for WooCommerce ===
Contributors: vishwasr
Tags: currency switcher, multi currency, payment gateway, woocommerce, currency
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-currency switcher and dedicated payment gateway router for WooCommerce. Display local prices and route checkout gateways by currency.

== Description ==

**VeloX Multi-Currency** is a high-performance, precision multi-currency switcher and payment gateway routing engine built natively for modern WooCommerce.

Show prices in customer currencies, eliminate floating-point rounding errors with deterministic minor-unit integer math, and route checkouts exclusively through compatible payment gateways.

### Architectural Highlights

* **Dedicated Payment Gateway Routing:** Restrict specific currencies to designated payment gateways (e.g., INR to Razorpay, USD to PayPal or Stripe) to prevent payment decline errors during checkout.
* **Cache-Safe Price Rendering:** Leverages immutable `data-velox-base` HTML attributes and `Vary: Cookie` HTTP headers so page caches (WP Rocket, LiteSpeed, Cloudflare) never display frozen currency rates.
* **Deterministic Minor-Unit Integer Math:** Eliminates IEEE 754 floating-point rounding issues with zero-fraction arithmetic and anti-zero rounding guard.
* **WooCommerce Store API & Checkout Blocks:** Full native compatibility with modern WooCommerce Cart & Checkout Blocks as well as Classic Checkout and High-Performance Order Storage (HPOS).

### Key Features

* **Multi-Currency Support:** Configure your store base currency plus two additional international currencies (e.g. USD + EUR + GBP) with zero fees.
* **Dedicated Payment Gateway Routing:** Lock any currency exclusively to specific payment gateways (e.g. INR -> Razorpay only, USD -> PayPal only). Incompatible payment methods are automatically suppressed at checkout to prevent payment declines.
* **Cache-Safe Price Rendering:** Automatically embeds `data-velox-base` HTML attributes and emits `Vary: Cookie` headers so caching engines never serve frozen currency prices.
* **Deterministic Integer Money Math:** Stores and calculates values in ISO-4217 minor units with an anti-zero guard to prevent small exchange rates from ever rounding down to 0.00.
* **Real-Time AJAX Switching:** Instant price updates across shop, single product, cart, and checkout without full page reloads.
* **Floating Switcher, Shortcodes & Header Bar:** Built-in floating widget with corner placement controls and top navigation bar integration, plus the `[velox_currency_switcher]` and `[velox_price id="..."]` shortcodes.
* **Automated Background Rate Sync:** WordPress Cron background scheduler with configurable sync intervals, plus on-demand manual sync.
* **100% HPOS & Blocks Compatible:** Fully compatible with High-Performance Order Storage (Custom Order Tables) and Cart/Checkout Blocks.

== External Services ==

This plugin connects to an external foreign exchange rate service provided by the European Central Bank (ECB) to retrieve current foreign exchange reference rates. Rate synchronization runs either automatically via WordPress Cron according to your configured interval (e.g., every 24 hours) or manually when an administrator clicks "Sync Rates Now" in the plugin settings.

When synchronizing rates, the plugin transmits an HTTP GET request to download the public daily XML reference rates table (`eurofxref-daily.xml`). No user data, customer information, cookies, IP addresses, or sensitive store data are transmitted.

* **Service Purpose:** Provides daily Euro foreign exchange reference rates published by the European Central Bank.
* **Data Sent:** An HTTP GET request to download the public daily XML reference rates table. No user or store data is transmitted.
* **Service Provider:** European Central Bank.
* **Terms of Use & Privacy Policy:** https://www.ecb.europa.eu/services/terms/html/index.en.html

== Installation ==

1. Upload the `velox-multi-currency` folder to your `/wp-content/plugins/` directory, or install directly through the WordPress Plugins dashboard.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **WooCommerce -> VeloX** to configure your currencies and set your dedicated gateway routing rules.
4. Place the switcher on your storefront using the floating widget, header bar, or `[velox_currency_switcher]` shortcode.

== Frequently Asked Questions ==

= Does VeloX work with modern WooCommerce Checkout Blocks? =
Yes! VeloX integrates directly with the modern WooCommerce Store API to disable unsupported gateways and update settlement amounts reactively in the Checkout Block.

= How does Dedicated Payment Gateway Routing work? =
Under the Gateway Routing tab in **WooCommerce -> VeloX**, you can enable exclusive routing for any currency and select the specific gateways allowed for it. Any unselected gateways will be automatically hidden at checkout when that currency is active.

= How does VeloX protect against static page caching? =
VeloX injects immutable `data-velox-base` HTML attributes into `wc_price` output and sends `Vary: Cookie` headers. The frontend JavaScript reads the base attribute directly rather than reverse-dividing formatted text.

= How are exchange rates fetched? =
Rates are fetched automatically via WordPress Cron or on-demand from trusted public exchange rate providers with zero API keys required.

= Can I set manual exchange rates? =
Yes. You can edit the exchange rate and buffer markup percentage (e.g. +2.5%) for any currency directly in the settings table.

= How many currencies can I configure? =
You can configure and switch between up to 3 active currencies simultaneously.

== Screenshots ==

1. Currency management table with exchange rates, buffer markups, and rounding rules.
2. Dedicated Payment Gateway Routing with currency-to-gateway locking.
3. Frontend floating currency switcher and top navigation bar integration.
4. WooCommerce Cart & Checkout Blocks integration with real-time gateway filtering.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Support for up to 3 active configured currencies with zero fees.
* Dedicated Payment Gateway Routing (lock currency to allowed gateways; hide incompatible gateways).
* Deterministic ISO 4217 minor-unit integer money math with anti-zero rounding guard.
* Edge-cache safe price rendering via `data-velox-base` HTML attributes and `Vary: Cookie` header.
* Real-time zero-reload AJAX currency switching.
* Floating switcher widget and top header bar switcher.
* Automated background exchange rate sync via WordPress Cron (12h & 24h).
* Declared full compatibility with High-Performance Order Storage (HPOS) and Checkout Blocks.
* 100% compliant with WordPress Plugin Check (PCP) across all static checks.
