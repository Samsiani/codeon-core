=== CodeOn Core — Georgian Locations for WooCommerce ===
Contributors: samsiani
Tags: woocommerce, georgia, address, checkout, location
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replaces WooCommerce's free-text "City" field with a real cascading Region → Municipality → Settlement picker for Georgia. 4,394 settlements bundled. Bilingual.

== Description ==

WooCommerce out of the box has no idea what a Georgian city is. Customers type their settlement name freehand, with five different spellings, in three languages. Reports are useless. Couriers reject orders.

**CodeOn Core** ships the entire administrative hierarchy of Georgia — 13 regions, 77 municipalities, 4,394 settlements (cities, towns, villages) — as a structured, cascading checkout dropdown.

Customers select **Region → Municipality → Settlement**. Validated server-side. Stored as structured order meta. Filterable in the admin orders list. Compatible with classic checkout (block checkout in v0.2.0).

= What you get =

* Cascading address picker on classic WooCommerce checkout (block-checkout coming in v0.2)
* 4,394 Georgian settlements, fully bilingual (Georgian + Latin transliteration)
* Region dropdown aligned with WooCommerce's existing Georgia state codes (TB, AJ, IM, …) — no breaking changes for existing orders
* REST API for typeahead search & cascade lookups
* HPOS-compatible structured order meta
* Custom Georgian address format on emails, invoices, and the My Account page
* Toggle to hide occupied territories (Abkhazia, Tskhinvali region) — hidden by default
* Display mode setting: Always Georgian, Always English (transliterated), or Bilingual ("კონდოლი (Kondoli)")

= What you don't get =

* No upsells in the checkout flow.
* No phoning home.
* No mandatory account creation.

= About CodeOn =

CodeOn Core also acts as the canonical home for the CodeOn family of premium WooCommerce plugins (TBC Card, BOG Card, Flitt, and others). When a CodeOn premium plugin is installed alongside Core, both share a single tidy admin menu instead of cluttering the sidebar. Premium plugins are sold separately at codeon.ge — they are **not** required for the locations feature.

== Installation ==

1. Install via Plugins → Add New → search for "CodeOn Core" → Install → Activate.
2. WooCommerce → CodeOn → Locations to configure display mode and toggle occupied territories.
3. That's it. The checkout cascade activates automatically when a customer selects Georgia as their country.

== Frequently Asked Questions ==

= Does it work with the WooCommerce Checkout block? =

Block checkout support arrives in v0.2.0 (next release). v0.1 supports the classic shortcode-based checkout.

= Does it conflict with my existing Georgia state codes? =

No. We preserve WooCommerce's existing 12 GE state codes (TB, AJ, IM, …) and only add `TS` for the Tskhinvali region. Existing orders continue to validate.

= Will villages I'm missing be added? =

The dataset comes from ka.wikipedia.org categorized by municipality. Open an issue at our GitHub if a village is wrong or missing — we re-sync on every plugin release.

= Are occupied territories included? =

Yes — Abkhazia and the Tskhinvali region are in the dataset but **hidden by default**. Enable them in the settings if your store ships there.

== Screenshots ==

1. Cascading address picker on the WooCommerce checkout — Region, Municipality, Settlement.
2. Diagnostics tab showing dataset size and version.
3. CodeOn hub menu with installed plugins listed underneath.

== Changelog ==

= 0.1.4 — 2026-04-25 =
* **Critical fix:** "Security check failed" on settings save when CodeOn Core is co-installed with another framework consumer (e.g. fina-sync). Fixed via framework v0.3.4 which adds a slug discriminator to the global admin-post save handler.
* **Critical fix:** Block checkout fields + REST endpoints now actually register. Old code added the `woocommerce_loaded` callback at `plugins_loaded(5)` — but WC fires `woocommerce_loaded` at `plugins_loaded(0)`, so our callback was registered AFTER the action already fired and never ran. Switched to `init` hook + direct call when WC is already loaded.
* PUC slug is now derived dynamically from the actual plugin folder name (works for both `codeon-core/` and `codeon-core-main/`).
* Translations deferred to `init` to silence the WP 6.7+ "translation loaded too early" notice.
* Re-entry guard at file load: bails silently if the plugin file is required twice in the same request (defense against autoloader oddities).

= 0.1.3 — 2026-04-25 =
* **Plugin Update Checker** wired to GitHub releases. Future versions appear in WP Admin → Updates without manual ZIP uploads, until codeon-core lands on WordPress.org (M3).

= 0.1.2 — 2026-04-25 =
* **Block checkout support** (M2). Registers Municipality (select) + Settlement (text + autocomplete via HTML5 datalist) as additional checkout fields via `woocommerce_register_additional_checkout_field` (WC 8.6+). Cascade JS narrows the settlement autocomplete by chosen municipality.
* WC Blocks compatibility now declared as supported.
* Branded icons + banner per the CodeOn Plugin Icon System (deep-blue gradient, location-pin glyph, CodeOn corner mark).
* Defensive `Cache-Control: no-store` headers on Codeon admin pages — protects nonces from being recycled by aggressive page caches like LiteSpeed Cache.
* Diagnostics tab gains a "Save-flow diagnostics" panel showing the current nonce action, capability check, and a fresh nonce — helps debug "Security check failed" reports.

= 0.1.1 — 2026-04-25 =
* Bump bundled `codeon/framework` to v0.3.3 (re-licensed from proprietary to GPL-2.0-or-later — required for WordPress.org distribution).
* No functional changes; release infrastructure improvement only.

= 0.1.0 — 2026-04-25 =
* Initial release.
* Classic checkout cascade (Region → Municipality → Settlement) for Georgia.
* 4,394 settlements bundled (13 regions, 77 municipalities).
* Bilingual display modes (Georgian, Latin transliteration, bilingual).
* REST endpoints for cascade lookups.
* HPOS-compatible order meta.
* CodeOn hub claim.

== Upgrade Notice ==

= 0.1.4 =
Critical fixes for "Security check failed" on save and missing block-checkout fields. Strongly recommended.

= 0.1.3 =
After upgrading to 0.1.3 once (manual ZIP install), all subsequent versions auto-update via the WP Updates screen. One-time manual install required.

= 0.1.2 =
Adds block-checkout support and a defensive cache-control fix. Recommended for anyone on WC 8.3+.

= 0.1.1 =
Framework re-license. No action required.

= 0.1.0 =
First public release.
