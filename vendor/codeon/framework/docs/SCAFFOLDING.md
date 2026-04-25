# Scaffolding a New CodeOn Plugin

Step-by-step recipe to start a new plugin built on the framework from day one. Takes ~30 minutes if you have your bank/API credentials ready.

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

## 2. composer.json

```json
{
    "name": "codeon/<your-plugin-slug>",
    "type": "wordpress-plugin",
    "license": "proprietary",
    "require": {
        "php": ">=8.1",
        "codeon/framework": "^0.1"
    },
    "autoload": {
        "psr-4": { "CodeOn\\YourPlugin\\": "includes/" }
    },
    "config": { "sort-packages": true }
}
```

Run:

```bash
composer install
```

---

## 3. Main plugin file

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

require __DIR__ . '/vendor/autoload.php';

add_action('woocommerce_loaded', static function (): void {
    \CodeOn\YourPlugin\Plugin::instance()->boot();
}, 20);
```

---

## 4. Boot file

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

## 5. First tab

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

## 6. Watermark scatter sites

See [`docs/WATERMARK.md`](WATERMARK.md) for the full pre-release checklist. In short:

1. `define('CODEON_BUILD_ID', '__CODEON_BUILD_ID__');` in the main plugin file.
2. `private const BUILD_FINGERPRINT = '__CODEON_BUILD_ID__';` on `LicenseGate`.
3. `/* codeon.ge build __CODEON_BUILD_ID__ */` at the top of one admin JS file you ship.

`FrameworkBuildStamp::verify()` checks all three match before the framework boots normally.

---

## 7. Smoke test before first release

- `Plugins → Add New → Upload`, install your built ZIP, activate.
- Open the admin page → standard chrome renders, tab nav works, footer shows build identifier.
- Save the General tab with no values → no PHP notices, no fatal.
- License tab → activate with a real codeon.ge key → status pill flips to OK.
- Remove `CODEON_BUILD_ID` from the main file → reload admin → recovery mode notice appears, all business-logic tabs disappear.

If all five pass, tag `v0.1.0` and let the release workflow build your ZIP.
