# Internationalisation + WPML compatibility

**Mandatory baseline for every CodeOn plugin.** Skip these and the plugin works in English but a multilingual store with WPML / Polylang / Loco Translate active sees mixed-language UI in the admin and at checkout — visible to every shopper, expensive to retrofit.

This file documents the FULL pattern. The TL;DR checklist is at the bottom.

---

## 1. Wrap every user-facing string

Every string a merchant or shopper can read MUST go through a gettext call:

```php
__('Installments available', 'codeon-bog-installments')
esc_html__('Pay in 4 installments', 'codeon-bog-installments')
_e('Configure credentials below.', 'codeon-bog-installments')
sprintf(
    /* translators: %s: bank name */
    esc_html__('Pay with %s', 'codeon-bog-installments'),
    $bank
)
_n('1 product', '%d products', $count, 'codeon-bog-installments')
```

The textdomain MUST be a **string literal**, not a variable, in every direct call site. WP-CLI's `wp i18n make-pot` and most static analysers can't follow a variable, so anything passed as `$textdomain` is invisible to .pot generation.

(The framework's `InstallmentEstimator::render()` accepts a `$textdomain` parameter so the consumer plugin's textdomain wins at runtime — that's a different concern; see §6.)

## 2. Plugin slug = textdomain

The plugin's textdomain MUST equal the plugin folder slug, no exceptions:

| Plugin | Slug & textdomain |
|---|---|
| `codeon-bog-installments/` | `codeon-bog-installments` |
| `codeon-bog-card-payment/` | `codeon-bog-card-payment` |
| `fina-sync/` | `fina-sync` |

Set it in the plugin header:

```
Text Domain: codeon-bog-installments
Domain Path: /languages
```

## 3. Load the textdomain on `init`, NOT on bootstrap

```php
add_action('init', static function (): void {
    load_plugin_textdomain(
        'codeon-bog-installments',
        false,
        dirname(CODEON_BOG_INST_BASENAME) . '/languages'
    );
});
```

Why `init`, not `plugins_loaded`: WPML hooks into `plugins_loaded` itself, and translations registered before WPML's hooks fire don't always get the right locale. `init` runs after both, every time, on every request.

## 4. Block-Checkout (and any other) JS strings

WC Blocks payment-method labels, descriptions, and inline strings rendered by client-side React MUST use `wp.i18n.__()` and translations have to be registered against the script handle:

**JS:**
```js
var i18n = window.wp && window.wp.i18n;
var __ = i18n && i18n.__ ? i18n.__ : function (s) { return s; };

var label = settings.title || __('Buy Now Pay Later', 'codeon-bog-installments');
```

**PHP, after `wp_register_script`:**
```php
wp_set_script_translations(
    $handle,
    'codeon-bog-installments',
    CODEON_BOG_INST_PATH . 'languages'
);
```

The script's deps array MUST include `'wp-i18n'`.

For each translated locale, generate the JSON companion to the `.po`:

```
wp i18n make-json languages/codeon-bog-installments-ka_GE.po
```

(WP looks for these JSON files at request time.)

## 5. Ship `wpml-config.xml` for admin-stored strings

Strings that aren't compiled into the source — e.g. the merchant-editable gateway title and description in the WC payment-method settings — must be declared as **admin-texts** in `wpml-config.xml` at the plugin root:

```xml
<?xml version="1.0" encoding="utf-8"?>
<wpml-config>
    <admin-texts>
        <key name="woocommerce_codeon_bog_loan_settings">
            <key name="title"/>
            <key name="description"/>
        </key>
        <key name="woocommerce_codeon_bog_bnpl_settings">
            <key name="title"/>
            <key name="description"/>
        </key>
    </admin-texts>
</wpml-config>
```

Without this, WPML's String Translation has no way to know the option carries user-facing strings — the merchant types a Georgian title once, every shopper sees Georgian regardless of their site language.

If the plugin has translatable custom-fields or taxonomies, declare those here too — see [WPML's reference](https://wpml.org/documentation/support/language-configuration-files/).

## 6. Framework helpers that emit text — pass the consumer textdomain

Framework code that renders user-facing markup (e.g. `\CodeOn\Framework\WooCommerce\Payments\InstallmentEstimator::render()`) takes a `$textdomain` parameter. The consumer plugin passes its OWN slug:

```php
InstallmentEstimator::render(
    $principal,
    $apr,
    $months,
    $defaultMonths,
    $brandName,
    'codeon-bog-installments'   // <-- consumer's textdomain
);
```

This means:
- The runtime `__($s, 'codeon-bog-installments')` call inside the framework registers under the CONSUMER's textdomain. WPML's String Translation picks them up automatically the first time the page renders.
- For .pot generation, scan `vendor/codeon/framework/src/` together with the plugin's own `includes/` so `make-pot` finds the strings:

```
wp i18n make-pot . languages/<slug>.pot \
    --domain=<slug> \
    --include="includes,vendor/codeon/framework/src,assets/js/blocks"
```

This pattern is mandatory for every plugin that calls into framework rendering helpers — see the readme/translation-instructions section in `codeon-bog-installments/languages/README.txt` for the canonical example.

## 7. Avoid these anti-patterns

- **Concatenating strings before translation.** `__('Order') . ' #' . $id` — translators get only "Order", can't reorder for languages where the number comes first. Use placeholders: `sprintf(__('Order #%d', 'slug'), $id)`.
- **Translating dynamic content.** `__($message, ...)` with a runtime `$message` — gettext can't translate values it can't see at parse time.
- **Empty translator comments where context is needed.** Always add `/* translators: ... */` immediately above any `sprintf` / `printf` with placeholders, especially when the placeholders' meaning isn't obvious from the format string.
- **String concatenation for HTML.** Translatable strings can include HTML; let translators reorder it: `sprintf(__('Read the <a href="%s">manual</a>.', 'slug'), $url)`.
- **Missing `Domain Path:` in plugin header.** Without it, WordPress doesn't know where to look for `.mo` files; falls back to global `wp-content/languages/plugins/`.

## 8. Mandatory checklist

Before tagging any plugin release:

- [ ] Plugin header has `Text Domain:` and `Domain Path: /languages`.
- [ ] Textdomain literal === plugin folder slug everywhere it appears.
- [ ] `load_plugin_textdomain()` called once on `init` (NOT `plugins_loaded`).
- [ ] Every user-facing string is wrapped (`__`, `_e`, `esc_html__`, `_x`, `_n`, …).
- [ ] Every gettext call uses a string literal as the textdomain argument.
- [ ] `sprintf` / `printf` have `/* translators: */` comments on the line above.
- [ ] `wpml-config.xml` exists at plugin root, with admin-texts declared for any merchant-edited option that contains user-facing strings.
- [ ] Block-Checkout / any client-side JS uses `wp.i18n.__()` AND `wp_set_script_translations()` is called for each script handle.
- [ ] `'wp-i18n'` is in the JS script's dependencies array.
- [ ] `languages/` directory exists at plugin root with a `README.txt` documenting the make-pot / make-json commands (see `codeon-bog-installments` for the reference shape).

Failing any item is a release-blocker. Build pipelines should grep for the textdomain literal across `includes/` and the plugin file as a sanity check.
