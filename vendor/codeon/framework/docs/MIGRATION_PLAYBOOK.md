# Migration Playbook

Generic 12-step recipe for porting an existing CodeOn plugin onto the framework. The three live plugins (fina-sync, quickshipper-delivery, codeon-payments) follow this same order.

> **Hard rule before you start**: Every existing `wp_option` key, every `admin_post_*` action name, every nonce action, and every REST namespace your plugin currently exposes must keep working after migration. Bytes-on-disk for every option must match before/after a no-op resave.

---

## 1. Vendor the framework

```bash
composer require codeon/framework:^0.1
```

Pin to a minor range, never `dev-main`. The version range determines which framework features (storage adapters, field types) are available to you.

## 2. Pick a storage adapter

| Existing storage shape | Adapter |
|---|---|
| One assoc array in one `wp_option` | `FlatOptionRepository` |
| Single `wp_option` with deep nesting | `NestedDotPathRepository` |
| WC payment gateway settings | `WCGatewayRepository` |
| Mixed across several `wp_option` keys | `SplitOptionRepository` |

Don't change your storage layout. The adapter exists so the framework can read/write your existing bytes.

## 3. Build a Manifest

```php
$manifest = (new Manifest('your-slug', __('Your Plugin')))
    ->version(YOUR_VERSION)
    ->capability('manage_options')   // or manage_woocommerce
    ->dashicon('dashicons-...')
    ->nonce('your_existing_nonce_action');  // critical for backwards compat
```

The `nonce()` override matters: if your old forms POST'd against `'your_plugin_admin'`, the framework must verify against the same string so existing merchant bookmarks (which carry a still-valid nonce) keep working.

## 4. Write a `LicenseAdapter`

Wrap your existing `License` / `LicenseGate` / `LicenseStore` / `LicenseClient` in a class implementing `CodeOn\Framework\License\LicenseAdapter`. See [`docs/LICENSE_INTEGRATION.md`](LICENSE_INTEGRATION.md) — about 30 lines.

## 5. Write a `BuildStampContract`

Wrap your existing watermark verifier (or seed scatter sites if you don't have one yet — see [`docs/WATERMARK.md`](WATERMARK.md)).

## 6. Port one tab as a `Tab` subclass

Pick the smallest tab first. Write it as `Tab::schema()` returning a `Field[]`. Hand it the same repository.

```php
final class GeneralTab extends Tab
{
    public function __construct(private readonly SettingsRepository $repo) {}
    public function slug(): string { return 'general'; }
    public function label(): string { return __('General', 'plugin'); }
    public function repository(): SettingsRepository { return $this->repo; }
    public function schema(): array { return [/* ... */]; }
}
```

Check the rendered HTML against your old tab. The form-table rows should look the same; the chrome (header / tab nav / footer) is now framework-supplied.

## 7. Register `AdminPostRouter::aliasLegacy()` for legacy POST URLs

For every old `admin_post_*` your plugin exposes:

```php
AdminPostRouter::aliasLegacy(
    legacyAction: 'your_plugin_save_general',
    page: $page,
    tabSlug: 'general',
    opName: 'save'
);
```

This keeps merchant bookmarks live. The framework's save flow is invoked transparently; the response is the same redirect-with-flash users are used to.

## 8. Byte-diff the `wp_options` row

This is the most important verification step. Before & after a no-op resave (open the form, save without changing anything), the option row must be byte-identical.

```bash
# Before
wp option get your_plugin_settings --format=json | jq -S . > /tmp/before.json

# … open the tab in wp-admin, click Save with no changes …

wp option get your_plugin_settings --format=json | jq -S . > /tmp/after.json
diff /tmp/before.json /tmp/after.json
# Expect: empty diff.
```

If the diff is non-empty, your adapter or schema is reordering keys / coercing types / dropping defaults. Fix before proceeding.

## 9. Smoke-test the round-trip

For every field in the tab:
- Change the value, save → reload the page → the new value displays.
- Save → check `wp option get` shows the new value with the same shape.
- Empty the value, save → either it persists as empty (most types) or the original stays (write-only password). No PHP notices.

## 10. Delete the legacy template

Once the new tab works end-to-end, delete the old template file (e.g. `includes/Admin/views/tab-general.php`). Don't leave it behind "just in case" — dead code rots and someone three months from now will edit the wrong file.

## 11. Repeat 6–10 for each remaining tab

Some tabs (diagnostics, events, dashboard) override `Tab::render()` instead of using `schema()` — they're report views, not forms. The chrome still wraps them.

## 12. Ship as a minor release; monitor for one week

Cut a minor version bump on the plugin (e.g. fina-sync `3.0.x → 3.1.0`). Tag, push, let the release workflow build the ZIP. Roll out to production. Watch logs for one week. The `CODEON_FRAMEWORK_DISABLE_CHROME` constant is a one-line emergency rollback if something melts down.

---

## Per-plugin notes

### fina-sync (canary)
- Native WP Settings API today → drop `register_setting('fina_sync_group')`.
- REST `/fina-sync/v1/license/*` keeps working — license POST flows through the adapter from both `admin-post.php` and REST.
- Recovery mode: move the `bootRecoveryMode()` decision into `Bootstrap::register($manifest, $tabs, $stamp)` — the framework returns `['recovery' => true]` when stamp is invalid, the plugin's `boot()` checks it and skips Scheduler/JobRunner/REST/CLI registration.

### quickshipper-delivery
- Bumps framework to `v0.2` to get `NestedDotPathRepository` + `Field::radioCards` + `Field::mapPicker`.
- `Support\Settings` static stays as a thin facade over the repository for one release so non-migrated callers (Webhook, Orders) don't break.
- `.qsd-*` CSS classes co-exist with `.codeon-*` for one release; delete the old stylesheet in the next minor.

### codeon-payments
- Bumps `Requires PHP` 7.4 → 8.1 in the same release that adopts the framework.
- Bumps framework to `v0.3` to get `SplitOptionRepository` + `WCGatewayRepository`.
- `OrderMetaBox.php` is OUT of the framework's chrome — leave it as-is.
- Watermark wiring is a NEW feature in this release (was a placeholder before).
- Diagnostics + Events tabs override `Tab::render()` (report views, not forms).
