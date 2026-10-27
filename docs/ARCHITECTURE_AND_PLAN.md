# Multi-Currency & Payment Gateway Plugin for WooCommerce

Product brief: name, features and implementation plan · Drafted 24 Oct 2026 · Working title: “Pricely” (see section 2: recommend changing it)

## 1. Summary and positioning

Goal: a WooCommerce plugin that matches the established multi-currency plugins (Aelia, CURCY, YayCurrency, TIV, FOX/WOOCS) on the basics and clearly beats them where they are weakest: **checkout correctness, gateway handling, caching, money-math safety and diagnostics**.

A realistic framing: the incumbents have years of compatibility work, so “better at everything on day one” is not achievable. The plan is to reach **parity** on the table-stakes list in v1.0 (marked P below), **lead** in gateway routing and checkout (marked ★), and widen the feature gap in later releases.

Positioning line: *Show prices in any currency, and always charge through the right gateway, in the right currency.*

Since “gateway per currency” alone is already offered by Aelia, YayCurrency, CURCY Pro and FOX, the product has to win on how well it works at checkout, not on the checkbox.

## 2. Name

### 2.1 “Pricely” is already in use

- WordPress.org already lists **“Pricely – Dynamic Pricing & Cart Discounts for WooCommerce”** (small install base, but live and recently updated).
- A **WooPricely** dynamic-pricing plugin is sold on CodeCanyon.
- A **Pricely** multilayer-discounts app exists on Shopify.

Same word, same ecosystem and an adjacent product category means search confusion, support mix-ups and a real risk of a naming dispute. The name also says “price”, while the product’s strength is currency and gateway handling. **Recommendation: do not use it.**

### 2.2 Naming rules

- Use a short brand plus a descriptive suffix: “Brand – Multi-Currency & Payment Gateway Router for WooCommerce”.
- Do not start the name or slug with Woo or WooCommerce; use the “for WooCommerce” suffix instead.
- Keep “currency switcher” in the short description and tags (it is a high-volume search phrase), but lead the name with the gateway/checkout angle.
- Before committing, check: wp.org slug, .com/.io domains, trademark databases (US, EU, India), GitHub and social handles.

### 2.3 Shortlist (none of these has been checked for availability)

| Name | Why it works | Watch-outs |
| --- | --- | --- |
| Cambio | “Exchange” in several languages; short, memorable, easy to brand | Common word; check finance-sector trademarks |
| PayRoute | Says what the hero feature does | Generic; may clash with payments companies |
| Currify | Coined, currency-centred, easy to say | May read as “curry” to some ears |
| CurrencyGate | Descriptive and SEO-friendly | Weak as a brand, long |
| Checkout Currency Router | Fully descriptive, no brand risk | Not memorable; hard to build a brand on |

My pick pending checks: **Cambio** (brand) or **PayRoute** (if you want the name to carry the gateway promise). Example full title: *Cambio – Multi-Currency & Payment Gateway Router for WooCommerce*.

## 3. Market gaps and how this plugin answers them

| Gap seen in reviews and changelogs | Response |
| --- | --- |
| Caching conflicts (WP Rocket, LiteSpeed) slow pages or show the wrong currency | Cache-safe by design: Vary-cookie mode, client-side price renderer fallback, built-in cache setup checker |
| Checkout bugs when currency is forced or changes mid-checkout | Strict separation of base, display and charge currency; deterministic settlement resolver |
| Gateways that do not support the chosen currency | Gateway capability registry plus three explicit modes per gateway and currency |
| Rounding bugs (CURCY 2.2.18: low rates rounded totals to 0.00 and allowed free checkouts) | Money layer with ISO 4217 decimals, hard zero-total guard, unit tests |
| Input-handling flaws (CURCY 2.2.17: reflected XSS via a URL parameter) | Currency allowlist, validated return URLs, escaped output, security review before each release |
| Query-parameter currency URLs getting crawled (Polylang case on CURCY) | Cookie-based switching, no indexable currency parameters, canonical and hreflang aware |
| Stale exchange rates | Provider fallback chain, staleness alerts, optional rate lock for a session |
| Slow or missing support, confusing renewals | Built-in System Report, simulator and logs so most issues are diagnosable; clear licence and renewal flow |
| Weak reporting by currency | Base-currency normalisation using rate snapshots stored on each order |

## 4. Design principles

1. **Three currencies, never one.** Base (store currency), Display (what the visitor browses in) and Charge (what is sent to the gateway and recorded as the order currency).
2. **No global currency mutation.** A request-scoped CurrencyContext decides what each code path sees, instead of overriding the store currency for the whole request.
3. **Money is never a float.** Decimal or integer minor units, per-currency ISO 4217 decimals, central rounding.
4. **Blocks-first, classic supported, HPOS declared.**
5. **Cache-safe by default.**
6. **Allowlist every input** that touches currency, country or redirects.
7. **Explain itself.** Every conversion and routing decision can be traced in a debug view.

