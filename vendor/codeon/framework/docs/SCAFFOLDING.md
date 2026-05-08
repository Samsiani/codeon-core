# Scaffolding a New CodeOn Plugin

Step-by-step recipe to start a new plugin built on the framework from day one. Takes ~30 minutes if you have your bank/API credentials ready.

> **AI agents — read [`/CLAUDE.md`](../CLAUDE.md) first.** Every session that touches plugin code starts by invoking the `andrej-karpathy-skills:karpathy-guidelines` skill, and the plugin's `assets/icon/` artwork follows [`docs/ICON_SYSTEM.md`](ICON_SYSTEM.md) — both rules are enforced repo-wide.

---

## 0. Prerequisites

- PHP 8.1+ (the framework's floor; codeon-payments was bumped from 7.4 in its v0.3.0).
- Composer 2.x.
- WordPress 6.2+ and WooCommerce 8.0+ on a local dev environment.
- A registered codeon.ge plugin slug (DM the plugin maintainer to reserve one).

---

## 1. Create the repo

```bash
gh repo create Samsiani/<your-plugin-slug> --private --clone
cd <your-plugin-slug>
```

Folder structure to mirror the existing plugins:

```
your-plugin-slug.php          # bootstrap, defines + plugins_loaded hook
composer.json                 # require codeon/framework
includes/                     # plugin source under your namespace
  Plugin.php                  # singleton, calls Bootstrap::register
  Admin/
    GeneralTab.php            # one Tab subclass per tab
    FrameworkLicenseAdapter.php
  License/
    LicenseGate.php           # your plugin's existing license stack
    LicenseStore.php
    LicenseClient.php
  Watermark/
    FrameworkBuildStamp.php
assets/
  icon/icon-128x128.png       # 128 / 256 / 512 + banners
.github/workflows/release.yml # tag → build ZIP → GitHub Release
readme.txt                    # WP-format readme for the install screen
```

---

## 2. ⚠️ Mandatory: Jetpack Autoloader

> **Read this before writing any composer.json. It is not optional.**

CodeOn plugins are independent ZIPs that merchants install side-by-side. Without coordination, two CodeOn plugins that each ship `vendor/codeon/framework/` will fight at load time: whichever plugin loads first wins, and any subsequent plugin that requires a newer minor version of the framework gets the OLDER classes loaded under the same FQCN. This is "Dependency Hell" for WordPress plugins, and Composer's stock `vendor/autoload.php` cannot solve it (it only knows about its own plugin's `vendor/`).

