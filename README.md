# VeloX — Multi-Currency & Payment Gateway for WooCommerce

<p align="center">
  <strong>The modern, deterministic multi-currency engine and payment gateway router for WooCommerce.</strong><br>
  Built natively for High-Performance Order Storage (HPOS) and WooCommerce Cart & Checkout Blocks.
</p>

<p align="center">
  <a href="https://github.com/vishwas-r/velox-multi-currency/releases"><img src="https://img.shields.io/badge/version-1.0.0-blue.svg" alt="Plugin Version"></a>
  <a href="https://woocommerce.com/"><img src="https://img.shields.io/badge/WooCommerce-HPOS%20%26%20Blocks%20Ready-green.svg" alt="WooCommerce Compatibility"></a>
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-6.0%2B-blue.svg" alt="WordPress Version"></a>
  <a href="https://php.net/"><img src="https://img.shields.io/badge/PHP-%3E%3D%207.4-8892BF.svg" alt="PHP Version"></a>
  <a href="https://github.com/vishwas-r/velox-multi-currency/blob/main/LICENSE"><img src="https://img.shields.io/badge/License-GPLv2-green.svg" alt="License"></a>
  <img src="https://img.shields.io/badge/Plugin%20Check%20(PCP)-100%25%20Clean-brightgreen.svg" alt="Plugin Check Status">
</p>

---

## The Problem VeloX Solves

Most multi-currency plugins only convert visual numbers on product pages, creating critical failures at checkout:

1. **The "Gateway by Currency" Paywall:** Competitors charge **$99 to $149/year** just to filter payment gateways per currency.
2. **Checkout Failures & Gateways Declines:** Payment gateways reject unsupported currencies (e.g., PayPal rejecting domestic INR or 3-decimal currencies like KWD; Razorpay settling primarily in INR).
3. **Static Cache Price Inversion:** When page-caching plugins (WP Rocket, LiteSpeed, Cloudflare) cache static HTML, reverse-calculating prices (`price / rate`) corrupts numbers for international visitors.
4. **Floating-Point Rounding Errors:** Using standard PHP `float` for currencies results in off-by-a-cent errors, accounting mismatches, and the infamous "free checkout" rounding exploit.

**VeloX fixes all of this natively.**

---

## Free Version Features

- **Up to 3 Configured Currencies:** Configure your store base currency plus two foreign currencies (e.g., USD + EUR + GBP) with zero subscription fees.
- **Dedicated Payment Gateway Routing:** Lock any currency to designated gateways (e.g., INR &rarr; Razorpay only, USD &rarr; PayPal only).
- **Gateway Matrix (Allow Native & Hide):** Enable compatible payment methods or completely suppress incompatible gateways per currency.
- **Deterministic Minor-Unit Money Math:** All amounts are computed in ISO-4217 minor units with an anti-zero guard that prevents small rates from ever rounding to `0.00`.
- **Cache-Safe Price Rendering:** Intercepts `wc_price` to inject `data-velox-base` HTML attributes and emits `Vary: Cookie` headers. JavaScript reads the immutable base price, eliminating reverse-division corruption on statically cached pages.
- **Zero-Reload AJAX Switching:** Instant storefront price and symbol recalculation across shop, product, cart, and checkout without full page reloads.
- **Storefront Switcher Widgets:** Floating currency switcher widget with corner placement controls, top navigation bar integration, and `[velox_currency_switcher]` shortcode.
- **Scheduled Background Rate Sync:** WordPress Cron background scheduler with configurable 12-hour and 24-hour sync intervals, plus on-demand manual sync.
- **100% HPOS & Checkout Blocks Compatible:** Built from day one for WooCommerce Custom Order Tables (HPOS) and modern Store API Checkout Blocks.
- **100% WordPress Plugin Check (PCP) Compliant:** 0 Errors, 0 Warnings across all 29 official static checks.

---

## Free vs. Pro Feature Comparison

Looking for cross-border enterprise automation? Check out [**VeloX Pro**](https://github.com/vishwas-r/velox-multi-currency-pro).

| Capability | VeloX Free | VeloX Pro |
| :--- | :---: | :---: |
| **Configured Currencies** | Up to 3 | **Unlimited** |
| **Payment Gateway Filtering** | Dedicated Routing | **Dedicated Routing & 3-Mode Matrix (Convert & Disclose)** |
| **Convert & Disclose Line-Item Scaling** | — | **Proportional Line-Item, Fee, Shipping & Tax Scaling** |
| **Customer GeoIP Auto-Detection** | — | **Zero-Dependency Core MaxMind with 50+ Country Map** |
| **Exchange Rate Failover Chain** | European Central Bank (Official Feed) | **Autonomous 4-Tier Zero-Downtime Cascade** |
| **Rate Auto-Sync Schedules** | 12h, 24h | **1h, 2h, 4h, 6h, 12h, 24h, custom** |
| **Analytics Normalization** | Raw WooCommerce Reporting | **Normalized Multi-Currency Sales Dashboard** |
| **Edge Cache Immunity** | `data-velox-base` + `Vary: Cookie` | **Full WP Rocket & LiteSpeed Dynamic Cookie Rules** |
| **License & Automatic Updates** | WordPress.org | **1-Click Commercial Dashboard Updates** |

---

## Developer Extension Hooks

VeloX Free is architected around clean WordPress action and filter hooks, allowing extensions (like VeloX Pro) to extend functionality without modifying core code:

```php
// Unlock unlimited currencies
add_filter( 'velox_mc_max_currencies', function( $limit ) {
    return PHP_INT_MAX;
});

// Unlock additional gateway routing modes
add_filter( 'velox_mc_supported_gateway_modes', function( $modes ) {
    $modes[] = 'convert_disclose';
    return $modes;
});

// Register custom GeoIP currency detector
add_filter( 'velox_mc_detected_currency', function( $detected, $enabled_currencies ) {
    // Return detected currency ISO code (e.g. 'EUR')
    return 'EUR';
}, 10, 2 );

// Hook into order settlement to execute custom conversions
add_action( 'velox_mc_order_settlement_applied', function( $order, $settlement, $display_currency ) {
    // Custom settlement handling
}, 10, 3 );
```

---

## Installation

1. Download the latest release from the [Releases page](https://github.com/vishwas-r/velox-multi-currency/releases) or install via the WordPress Plugin Directory.
2. Upload the `velox-multi-currency` directory to `/wp-content/plugins/`.
3. Activate the plugin in **Plugins &rarr; Installed Plugins**.
4. Configure your currencies and gateway routing rules in **WooCommerce &rarr; VeloX**.
5. Add the currency switcher to your storefront via the floating widget, header bar, or `[velox_currency_switcher]` shortcode.

---

## Author & Support

- **Author:** Vishwas R
- **Website:** [https://vishwas.me/](https://vishwas.me/)
- **Repository:** [https://github.com/vishwas-r/velox-multi-currency](https://github.com/vishwas-r/velox-multi-currency)
- **Pro Version:** [https://github.com/vishwas-r/velox-multi-currency-pro](https://github.com/vishwas-r/velox-multi-currency-pro)

---

## License

VeloX is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
