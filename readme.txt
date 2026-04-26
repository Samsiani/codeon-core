=== CodeOn Core ===
Contributors: samsiani
Tags: woocommerce, georgia, address, checkout, location
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Georgian Locations for WooCommerce — cascading Region → Municipality → Settlement address picker. 4,394 settlements bundled.

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

= 0.2.2 — 2026-04-26 =
* Short description leads with "Georgian Locations for WooCommerce" so the .org search and listing card surface that phrase prominently. Still under the 150-char cap (130 chars).

= 0.2.1 — 2026-04-26 =
* **Plugin Check fixes:**
    * Added `defined('ABSPATH') || exit;` direct-access guard to all 17 PHP files in `includes/` (Plugin Check `missing_direct_file_access_protection` error).
    * Trimmed the short description in `readme.txt` to 118 chars (under the 150-char limit Plugin Check enforces).

= 0.2.0 — 2026-04-26 =
* **WordPress.org submission release.**
* Removed bundled Plugin Update Checker (PUC) — WP.org doesn't allow third-party update checkers in hosted plugins. Updates now flow exclusively through WP's native plugin-updates infrastructure.
* "Plugin Name" header shortened to "CodeOn Core" so the .org slug becomes `codeon-core`. The full descriptive title moves into the description.
* `Tested up to` bumped to 6.9.
* `languages/codeon-core.pot` template added so translators can contribute via wordpress.org/translate.
* Release ZIP now includes `composer.json` so reviewers can verify our dependency manifest.

= 0.1.14 — 2026-04-26 =
* Verification release for the v0.1.13 icon/banner fix — no code changes. Triggers a fresh PUC update poll so the merchant can confirm Plugins → Updates renders the branded artwork.

= 0.1.13 — 2026-04-26 =
* Plugins → Updates screen now shows the plugin's icon + banner instead of the empty-picture placeholder. PUC's update response was missing the `icons` and `banners` keys WordPress needs to render branded artwork in the updater UI.

= 0.1.12 — 2026-04-26 =
* **Field visibility toggles** in plugin settings — five new checkboxes under Settings → Locations → "Checkout field visibility":
    * Hide Region (state) field — default ON (auto-derived from muni)
    * Hide Country / Region field — default OFF
    * Hide Company field — default OFF
    * Hide Address line 2 field — default OFF
    * Hide Postcode field — default OFF
* Hide CSS targets BOTH classic and block-checkout selectors so a single setting works in both.
* **Region is now opt-in instead of always-hidden:** when shown, picking a Region narrows the Municipality dropdown to that region's munis. Picking a Municipality still auto-sets Region (whether visible or hidden).
* **Municipality labels no longer prefix the region name** — just "დმანისის მუნიციპალიტეტი" instead of "ქვემო ქართლი — დმანისის მუნიციპალიტეტი". Region info is conveyed by the (optional) Region dropdown and the Settlement cascade.

= 0.1.11 — 2026-04-26 =
* **UX redesign:** cascade now starts at Municipality. Region (state) field is hidden and auto-set from the chosen municipality, so customers only interact with two dropdowns instead of three.
* Municipality dropdown is pre-loaded with all 77 municipalities (label includes region prefix for context: "Kakheti — Telavi Municipality"). No prerequisite — pick directly.
* Settlement (city) cascades from Municipality.
* Municipality + Settlement now use WooCommerce's bundled Select2 — same look & feel as the Country/State dropdowns.
* State value still gets recorded on every order (auto-derived from muni server-side as defense-in-depth) so tax / shipping zones / reports continue to work.

= 0.1.10 — 2026-04-25 =
* **Theme override resistance:** Woodmart's "Checkout fields manager" hooks `woocommerce_checkout_fields` at priority 99999 and rewrites the entire field array, undoing our priority/label/type tweaks. Added a final-pass enforcement at priority 100000 that re-applies our changes AFTER any theme/plugin meddling. Works with Woodmart out-of-the-box; same defense applies to any other plugin/theme that touches checkout fields.

= 0.1.9 — 2026-04-25 =
* **Critical UX fix:** field render order on classic checkout. WooCommerce's default state-field priority is 80 (after city which is 70). With our cascade that meant users saw Settlement and Municipality dropdowns BEFORE the Region dropdown — both empty and waiting on Region. The fields now render top-to-bottom in cascade order: Country → Region → Municipality → Settlement → Address → Postcode.

= 0.1.8 — 2026-04-25 =
* **UX:** clearer placeholder text in dependent dropdowns. Before: an empty Municipality dropdown looked broken. Now: it shows "— pick a Region first —" and is disabled, so users immediately see what unblocks them.
* Same hint cascades to Settlement: shows "— pick a Region first —" when Region is empty, "— pick a Municipality first —" when Region is set but Municipality isn't.
* Disabled state on dependent dropdowns until prerequisites are met (visual cue).

= 0.1.7 — 2026-04-25 =
* **Critical fix #1:** classic-checkout cascade was triggering an infinite loop. After populating the municipality/city dropdowns, `fillSelect` called `$select.trigger('change')` to notify Select2/WC. WC's `update_checkout` handler then re-fired our cascade, which called `fillSelect` again — tight loop, dozens of "Forced reflow" warnings per second, page essentially unusable. Removed the `trigger('change')` and the `updated_checkout` listener; cascade is now purely user-driven (state change → muni populate, muni change → city populate) plus a one-shot population on page load.
* **Critical fix #2:** block-checkout JS was setting attributes on React-controlled inputs in a setInterval, which combined with React's reconciliation caused continuous reflows. Replaced with two delegated event listeners (focus + change) that don't pre-emptively mutate the DOM — `list="codeon-geo-settlements"` is bound the first time the user focuses the settlement input, by which time React has fully rendered and won't re-render on a non-tracked attribute change.

= 0.1.6 — 2026-04-25 =
* **Critical fix:** block-checkout JS no longer infinite-loops. The previous version's `MutationObserver` on document.body fired on every DOM change in the WC checkout — combined with React's frequent re-renders, this caused continuous reflows and made it impossible to select municipality/settlement. Replaced with one-shot scan + per-element `data-codeon-bound` flag + 1-second poll fallback.
* Release workflow: stop excluding nested vendor `composer.json` files from the ZIP — only the top-level plugin composer.json should be excluded.

= 0.1.5 — 2026-04-25 =
* Block-checkout settlement field: removed `list` and `placeholder` from server-side attributes (WC Blocks rejects them as invalid). Now attached client-side via `assets/js/blocks-cascade.js` after WC renders the input.

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