## 5. Feature list

Legend: **P** = parity with incumbents, **★** = differentiator. Tier is a proposal (see section 9). Phase refers to section 8.

### A. Currencies and exchange rates

| Feature | Tier | Phase |
| --- | --- | --- |
| Any ISO 4217 currency (160+), custom symbol, position, separators, per-currency decimals (P) | Free | 1 |
| Manual rates (P) | Free | 1 |
| Automatic rates via a provider abstraction, scheduled with Action Scheduler (P) | Free | 1 |
| Provider fallback chain and stale-rate admin notice ★ | Free | 1 |
| Staleness email alerts, rate history log | Pro | 4 |
| Per-currency markup, percentage or fixed, to cover FX fees (P) | Free | 1 |
| Rate lock for a shopper’s session or checkout window ★ | Pro | 4 |

### B. Pricing

| Feature | Tier | Phase |
| --- | --- | --- |
| Convert simple, variable and sale prices (P) | Free | 1 |
| Fixed price per product and per variation in each currency (P) | Free | 2 |
| Bulk fixed prices via CSV import and export (P) | Pro | 4 |
| Rounding rules per currency with live preview (nearest 0.05, charm endings) and a never-to-zero guard ★ | Free basic, Pro advanced | 2 |
| Shipping rates and free-shipping thresholds per currency (P) | Free | 2 |
| Fixed-amount coupons, min/max spend and fees per currency (P) | Free | 2 |
| Tax display handling in converted prices (P) | Free | 3 |

### C. Detection and switching

| Feature | Tier | Phase |
| --- | --- | --- |
| Switcher as block, widget, shortcode, menu item and floating bar; flags; keyboard and screen-reader accessible (P) | Free | 3 |
| Detection order: saved choice, then URL, then geolocation, then billing/shipping country, then language, then default (P) | Free | 3 |
| Country-to-currency map with optional auto-switch when billing country changes | Pro | 4 |
| Language-to-currency (WPML, Polylang) | Pro | 4 |
| Preferred currency saved to the logged-in user’s profile | Pro | 4 |
| Approximate-price display (base price plus “about X” in the visitor’s currency) (P) | Free | 3 |

### D. Caching and performance

| Feature | Tier | Phase |
| --- | --- | --- |
| Three cache modes: Vary-cookie aware, client-side price renderer for fully cached pages, and automatic exclusion of cart, checkout and account pages ★ | Free | 3 |
| Auto-detect the active cache plugin and recommend a mode; one-click setup check ★ | Free | 3 |
| No indexable currency URL parameters; canonical and hreflang aware ★ | Free | 3 |

### E. Gateway routing (hero feature)

| Feature | Tier | Phase |
| --- | --- | --- |
| Per gateway and currency: **Allow native**, **Convert and disclose**, or **Hide** ★ | Free: allow and hide. Pro: convert and disclose | 2 |
| Gateway capability registry: read supported currencies from gateways that declare them, manual override, warnings for unsupported pairs ★ | Free | 2 |
| Fallback policy when no gateway fits: block with a message, revert to base currency before payment, or show an estimate | Free | 2 |
| Default gateway and ordering per currency | Free | 2 |
| Routing rules beyond currency: country, cart total, product category or type, user role, shipping method ★ | Pro | 4 |
| Editable disclosure notice at checkout (“You will be charged $X USD via PayPal”) for Blocks and classic | Pro | 2 |
| Gateway- and currency-specific fees or discounts | Pro | 4 |
| Admin simulator: choose currency and country, see the gateways, totals and charge currency a shopper would get ★ | Pro | 4 |

### F. Checkout and orders

| Feature | Tier | Phase |
| --- | --- | --- |
| Cart and Checkout Blocks plus classic checkout (P) | Free | 2 |
| Every order stores base currency, base total, display currency, charge currency, rate snapshot and markup ★ | Free | 2 |
| Correct handling of pending-order reuse when a shopper retries with a different gateway ★ | Free | 2 |
| Refunds and partial refunds in the charge currency | Free | 2 |
| Emails, invoices and PDF plugins show the correct currency (P) | Free | 3 |
| Admin order screen shows charge and base amounts side by side | Free | 3 |
| WooCommerce Subscriptions: renewals keep the subscription’s currency | Pro | 4 |

### G. Reports

| Feature | Tier | Phase |
| --- | --- | --- |
| Orders and revenue by currency, normalised to base currency using stored snapshots, with export (P) | Pro | 4 |
| Currency column in WooCommerce Analytics | Pro | later |

### H. Compatibility

