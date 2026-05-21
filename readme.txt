=== CodeOn Core ===
Contributors: samsiani
Tags: woocommerce, georgia, address, checkout, location
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.3.15
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

= 0.3.15 — 2026-05-21 =
* No-op release to flush WordPress's plugin-card cache so the refreshed v0.3.14 icon + banner artwork shows up immediately on every merchant's Dashboard → Updates and Add New screens instead of after WP's next scheduled metadata refresh.

= 0.3.14 — 2026-05-21 =
* New: refreshed plugin icon + banner artwork. Glyph is now a stylized "C" with the CodeOn brand-mark dot inside its opening — reads as "the canonical CodeOn plugin" instead of the previous location-pin (which was misleading: CodeOn Core is the free hub for the whole plugin family, Georgian Locations is one feature among several planned). Family-consistent: deep-blue gradient + CodeOn corner mark held invariant per CODEON_PLUGIN_ICON_SYSTEM.md.
* Update: banner subtitle now reads "The canonical hub for the CodeOn plugin family" with a feature strip that covers the broader scope ("PLUGIN HUB · GEORGIAN LOCATIONS · CHECKOUT FIELDS · MORE TO COME") rather than implying the plugin is locations-only.

= 0.3.13 — 2026-05-21 =
* Hygiene: bump bundled `codeon/framework` from 0.3.16 → 0.3.18 for parity with the rest of the CodeOn plugin suite. No behavioural change — CodeOn Core is free and has no license enforcement to gate.

= 0.3.12 — 2026-05-11 =
* **Area position survives WC's client-side resort.** The previous releases had Area at the correct DOM position (right after Country) — but `assets/js/frontend/address-i18n.js` (shipped by WooCommerce itself) re-sorts checkout fields by `data-priority` on every `country_to_state_changing` event, reading the priorities from `wc_address_i18n_params.locale.default`. Those localised priorities still carried WC's untouched default `city: 70`, so on every country pick WC's own JS re-tagged Area with priority 70 and moved it to between Address-line-2 (60) and State (80). That's what the merchant kept screenshotting.

  Fix: also set the unprefixed `default.city.priority` and `default.state.priority` in the `woocommerce_default_address_fields` filter, AND add a `priority` key to the GE-specific override in `woocommerce_get_country_locale`. Both filters feed `wc_address_i18n_params` directly, so the JS now reads Area at priority `country_priority + 1` and the resort puts it back where the PHP rendered it. Reads country's actual priority at filter time so it works under any theme (Woodmart, default, custom) that may have re-mapped country.
* Plugin survey: also reviewed every other active hook on `woocommerce_checkout_fields` / `woocommerce_default_address_fields` / `woocommerce_get_country_locale` on the live install. The Personal-ID field plugins (balance-sync, fina-sync) add fields without specifying a priority for the filter callback — they don't re-order, no conflict with Area positioning. Woodmart's checkout-fields-manager hooks at priority 99999 but its frontend JS only modifies the "required" badge, not the DOM order.

= 0.3.11 — 2026-05-11 =
* **Area now sits truly right-after-Country, even when a theme remaps Country's priority.** On artcase.ge a live `WC_Checkout::get_checkout_fields('billing')` dump showed:

  - `billing_first_name` priority **10** (was 20 by default — Woodmart / Personal-ID plugin remapped it)
  - `billing_city` (Area) priority **11** (our v0.3.10 value)
  - `billing_country` priority **40** (was 10 by default — also remapped)

  My hard-coded `priority = 11` put Area between first_name (10) and last_name (20) — visually "right after first name", not what the merchant wanted. Fix: read whatever priority Country ended up at AFTER all other filters have run (we still hook `woocommerce_checkout_fields` at priority 100000, and the WC 10.7 source confirms `uasort()` runs AFTER our filter) and set Area to `country.priority + 1`. State (hidden, auto-filled) gets `country.priority + 2`. Now Area renders immediately under Country regardless of what other plugins decided Country's priority should be.
* **Shipping-zone "Add region" no longer hides 10 of the 13 Georgian regions.** v0.3.2's Tbilisi-mode override trimmed `woocommerce_states['GE']` to only the merchant's allowed area codes — which also applied to WP-admin → WC → Settings → Shipping → Add region. Removed the filter; the GE state catalog stays intact globally. The state field at checkout is still hidden via CSS + auto-filled, which is all Tbilisi mode actually needs.

