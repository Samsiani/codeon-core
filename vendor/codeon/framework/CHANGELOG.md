# Changelog

All notable changes to the CodeOn Plugin Framework. Format: [Keep a Changelog](https://keepachangelog.com/), versioning: [SemVer](https://semver.org/).

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