| Target | Phase |
| --- | --- |
| HPOS and Cart/Checkout Blocks compatibility declared and tested | 2 |
| WPML and Polylang | 4 |
| WooCommerce Subscriptions, Bookings, Product Add-Ons, Bundles, Name Your Price, Memberships, dynamic-pricing plugins | 4 |
| Caching: WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Cloudflare | 3 |
| Builders and themes: Elementor, Divi, Storefront, Astra, Kadence | 3 to 4 |
| Initial certified gateways: to be decided from your target regions (see section 11); likely WooPayments, Stripe and PayPal plus a regional set | 2 and 4 |
| Adapter mode (read the active currency from another switcher or WooPayments). Feasibility is not yet verified | later |

### I. Admin experience

| Feature | Tier | Phase |
| --- | --- | --- |
| Setup wizard: base currency, add currencies, rate provider, gateway rules, test order ★ | Free | 3 |
| System Report and health checks (cache, rates age, gateway support, decimals) ★ | Free | 3 |
| Debug log and settings import/export | Free | 3 |
| In-app help links and short docs per screen | Free | 5 |

### J. Developer features

| Feature | Tier | Phase |
| --- | --- | --- |
| Documented filters and actions for convert, round, route and resolve-settlement (P) | Free | 3 |
| REST endpoints for rates and active currency; WP-CLI commands to update and list rates | Free | 4 |
| Unit-tested Money class and settlement resolver | Free | 1 |

## 6. Gateway routing and settlement spec

**Inputs:** cart or order, display currency, billing and shipping country, chosen gateway. **Output:** charge currency, charge amount, whether a disclosure is required.

Per gateway and currency pair, the admin chooses one of three modes:

| Mode | Behaviour |
| --- | --- |
| Allow native | The selected currency is passed straight to the gateway |
| Convert and disclose | The cart stays in the shopper’s currency. The order is created in the gateway’s currency at a locked rate, and a notice states the amount that will be charged |
| Hide | The gateway is removed for that currency |

Example matrix (illustrative):

| Gateway | INR | USD | EUR |
| --- | --- | --- | --- |
| Regional gateway | Allow native | Hide | Hide |
| Card gateway | Hide | Allow native | Allow native |
| PayPal (USD-only account) | Convert and disclose | Allow native | Convert and disclose |

Rules that keep checkout safe:

1. The gateway reads currency and total from the **order**, so the order itself must carry the charge currency. It is created from a pure function: cart, chosen gateway and rate snapshot in, currency and totals out.
2. WooCommerce reuses the pending order when a payment fails and the shopper retries. The same function must run again on every reuse, so changing gateway between attempts always recomputes currency and totals.
3. The shopper’s cart and session currency are never changed by gateway selection. A failed or cancelled payment therefore returns them to the same view they left.
4. If no gateway can take the selected currency, apply the fallback policy before payment, not after.
5. Zero-decimal and three-decimal currencies are handled by the Money layer, never by gateway-specific code.
6. In Blocks checkout, changing country or currency must refresh the available gateways. This is the most fragile area and needs explicit tests in Phase 0 and Phase 2.

## 7. Architecture

**Components**

- `Money`: value object with ISO decimals, rounding strategies, zero guard.
- `CurrencyRegistry`: enabled currencies, formats, rules.
- `RateService`: provider interface, fallback chain, scheduling via Action Scheduler, history, markup, session lock.
- `CurrencyContext`: request-scoped base, display and charge currencies.
- `PriceConverter`: hooks into product, variation, shipping, coupon and fee pricing.
- `SettlementResolver` and `GatewayRouter`: section 6.
- `CheckoutIntegration`: classic hooks plus Store API extension for Blocks.
- `OrderRecorder`: HPOS-safe order meta through the order object.
- `CacheManager`: cache-mode detection and the client-side price renderer.
- `Admin`: settings, wizard, simulator, System Report.
- `Compat/*`: one isolated module per third-party plugin, loaded only when that plugin is active.

**Core WooCommerce touchpoints (to confirm in Phase 0):** product price filters (including variations and the variation prices hash), the available-payment-gateways filter, checkout order creation, the Store API extension and update-callback functions, the HPOS and Blocks compatibility declarations, and Action Scheduler for rate refresh.

**Security:** allowlist currency codes, escape all output, validate return URLs, nonce and capability checks on admin actions, no `eval`-style conditional rules.

**Tooling:** PHP 7.4+ minimum (to be confirmed against current WooCommerce requirements), WordPress coding standards, PHPUnit, Playwright end-to-end tests on wp-env, GitHub Actions CI, WooCommerce QIT for marketplace-style checks.

## 8. Implementation plan

Estimates are rough and assume focused full-time solo work. Double them for part-time work.