= 0.3.10 — 2026-05-11 =
* **Area field now renders immediately under Country / Region on checkout.** Previously the `priority = 11` change was applied in `enforceFinalFieldSetup` (hooked at `woocommerce_checkout_fields` priority 100000) — but WC's `WC_Checkout::get_checkout_fields()` runs `uasort()` on each fieldset BEFORE applying that filter, so the late priority change had no effect on render order. Moved the priority assignment to the earlier `woocommerce_default_address_fields` + `woocommerce_billing_fields` / `woocommerce_shipping_fields` filters, which run before WC's sort, so Area now lands right after Country exactly as configured.
* **Admin Surroundings picker is Georgian-only.** No more redundant labels like *"ნორიო (გარდაბნის მუნიციპალიტეტი) (Norio (gardabnis munitsipaliteti))"* — the trailing Latin transliteration is dropped both for pre-rendered selected pills AND for AJAX search results. The customer-facing Area dropdown still honours the merchant's `display_mode` setting; this change only affects the admin picker.

= 0.3.9 — 2026-05-11 =
* **Surroundings-picker pill polish** per merchant feedback:
    * Removed `line-height: 1` from the scoped remove-× rule so the × is no longer cropped vertically (was overriding the natural pill line-height).
    * Neutralised Select2 v4.1.0-rc.0's default `border-right: 1px solid #aaa` on `.select2-selection__choice__remove` — the ugly faux-separator between × and pill text is gone.
    * Bumped the choice's `padding-left` to `20px !important` so the × has visible breathing room from the pill's left edge.
* Headless-verified the three computed values before tagging (`paddingLeft: 20px`, `borderRight: 0px none`, `lineHeight: normal`).

= 0.3.8 — 2026-05-11 =
* **Surroundings picker pills no longer spill outside the box.** Root cause was twofold:
    1. `Select2 v4.1.0-rc.0`'s `containerCssClass` option silently dropped on init — so every CSS rule I'd scoped to `.codeon-tbilisi-picker` never matched the rendered container, and Select2's tight defaults applied unmodified. Now adding the scoping class **manually** via `$sel.next('.select2-container').addClass(...)` immediately after init, guaranteed to take effect.
    2. The fallback CSS used `float: left` on pills with a `display: block` parent `<ul>` — classic float-containment problem (parent height collapses, pills overflow downward). Switched to `display: flex; flex-wrap: wrap; gap: 6px` on the `<ul>` with `float: none; flex: 0 0 auto` on the pills. Parent now grows organically as pills wrap.
* **Typed text in the search input is now unambiguously visible.** The search `<li>` is `flex: 1 0 100%` so it always wraps to its own line below the pills. The `<input>` inside is `width: 100%`, `min-height: 40px`, `font-size: 14px`, `color: #0b0f19`, `background: #fff`, with a 1px visible border and brand focus ring. No more typing into invisible 0-px inputs.
* **Headless verified before tagging.** Built a puppeteer rig that loads the picker with 10 pre-selected pills, then types "gant" into the empty-state search field. Measured: 0 pills outside the container bounds, search input box is 684×40px, typed text colour is `rgb(11,15,25)`, background `rgb(255,255,255)`, font size 14px. Both screenshots inspected (`/tmp/picker-1-initial.png`, `/tmp/picker-2-typing-empty.png`) — pills wrap inside, typed text is clearly readable.

= 0.3.7 — 2026-05-11 =
* **Surroundings picker UX polish, modelled after WooCommerce's country selector.**
    * Search input now sits on its own full-width row below the pills with a visible white background + light border. Typed text is unambiguously readable — fixes the "I can't see what I'm typing" report.
    * Container background is light grey (`--bg-muted`) so the white pills + white search row read as distinct surfaces inside the picker.
    * Container grows downward smoothly as pills wrap; no more pills falling outside the visible border.
    * Dropped the redundant `" — Muni, Region"` suffix that the picker was appending to every result. The dataset's `name_ka` already disambiguates similarly-named villages with `(<muni>)` in parens — appending the slugs on top produced labels like *"არაშენდა (მცხეთის მუნიციპალიტეტი) — მცხეთის მუნიციპალიტეტი, მცხეთა-მთიანეთი"*. Now you just get *"არაშენდა (მცხეთის მუნიციპალიტეტი)"*.
