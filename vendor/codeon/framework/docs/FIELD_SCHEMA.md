# Field Schema Reference

A `Tab::schema()` returns an array of `Field` builders. The framework walks them top-to-bottom and emits one form-table row per field. Validation, sanitization, and conditional visibility are declared on the field — no plugin code ever writes form HTML.

The fluent builder is in [`src/Schema/Field.php`](../src/Schema/Field.php), the renderer in [`src/Schema/FieldRenderer.php`](../src/Schema/FieldRenderer.php), the validator in [`src/Schema/FieldValidator.php`](../src/Schema/FieldValidator.php).

---

## Field types

### `Field::text(path, label)`

Plain `<input type="text">`. Default sanitizer: `sanitize_text_field`.

```php
Field::text('api_base', __('API base URL', 'plugin'))
    ->wide()
    ->placeholder('https://api.example.com')
    ->description(__('Without trailing slash.', 'plugin'));
```

### `Field::password(path, label)`

`<input type="password">`. **Defaults to write-only** — empty submission keeps the stored value, the placeholder shows `••••••` to indicate one is set.

```php
Field::password('api_secret', __('API secret', 'plugin'))->autocomplete('new-password');
```

### `Field::url(path, label)`

`<input type="url">`. Default sanitizer: `esc_url_raw`.

### `Field::number(path, label)`

`<input type="number">`. Default sanitizer: cast to int/float. Combine with `->validate()` for ranges:

```php
Field::number('batch_size', __('Batch size', 'plugin'))
    ->default(50)
    ->validate(static fn ($v) =>
        ($v >= 10 && $v <= 500) ? null
        : new \WP_Error('range', __('Must be 10–500.', 'plugin')));
```

### `Field::select(path, label)`

`<select>`. Pair with `->options(['key' => 'Label', ...])` or `->optionsCallback(fn () => [...])` for dynamic lists.

### `Field::multiselect(path, label)`

`<select multiple>`. Saved as array of selected keys. Empty = `[]` (not null).

### `Field::radio(path, label)`

Vertical `<input type="radio">` group.

### `Field::radioCards(path, label)`

Visual radio cards (the qsd-style "Test / Production" environment selector). Pair with `->optionHelp(['key' => 'caption text', ...])` to add per-option subtitle text.

```php
Field::radioCards('environment', __('Environment', 'plugin'))
    ->options(['test' => __('Test', 'plugin'), 'prod' => __('Production', 'plugin')])
    ->optionHelp([
        'test' => __('Sandbox; safe to experiment with.', 'plugin'),
        'prod' => __('Live transactions hit your bank.', 'plugin'),
    ])
    ->default('test');
```

### `Field::checkbox(path, label)`

Boolean. `->with('checkbox_label', __('Enable foo'))` adds the inline label next to the box.

### `Field::textarea(path, label)`

`<textarea>`. `->with('rows', 6)` to size it.

### `Field::heading(id, label, description = '')`

Renders a section divider with H2 + optional caption. Not a form field — produces no input, no storage write.

### `Field::raw(id, fn ($value) => echoHtml())`

Escape hatch. The closure receives the current stored value (or null for raw rows) and is responsible for emitting safe HTML inside the row's `<td colspan=2>`. Use sparingly — if you reach for this twice for similar widgets, propose a new field type instead.

### `Field::mapPicker(latPath, lngPath, mapKeyPath)`

Google Maps coordinate picker. Reads three paths from the repository: latitude, longitude, and the API key. Renders a draggable pin and two coordinate inputs. (Available v0.2+.)

---

## Modifiers

| Modifier | Effect |
|---|---|
| `->description(string)` | Caption rendered under the input |
| `->default(mixed)` | Value used when the repository returns null |
| `->placeholder(string)` | HTML placeholder attribute |
| `->autocomplete(string)` | HTML autocomplete attribute (e.g. `'off'`, `'new-password'`) |
| `->wide()` | Use `large-text` class instead of `regular-text` |
| `->writeOnly()` | (Password only) keep stored value when submitted blank — default for password |
| `->options(array)` | Static options for select/radio/multiselect |
| `->optionsCallback(Closure)` | Dynamic options — called at render time |
| `->optionHelp(array)` | Per-option caption (radio cards) |
| `->showWhen(path, op, value)` | Hide the row unless another field's value satisfies the predicate |
| `->sanitize(Closure)` | Append a custom sanitizer (chained after the type's default) |
| `->validate(Closure)` | Append a validator returning `null` (pass) or `WP_Error` (fail) |
| `->with(key, value)` | Stash extras the renderer or your custom code can read via `$field->extra()` |

### `showWhen` operators

| Op | Behaviour |
|---|---|
| `=` / `==`  | strict string equality |
| `!=`        | strict string inequality |
| `in`        | comma-separated string of allowed values |
| `truthy`    | non-empty, non-`'0'` |
| `falsy`     | empty or `'0'` |

```php
Field::select('automation.trigger_status', __('Trigger status', 'plugin'))
    ->options(self::wcStatusOptions())
    ->showWhen('automation.registration_mode', '=', 'auto_custom');
```

The hidden row is also skipped during save — its stored value is preserved, never overwritten with the absent payload.

---

## Validation behaviour

Fields fail individually. A failing validator on `field_a` stops `field_a` from being persisted but the rest of the form still saves. The user sees the error message in a `notice-error` flash; the rejected field is left at its previous value.

If you need atomic form-level validation (everything saves or nothing), throw a `WP_Error` from a `Tab::beforeSave()` override and skip your own `flush()` — the repository's buffer evaporates if you don't call it.

---

## Storage path semantics

The `path` you pass to `Field::*()` is interpreted by the repository:

| Adapter | Path | Storage |
|---|---|---|
| `FlatOptionRepository` | `'foo'` | `$option['foo']` |
| `NestedDotPathRepository` | `'foo.bar.baz'` | `$option['foo']['bar']['baz']` |
| `WCGatewayRepository` | `'foo'` | `WC_Payment_Gateway::get_option('foo')` |
| `SplitOptionRepository` | `'license.key'` | routes `license.*` to one option, others to another |

Same schema, any adapter. See [`docs/STORAGE_ADAPTERS.md`](STORAGE_ADAPTERS.md).
