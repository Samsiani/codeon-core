# Hub Architecture (v0.2+)

CodeOn plugins are micro-plugins by design: every payment method,
every sync integration, and every shipping method ships as its own
ZIP, gets its own license key, and stays under 30 KB of plugin-
specific code by leaning on this framework. The hub architecture is
how multiple co-installed micro-plugins present **one cohesive
admin experience** instead of cluttering the merchant's WordPress
sidebar with N separate top-level menus.

This document covers:

1. [The hub menu — one top-level for many plugins](#1-the-hub-menu)
2. [Catalog endpoint + Extensions tab](#2-catalog-endpoint--extensions-tab)
3. [1-click installer (license-key flow)](#3-1-click-installer)
4. [Shared services every micro-plugin can lean on](#4-shared-services)
5. [Migration from a v0.1 plugin](#5-migration-from-v01)
6. [Free CodeOn Core override points](#6-codeon-core-override-points)

---

## 1. The hub menu

### Opt-in via the manifest

```php
$manifest = (new Manifest('fina-sync', __('Fina Sync', 'fina-sync')))
    ->version(FINA_SYNC_VERSION)
    ->capability('manage_options')
    ->dashicon('dashicons-update')
    ->hub(true)                      // ← join the shared hub
    ->hubLabel('Fina Sync')          // ← submenu label (defaults to menuTitle)
    ->support('https://codeon.ge/plugins/synchronization/fina');

Bootstrap::register($manifest, $tabs, $buildStamp);
```

When `->hub(true)` is set the plugin's tab page lands as a
submenu under the shared `toplevel_page_codeon` slug. Every other
hub-mode CodeOn plugin lands as a sibling submenu of the same
parent — no matter how many install on the same site.

### Graceful degradation

The free CodeOn Core plugin (when it ships) owns the hub's
identity (icon, label, position, dashboard landing). When Core is
absent the framework's defaults take over: label "CodeOn",
`dashicons-admin-generic`, position 58, and the parent page
redirects to the first installed plugin's submenu. Premium plugins
work exactly the same way before and after Core is installed.

### Legacy escape hatch

Plugins that want to keep their own top-level menu pass
`->hub(false)` (the framework default for back-compat). Useful for
old plugins migrating onto the framework gradually — they can
upgrade to v0.2 without changing menu UX.

### Filter hook

```php
add_filter('codeon/hub/toplevel_config', function ($config, $group) {
    if ($group !== 'codeon') {
        return $config;
    }
    return [
        'label'             => 'CodeOn',
        'icon'              => 'dashicons-superhero',
        'position'          => 58,
        'capability'        => 'manage_options',
        'dashboardCallback' => [\CodeOn\Core\Dashboard::class, 'render'],
    ];
}, 10, 2);
```

The Core plugin uses this to take over the hub's identity. It's a
plain `apply_filters` so any plugin can override (last-writer
wins; document priorities at your own peril).

---

## 2. Catalog endpoint + Extensions tab

### `GET https://codeon.ge/api/v1/catalog`

Public, edge-cached for 5 minutes. Returns the complete plugin
catalog grouped by `pluginSlug` so each entry maps to one
installable ZIP, with one or more purchasable SKUs per plugin.

```jsonc
{
  "version": "1",
  "fetchedAt": "2026-04-25T12:00:00Z",
  "categories": [{ "id": "payments", "label": "Payments", "iconKey": "card" }, …],
  "plugins": [
    {
      "pluginSlug": "fina-sync",
      "pluginId": "fina-sync",
      "name": "Fina ↔ WooCommerce Sync",
      "tagline": "Push Fina price + stock straight into WooCommerce.",
      "category": "synchronization",
      "iconKey": "cog",
      "currentVersion": "3.1.3",
      "popular": false,
      "requirements": { "php": "8.1", "wp": "6.4", "wc": "8.3" },
      "products": [
        { "id": "fina-sync", "name": "Fina Sync", "priceTetri": 25000, "currency": "GEL" }
      ]
    }
  ]
}
```

Today's payment plugin (`codeon-georgian-payments`) groups all four
SKUs under one `pluginSlug` because the four methods still ship in
one ZIP. When the methods later split into separate plugins each
gets its own pluginSlug and the response shape stays identical.

### `CodeOn\Framework\Extensions\CatalogClient`

Cached HTTP client. Calls the catalog endpoint with
`?wp_version=&php_version=&wc_version=&site_url=` so the catalog
can later hide plugins the host doesn't satisfy. Caches in a
6-hour transient. Falls back to last-known data on HTTP failure.

```php
$catalog = (new CatalogClient())->fetch();
$catalog = (new CatalogClient())->fetch(force: true);   // bust cache
```

### `CodeOn\Framework\Extensions\ExtensionsTab`

Single shared tab class. The framework auto-registers it as a
submenu under the hub (`/wp-admin/admin.php?page=codeon-extensions`)
unless suppressed via the
`codeon/hub/default_extensions_enabled` filter. Renders three
buckets — installed & active, installed & inactive, locked
(catalog-only) — with one card per plugin.

When CodeOn Core ships, Core registers its own ExtensionsTab
subclass with richer marketing copy, suppresses the framework
default, and inherits all the catalog plumbing.

---

## 3. 1-click installer

The locked-card "Unlock" button opens a modal that asks for the
plugin's license key and POSTs it to the framework's AJAX handler
(`InstallController::handle()`). The flow:

1. **Nonce + capability** — `current_user_can('install_plugins')` and
   `current_user_can('activate_plugins')` both required.
2. **Validate license** — POSTs to codeon.ge `/api/v1/validate-license`
   (signed RSA-SHA256 response verified against the pinned public
   key). Status must be `active` or `grace`.
3. **Entitlement** — the licensed module must be in the catalog
   plugin's `products[]` SKU list (handles both hyphen and underscore
   wire spellings).
4. **Update manifest** — GET `/api/v1/updates/<plugin_id>` returns
   the canonical license-gated download URL.
5. **Plugin_Upgrader** — runs `WP_Filesystem` + `Plugin_Upgrader::install()`
   (no FTP prompts on direct-write hosts). Watermarked ZIP unpacks
   into `wp-content/plugins/<plugin_slug>/`.
6. **Activate** — `activate_plugin($plugin_file)` if not already
   active.
7. **Persist key** — writes `<plugin_slug>_license_key`. The
   freshly-activated plugin's own LicenseStore reads this option on
   first boot.
8. **Redirect** — JS sends the merchant to the new plugin's submenu
   URL so they land on the configuration screen they need.

### Failure modes (all surfaced as JSON errors to the modal)

| code | when |
|---|---|
| `bad_nonce` / `forbidden` | Nonce or capability check failed |
| `unknown_plugin` | Plugin id no longer in the catalog |
| `license_rejected` | validate-license returned non-2xx, signature failed, etc. |
| `license_inactive` | License is suspended / expired beyond grace |
| `plugin_not_in_license` | Module not in catalog plugin's gate |
| `manifest_failed` | Update manifest couldn't be fetched |
| `install_failed` | Plugin_Upgrader returned WP_Error |
| `install_unverifiable` | Install completed but the plugin file couldn't be located |
| `activation_failed` | Activate hook errored |

---

## 4. Shared services

Every CodeOn micro-plugin can lean on this once-and-for-all
infrastructure:

| Class | Responsibility |
|---|---|
| `CodeOn\Framework\License\LicenseClient` | RSA-signed validate-license + release-domain calls |
| `CodeOn\Framework\License\LicenseStore` | Per-plugin transient-cached snapshot + grace logic |
| `CodeOn\Framework\License\PublicKey` | Resolves the production key from `CODEON_LICENSE_PUBLIC_KEY` constant or filter |
| `CodeOn\Framework\License\TamperHeartbeat` | Daily WP-Cron POST to `/api/v1/tamper-report` when watermark fails |
| `CodeOn\Framework\Updates\UpdateChecker` | `site_transient_update_plugins` injection + `plugins_api` modal |
| `CodeOn\Framework\Logging\Logger` | WC-aware logger with optional redaction |
| `CodeOn\Framework\WooCommerce\AbstractGateway` | Lazy-loaded WC gateway base — license gate + form fields |
| `CodeOn\Framework\WooCommerce\AbstractShippingMethod` | Same idea for `WC_Shipping_Method` |
| `CodeOn\Framework\WooCommerce\GatewayRegistrar` | One-liner for `woocommerce_payment_gateways` + Blocks |

WC base classes are gated behind `class_exists('WC_Payment_Gateway')`
so the framework stays WC-agnostic at the package level — non-WC
plugins (1C sync, Fina sync) never touch them.

### Per-plugin BUILD_ID rule

Each plugin defines its own watermark constant, e.g.
`FINA_SYNC_BUILD_ID`, `QUICKSHIPPER_DELIVERY_BUILD_ID`. Sharing a
single global `CODEON_BUILD_ID` between co-installed plugins is a
known footgun — the alphabetically-first plugin wins the `define()`
race and every other plugin's recovery-mode check fails. The
framework's `LicenseClient`, `UpdateChecker`, and `TamperHeartbeat`
all take the constant name as a constructor arg.

---

## 5. Migration from v0.1

A typical v0.1 plugin had a single top-level menu and copies of
LicenseClient, Logger, UpdateChecker, and TamperHeartbeat checked
into its own `includes/` tree. To migrate to v0.2:

1. **Manifest**: add `->hub(true)`. That's the only line your
   menu UX needs.
2. **License**: replace your handwritten `LicenseClient` /
   `LicenseStore` / cache logic with the framework versions.
   Your existing `LicenseAdapter` typically wraps a
   `framework LicenseStore` and just needs its constructor
   signature updated.
3. **Updates**: instantiate `UpdateChecker` with your plugin
   identifiers. Delete your local copy.
4. **Tamper**: instantiate `TamperHeartbeat` once per plugin in
   the boot path. Delete your local copy.
5. **Logger**: search-replace `YourNamespace\Logging\Logger` →
   `CodeOn\Framework\Logging\Logger`. The constructor stays
   compatible.
6. **WC gateways** (payment plugins): extend `AbstractGateway`
   instead of `WC_Payment_Gateway` directly. The shared form
   fields move to the base class so each gateway keeps only the
   bank-specific bits.

A reference migration of `fina-sync` and `quickshipper-delivery`
ships in their respective repos in subsequent v3.2 / v0.4 releases.

---

## 6. CodeOn Core override points

The future free `codeon-core` plugin claims authority over the hub
without changes to the framework or to any premium plugin:

- `codeon/hub/toplevel_config` — sets the menu icon, label,
  position, capability, and dashboard landing callback.
- `codeon/hub/default_extensions_enabled` — return false to suppress
  the framework's stub Extensions submenu and replace it with Core's
  own (which can include a Dashboard tab, license-management tab,
  and richer install UX).
- `codeon/license/public_key` — return a PEM string to override the
  vendored public key without an environment constant.
- `codeon/admin/enqueue` — fires on every codeon admin page so Core
  can layer in its own assets without touching the framework.

The framework never special-cases Core. It stays a pure library
that ships sane defaults and exposes filters; Core is just a
particularly opinionated consumer.
