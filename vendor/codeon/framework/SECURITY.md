# Security Posture

This framework is shipped only inside CodeOn-distributed plugins. Code lives in private repositories and inside license-gated, watermarked ZIPs delivered through codeon.ge. The same defensive posture applies whether you're contributing to the framework itself or to a plugin that vendors it.

## Reporting a vulnerability

Email **support@codeon.ge** with:

- A description of the vulnerability and its impact.
- Reproduction steps (a minimal PoC if applicable).
- The framework / plugin version(s) affected.
- Your name + how you'd like to be credited (or noted as anonymous).

**Do not open a public GitHub issue.** Acknowledgement within 72 hours; coordinated disclosure timeline scoped per case.

---

## Mandatory rules every plugin must follow

### Capabilities & nonces

- Every write endpoint (admin-post, REST mutator, AJAX) verifies a capability AND a nonce. The framework's `NonceGate::verifyOrDie()` is the only path that should call `wp_verify_nonce` for codeon write surfaces. Plugin code that hand-rolls nonce checks is a code smell.
- Default capability is `manage_options`. Use `manage_woocommerce` only when the plugin's domain is exclusively WC-adjacent (e.g. payment gateways).

### Field rendering

- All output is escaped at print time via `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`. The renderer enforces this — never bypass it by calling `Field::raw()` with unescaped strings.
- `Field::password()` defaults to write-only. Don't override that without a hard reason.
- The renderer never echos a stored value back into a `<input type="password">` — empty `value=""` always.

### Sanitization

- The default sanitizer for each field type runs first; chained `->sanitize()` callbacks run after. Never skip the default — `FieldValidator::process()` enforces the chain.
- Validators return `WP_Error` to reject; rejections leave the previous stored value untouched (partial-failure-safe).

### SQL

- No `$wpdb->query()` with un-`prepare`d input. Inside the framework there should be no SQL at all — repositories go through `update_option` / `get_option`. Plugin-level `$wpdb` use needs `$wpdb->prepare(...)`.

### Watermarking

- Production builds MUST pass `BuildStampContract::verify()`. A failed verify must NOT exit/wp_die — the framework's `Bootstrap::register()` already routes to recovery mode. Plugins must skip their own business-logic registration when `Bootstrap::register()` returns `['recovery' => true]`.
- Three scatter sites required (main file, core-class const, JS sentinel). See [`docs/WATERMARK.md`](docs/WATERMARK.md) for the pre-release grep checklist.

### JavaScript

- No `innerHTML` with user-supplied content. The framework's `codeon-admin.js` uses `textContent` for everything dynamic.
- No global window pollution beyond `window.CodeOnFramework` (which is namespaced, frozen, and contains only static data localized via `wp_localize_script`).

### Dependencies

- The framework has zero runtime dependencies beyond the WP/PHP platform.
- Adding a runtime dep requires (a) a security audit, (b) a justification in CHANGELOG, (c) a MAJOR version bump.

### Logging

- Plugins use `WC_Logger` (or equivalent). Never log raw credentials, signed payloads, or auth headers — redact before write.
- Never log raw bank/payment-gateway responses. Whitelist the diagnostic keys you actually need (e.g. `array_keys($response)`, `isset($response['_links']['redirect']['href'])`). See [`docs/PAYMENT_GATEWAY_HARDENING.md`](docs/PAYMENT_GATEWAY_HARDENING.md) §7.

### Payment gateway plugins

Payment plugins have a stricter mandatory baseline on top of everything above. See [`docs/PAYMENT_GATEWAY_HARDENING.md`](docs/PAYMENT_GATEWAY_HARDENING.md). Highlights:

- Webhooks bind to the **bank-side order id** (constant-time compare), not the WC order id.
- Success transitions re-fetch the bank's receipt before applying — webhook is a notification, receipt is the source of truth. Return 503 on transient failure.
- Webhook + reconcile cron share a canonical event id and a per-order lock so concurrent paths can't both call `OrderStatusMapper::apply()`.
- Reconcile cron fans out via Action Scheduler — never an inline 50-call loop.
- OAuth token refresh runs through a `wp_cache_add` single-flight mutex.
- Amounts crossing the wire go through `Money::toApiDecimal` + `number_format(…, 2, '.', '')`. Never `(string) $float`.
- Partial pre-auth captures record the un-captured remainder via `wc_create_refund([…, refund_payment => false])` so the WC ledger matches the bank.

---

## Build / release pipeline

- `release.yml` runs in GitHub Actions on a tagged push only. Never publish from a local machine.
- The workflow does not interpolate PR / issue / commit text into shell commands. Inputs that come from outside the repo (tag name only) are passed via `env:` and quoted.
- Composer `--no-dev` is mandatory for plugin release ZIPs. The framework itself is a library, distributed through Packagist (private) — the consumer's release pipeline strips dev deps.

---

## What goes in this repo

Source, docs, CSS, JS, workflows. **Never** commit:

- Test fixtures with real license keys, real bank API credentials, or real customer data.
- `.env`, `wp-config.php` snippets, or `auth.json` / Composer auth tokens.
- Pre-built ZIPs.
- Any file containing `__CODEON_BUILD_ID__` other than the documented scatter sites in plugin repos.
