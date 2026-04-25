# CodeOn Plugin Framework

Shared admin UI + chrome framework for [codeon.ge](https://codeon.ge) WordPress / WooCommerce plugins. One header, one tab system, one License & Updates tab, one design language across every CodeOn product.

> **Private** — vendor only into CodeOn-owned plugins distributed through codeon.ge. Do not redistribute.

---

## What it gives you

- **Standard chrome** — header (logo + name + version + global status pill), tab navigation with health dots, content area, footer with build watermark.
- **Field schema DSL** — declare an array of `Field` builders, the framework renders the form, validates the POST, and persists through whichever storage adapter you wired in.
- **Storage adapters** — `FlatOptionRepository` ships in v0.1; `NestedDotPathRepository`, `WCGatewayRepository`, `SplitOptionRepository` follow as the larger plugins migrate.
- **License & Updates tab** — concrete framework class. Plug in a `LicenseAdapter` over your existing `License`/`LicenseGate`/`LicenseStore` and the standard UI just works. No license business logic ever touches the framework.
- **Watermark + recovery mode** — `BuildStampContract` interface. When `verify()` returns false, the framework drops every business-logic tab and renders only the License tab + a recovery-mode notice. Your store keeps serving traffic.
- **Backwards-compat router** — `AdminPostRouter::aliasLegacy()` re-routes existing `admin_post_*` URLs into the framework's save flow without breaking merchant bookmarks.

## Install

```bash
composer require codeon/framework:^0.1
composer require automattic/jetpack-autoloader
```

> **Mandatory: use the Jetpack autoloader, not Composer's default.** Multiple CodeOn plugins commonly run side-by-side on the same WordPress install, and each ships its own `vendor/codeon/framework/`. Composer's `vendor/autoload.php` has no concept of "another plugin already loaded a different copy of the same package", so whichever plugin loads first wins and the others get the wrong version under the same FQCN.
>
> `automattic/jetpack-autoloader` solves this by registering classes under a versioned key in a shared global registry — the highest-versioned copy of each package wins for every consumer regardless of plugin load order. Add the package, set `"extra": { "jetpack-autoloader": { "classmap-authoritative": false } }` in your `composer.json`, and `require __DIR__ . '/vendor/autoload_packages.php';` in the main plugin file (NOT `vendor/autoload.php`). See [`docs/SCAFFOLDING.md` §2](docs/SCAFFOLDING.md) for the full recipe.

This package ships PHP source + CSS/JS under `assets/`. Your plugin's release script must keep `vendor/codeon/framework/assets/` inside the production ZIP — the framework's `Assets` class resolves URLs relative to its own location at runtime.

## Quickstart

```php
use CodeOn\Framework\Plugin\Bootstrap;
use CodeOn\Framework\Plugin\Manifest;
use CodeOn\Framework\Admin\LicenseTab;
use CodeOn\Framework\Storage\FlatOptionRepository;

add_action('plugins_loaded', function (): void {
    $manifest = (new Manifest('my-plugin', __('My Plugin', 'my-plugin')))
        ->version('1.2.3')
        ->dashicon('dashicons-admin-generic')
        ->capability('manage_options')
        ->support('https://codeon.ge/support');

    $repo = new FlatOptionRepository('my_plugin_settings');
    $licenseAdapter = new MyPlugin\Admin\FrameworkLicenseAdapter(/* … */);

    Bootstrap::register(
        $manifest,
        [
            new MyPlugin\Admin\GeneralTab($repo),
            new LicenseTab($licenseAdapter),
        ],
        new MyPlugin\Watermark\FrameworkBuildStamp()
    );
});
```

A `Tab` subclass that uses the schema DSL is ~30 lines — see `docs/FIELD_SCHEMA.md`.

## Docs

| File | What's in it |
|---|---|
| [`docs/UI_GUIDELINES.md`](docs/UI_GUIDELINES.md) | Design tokens, layout rules, when deviation is allowed |
| [`docs/SCAFFOLDING.md`](docs/SCAFFOLDING.md) | Start a new CodeOn plugin from scratch |
| [`docs/FIELD_SCHEMA.md`](docs/FIELD_SCHEMA.md) | Every `FieldType`, every modifier, examples |
| [`docs/STORAGE_ADAPTERS.md`](docs/STORAGE_ADAPTERS.md) | Pick the right adapter; write your own |
| [`docs/LICENSE_INTEGRATION.md`](docs/LICENSE_INTEGRATION.md) | Wire your existing License code into the framework's License tab |
| [`docs/WATERMARK.md`](docs/WATERMARK.md) | Scatter sites, verification, recovery-mode contract |
| [`docs/MIGRATION_PLAYBOOK.md`](docs/MIGRATION_PLAYBOOK.md) | Recipe for porting an existing plugin |
| [`SECURITY.md`](SECURITY.md) | Security posture; vulnerability disclosure |
| [`CHANGELOG.md`](CHANGELOG.md) | Strict Keep-a-Changelog + semver |

## Versioning

Strict [SemVer](https://semver.org/). Plugins should pin to a minor range (`"^0.2"`), never to `dev-main`.

- MAJOR — breaking change to `Field`, `SettingsRepository`, `LicenseAdapter`, or `Bootstrap::register()`.
- MINOR — new field type, new adapter, new framework feature.
- PATCH — bugfix, documentation, internal refactor.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Copyright © 2026 CodeOn.

Re-licensed from proprietary in v0.3.3 to enable bundling inside `codeon-core` (the free WordPress.org plugin). The framework is now safe to ship inside any GPL-compatible WordPress plugin.
