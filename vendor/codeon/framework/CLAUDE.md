# CLAUDE.md

Project-level rules for AI coding agents working in this framework or in any CodeOn plugin built on top of it.

## Before writing plugin code

Invoke the `andrej-karpathy-skills:karpathy-guidelines` skill at the start of any session that will produce or modify CodeOn plugin code (this framework, or downstream micro-plugins like `codeon-bog-installments`, `codeon-tbc-card-payment`, `codeon-credo-installments`, etc.).

The skill enforces four habits that map directly onto this framework's design:

1. **Think before coding.** State assumptions, surface tradeoffs, ask when uncertain instead of picking silently. The framework already defends against many silent failures (recovery mode, license gating, build-stamp validation) — extending that posture into the work itself is the easiest way to keep the conservative defaults intact.
2. **Simplicity first.** No abstractions for single-use code, no speculative configurability. Plugins in this suite are deliberately small; resist the urge to add layers the framework already provides.
3. **Surgical changes.** Touch only what the task requires. Match existing style. Don't refactor adjacent code, don't delete pre-existing dead code, don't reformat files you happen to open.
4. **Goal-driven execution.** Define a verifiable success criterion before implementing — a syntax check, a passing reconcile sweep, a working license activation, a clean ZIP build — so a session can loop independently instead of guessing.

## Payment gateway hardening (mandatory for every payment plugin)

If you are scaffolding or modifying a CodeOn **payment** plugin (TBC Card, TBC Installments, BOG Card, BOG Installments, Credo, Flitt, etc.), read [`docs/PAYMENT_GATEWAY_HARDENING.md`](docs/PAYMENT_GATEWAY_HARDENING.md) **before** writing code.

The doc encodes lessons from the v0.2.0 audit pass on `codeon-bog-card-payment`. Skipping these patterns produces plugins that work in dev but are exploitable, racy, or financially wrong in production. Specifically:

1. **Bind webhooks to the bank's order id, not the WC order id.** WC order ids are public (emails, URLs, JSON-LD); bank ids are not. Constant-time-compare the payload's bank id against the value stored at `process_payment` time. Without this, an attacker who knows any order's total can spoof a "captured" callback.
2. **Trust-but-verify.** For any "money landed" mapper result, server-to-server fetch the receipt before applying the WC transition. The webhook is a notification; the receipt endpoint is the source of truth. Return 503 on transient failure so the bank retries.
3. **Canonical event id + per-order lock** across webhook and reconcile cron. Both paths must converge on the same idempotency row, and the actual `OrderStatusMapper::apply()` call must be inside a `withOrderLock()` critical section.
4. **Reconcile cron fans out via Action Scheduler** — one async action per stuck order, never an inline 50-call loop.
5. **OAuth token refresh single-flight mutex.** `wp_cache_add` lock + 150ms backoff on lock loss + retry-cache. Prevents bank rate-limit lockout under concurrent checkouts at expiry.
6. **Money discipline.** Every amount through `Money::toApiDecimal`. Every JSON-body amount through `BogClient::formatAmountForApi`-style `number_format(…, 2, '.', '')`. Never `(string) $float`.
7. **Partial pre-auth captures must record the un-captured remainder as a `wc_create_refund([…, refund_payment => false])`** so WC's ledger matches what actually moved bank-side. Calling `payment_complete()` alone over-credits the order.
8. **Whitelist log payloads.** Never log raw bank responses (PII), credentials, tokens, or `Authorization` headers.
9. **Capability before nonce** in admin handlers — cheaper failure for unauth probes, clearer 403.

The reference implementation is `codeon-bog-card-payment` v0.2.0+. Copy its symbols when scaffolding a new gateway. Don't reinvent the patterns per plugin.

## Internationalisation + WPML compatibility (mandatory for every plugin)

Every CodeOn plugin — payment, sync, admin tooling — MUST follow [`docs/I18N_AND_WPML.md`](docs/I18N_AND_WPML.md). This isn't about whether the merchant's storefront speaks one language or twelve; it's about the plugin staying coherent the moment any locale-aware tooling (WPML, Polylang, Loco Translate, native WP `wp-content/languages/`) loads.

The minimum bar:

1. **Every user-facing string is wrapped** (`__`, `_e`, `esc_html__`, `_x`, `_n`, …). The textdomain argument is a string literal, NEVER a variable, at every direct call site — `wp i18n make-pot` can't follow variables.
2. **Plugin slug equals textdomain** everywhere. Header has `Text Domain:` and `Domain Path: /languages`.
3. **`load_plugin_textdomain()` runs on `init`**, not `plugins_loaded` (WPML's hooks fire on plugins_loaded too — racing it causes silent fall-through to English).
4. **Block-Checkout / any client-side JS** uses `wp.i18n.__()` and PHP calls `wp_set_script_translations()` with the script handle. The script's deps array includes `'wp-i18n'`.
5. **`wpml-config.xml` ships at the plugin root** declaring admin-texts for any merchant-edited option that contains user-facing strings (e.g. WC payment-gateway settings' `title` / `description`). Without this, WPML's String Translation can't see option-stored strings.
6. **Framework helpers that emit text** (`InstallmentEstimator::render`) take a `$textdomain` parameter — pass the consumer plugin's slug. Runtime calls register through WPML transparently; for `make-pot`, scan `vendor/codeon/framework/src/` together with `includes/`.
7. **`languages/` directory exists** at the plugin root with a `README.txt` documenting the make-pot / make-json commands. `codeon-bog-installments` is the reference shape.

The doc has a checklist; failing any item is a release-blocker. AI agents working on plugin code MUST audit the strings they write against this checklist before tagging a release.

## Plugin icon + banner system

Every CodeOn plugin must follow [`docs/ICON_SYSTEM.md`](docs/ICON_SYSTEM.md) for its `assets/icon/` artwork — `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner.svg`, `banner-772x250.png`, `banner-1544x500.png`. The system fixes the canvas, gradient, corner radius, and CodeOn corner mark so the suite of plugins reads as one family on the WordPress Plugins screen.

Two consequences for AI agents:

1. **Don't reuse a bank logo as the icon.** Drop the bank logo verbatim onto a square canvas violates rules 1–4 (canvas, background, glyph, corner mark) of the spec. Use the bank logo only as inspiration for the central glyph and the bank-specific colour accent — the canvas, gradient, and corner mark are invariant.
2. **Render PNGs via headless Chrome, not `qlmanage` or `sips` from a logo file.** `sips` downscales between sizes (256 → 128, 1544 → 772), but the source PNGs themselves come from rasterising the hand-authored SVG through Chromium. The exact commands live in the spec's "Rendering pipeline" section.

If you scaffolded an icon set with placeholder logo PNGs while bringing up a new plugin, treat them as a stub: the spec-compliant artwork is part of the same patch release that ships the gateway code.

## Why these rules live in the framework repo

Every CodeOn plugin uses this framework as a Composer dependency, so anyone scaffolding or maintaining a plugin lands here through `docs/SCAFFOLDING.md` or `composer.json`. Pinning the rules at the framework level means they propagate to every downstream plugin without each one having to re-state them.
