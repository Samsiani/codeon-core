# Watermark + Recovery Mode

Every CodeOn ZIP delivered through codeon.ge is rewritten in-flight to embed a per-license build identifier (a UUID from the `download_audits` table). The plugin verifies that identifier against itself at boot — if any of the scatter sites disagree (because someone repacked the ZIP), the plugin falls into **recovery mode**: admin chrome and License tab still render so a merchant can replace the install, but every business-logic subsystem (sync, gateways, schedulers, REST mutators, CLI) stays off.

The framework supplies the contract; the **plugin** owns the scatter sites and the verifier. No `__CODEON_BUILD_ID__` placeholders ever appear in the framework source itself.

The reference implementation is **fina-sync** — the framework's `BuildStampContract` was lifted directly from `Plugin::isBuildStampValid()` + `LicenseGate::buildFingerprint()` in that repo.

---

## The three scatter sites

A pirate stripping one obvious seed should still leave two traceable fingerprints. Three is the baseline; more is fine but rapidly hits diminishing returns.

### #1 — Main plugin file

```php
/*
 * Build watermark (primary site).
 * codeon.ge replaces this placeholder with a UUID at delivery time.
 * A real install always sees a UUID here; dev checkouts see the placeholder.
 * Never edit the placeholder string. Never remove this define.
 */
define('CODEON_BUILD_ID', '__CODEON_BUILD_ID__');
```

### #2 — A core class that always boots

Canonically the `LicenseGate`:

```php
final class LicenseGate
{
    private const BUILD_FINGERPRINT = '__CODEON_BUILD_ID__';

    public static function buildFingerprint(): string
    {
        return self::BUILD_FINGERPRINT;
    }

    // ... rest of the gate
}
```

### #3 — One frontend / admin JS file

```js
/* codeon.ge build __CODEON_BUILD_ID__ — do not edit */
(function () { ... })();
```

Pick a JS file that always ships and that `wp_enqueue_script`s at every page load (your admin chrome JS, or your storefront tracking pixel). The placeholder is parsed by the codeon.ge ZIP rewriter at delivery time — match it character-for-character (35 chars, including the underscores).

---

## Build-stamp verifier

The plugin implements `CodeOn\Framework\Watermark\BuildStampContract`:

```php
namespace CodeOn\YourPlugin\Watermark;

use CodeOn\Framework\Watermark\BuildStampContract;
use CodeOn\YourPlugin\License\LicenseGate;

final class FrameworkBuildStamp implements BuildStampContract
{
    public function verify(): bool
    {
        if (!defined('CODEON_BUILD_ID')) return false;
        $id = (string) CODEON_BUILD_ID;
        if ($id === '' || $id === '__CODEON_BUILD_ID__') return false;
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return false;
        }
        // Constant-time compare; never == or === here.
        if (!hash_equals($id, LicenseGate::buildFingerprint())) return false;

        return true;
    }

    public function fingerprint(): string
    {
        return $this->verify() ? (string) CODEON_BUILD_ID : '';
    }
}
```

Hand it to the framework:

```php
Bootstrap::register($manifest, $tabs, new FrameworkBuildStamp());
```

---

## Recovery-mode contract

When `verify()` returns false, the framework:

- **Keeps**: admin menu, page chrome, License tab, asset enqueue, recovery-mode admin notice.
- **Drops**: every business-logic tab (the framework filters them out from the registered Tab[] array).

What the framework **cannot** drop is the rest of your plugin's bootstrap — sync schedulers, payment gateway registrations, REST mutators, CLI commands. **The host plugin is responsible for skipping those itself.** The pattern:

```php
$result = Bootstrap::register($manifest, $tabs, new FrameworkBuildStamp());

if ($result['recovery']) {
    return; // skip everything below
}

(new Sync\Scheduler())->register();
(new Sync\JobRunner())->register();
add_action('rest_api_init', fn () => (new REST\Routes())->register());
\WP_CLI::add_command('your-plugin', Commands::class);
```

The recovery mode notice clearly tells the merchant their store is unaffected and points them at codeon.ge for a fresh install.

---

## Pre-release checklist

Before tagging a release, run this checklist by hand (or wire it into your `release.yml`):

```bash
# Each scatter site exists exactly once with the placeholder intact.
grep -rn '__CODEON_BUILD_ID__' .

# Expected: 3 hits.
# - one in your-plugin.php (the define)
# - one in includes/License/LicenseGate.php (the const)
# - one in assets/js/admin.js (the comment)
```

If you see 0 hits, your scatter sites have been replaced (probably by a previous release script that didn't restore them). If you see >3 hits, an extra placeholder leaked in — the codeon.ge rewriter only replaces the first three matches, so any extra would never be fingerprinted and would always fail verification.

Verify locally that recovery mode works before each release:

1. Build and install your ZIP.
2. Edit `wp-content/plugins/your-plugin/your-plugin.php` and change `CODEON_BUILD_ID` to a different UUID.
3. Reload an admin page — recovery notice should appear; sync/payment subsystems should NOT register.
4. Restore `CODEON_BUILD_ID` to its original value — admin returns to normal.
