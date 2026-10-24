=== SettleX – Payment Gateway Router & Multi-Currency for WooCommerce ===
Contributors: settlex
Tags: currency switcher, multi currency, payment gateway, stripe, paypal, razorpay, woocommerce
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cache-safe multi-currency switcher and smart payment gateway router for WooCommerce. Display local prices and route or convert currencies per gateway at checkout.

== Description ==

**SettleX** is the modern multi-currency and payment gateway settlement router for WooCommerce.

Show prices in any currency, and ensure every order is settled through the right gateway, in the right currency.

### Why SettleX?

Most multi-currency plugins only convert visual prices on product pages, creating serious failures at checkout:
* **Gateway Currency Incompatibilities:** Gateways like PayPal reject unsupported currencies (e.g. domestic INR or 3-decimal currencies like KWD). Razorpay primarily settles in INR. Passing unsupported currencies causes transaction errors and abandoned carts.
* **The "Gateway by Currency" Paywall:** Other plugins lock payment gateway filtering behind expensive annual licenses ($99 to $149/yr).
* **Ugly Query Parameters:** Plugins that append `?currency=EUR` to URLs break server-side page caches and pollute search engine indices.
* **WooCommerce Checkout Blocks Ready:** Built from day one for modern WooCommerce Cart & Checkout Blocks, as well as Classic Checkout and High-Performance Order Storage (HPOS).

### Key Features

* **3-Mode Payment Gateway Matrix:**
  * **Allow Native:** Charge the payment gateway directly in the customer's selected currency.
  * **Convert & Disclose:** Keep the customer browsing in their local currency, but settle the order in the gateway's supported currency with an automatic, transparent checkout disclosure notice.
  * **Hide:** Completely disable gateways that do not support the customer's currency.
* **Certified Gateway Profiles:** Built-in smart rules for **Stripe**, **PayPal**, and **Razorpay**.
* **Zero-Config Exchange Rates:** Daily live foreign exchange rates fetched automatically from the **European Central Bank (ECB)** with zero API keys required.
* **Cache-Safe Switching:** Cookie-driven currency switching with clean AJAX updates. No query parameters, no broken full-page caches (WP Rocket, LiteSpeed, Cloudflare).
* **ISO 4217 Minor-Unit Money Math:** Strict integer-based money math with a built-in zero-guard to prevent low exchange rates from ever rounding down to 0.00.
* **Custom Rounding & Buffer Markup:** Add an FX fee buffer (e.g. +2.5%) to offset bank conversion charges and apply charm pricing (.99, .95, nearest integer).
* **HPOS & Blocks Compatible:** Declares full compatibility with WooCommerce High-Performance Order Storage and Cart/Checkout Blocks.

== Installation ==

1. Upload the `settlex-multi-currency` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **WooCommerce -> SettleX** to configure your currencies and set your payment gateway matrix rules.
4. Place the switcher on your storefront using the `[settlex_switcher]` shortcode, widget, or PHP snippet.

== Frequently Asked Questions ==

= Does SettleX work with WooCommerce Checkout Blocks? =
Yes! SettleX integrates with the modern WooCommerce Store API to disable unsupported gateways and update settlement amounts reactively in the Checkout Block.

= How are exchange rates updated? =
Rates are fetched daily from the European Central Bank (ECB) via background scheduler. You can also click "Sync Rates Now" at any time from the admin settings.

= Can I set manual exchange rates? =
Yes. You can edit the exchange rate and buffer markup percentage for any currency directly in the settings table.

= What happens if a customer chooses a currency not supported by PayPal? =
With SettleX's Gateway Matrix, you can either **Hide** PayPal for that currency (leaving Stripe or Razorpay visible), or choose **Convert & Disclose**, which allows the customer to browse in their local currency while charging their PayPal account in USD or EUR with an explicit disclosure.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added European Central Bank (ECB) zero-key exchange rate provider.
* Added 3-mode Payment Gateway Routing Matrix (Allow Native, Convert & Disclose, Hide).
* Added certified capability profiles for Stripe, PayPal, and Razorpay.
* Added ISO 4217 Money value object with zero-guard math.
* Declared full HPOS and WooCommerce Checkout Blocks compatibility.