| Phase | Scope | Rough effort | Exit criteria |
| --- | --- | --- | --- |
| 0. Teardown and decisions | Test CURCY, FOX, YayCurrency and Aelia on Blocks checkout and a caching plugin. Cover billing-country change, fixed-cart coupon, gateway without currency support, partial refund and a WooPayments-with-other-gateway case. Confirm WooCommerce hooks. Decide name, target gateways and distribution | 3–5 days | Written failure-mode list, name and gateway set chosen |
| 1. Core engine | Money, CurrencyRegistry, RateService with fallback and scheduling, basic conversion, unit tests | 2–3 weeks | Tests green, rates update on schedule, zero-guard proven by tests |
| 2. Gateway routing and checkout | CurrencyContext, SettlementResolver, three-mode matrix, capability registry, fallback policies, order recording, pending-order reuse, refunds, Blocks and classic | 3 weeks | End-to-end tests pass for every mode on both checkouts, including a failed-payment retry |
| 3. Storefront, switcher and caching | Switcher block, widget and shortcode, detection order, cache modes and checker, fixed prices, rounding, shipping and coupon conversion, wizard, System Report | 2–3 weeks | Correct prices on cached pages with at least WP Rocket and LiteSpeed; no currency query parameters |
| 4. Compatibility and Pro | Subscriptions, WPML, Polylang, reports, routing rules, simulator, rate lock, CSV import, REST and WP-CLI | 3 weeks | Compatibility matrix green; Pro licensing works |
| 5. Beta and launch | Private beta with 10–20 real stores, security review, wp.org submission and review, docs and site, support process | 3–4 weeks | Beta issues triaged, wp.org approval, launch checklist done |

Total: roughly **14–18 weeks** of full-time work to a credible 1.0. This is an estimate, not a commitment, and compatibility work is the usual source of overruns.

## 9. Free vs Pro and pricing (hypotheses to test)

- **Never paywall safety.** Money-math guards, validation, cache checker and System Report stay in Free.
- **Make Free adoption-friendly.** CURCY’s free version caps at 2 currencies, which is a known frustration. Unlimited currencies in Free is a cheap differentiator. A cap of 3 is the fallback if the free tier proves too generous.
- **Pro value:** Convert and disclose, advanced routing rules, rate lock, Subscriptions, reports, geo auto-switch, CSV import, simulator.
- **Price points:** the Woo Marketplace competitors we looked at charge about $99 (YayCurrency) and $149 (TIV) per year. Test roughly $79–$129 per year for a single site with tiered multi-site plans.
- **Channels:** wp.org for Free, your own site for Pro (own licensing or a licensing service). Consider the Woo Marketplace later and check its current terms and revenue share first.

## 10. QA test matrix

**Environments:** latest and previous WordPress and WooCommerce, PHP 7.4, 8.1, 8.3 and 8.4; classic and Blocks checkout; no cache, WP Rocket, LiteSpeed, W3 Total Cache; Storefront, a block theme, Astra or Kadence, Elementor.

**Scenarios**

- Switch currency on a fully cached page; confirm no flash of wrong price and no URL parameters
- Change currency and billing country on Blocks checkout and confirm gateways refresh
- Fixed-cart and percentage coupons in a secondary currency
- Free-shipping thresholds and per-currency shipping rates
- Variable-product price ranges, sale prices and tax-inclusive display
- Every routing mode, including a gateway that does not support the display currency
- Failed payment, then retry with a different gateway
- Partial and full refunds
- Subscription renewal after the rate has changed
- Rate update mid-session and rate lock behaviour
- Zero-decimal (JPY) and three-decimal (KWD, BHD) currencies
- Very low exchange rates never produce a zero total
- Bot crawl produces no currency URLs
- XSS payloads in every currency, country and return-URL parameter

## 11. Risks and open decisions

**Risks**

- Compatibility long tail across themes, builders and extensions
- Gateway APIs differ, and many gateways only accept the store base currency
- Changes in WooCommerce Blocks internals
- Support load; the diagnostics tooling in section 5.I is the main mitigation
- Rate provider limits and terms of use
- Disclosure wording and refund-currency behaviour may need legal review in key markets

**Decisions needed**

1. Final name (section 2) and availability checks
2. Who the plugin is for first: your own or client stores, or a public product (this document assumes a public product, per your goal of competing with the existing plugins)
3. Which gateways and regions to certify for v1. Please tell me the gateways and countries that motivated this project
4. Standalone first, or adapter-first. I recommend standalone with adapters later
5. Free-tier limits and Pro price points
6. Minimum PHP, WordPress and WooCommerce versions

## 12. Immediate next steps

1. Choose two or three names and run the availability checks
2. Send me your target gateways and regions to fix the v1 certified set
3. Run the Phase 0 teardown
4. Optional: I can draft the plugin skeleton, the Money class with tests, the settlement resolver spec and admin wireframes
