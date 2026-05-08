# Changelog

All notable changes to the CodeOn Plugin Framework. Format: [Keep a Changelog](https://keepachangelog.com/), versioning: [SemVer](https://semver.org/).

---

## [Unreleased]

## [0.3.16] — 2026-05-04

### Documented — `docs/I18N_AND_WPML.md` (mandatory for every CodeOn plugin)

New top-level rule encoded in the framework: **every CodeOn plugin must be WPML-compatible** at the bar described in the new doc. Pulls together what already-shipped plugins do correctly (load_plugin_textdomain on init, slug-equals-textdomain) and adds the bits that were inconsistent across the suite:

- string-literal textdomain arguments at every direct call site (so `wp i18n make-pot` actually finds them);
- `wpml-config.xml` at plugin root declaring admin-texts for any merchant-edited option that carries user-facing strings (e.g. WC payment-gateway `title` / `description`);
- `wp.i18n.__()` plus `wp_set_script_translations()` for every Block-Checkout / client-side script handle; deps array includes `'wp-i18n'`;
- a `languages/` directory at plugin root with a README that documents the make-pot / make-json commands;
- framework helpers that take a `$textdomain` parameter (today: `InstallmentEstimator::render()`) — consumer plugin passes its own slug; .pot generation scans both `includes/` AND `vendor/codeon/framework/src/`.

`CLAUDE.md` adopts the doc as a release-blocker rule; failing any checklist item blocks a tag.

`codeon-bog-installments` v0.3.5 is the reference implementation — it's the first plugin to retro-fit the full bar (admin-texts via `wpml-config.xml`, JS translations via `wp_set_script_translations`, `languages/` README). Other plugins (`codeon-bog-card-payment`, `fina-sync`, …) inherit the requirement on next release.

---

## [0.3.15] — 2026-05-04

### Added — `BogPayments\Client::enableSaveCard()` (`PUT /payments/v1/orders/{id}/cards`)

Marks an existing order as eligible for BOG's "save card" hosted-page option. Per BOG's docs the call must happen BEFORE redirecting the customer; the card plugin v0.3.1 wires it conditionally on a new gateway settings toggle. Returns BOG's `202 Accepted` response body. Optional `Idempotency-Key` (UUID v4) on retries.

Internal: `request()` gained an `$extraHeaders` parameter so per-call headers (Idempotency-Key today; future ones tomorrow) merge cleanly with the standard Authorization / Accept / X-Request-ID / User-Agent set without each method building its own array.

---

## [0.3.14] — 2026-05-04

### Added — `WooCommerce\Payments\BogPayments\Client` (lift from per-plugin clients)

Both `codeon-bog-installments` v0.3.x and `codeon-bog-card-payment` shipped near-identical 250-line HTTP clients (`Api\BogPaymentsClient` / `Api\BogClient`) talking to `api.bog.ge/payments/v1/...`. The "two real consumers" trigger from CLAUDE.md rule 2 has fired with the card plugin's v0.3.0 refactor — lifted to the framework as a single parameterised client.

Public API:
- Constructor: `(string $clientId, string $clientSecret, string $environment, string $pluginSlug, string $pluginVersion, Logger $logger)`. Plugin slug + version scope the token cache key, object-cache lock group, log channel, and User-Agent so two CodeOn payment plugins co-installed on the same site never collide.
- `createOrder(array)` — `POST /payments/v1/ecommerce/orders` (any payment_method).
- `getReceipt(string)` — `GET /payments/v1/receipt/{id}`.
- `refund(string, ?float, string)` — `POST /payments/v1/payment/refund/{id}`.
- `completePreauth(string, string $authType, ?float, string)` — `POST /payments/v1/payment/{id}` (capture, full or partial via amount) or `DELETE /payments/v1/payment/{id}` (void).
- `accessToken()`, `clearCachedToken()`, `isConfigured()`, `environment()`.
- Static `formatAmountForApi(float, string)` — currency-aware string formatting (HARDENING.md §5).
- Constant `CALLBACK_PUBLIC_KEY_PEM` — BOG's RSA-2048 callback public key (HARDENING.md §1.4: never make this configurable).

Plus `BogPayments\ApiException` — parallel exception class with `httpStatus()` / `responseBody()` / `errorCode()` accessors. Replaces each plugin's local `BogApiException`.

OAuth flow + token caching follows HARDENING.md §4 verbatim: `wp_cache_add` single-flight mutex, 150ms backoff on lock loss, transient with `expires_in - 60s` safety margin, 30s max lock TTL.

The plugin-local clients in v0.3.x of either plugin keep working — this is purely an additive framework option. v0.3.x of `codeon-bog-card-payment` (refactor due) and v0.3.4+ of `codeon-bog-installments` migrate to the new shared class and delete their local copies.

---

## [0.3.13] — 2026-05-04

### Added — `Http\RsaCallbackSignature` (generic RSA-SHA256 callback verifier)

New `src/Http/RsaCallbackSignature.php`. Verifies a callback body against an `Authorization`-style signature header using RSA-SHA256 + a configured PEM public key. First consumer is `codeon-bog-installments` v0.3.0 (BOG's new Payments API at `api.bog.ge/payments/v1/...` signs every callback). Future BOG plugins (when `codeon-bog-card-payment` migrates) and any other bank that adopts the same pattern reuse this primitive without forking it.

Fail-closed by contract: empty / malformed PEM, missing / malformed signature, openssl_verify mismatch — every error path returns `false`. Never throws. Never returns `true` on error. Never falls back to "skip verification". Silent on legitimate signature mismatches (so the log can't become a probing oracle); loud on every deployment-bug path (empty key, key parse failure, openssl_verify returning -1).

Algorithm hard-coded to SHA-256 — the only meaningful choice today; a different scheme is a different verifier class, not a polymorphic constructor.

### Changed — `OrderStatusMapper::fromBogIpayEvent()` renamed to `fromBogPaymentsStatus()`

Same `order_status.key` enum is returned by every product on `api.bog.ge/payments/v1/...` (card, Apple Pay, Google Pay, BoG P2P, BoG Loyalty, gift card, `bog_loan`, `bnpl`). Renaming clarifies the mapper covers all of them, not just iPay card flows.

Also adds two cases that were missing: `auth_requested` (loan applications awaiting bank decision → `RESULT_PENDING`) and `refund_requested` (transient state preceding the actual refund event → `RESULT_PENDING`).

`fromBogIpayEvent()` is kept as a `@deprecated` thin alias forwarding to `fromBogPaymentsStatus()` so `codeon-bog-card-payment` (and any other consumer) keeps building until it migrates. Will be removed in a future release.

### Documented — signed callbacks section in `docs/PAYMENT_GATEWAY_HARDENING.md`

New `§1.4 Signed callbacks (RSA)` after the IP-allowlist section. Documents:
- when to use `RsaCallbackSignature` (any bank that publishes a public key),
- fail-closed contract — every error path returns false,
- RSA replaces the `extra` HMAC defense layer when both exist on the same API,
- bank-order-id binding (§1.1) still applies on top — defense in depth,
- pass the raw body (`WP_REST_Request::get_body()`), not a re-encoded version,
- store the public key as a plugin-side class constant (not a configurable option).

---

## [0.3.12] — 2026-05-04

### Changed — minimal estimator layout (drops card chrome, eyebrow, brand icon, term-line, meta footer)

The estimator widget is now intentionally chromeless: two stat blocks (months count + monthly amount) on top, the orange-themed range slider in the middle, allowed-term labels at the bottom. No card border, no shadow, no brand icon, no "over X months · total Y" line, no "Effective rate · Apply in under a minute · Decision in real time" footer. The compact form fits inside a checkout payment-method panel without dominating the UI.

The `$brandName` and `$iconUrl` parameters on `InstallmentEstimator::render()` are kept for API compatibility but are no longer rendered. Existing call sites continue to work unchanged.

### Changed — labels positioned by *value*, slider returns to value-based semantics with snap-to-allowed

In the new layout each allowed-term label is absolutely positioned at its actual fraction of the value range (`(m − min) / (max − min)`) using `calc(thumb_w/2 + (100% − thumb_w) * fraction)`. This is the same arithmetic the native `<input type=range>` thumb uses to compute its own centre, so the active label sits directly under the thumb at every value. As a side effect, labels for closely-spaced terms (e.g. `6` and `9` near the start of a `6..48` range) cluster visually — which is correct, because that's where those values actually live on the slider's scale.

The slider is back to month-value semantics (`min=6`, `max=48`, `step=1`, `value=24`). Dragging snaps to the nearest allowed term on every `input` event via `snapToAllowed()`, so the thumb visually parks on a term as the user drags rather than landing on arbitrary months. `data-months` switched from a `{months: monthly}` map (used in v0.3.0–0.3.11) to a flat sorted array of allowed terms — JS recomputes the monthly via `monthlyPayment()` for every render.

`--codeon-est-thumb-w` is now a shared CSS custom property (`20px`) referenced by both the slider thumb rules and the absolutely-positioned mark labels. Changing the thumb size in one place keeps label alignment correct automatically.

---

## [0.3.11] — 2026-05-04

### Fixed — estimator slider thumb did not align with the active term pill (off-by-one selection)

The estimator's `<input type=range>` was emitted with the actual month range as bounds (`min=6`, `max=48`, `step=1`), so a linear sweep from 6 → 48 mapped value `9` to ~7% of the slider's width. But the eight allowed terms — `6, 9, 12, 18, 24, 30, 36, 48` — are *not* evenly spaced, while the pill row laid them out at even fractions (`i / 7`). Result: dragging the thumb visually under the "9" pill landed at a slider value that snapped to "12", and selecting `36` (index 6 of 8) parked the thumb between "30" and "36". Cosmetically the widget looked desynchronised on every drag.

`v0.3.11` makes the slider **index-based**:

- PHP renders the slider with `min=0`, `max=N-1`, `step=1`, `value=defaultIdx`. JS reads the slider value as an index into the allowed-terms array and looks up the month count from there. The thumb now stops at exactly one pill per slider step.
- Pill diameter is locked to the slider thumb diameter (`32 × 32`, `28 × 28` on narrow viewports). This is the geometric invariant that makes `justify-content: space-between` pill centers and the native `<input type=range>` thumb's travel range (`thumb_w/2 .. track_w − thumb_w/2`) coincide. When pill width and thumb width drift apart, alignment drifts in proportion.
- Pill row is `flex-wrap: nowrap` — wrapping would put pills on two rows while the slider stays one row, breaking the spatial mapping.
- `--codeon-est-progress` is seeded inline from PHP using the default term's *index*, so the orange filled portion of the slider is correct on first paint without waiting for JS.

No API changes for callers. Plugins that consume `InstallmentEstimator::render()` get the fix on `composer update`.

---

## [0.3.10] — 2026-05-04

### Changed — installment estimator polish: BoG accent + uniform pill geometry

Two visual fixes on top of the v0.3.9 estimator widget:

- **Bank of Georgia accent (`#ff671d`).** The active term pill, slider thumb, slider focus ring, and the *filled* portion of the slider track now use the BoG orange. The unfilled track stays neutral slate (`#e2e8f0`). The colour is exposed as a CSS custom property `--codeon-est-accent` (with a `--codeon-est-accent-hover` and `--codeon-est-accent-soft` companion), so a future caller that needs a different bank's accent can override it inline on `.codeon-est-card` without forking the stylesheet.
- **Uniform circular pills.** Term pills are now fixed `40 × 40` circles with `flex: 0 0 40px`, so single-digit ("6") and two-digit ("48") labels render as identical circles. Previously horizontal padding made `48`-style pills visibly wider than `6`-style pills, breaking the row's rhythm.

The slider's filled-track effect uses a CSS gradient driven by a `--codeon-est-progress` variable; the JS recomputes that variable on every `setTerm()` call (pill click, slider input, default-term init) so the orange portion always reaches up to the thumb. Falls back to a flat 50% fill before JS executes (cosmetic only — actual UX is identical).

No API changes. Consumers bump and rebuild.

---

## [0.3.9] — 2026-05-04

### Fixed — installment estimator widget rendered unstyled and non-interactive on every consumer site (critical UX)

`InstallmentEstimator::render()` (added in 0.3.0) emitted the `.codeon-est-card` markup — eyebrow, amount row, term pills, range slider, meta line — but the framework shipped no matching CSS or JS. Every consumer plugin that called it (TBC card, BoG card, BoG installments) rendered an unstyled column of text and a button list at the top of `payment_fields()`, plus a slider with native browser styling and zero interactivity: clicking a term pill or dragging the slider did not update the monthly amount or total.

The widget was structurally fine (data-attrs already carried `principal`, `apr`, `months` map, `default`); only the presentation/behavior layer was missing. v0.3.9 ships:

- `assets/css/codeon-estimator.css` — theme-agnostic stylesheet for `.codeon-est-card` and children. Self-contained: own font stack, explicit resets on the buttons and range input we render (`-webkit-appearance: none`, `appearance: none`, `box-shadow: none`, `text-shadow: none`), high-specificity `.codeon-est-card .codeon-est-pill` selectors so theme button rules can't out-rank ours. Tested intent against Storefront, Astra, OceanWP, WoodMart, Flatsome, Avada — no theme tokens or CSS variables, no dependency on a parent layout.
- `assets/js/codeon-estimator.js` — vanilla JS, no jQuery dependency. Wires every `[data-codeon-estimator]` on the page: clicking `[data-codeon-est-pill]` selects that term, dragging `[data-codeon-est-slider]` snaps to the nearest allowed term, and `[data-codeon-est-monthly]` / `[data-codeon-est-months]` / `[data-codeon-est-total]` update in lock-step. Math mirrors PHP `monthlyPayment()` exactly so SSR'd default-term values match JS recomputation. Also re-inits on jQuery `updated_checkout` / `payment_method_selected` for WC classic checkout fragment refresh.
- `InstallmentEstimator::render()` now auto-enqueues both assets via `wp_enqueue_style` / `wp_enqueue_script`. Consumer plugins don't have to wire anything; calling `render()` is enough. URL is computed from `__FILE__` at runtime, so the same code works wherever the framework is composer-installed (any plugin slug, any wp-content path).

No API changes. No upgrade steps for plugins beyond `composer update codeon/framework` and rebuilding the release ZIP.

---

### Added — `docs/PAYMENT_GATEWAY_HARDENING.md` (mandatory baseline for every payment plugin)

Encodes the patterns from the v0.2.0 audit pass on `codeon-bog-card-payment`. Every CodeOn payment micro-plugin (TBC Card, TBC Installments, BOG Card, BOG Installments, Credo, Flitt) now has a single canonical reference for:

- The 7-layer webhook defence stack (IP allowlist → order lookup → payment-method check → **bank-side order id binding** → amount → currency → trust-but-verify re-fetch).
- Canonical event id + per-order lock pattern shared between webhook and reconcile cron — prevents racing webhook + cron from both calling `OrderStatusMapper::apply()` on the same order.
- Reconcile cron fan-out via Action Scheduler (one async action per stuck order, never an inline loop with bank HTTP calls).
- OAuth token refresh single-flight mutex (`wp_cache_add` lock + 150ms backoff + retry-cache) — prevents bank rate-limit lockout under concurrent checkouts at expiry.
- Money discipline: `Money::toApiDecimal` + `number_format(…, 2, '.', '')` for every JSON-body amount. **Forbidden:** `(string) $float`.
- Partial pre-auth capture accounting: `payment_complete()` + `wc_create_refund([…, refund_payment => false])` for the un-captured remainder so WC's ledger matches what actually moved bank-side.
- Logging discipline: never log raw bank responses (PII), credentials, tokens, or `Authorization` headers.
- Admin handler ordering: capability check **before** nonce check.
- Anti-patterns: defence-in-depth that defends nothing (e.g. round-trip HMAC values never verified), `(string) $float` for amounts, returning 200 on transient verify failures (banks need 503 to retry).

Linked from `README.md`, `CLAUDE.md`, and `SECURITY.md`. Reference implementation: `codeon-bog-card-payment` v0.2.0+.

---

## [0.3.8] — 2026-04-30

### Fixed — v0.3.7 broke `Tab` subclasses with the existing one-arg `render()` override (critical regression)

`v0.3.7` changed the abstract `Tab::render()` signature to add a second `$pluginSlug` parameter with a default value. PHP enforces signature compatibility on overrides regardless of default values, so every plugin with a `Tab` subclass that overrode `render(string $nonceAction): void` (fina-sync's `DashboardTab` / `SettingsTab`, codeon-payments's tabs, etc.) crashed on activation with:

```
Fatal error: Declaration of …\DashboardTab::render(string $nonceAction): void
must be compatible with CodeOn\Framework\Admin\Tab::render(string $nonceAction, string $pluginSlug = ''): void
```

`v0.3.8` reverts the signature change and uses a setter on the base class instead. `Tab` now has `protected string $pluginSlug = ''` and `setPluginSlug(string $slug)`. `Page::render()` calls `$active->setPluginSlug($this->manifest->slug)` immediately before `$active->render($nonceAction)`. The default `Tab::render()` reads `$this->pluginSlug` and forwards to `FieldRenderer::renderForm()` (which kept the optional last-position `$pluginSlug` parameter from 0.3.7); `LicenseTab::render()` reads it the same way before calling its private `renderActionForm()`.

Existing `Tab` subclasses with the one-arg signature override keep working — their override stays valid. They get the slug for free if they call the parent or `FieldRenderer::renderForm()`; if they emit their own discriminator-bearing form, they can read `$this->pluginSlug`.

**`v0.3.7` should not be used.** Anyone who pinned to `^0.3.7` should re-pin to `^0.3.8` and rebuild their release ZIP.

## [0.3.7] — 2026-04-30 (yanked, broke `Tab` subclasses — see 0.3.8)

### Fixed — empty white page on Save / License-tab actions for plugins with custom `Manifest::nonce()` (critical, follow-up to 0.3.4 / 0.3.5)

The multi-plugin form discriminator added in 0.3.4 (Save) and 0.3.5 (License tab) computed `codeon_plugin_slug` by stripping the `codeon_admin_` prefix from the nonce action:

```php
$slug = (string) preg_replace('/^codeon_admin_/', '', $nonceAction);
```

That assumes every plugin keeps the default nonce-action shape. Every plugin that overrides it to anything else — `codeon-payments → codeon_payments_admin`, `fina-sync → fina_sync_admin`, presumably others — saw the regex match nothing, leaving the original nonce-action string in the hidden field. `Page::isOurPost()` then compared (e.g.) `fina_sync_admin` against `manifest->slug = 'fina-sync'`, returned false, and every handler bailed. With nothing emitted, `wp-admin/admin-post.php` returned an empty white page on every Refresh / Release / Activate / Save click in the affected plugins. Symptom on the merchant side: License-tab buttons that don't appear to do anything, browser sitting on `/wp-admin/admin-post.php` with a blank body. (TBC, BoG, Flitt, Credo and any other plugin that didn't customise `Manifest::nonce()` were unaffected — their derived slug matched.)

The fix uses a setter on the `Tab` base class (`setPluginSlug(string)`), not a new `render()` parameter — PHP rejects an override with a different parameter count even when the new parameter has a default value, and existing plugins (fina-sync's `DashboardTab` / `SettingsTab`, etc.) already override `render(string $nonceAction): void` with the one-arg signature. `Page::render()` now calls `$active->setPluginSlug($this->manifest->slug)` immediately before `$active->render($nonceAction)`. The default `Tab::render()` reads `$this->pluginSlug` and forwards it to `FieldRenderer::renderForm()`. `LicenseTab::renderActionForm()` reads it the same way. `FieldRenderer::renderForm()` gained an optional last-position `$pluginSlug` argument and prefers it; the legacy `preg_replace('/^codeon_admin_/', '', $nonceAction)` fallback stays only for callers that still pass none (back-compat for any external `Tab` subclass that calls `FieldRenderer::renderForm()` directly).

External `Tab` subclasses with the existing `render(string $nonceAction): void` override keep working unchanged. They just don't get the new slug discriminator unless they call `setPluginSlug` / read `$this->pluginSlug` themselves — which is irrelevant unless they emit their own form posting to `admin_post_codeon_save_tab` / `admin_post_codeon_tab_action`.

No upgrade steps required for plugins. Bumping `composer.json` to `^0.3.7` and rebuilding the release ZIP is enough.

---

## [0.3.6] — 2026-04-30

### Fixed — ghost-active licenses after server-side deletion (critical)

`LicenseGate::revalidate()` (in every consumer plugin) silently dropped every non-OK response from `/api/v1/validate-license`. When a merchant's key was deleted on codeon.ge — or the binding was reassigned to another domain, or the license was revoked — the plugin's local snapshot kept its original `expires` field (often years out), so `LicenseStore::effectiveStatus()` kept returning `'active'` indefinitely. Cron tick after cron tick, the server said "no", and the plugin shrugged. Symptom on the License tab: `Last checked` stuck at the day the key was deleted (because `cached_at` was only written on success), `Expires` still showing the original year, gateway/sync features still live.

The fix has two halves:

- **`LicenseClient::validate()` now returns a `definitive` flag on failure.** HTTP 4xx with a parseable JSON body (key unknown, revoked, domain mismatch) is `definitive: true` — the server is actively saying "no". Network errors, 5xx, signature mismatch, and malformed bodies are `definitive: false` — could be a transient codeon.ge outage, preserve the local snapshot, retry next tick.
- **`LicenseStore` gained a third option `<slug>_license_check`** that records every heartbeat (success or failure) with `checked_at`, `ok`, `definitive`, `error`. `effectiveStatus()` returns `'revoked'` immediately when the last check was a definitive rejection, regardless of the snapshot's `expires`. The License tab's "Last checked" now reflects every cron tick, and "Last error" surfaces what codeon.ge actually said.

Plugin-side wiring (each consumer plugin's `LicenseGate::revalidate()`):

```php
$result = $client->validate($key, home_url('/'), CONST_VERSION);
if ($result['ok'] ?? false) {
    $store->setSnapshot((array) $result['response']);  // also records success check
    return;
}
$store->recordCheck(false, (bool) ($result['definitive'] ?? false), (string) ($result['error'] ?? ''));
```

Adapter `snapshot()` should now read `last_check` and `last_error` from `$store->getCheck()` so the heartbeat truth surfaces in the UI:

```php
$check = $store->getCheck();
return [
    // …
    'last_check' => isset($check['checked_at']) ? (int) $check['checked_at'] : 0,
    'last_error' => (string) ($check['error'] ?? ''),
];
```

Backward-compatible: pre-0.3.6 plugins still work — they just keep dropping failures on the floor. Every consumer plugin should ship a release that picks up `^0.3.6` and adopts the two-line wiring above (this is the same blast radius as 0.3.4 / 0.3.5; the same "TBC + BoG + Flitt + Credo + Fina" install set is affected).

`AbstractGateway::licenseModuleActive()` already gates on `effectiveStatus() in ['active','grace']`, so any existing gateway built on the framework will start refusing checkout the moment the heartbeat flips to `revoked` — no per-plugin gateway change required.

---

## [0.3.5] — 2026-04-28

### Fixed — multi-plugin License-tab actions (critical, follow-up to 0.3.4)

The `0.3.4` fix patched only `Schema/FieldRenderer::renderForm()`. The License tab's Activate / Refresh now / Release this domain buttons live in `Admin/LicenseTab::renderActionForm()`, which posts to the same global `admin_post_codeon_tab_action` hook without the discriminator field. With two or more framework consumers active (the user hit this with Flitt + Credo + TBC + BoG all installed), every plugin's `handleTabAction()` ran on every click, the first one won, and `wp_die`'d on a nonce minted for a different plugin — surfacing as "Security check failed. Please reload the page and try again." on a perfectly fresh tab.

`LicenseTab::renderActionForm()` now injects the same `codeon_plugin_slug` hidden field. `Admin/Page::handleTabAction()`'s existing `isOurPost()` check picks it up and bails on cross-plugin posts.

No upgrade steps required. Backward compatible — pre-0.3.5 forms (no slug field) still pass through.

---

## [0.3.4] — 2026-04-25

### Fixed — multi-plugin save flow (critical)

When two or more framework consumers were active at the same time (e.g. `codeon-core` + `fina-sync`), every consumer's `Page::handleSave` listened on the global `admin_post_codeon_save_tab` hook. The first registered handler ran first, called `guardWriteRequest()`, and `wp_die()`'d on a nonce minted for a *different* plugin's form. Net result: the merchant got "Security check failed" on every Save click no matter which plugin's settings page they were on.

- `Schema/FieldRenderer::renderForm()` now injects a hidden `codeon_plugin_slug` field with the slug parsed out of the nonce action.
- `Admin/Page::handleSave()` and `handleTabAction()` bail early via the new `isOurPost()` helper when the posted slug doesn't match this Page's manifest. Legacy forms (no posted slug) still pass through for back-compat.

No upgrade steps required for plugins on the framework — the discriminator is fully backward-compatible.

---

## [0.3.3] — 2026-04-25

### Changed — License

- **Re-licensed from `proprietary` to `GPL-2.0-or-later`**. Previous releases shipped under a proprietary license that restricted use to CodeOn-owned plugins distributed via codeon.ge. That model conflicts with the new free `codeon-core` plugin shipping on WordPress.org, which requires every bundled library to be GPL-2.0-compatible. Re-licensing has zero downside: the framework was already de-facto public the moment Core's first ZIP shipped, and the watermarked-build-id mechanism that protects paid plugins is independent of the framework's license.
- `LICENSE` file replaced with the full GPL-2.0 text.
- `composer.json` `license` field updated.
- `README.md` and `docs/SCAFFOLDING.md` example updated to reflect the new license.

### Notes for downstream paid plugins (Flitt, TBC, BOG, etc.)

- Update your plugin's `composer.json` `license` field to `GPL-2.0-or-later` — bundling a GPL library makes the combined work GPL. This is the same license model WordPress itself, Yoast Premium, ACF Pro, and every other "paid" WordPress plugin uses. Your commercial moat is the license-key gate + signed updates from codeon.ge, not source ownership.
- No code changes required.

---

## [0.1.3] — 2026-04-25

### Added

- `CodeOn\Framework\License\KnownPlugins` — canonical slug → human-label
  registry for the publicly listed CodeOn plugin catalogue
  (`fina-sync`, `codeon-payments`, `quickshipper-delivery`). Filterable
  via `codeon/framework/known_plugins` so individual plugins can register
  additional labels (private bundles, unreleased plugins) without waiting
  for a framework release. Adapter `features()` implementations should
  delegate to `KnownPlugins::label()` so multi-plugin licenses render the
  same human-readable name regardless of which plugin's License tab is
  open. Documented in `docs/LICENSE_INTEGRATION.md`.

### Changed — License tab UX

- **Dropped the internal `<h2>License status</h2>` heading.** The chrome
  header band already shows the status pill; duplicating the label
  inside the section was noise. The status pill in the header is the
  single source of truth.
- **Key input is now conditional** on licence state. When the licence is
  ACTIVE, the input is hidden — preventing accidental overwrites of a
  working license with a typo. Swapping keys is a deliberate two-step:
  Release → input reappears → re-Activate.
- **Action buttons live inline with the status meta** instead of in a
  separate `<section>` below. Active licenses show `[Refresh now,
  Release this domain]`; non-active licenses show
  `[Activate / Save, Refresh now, Release this domain]`. The button row
  sits directly under the meta dl with a thin top border separator —
  one section, one form, one save flow.
- **Release button has a confirm guard** (`data-codeon-confirm` on the
  button, picked up by the existing `wireConfirmGuards()` JS handler).
  A misclick can't strand a working license binding now.
- CSS: `.codeon-license-form` got a top border and tighter spacing;
  `.codeon-license-key-input` is the new selector for the key text
  field with a 480 px max-width cap.

### Documentation

- `docs/LICENSE_INTEGRATION.md` gained a "Plan includes" section
  showing the `KnownPlugins` pattern and a "License tab UX rules"
  section codifying the new behaviour so adapter authors know what
  they get for free.

## [0.1.2] — 2026-04-24

### Changed

- `.codeon-wrap` is now full-width by default. The 1200 px `max-width` cap has been removed so admin pages use the full WP admin canvas on wide screens. Anything that would visually break at 2000+ px should cap itself at the component level (form-table columns, multi-column grids, prose paragraphs), not at the page wrap. The new rule is documented in `docs/UI_GUIDELINES.md` under "Width" so future contributors don't reintroduce a wrap-level cap to "fix" a layout problem.

## [0.1.1] — 2026-04-24

### Fixed

- `HealthCard` is now a regular `final class` with individual `readonly` properties instead of a `final readonly class`. The class-level `readonly` modifier requires PHP 8.2; the framework's stated floor is 8.1 (matching fina-sync). The CI release job on PHP 8.1 surfaced the parse error before any consumer saw the broken tag.

## [0.1.0] — 2026-04-24

Initial release. The minimum surface needed to migrate **fina-sync** as the canary adopter.

### Added

- **Plugin bootstrap**: `Bootstrap::register($manifest, $tabs, $stamp)` is the single entry point a host plugin calls. Returns `['page' => Page, 'recovery' => bool]` so the plugin can skip its own subsystems when watermark verify fails.
- **Manifest** value object: plugin slug, menu title, icon, version, capability, support URL, optional nonce-action override (for backwards-compat with merchant bookmarks).
- **Page + Tab** chrome: framework owns the `<div class="wrap codeon-wrap">`, header band, tab nav with health dots, content area, footer with build watermark. Plugin's `Tab` subclasses only emit what goes inside `<section>`.
- **Schema DSL** (`Field`, `FieldType`, `FieldRenderer`, `FieldValidator`): plugins declare an array of fluent `Field` builders; the framework renders the form, validates the POST, persists through the adapter, flashes a notice. Field types: `text`, `password` (write-only by default), `url`, `number`, `select`, `multiselect`, `radio`, `radio_cards`, `checkbox`, `textarea`, `heading`, `raw`, `map_picker`. Modifiers: `description`, `default`, `placeholder`, `autocomplete`, `wide`, `writeOnly`, `options`, `optionsCallback`, `optionHelp`, `showWhen`, `sanitize`, `validate`, `with`.
- **Storage**: `SettingsRepository` interface + `FlatOptionRepository` concrete (single `wp_option` storing an assoc array — the fina-sync pattern). Buffered writes; one `update_option` call per form save.
- **License & Updates tab** (`LicenseTab`): concrete framework class. Plugin supplies a `LicenseAdapter` that wraps its existing `License`/`LicenseGate`/`LicenseStore`. Standard UI: status pill, masked key + plan + expiry meta, key input, Activate / Refresh / Release buttons, Plan-includes feature list.
- **Watermark** (`BuildStampContract`, `RecoveryMode`): interface + helpers for the per-license build-ID scatter pattern. When `verify()` returns false, framework strips business-logic tabs and emits a recovery-mode admin notice — the host plugin remains responsible for skipping its own sync/payment subsystems.
- **HTTP** (`AdminPostRouter::aliasLegacy()`, `NonceGate`): backwards-compat router that re-routes legacy `admin_post_*` URLs into the framework's save flow without changing the URL or nonce action.
- **Notices**: per-user transient flash bus (`Notices::add/flush/clear`). Replaces ad-hoc `add_action('admin_notices', closure)` and per-plugin redirect-with-querystring patterns.
- **Assets**: enqueues `codeon-admin.css/.js` only on framework-owned hook suffixes; fires `codeon/admin/enqueue` action for plugins to add their own assets behind the same predicate.
- **CSS** (`assets/css/codeon-admin.css`): single file, design tokens (tones / spacing / radius / typography), chrome (header / tabs / sections / footer), pills, radio-cards, health-card grid, map-picker shell, write-only password placeholder treatment. Adapted from quickshipper-delivery's `.qsd-*` palette and renamespaced to `.codeon-*`.
- **JS** (`assets/js/codeon-admin.js`): vanilla, no framework. Conditional `data-codeon-show` row toggling, `data-codeon-confirm` guards, `data-codeon-copy` clipboard buttons, write-only password focus/blur UX, click-anywhere radio cards. Uses `textContent` everywhere — never `innerHTML` — for XSS hygiene.
- **Docs**: README, UI_GUIDELINES, SCAFFOLDING, FIELD_SCHEMA, STORAGE_ADAPTERS, LICENSE_INTEGRATION, WATERMARK, MIGRATION_PLAYBOOK, SECURITY.

### Notes

- PHP 8.1+ floor (enables `enum`, `readonly`, first-class callable syntax).
- Zero runtime dependencies.
- `NestedDotPathRepository`, `WCGatewayRepository`, `SplitOptionRepository`, `Field::radioCards` JS conditional wiring extras, and `Field::mapPicker` enrichments arrive in v0.2 / v0.3 alongside the quickshipper-delivery and codeon-payments migrations.
