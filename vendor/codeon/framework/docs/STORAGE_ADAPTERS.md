# Storage Adapters

The framework never reads or writes `wp_options` directly. All persistence flows through a `SettingsRepository` so the same `Field[]` schema can run against any storage layout.

The interface is three methods:

```php
interface SettingsRepository
{
    public function get(string $path, mixed $default = null): mixed;
    public function set(string $path, mixed $value): void;  // buffered
    public function flush(): void;                          // commit
}
```

The `Page::handleSave()` flow buffers all field writes via `set()`, then calls `flush()` once at the end so the entire form save is atomic from the user's perspective.

---

## Picking an adapter

| Plugin shape | Adapter |
|---|---|
| All settings in one assoc-array `wp_option` | **`FlatOptionRepository`** |
| Single `wp_option` with deep nesting via dot paths | **`NestedDotPathRepository`** (v0.2+) |
| Settings live inside a `WC_Payment_Gateway`'s `form_fields` | **`WCGatewayRepository`** (v0.3+) |
| Settings split across several `wp_option` keys | **`SplitOptionRepository`** (v0.3+) |

---

## `FlatOptionRepository` (v0.1+)

Fields are top-level keys of one assoc array stored as one `wp_option`.

**Bytes on disk** — the option holds `serialize(['field_a' => ..., 'field_b' => ...])`. Identical to what `update_option('foo', ['field_a' => ..., 'field_b' => ...])` produces.

**Reference plugin**: fina-sync (`fina_sync_settings`).

```php
$repo = new FlatOptionRepository(
    optionName: 'my_plugin_settings',
    defaults: ['batch_size' => 50, 'debug' => false]
);

Field::number('batch_size', __('Batch size'))->default(50);
// Reads as $option['batch_size']; writes go to the same key.
```

**Caveat**: paths can't contain dots — they're treated as literal key strings. If you have nested data, use the dot-path adapter.

---

## `NestedDotPathRepository` (v0.2+, ships with quickshipper-delivery's migration)

Same single-`wp_option` model, but paths walk nested arrays.

```php
$repo = new NestedDotPathRepository('my_plugin_settings');

Field::text('api.username', __('Username'));
Field::text('api.env_overrides.test.auth_base', __('Test auth URL'));
// Reads as $option['api']['env_overrides']['test']['auth_base'].
```

**Reference**: ports `CodeOn\QuickShipper\Support\Settings::get/set` verbatim. The `mergeDeep` logic for default seeding is preserved so existing dot-path values don't reorder.

---

## `WCGatewayRepository` (v0.3+, ships with codeon-payments' migration)

Routes reads/writes through `WC_Payment_Gateway::get_option()` / `update_option()` so the bytes still live in `woocommerce_<gateway-id>_settings` exactly the way WC expects.

```php
$repo = new WCGatewayRepository(gatewaySlug: 'codeon_tbc_card');

Field::text('client_id', __('Client ID'));
// Reads as $gateway->get_option('client_id'); writes via update_option
// onto the same WC-managed wp_option key, which keeps WC admin pages
// reading the value unchanged.
```

**Why**: lets a CodeOn unified admin page contain a tab for each WC gateway without the gateway forgetting its own settings page is also still wired up.

---

## `SplitOptionRepository` (v0.3+, ships with codeon-payments' migration)

Routes top-level path segments to different `wp_option` keys (or even different adapters).

```php
$repo = new SplitOptionRepository([
    'license' => new RawOptionRepository('codeon_payments_license_key'),
    'data'    => new TransientRepository('codeon_payments_license_data'),
    'settings' => new FlatOptionRepository('codeon_payments_settings'),
    'gateways' => new GatewayMultiplexRepository(),
]);

Field::text('license.key', __('License key'));
Field::checkbox('settings.custom_checkout_ui', __('Custom UI'));
Field::text('gateways.codeon_tbc_card.client_id', __('TBC Client ID'));
// Each path's first segment chooses the sub-adapter.
```

**Why**: codeon-payments has a single admin page that touches: a raw string option (the license key), a transient (cached license data), a flat-array option (plugin settings), and N WC gateway option blobs (one per registered gateway). One schema; one save flow; one POST URL — but four different storage layouts behind it.

---

## Writing your own adapter

Three methods. Read the buffer-then-flush pattern from `FlatOptionRepository::set/flush` and copy it. Adapters that write eagerly (no buffer) make `flush()` a no-op.

Round-trip lossless is the only hard rule — if a value goes through `set()` and then `get()`, you get back what you put in (allowing for the type's documented coercion). Add a unit test that asserts this for every type your storage layer touches.
