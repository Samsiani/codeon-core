# License Integration

The framework ships a concrete `LicenseTab` that draws the standard CodeOn License & Updates UI — status pill, key field, activate / refresh / release buttons, plan/feature list, last-error meta. **No license business logic ever lives in the framework.**

To plug your existing License code in, you write one ~30-line adapter implementing `CodeOn\Framework\License\LicenseAdapter`.

---

## Why an adapter (not a base class)

Each plugin's License stack already does its own thing — RSA-signed responses (codeon-payments), Action-Scheduler-driven cron refresh (fina-sync), domain-binding release (qsd). The right answer is to keep all of that intact and write a thin presenter that translates between your `License`/`LicenseGate`/`LicenseStore` and the framework's display contract.

> **Hard rule**: The adapter has no decisions of its own. If your adapter has a regex or a date math expression, you're putting business logic in the wrong layer — push it back into `License` / `LicenseGate`.

---

## The contract

```php
interface LicenseAdapter
{
    public function status(): string;     // one of STATUS_ACTIVE|GRACE|EXPIRED|INACTIVE
    public function snapshot(): array;    // display-safe metadata
    public function activate(string $key): array;   // ['ok' => bool, 'message' => string]
    public function release(): array;
    public function refresh(): array;
    public function features(): array;    // ['Card payments', 'Refunds', ...]
}
```

`snapshot()` recommended keys (all optional except `key_masked`):

| Key | Type | Use |
|---|---|---|
| `key_masked` | string | `XXXX-XXXX-1234` — never the full key |
| `plan` | string | License plan name |
| `expires_at` | int | Unix timestamp; 0 = perpetual |
| `last_check` | int | Unix timestamp of last successful validate |
| `bound_domain` | string | Domain the license is bound to |
| `last_error` | string | Human-readable last failure ('' if none) |

---

## The "Plan includes" list

`features(): array<int,string>` returns the human labels rendered under
"Plan includes" on the License tab. **Use the framework's `KnownPlugins`
registry** so multi-plugin bundles render consistent labels regardless
of which plugin's License tab the merchant happens to be viewing:

```php
use CodeOn\Framework\License\KnownPlugins;

public function features(): array
{
    $modules = $this->yourLicenseStore->modules(); // e.g. ['fina-sync', 'codeon-payments']
    return array_values(array_filter(array_map(
        static fn($slug) => is_string($slug) ? KnownPlugins::label($slug) : null,
        $modules
    )));
}
```

`KnownPlugins::map()` ships with every publicly listed CodeOn plugin
slug → label. Add a new plugin label without waiting for a framework
release by hooking the `codeon/framework/known_plugins` filter:

```php
add_filter('codeon/framework/known_plugins', static function (array $map): array {
    $map['my-private-plugin'] = __('My Private Plugin', 'my-plugin');
    return $map;
});
```

Unknown slugs fall back to a Title-Cased version of the slug, so a
brand-new plugin doesn't crash the License tab.

## License tab UX rules

The framework's `LicenseTab` follows these rules — adapters don't need
to do anything special, but knowing the rules helps you debug what the
merchant sees:

- **No internal "License status" heading.** The chrome header band
  already shows the status pill ("Active", "Grace period", …).
  Duplicating it inside the section is noise.
- **Key input hidden when the licence is active.** Swapping keys is a
  deliberate two-step: Release → input appears → re-Activate. This
  stops accidental overwrites of a working licence with a typo.
- **Action buttons inline with the meta dl.** Refresh + Release sit
  directly under the status meta when active; Activate joins them when
  not. No second `<section>` for the form.
- **Release button has a confirm guard** (`data-codeon-confirm`) so a
  misclick can't strand a working license.

## Reference: fina-sync adapter

```php
namespace CodeOn\FinaSync\Admin;

use CodeOn\Framework\License\LicenseAdapter;
use CodeOn\FinaSync\License\LicenseClient;
use CodeOn\FinaSync\License\LicenseGate;
use CodeOn\FinaSync\License\LicenseStore;

final class FrameworkLicenseAdapter implements LicenseAdapter
{
    public function status(): string
    {
        return match (LicenseGate::status()) {
            'active'   => self::STATUS_ACTIVE,
            'grace'    => self::STATUS_GRACE,
            'expired'  => self::STATUS_EXPIRED,
            default    => self::STATUS_INACTIVE,
        };
    }

    public function snapshot(): array
    {
        $row = LicenseStore::get() ?? [];
        return [
            'key_masked'   => $this->mask($row['key'] ?? ''),
            'plan'         => $row['response']['plan']['name'] ?? '',
            'expires_at'   => (int) ($row['response']['expires_at'] ?? 0),
            'last_check'   => (int) get_option('fina_sync_license_last_check', 0),
            'bound_domain' => $row['response']['domain'] ?? home_url(),
            'last_error'   => (string) ($row['last_error'] ?? ''),
        ];
    }

    public function activate(string $key): array
    {
        $r = (new LicenseClient())->validate($key, home_url());
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['error'] ?? __('Activation failed.', 'fina-sync')];
        }
        LicenseStore::applyResponse($key, $r['response']);
        return ['ok' => true, 'message' => __('License activated.', 'fina-sync')];
    }

    public function release(): array
    {
        $r = (new LicenseClient())->release(LicenseStore::get()['key'] ?? '', home_url());
        LicenseStore::clear();
        return ['ok' => $r['ok'], 'message' => $r['ok']
            ? __('Domain binding released.', 'fina-sync')
            : ($r['error'] ?? __('Release failed.', 'fina-sync'))];
    }

    public function refresh(): array
    {
        $stored = LicenseStore::get();
        if (!$stored || !($stored['key'] ?? '')) {
            return ['ok' => false, 'message' => __('No license to refresh.', 'fina-sync')];
        }
        return $this->activate($stored['key']);
    }

    public function features(): array
    {
        $row = LicenseStore::get() ?? [];
        return $row['response']['features'] ?? [];
    }

    private function mask(string $key): string
    {
        if ($key === '') return '';
        $tail = substr($key, -4);
        return str_repeat('X', max(0, strlen($key) - 4)) . $tail;
    }
}
```

---

## Wiring it up

```php
use CodeOn\Framework\Admin\LicenseTab;

Bootstrap::register(
    $manifest,
    [
        new YourPlugin\Admin\GeneralTab($repo),
        new LicenseTab(new YourPlugin\Admin\FrameworkLicenseAdapter()),
    ],
    new YourPlugin\Watermark\FrameworkBuildStamp()
);
```

That's it. The framework's `LicenseTab`:
- Displays the status pill.
- Renders the key input + Activate / Refresh / Release buttons.
- POSTs each button through `admin_post_codeon_tab_action`.
- Calls the matching adapter method.
- Flashes the result message via `Notices::add()`.

If you also need to expose activate/release over REST (fina-sync does, for its custom dashboards), do it in the plugin — the framework only handles the admin POST surface. The same adapter methods can serve both delivery paths.

---

## Don't

- **Don't** stash the license key inside a `Field`. Keys live in your `LicenseStore`, not in the framework's settings option.
- **Don't** bypass the adapter to call `LicenseClient` from the framework directly. The framework must stay license-stack-agnostic.
- **Don't** add a `LicenseAdapter` method that returns HTML. If you need to render extra data, use `Tab::render()` override on a custom subclass that wraps `LicenseTab` — never inflate the adapter.