Every CodeOn plugin **MUST** use [`automattic/jetpack-autoloader`](https://packagist.org/packages/automattic/jetpack-autoloader). It registers each plugin's classes under a versioned key in a shared global registry; when multiple plugins ship the same package, the highest-versioned copy wins for every consumer, regardless of plugin load order.

### Three things you MUST do

1. **Add it to composer.json**:

    ```bash
    composer require automattic/jetpack-autoloader
    ```

2. **Configure non-authoritative classmaps** in `composer.json` so the autoloader resolves classes dynamically rather than baking a frozen list at install time:

    ```json
    "extra": {
        "jetpack-autoloader": {
            "classmap-authoritative": false
        }
    }
    ```

    Authoritative classmaps would fail any class added after `composer install` ran — fine on a build machine, broken on merchant sites that pull the ZIP and never run Composer themselves.

3. **Require the Jetpack autoloader bootstrap** in the main plugin file, **NOT** Composer's default:

    ```php
    // CORRECT — Jetpack autoloader, version-aware across co-installed plugins
    require __DIR__ . '/vendor/autoload_packages.php';
    ```

    NOT:

    ```php
    // WRONG — clobbers any other CodeOn plugin's framework copy
    require __DIR__ . '/vendor/autoload.php';
    ```

The full composer.json + main file examples in §3 and §4 below already reflect this. Don't strip it.

---

## 3. composer.json

```json
{
    "name": "codeon/<your-plugin-slug>",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1",
        "codeon/framework": "^0.3",
        "automattic/jetpack-autoloader": "^5.0"
    },
    "autoload": {
        "psr-4": { "CodeOn\\YourPlugin\\": "includes/" }
    },
    "config": { "sort-packages": true },
    "extra": {
        "jetpack-autoloader": {
            "classmap-authoritative": false
        }
    }
}
```

Run:

```bash
composer install
```

After the install, verify the Jetpack bootstrap exists:

```bash
ls vendor/autoload_packages.php   # must exist; this is what your main file requires
```

If that file is missing the autoloader didn't install — re-check the `automattic/jetpack-autoloader` require line and the `extra` block.

---

## 4. Main plugin file

`your-plugin-slug.php`:

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

define('YOUR_PLUGIN_VERSION', '0.1.0');
define('YOUR_PLUGIN_FILE', __FILE__);
define('YOUR_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Watermark scatter site #1 of 3 — codeon.ge replaces at delivery time.
define('CODEON_BUILD_ID', '__CODEON_BUILD_ID__');

// Jetpack autoloader — version-aware across co-installed CodeOn plugins.
// See §2 of this guide. Do NOT swap this for `vendor/autoload.php`.
require __DIR__ . '/vendor/autoload_packages.php';

add_action('woocommerce_loaded', static function (): void {
    \CodeOn\YourPlugin\Plugin::instance()->boot();
}, 20);
```

---

## 5. Boot file

`includes/Plugin.php`:

```php
<?php
declare(strict_types=1);
namespace CodeOn\YourPlugin;

use CodeOn\Framework\Plugin\Bootstrap;
use CodeOn\Framework\Plugin\Manifest;
use CodeOn\Framework\Admin\LicenseTab;
use CodeOn\Framework\Storage\FlatOptionRepository;
use CodeOn\YourPlugin\Admin\GeneralTab;
use CodeOn\YourPlugin\Admin\FrameworkLicenseAdapter;
use CodeOn\YourPlugin\Watermark\FrameworkBuildStamp;

final class Plugin
{
    private static ?self $instance = null;
    public static function instance(): self { return self::$instance ??= new self(); }
    public function boot(): void
    {
        $manifest = (new Manifest('your-plugin-slug', __('Your Plugin', 'your-plugin-slug')))
            ->version(YOUR_PLUGIN_VERSION)
            ->dashicon('dashicons-admin-generic')
            ->capability('manage_options');

        $repo = new FlatOptionRepository('your_plugin_settings');
        $licenseAdapter = new FrameworkLicenseAdapter();

        Bootstrap::register(
            $manifest,
            [ new GeneralTab($repo), new LicenseTab($licenseAdapter) ],
            new FrameworkBuildStamp()
        );
    }
}
```

---

## 6. First tab

`includes/Admin/GeneralTab.php`:

```php
<?php
declare(strict_types=1);
namespace CodeOn\YourPlugin\Admin;

use CodeOn\Framework\Admin\Tab;
use CodeOn\Framework\Schema\Field;
use CodeOn\Framework\Storage\SettingsRepository;

final class GeneralTab extends Tab
{
    public function __construct(private readonly SettingsRepository $repo) {}
    public function slug(): string  { return 'general'; }
    public function label(): string { return __('General', 'your-plugin-slug'); }
    public function repository(): SettingsRepository { return $this->repo; }
    public function schema(): array
    {
        return [
            Field::heading('connection_h', __('Connection', 'your-plugin-slug')),
            Field::text('api_base', __('API base URL', 'your-plugin-slug'))->wide(),
            Field::password('api_key', __('API key', 'your-plugin-slug')),
            Field::checkbox('debug', __('Debug logging', 'your-plugin-slug')),
        ];
    }
}
```

That's it for a minimum viable plugin shell. From here the [`docs/FIELD_SCHEMA.md`](FIELD_SCHEMA.md) and [`docs/LICENSE_INTEGRATION.md`](LICENSE_INTEGRATION.md) carry you the rest of the way.

---

## 7. Watermark scatter sites

See [`docs/WATERMARK.md`](WATERMARK.md) for the full pre-release checklist. In short:

1. `define('CODEON_BUILD_ID', '__CODEON_BUILD_ID__');` in the main plugin file.
2. `private const BUILD_FINGERPRINT = '__CODEON_BUILD_ID__';` on `LicenseGate`.
3. `/* codeon.ge build __CODEON_BUILD_ID__ */` at the top of one admin JS file you ship.

`FrameworkBuildStamp::verify()` checks all three match before the framework boots normally.

---

## 8. Smoke test before first release

- `Plugins → Add New → Upload`, install your built ZIP, activate.
- Open the admin page → standard chrome renders, tab nav works, footer shows build identifier.
- Save the General tab with no values → no PHP notices, no fatal.
- License tab → activate with a real codeon.ge key → status pill flips to OK.
- Remove `CODEON_BUILD_ID` from the main file → reload admin → recovery mode notice appears, all business-logic tabs disappear.

If all five pass, tag `v0.1.0` and let the release workflow build your ZIP.