* **Area field repositioned on checkout.** Was rendering at priority 47 (between Company and Address line 1). Moved to priority 11 — sits immediately after Country / Region as the merchant requested. Hidden state field moved to priority 12 to stay paired with country in case any theme renders hidden rows.
* The customer-facing Area dropdown labels also lost the redundant *" — Muni, Region"* suffix for the same dataset-disambiguation reason.

= 0.3.6 — 2026-05-11 =
* **Surroundings multiselect now actually persists picked settlements.** The framework's `FieldValidator::process()` explicitly skips `Field::RAW` entries (no built-in sanitizer / validator), so the picker rendered fine but the chosen settlement IDs never reached the option. Hooked `Tab::beforeSave()` to pull the raw POST array directly, sanitize each entry to a positive int + validate it exists in the dataset, and inject the result into the clean payload that gets persisted. Settlement IDs that don't resolve are silently dropped (defensive against tampered POSTs).
* **Bigger, clearer picker UI.** Visible input box is now `min-height: 120px` (was Select2's default ~32px) so it reads as a proper multi-line picker. Search input inside is `min-width: 240px`, `font-size: 14px`, with placeholder colour and a transparent background so typed text is visible against the white pill area. Pills use the brand `--brand-soft` background + `--brand-border` border to match the rest of the dashboard.
* **Container grows dynamically as more pills are added.** Replaced the earlier `display: flex` rule (which fought Select2's natural `<li>` flow and clipped pills below the visible border) with an inline-block layout + `height: auto` + `overflow: visible` overrides — the box now extends downward smoothly as the merchant adds more surroundings.
* **Scoped Select2 styling** via `containerCssClass: 'codeon-tbilisi-picker'` + `dropdownCssClass: 'codeon-tbilisi-picker-dropdown'` so these overrides only touch the surroundings picker and never bleed into other Select2 instances elsewhere on the page.

= 0.3.5 — 2026-05-11 =
* **Fully self-contained Tbilisi-tab reveal logic — no FOUC, no framework dependency.** v0.3.4 patched the framework's own `inputValue()`, but on stores with multiple CodeOn plugins co-installed, WordPress only registers the `codeon-framework-admin` script handle ONCE — whichever plugin loads first wins. If a co-installed plugin still ships the stale (broken) framework JS, codeon-core's patched copy is never loaded. v0.3.5 sidesteps the problem entirely:
    * Inline `<style>` at the top of the tab pre-hides our conditional rows so they never render visible (eliminates the visible→hidden flicker the user reported).
    * Inline `<script>` immediately after the rows snapshots them, **strips the `data-codeon-show*` attributes** so the framework's automatic logic ignores them, and runs our own scoped reveal logic.
    * Synchronous first-pass during HTML parsing — runs before any DOMContentLoaded handler (framework's or otherwise) can fire — so the correct visibility is set from the very first paint.
    * Live updates as the merchant ticks the master toggle / changes the Coverage radio.
* Verified end-to-end via simulated DOM traces of all three state transitions (master OFF / master ON+only / master ON+plus_areas) before tagging.

= 0.3.4 — 2026-05-11 =
* **Real fix for the Tbilisi tab conditional fields.** v0.3.3 changed the showWhen operator to `truthy` but the underlying bug was actually in the framework's own admin JS — `inputValue()` did `document.querySelector('[name="codeon[…]"]')` which matched the leading `<input type="hidden" value="0">` that the framework injects alongside every checkbox (so an unchecked box still POSTs `0`). The hidden input came first in DOM order, so the function always returned `'0'` regardless of the checkbox's actual `checked` state, and EVERY `showWhen` predicate gated on a checkbox always evaluated false. Patched the vendored `vendor/codeon/framework/assets/js/codeon-admin.js` to look up the `[type=checkbox]` input directly first, falling through to the original logic for selects, radios, and text inputs. Verified with two unit-style traces (checkbox + radio paths) before tagging this release.

= 0.3.3 — 2026-05-11 =
* **Hotfix: Tbilisi tab conditional fields not appearing.** The Coverage radio + Surrounding-areas multiselect were gated on `showWhen('tbilisi_only_mode', '=', true)`, but the framework's checkbox value-coercion returns the literal string `'1'` (not `'true'`) — so the comparison always failed and the merchant only ever saw the master checkbox with no way to pick "Tbilisi + surroundings" or add surrounding settlements. Switched the gate to the `truthy` operator, which correctly evaluates the `'1'` value.
* **Hotfix: Diagnostics tab leaked a local filesystem path.** The "Source" line read `"ka.wikipedia.org via /Users/george/Documents/georgian-data/scraper.py"` — the source string got captured from the bundler's environment when the dataset was built. Cleaned the bundled `data/locations.php`, the regenerator script `build/sync-from-georgian-data.php`, AND added a defensive display-time strip in DiagnosticsTab so any merchant still on an older bundle gets a clean value too.

= 0.3.2 — 2026-05-11 =
* **New "Tbilisi & surroundings" settings tab.** A purpose-built override mode for stores that ship to Tbilisi only or to Tbilisi plus a curated list of surrounding villages. When enabled, the entire Region → Municipality → Settlement cascade is replaced and **all** General-tab location rules (the field modes AND the master `locations_enabled` switch) are ignored. Two coverage modes:
    * **Tbilisi only** — every geographic dropdown disappears from checkout. Customer sees only the address fields (line 1, line 2, postcode, phone). Behind the scenes: state = TB, muni = tbilisi, city = "Tbilisi" — order meta stays consistent for WC reports + shipping zones.
    * **Tbilisi + surrounding areas** — single Area dropdown replaces the whole cascade. Customer picks "Tbilisi" or one of the merchant-curated surrounding settlements (typeahead-searchable Select2 picker over the 4,394-strong dataset, results labelled `"Settlement — Muni, Region"` for disambiguation). Picking an area silently fills the hidden state field with the resolved WC code, and a server-side `woocommerce_checkout_posted_data` filter translates the chosen area key back to the canonical settlement name + state code on submit so reports / shipping zones see real values, not picker keys.
* **Override banner on the General tab** — when Tbilisi mode is on, a yellow info-notice renders above the field-mode dropdowns explaining that they're being overridden, with a one-click "Open Tbilisi tab" button. Eliminates the surprise of "I changed Region to Required, why isn't it taking effect?".
* **Dashboard reflects Tbilisi mode** — the status pill in the welcome card switches from "Locations cascade is ON / OFF" to "Locations: Tbilisi only" or "Locations: Tbilisi + N areas" (singular/plural i18n-aware). The Locations dataset card replaces the 13 / 77 / 4,394 stats with a Tbilisi-mode-aware summary: coverage scope, areas at checkout, mode label.
* **States filter respects Tbilisi mode** — `woocommerce_states['GE']` is trimmed to the allowed state codes when Tbilisi mode is active, so any third-party code reading WC's state list sees the restricted set.
* **Block checkout in Tbilisi mode** — graceful fallback: the custom muni / settlement block-checkout fields are not registered, the cascade datalist is not injected, and the cascade JS is not enqueued. Vanilla WC city field stays as a text input. Full Area-picker UX for block-checkout is on the roadmap; classic checkout (the dominant path on Georgian stores using Woodmart and similar themes) is fully covered in this release.
* **Dashboard width cap dropped.** The 1180px max-width on `.codeon-dashboard` is gone — the page now spans the full WP-admin content area like every other tab.
* **REST**: the existing `/wp-json/codeon-geo/v1/search` endpoint is reused as the typeahead source for the Tbilisi surroundings picker — no new endpoints, no new permission surfaces.

= 0.3.1 — 2026-05-11 =
* **Cascade placeholder unification.** Every dropdown in the cascade now uses a consistent "Choose X" placeholder ("Choose Region", "Choose Municipality", "Choose Settlement") in both the static PHP-rendered first option AND the JS-driven post-cascade refresh. The previous wording (mix of "Select municipality…", "Select settlement…", "— pick a Municipality first —") is gone; the Settlement dropdown now reads **"Choose Settlement"** the moment a Municipality is picked, instead of being stuck on "— pick a Municipality first —" until the customer focuses it.
* **Bulletproof Select2 placeholder transition.** Refactored `enhanceWithSelect2()` to be idempotent — destroys any pre-existing Select2 instance before re-initialising, and now accepts the placeholder text as an explicit argument (passed straight from `fillSelect()`) instead of inferring it from the first option's text. Eliminates a class of bugs where Select2's internal cache could keep showing the previous placeholder after the underlying option list was rewritten.
* **Region placeholder via WC locale.** The standard WooCommerce Region (state) field now picks up a "Choose Region" placeholder via the GE locale — same wording family as Municipality + Settlement, no more theme-specific "Select an option…" leakage.
* **Dashboard restyle: minimalistic + brand-aligned.** Adopted the codeon.ge token palette as scoped CSS variables on `.codeon-dashboard` — same blue (`#2563eb`), success/warning/danger soft tints, and radii/shadows as the storefront. Visible changes:
    * Welcome card now renders on a flat white surface (border + subtle shadow) instead of the deep-blue gradient. Heading slimmer, body copy in muted-grey for readability, status pill uses the brand `--success-soft` / `--danger-soft` tokens.
    * Stat tiles (regions / municipalities / settlements / field-modes) sit on `--bg-soft` with quiet `--border-muted` strokes; numbers are tabular-nums in `--fg-strong` instead of a heavy navy blue.
    * Ecosystem cards lose the hover lift and shadowy navy treatment — clean white on hover (`--bg-soft`), brand-soft tint when installed, brand-border accent. Badges use `--success-soft` / `--brand-soft` / `--bg-muted` for parity with the codeon.ge product-card chips.
    * Buttons: primary CTA flat brand colour (`--brand`) with focus ring; secondary CTA neutral white with hover tint to brand. No more gradient text-shadow.
    * Whole layout capped at 1180px so it stops sprawling on ultra-wide admin screens. Mobile breakpoint stacks the welcome head + the card CTA buttons full-width.

= 0.3.0 — 2026-05-11 =
* **3-state field modes for Region / Municipality / Settlement.** The Locations settings tab replaces the old single "is required" checkboxes with one **Disabled / Optional / Required** dropdown per geo field. *Disabled* hides the field at checkout AND drops all validation against it; *Optional* renders the field but allows an empty value; *Required* renders + enforces.
    * **Region:** the merchant can now choose whether the Region (state) dropdown is shown to the customer or auto-derived from the chosen Municipality (the previous "Hide Region" toggle now lives as the Disabled mode of this dropdown).
    * **Municipality:** disabling it removes the muni dropdown entirely. When disabled, the cascade JS, datalist, REST scripts and inline CSS targeting the muni field are all skipped — Settlement automatically falls back to the standard WooCommerce free-text city field (its required-ness still tracks the Settlement mode).
    * **Settlement:** disabling it hides the city field entirely (and drops it from validation).
    * Backwards compatibility: existing v0.2.x option keys (`hide_region_field`, `require_municipality`, `require_settlement`) are read transparently as fallback, and migrated to the new keys on the next activation. Live merchants on 0.2.9 keep their exact behavior after the update.
    * Cross-field guard: combinations that would break checkout (e.g. Region hidden + Municipality disabled → no source for state value) are detected and the state field's `required` flag is relaxed automatically. No combination should be able to brick the checkout form.
* **Dashboard redesign (visual + structural).**
    * The Welcome card no longer carries the **Configure Locations** and **Browse Extensions** buttons. The card now has a status pill in the header showing whether the Locations cascade is ON / OFF at a glance, and only a quiet "Visit codeon.ge" link below the copy.
    * **Configure Locations** moved into the **Georgian Locations dataset** card — right next to the 13 / 77 / 4,394 stats, so the merchant sees the dataset they're configuring and the button at the same time.
    * **Browse Extensions** moved into the **CodeOn ecosystem** card header — sits next to the section title, exactly where a merchant looking at "the rest of the family" expects to find the catalog link.
    * The Locations card now also surfaces the three field-mode values at a glance (Disabled / Optional / Required) alongside the dataset numbers, so the merchant can see at a glance how the checkout cascade is configured without having to open the settings tab.
    * Visual polish: softer card shadow + rounded corners, subtle hover lift on ecosystem cards, deeper blue brand-accent on stats, status pill in the welcome header, mobile-stacked CTA buttons.

= 0.2.9 — 2026-05-08 =
* **Dashboard refinements.**
    * Welcome card now opens with a project-level paragraph (what CodeOn is, why every plugin shares the hub), then explains what Core itself ships, then introduces the rest of the family. Three short paragraphs instead of one long one.
    * Removed "on WordPress.org" from the welcome and from the CodeOn Core ecosystem-card description — the plugin no longer ships through WP.org, so the phrasing was stale.
    * Ecosystem cards now show only **plugin name + status badge + category** — taglines and Manage / Learn more action links are dropped. Cards are noticeably more compact (smaller font, tighter padding, smaller minimum column width) so a typical WP admin width fits ~5 cards per row.
    * Cards now sort by family so related plugins sit side-by-side: CodeOn Core first, then BOG Card + BOG Installments, then TBC Card + TBC Installments, then Flitt, then Credo, then sync plugins (Fina, 1C), then QuickShipper. Unknown slugs sink to the end alphabetically.

= 0.2.8 — 2026-05-08 =
* Verification release — confirms the v0.2.7 PUC integration successfully surfaces a new GitHub release in WordPress's Plugins → Updates UI. No code changes besides the version bump.

= 0.2.7 — 2026-05-08 =
* **Self-hosted update channel restored** via the bundled `yahnis-elsts/plugin-update-checker` library (PUC v5). The plugin now polls `github.com/Samsiani/codeon-core` for new tags every ~12h and surfaces matching `codeon-core-vX.Y.Z.zip` release assets to WordPress's native Plugins → Updates UI — same one-click update UX as a WP.org plugin, but without WP.org as the channel. Reverses the v0.2.0 PUC removal that was made specifically to comply with WP.org hosting rules; codeon-core no longer ships through WP.org, so that constraint no longer applies.
* PUC hits the GitHub API unauthenticated (codeon-core is a public repo). Each WP install consumes ~2 unauthenticated GitHub requests/day (60/h limit per IP), so even on shared hosting with many WP installs the budget is fine.
* `enableReleaseAssets()` is set so PUC prefers the uploaded release ZIP (which contains the bundled `vendor/`) over the source-tarball that GitHub auto-generates from each tag (which would not contain `vendor/`).

= 0.2.6 — 2026-05-08 =
* **Dashboard redesign for the CodeOn ecosystem.** The CodeOn top-level admin page now leads with an ecosystem-first welcome card and adds a new "The CodeOn ecosystem" section that lists every plugin in the family — Core (free), Fina Sync, QuickShipper Delivery, the four Georgian payment-card gateways (TBC, BOG, Flitt) and the three installment gateways (TBC, BOG, Credo). Each plugin is shown with its tagline, category, and a status badge (Installed v0.x.x · Free · Available). The list is sourced from the live codeon.ge catalog (cached for 6h via the framework's `CatalogClient`) with a hardcoded fallback so the section never renders empty on first install or when offline.
* **CSS: dropped the 760px `max-width` cap on `.codeon-dashboard .codeon-card`.** Cards now use the full width of WP's content area on large screens, which makes the new ecosystem grid (`auto-fill, minmax(280px, 1fr)`) actually have room to breathe. The Georgian Locations dataset card and the Installed-plugins card were unchanged structurally — only the width cap was lifted.
* The "Georgian Locations dataset" card (13 regions / 77 municipalities / 4,394 settlements + bundle-built timestamp) is unchanged and still renders below the ecosystem section.

= 0.2.5 — 2026-05-08 =
* **Master Enable / Disable toggle for the Locations feature.** New first field on Settings → CodeOn → Locations: "Enable Georgian Locations cascade at checkout" — default ON so existing merchants are unaffected on update. When OFF, none of the WC-side hooks register: classic checkout, block checkout, address-format override, order-meta capture, and the typeahead REST endpoints all stay dormant. WooCommerce falls back to its standard built-in checkout fields. Existing order data is never altered either way.
* **CodeOn hub menu icon redesigned.** The location-pin Dashicon previously shown next to the "CodeOn" top-level admin menu has been replaced with a brand-aligned hub-and-satellite mark — central node connected to six plugin satellites — that signals "ecosystem of plugins" rather than the locations feature alone (Locations is one tab; Extensions and any installed CodeOn premium plugin sit under the same menu).

= 0.2.4 — 2026-05-08 =
* Bump bundled `codeon/framework` from `^0.3.8` to `^0.3.16`. Picks up framework v0.3.9 through v0.3.16:
    * **v0.3.9–v0.3.12** — `InstallmentEstimator` widget asset auto-enqueue + visual polish (BoG accent, uniform pill geometry, index-based slider, value-aligned labels). No consumer impact for Core (no estimator usage).
    * **v0.3.13** — New `Http\RsaCallbackSignature` primitive + `OrderStatusMapper::fromBogIpayEvent()` deprecated alias renamed to `fromBogPaymentsStatus()`. No consumer impact for Core.
    * **v0.3.14–v0.3.15** — Shared `WooCommerce\Payments\BogPayments\Client` lifted from per-plugin clients; `enableSaveCard()` added. No consumer impact for Core.
    * **v0.3.16** — Mandatory WPML/i18n compatibility bar documented in `docs/I18N_AND_WPML.md`. `InstallmentEstimator::render()` gained an optional `$textdomain` parameter (defaults to `codeon-framework`).
* No code changes in Core itself — `LocationsTab` / `DiagnosticsTab` / `ExtensionsTab` keep working unchanged because every framework change between 0.3.9 and 0.3.16 is either additive or a deprecated-alias path.

= 0.2.3 — 2026-04-30 =
* Bump bundled `codeon/framework` from `^0.3.4` to `^0.3.8`. Picks up two framework fixes (none of them critical to Core itself, since Core has no License module — but they matter when Core is co-installed alongside any of the licensed CodeOn plugins, which is the common deployment):
    * **v0.3.6** — License heartbeat infra: `LicenseClient` now returns a `definitive` flag on failure and `LicenseStore` records every cron tick. Lets paid plugins surface the real "Last checked" + "Last error" on their License tab and flip to Revoked the moment codeon.ge stops recognising the key, instead of reporting Active forever after a server-side delete.
    * **v0.3.7 / v0.3.8** — multi-plugin form-discriminator: `Tab::setPluginSlug()` setter so plugins that override `Manifest::nonce()` (fina-sync, codeon-payments) don't post a wrong slug that makes their License tab buttons land on a blank `admin-post.php` page.
* No code changes in Core itself — the existing `LocationsTab` / `DiagnosticsTab` / `ExtensionsTab` subclasses keep working unchanged because `v0.3.8` reverted the `Tab::render()` signature change from `v0.3.7` and uses a setter instead.

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

= 0.3.12 =
The actual fix for Area's position at checkout: WC's own client-side address-i18n.js re-sorts fields by data-priority and was reading WC's default city.priority of 70. Now feeding country_priority+1 into the unprefixed default AND the GE locale override.

= 0.3.11 =
Area at checkout now lands right after Country regardless of theme re-mapping. Shipping-zone region picker no longer hides 10 of 13 GE regions. Strongly recommended.

= 0.3.10 =
Area field now sits right after Country/Region on checkout. Admin Surroundings picker shows Georgian-only labels (no redundant Latin transliteration).

= 0.3.9 =
Pill polish on the Surroundings picker: remove-× no longer cropped, default separator border dropped, 20px left-padding on each pill.

= 0.3.8 =
Surroundings picker: pills no longer overflow the container, typed search text is fully visible. Headless-browser verified before tagging. Strongly recommended.

= 0.3.7 =
Surroundings picker now mirrors the WC country-selector look — dedicated search row, visible typed text, clean labels. Area field moved to right after Country / Region on checkout. Recommended.

= 0.3.6 =
Surroundings picker now actually saves selected settlements, has a tall + clear input area, and grows dynamically as you add more pills. Strongly recommended.

= 0.3.5 =
The actual fix: Tbilisi tab reveals its conditional fields via fully self-contained inline JS, bypassing the framework's broken showWhen entirely. No more FOUC, works regardless of co-installed plugins. Strongly recommended.

= 0.3.4 =
Real fix for the Tbilisi-tab conditional reveal: framework JS was reading the wrong DOM input for every checkbox-gated showWhen. Strongly recommended for anyone on 0.3.2 or 0.3.3.

= 0.3.3 =
Hotfix: Tbilisi tab Coverage radio + Surroundings picker were never appearing because of a checkbox-vs-string comparison bug. Strongly recommended for anyone on 0.3.2.

= 0.3.2 =
New "Tbilisi & surroundings" tab adds a single-area-picker override for stores that ship to Tbilisi only or Tbilisi + curated nearby settlements. Existing checkouts unaffected unless the merchant explicitly enables Tbilisi mode.

= 0.3.1 =
Cascade placeholders unified to "Choose X" wording, Select2 placeholder transition is now bulletproof, and the CodeOn dashboard adopts the storefront's minimalistic brand palette.

= 0.3.0 =
3-state field modes (Disabled / Optional / Required) for Region, Municipality, and Settlement at checkout. Existing settings migrated automatically — no merchant action required.

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
