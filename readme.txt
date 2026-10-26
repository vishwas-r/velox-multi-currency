=== NammaWP - Multi-Currency & Payment Gateway Plugin for WooCommerce ===
Contributors: nammawp
Tags: currency switcher, multi currency, payment gateway, stripe, paypal, razorpay, woocommerce
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cache-safe multi-currency switcher and dedicated payment gateway routing for WooCommerce. Display local prices and route currencies per gateway at checkout.

== Description ==

**NammaWP Multi-Currency** is the modern multi-currency switcher and payment gateway router for WooCommerce.

Show prices in any currency, and ensure every order is routed through the right gateway in the right currency.

### Why NammaWP?

Most multi-currency plugins only convert visual prices on product pages, creating serious failures at checkout:
* **Gateway Currency Incompatibilities:** Gateways like PayPal reject unsupported currencies (e.g. domestic INR or 3-decimal currencies like KWD). Razorpay primarily settles in INR. Passing unsupported currencies causes transaction errors and abandoned carts.
* **The "Gateway by Currency" Paywall:** Other plugins lock payment gateway filtering behind expensive annual licenses ($99 to $149/yr).
* **Ugly Query Parameters:** Plugins that append `?currency=EUR` to URLs break server-side page caches and pollute search engine indices.
* **WooCommerce Checkout Blocks Ready:** Built from day one for modern WooCommerce Cart & Checkout Blocks, as well as Classic Checkout and High-Performance Order Storage (HPOS).

### Key Features

* **Dedicated Payment Gateway Routing:** Lock any currency exclusively to designated payment methods (e.g. INR -> Razorpay only, USD -> PayPal only).
* **3-Mode Payment Gateway Matrix:**
  * **Allow Native:** Charge the payment gateway directly in the customer's selected currency.
  * **Convert & Disclose:** Keep the customer browsing in their local currency, but settle the order in the gateway's supported currency with an automatic, transparent checkout disclosure notice.
  * **Hide:** Completely disable gateways that do not support the customer's currency.
* **Zero-Reload AJAX Switching:** Pure AJAX real-time price and symbol updates across shop, product, cart, and checkout without page reloads.
* **Floating Switcher & Top Header Bar:** Beautiful floating widget with corner placement selector and instant visual loader feedback.
* **Multiple Exchange Rate Providers:** Choose between Frankfurter, FloatRates, ExchangeRate-API, or ECB.
* **ISO 4217 Minor-Unit Money Math:** Strict integer-based money math with a built-in zero-guard to prevent low exchange rates from ever rounding down to 0.00.
* **Custom Rounding & Buffer Markup:** Add an FX fee buffer (e.g. +2.5%) to offset bank conversion charges and apply charm pricing (.99, .95, nearest integer).
* **HPOS & Blocks Compatible:** Declares full compatibility with WooCommerce High-Performance Order Storage and Cart/Checkout Blocks.

== Installation ==

1. Upload the `nammawp-multi-currency` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **WooCommerce -> NammaWP** to configure your currencies and set your payment gateway matrix rules.
4. Place the switcher on your storefront using the `[nammawp_switcher]` shortcode, floating widget, or header menu.

== Frequently Asked Questions ==

= Does NammaWP work with WooCommerce Checkout Blocks? =
Yes! NammaWP integrates with the modern WooCommerce Store API to disable unsupported gateways and update settlement amounts reactively in the Checkout Block.

= How are exchange rates updated? =
Rates can be fetched automatically via background scheduler or on-demand from trusted providers with zero API keys required.

= Can I set manual exchange rates? =
Yes. You can edit the exchange rate and buffer markup percentage for any currency directly in the settings table.

= What happens if a customer chooses a currency not supported by PayPal? =
With NammaWP's Gateway Routing, you can lock USD to PayPal and INR to Razorpay, or use the Gateway Matrix to hide incompatible gateways.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added European Central Bank (ECB) zero-key exchange rate provider.
* Added 3-mode Payment Gateway Routing Matrix (Allow Native, Convert & Disclose, Hide).
* Added certified capability profiles for Stripe, PayPal, and Razorpay.
* Added ISO 4217 Money value object with zero-guard math.
* Declared full HPOS and WooCommerce Checkout Blocks compatibility.
