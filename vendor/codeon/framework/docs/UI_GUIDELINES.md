# UI Guidelines

The framework defines one visual language for every CodeOn admin page. These are the rules. Deviations are rare and require a justification you can defend in review.

---

## Design tokens

All tokens live in `assets/css/codeon-admin.css` as CSS custom properties. **Never hardcode colors, spacing, or radii in plugin code** — read tokens or inherit framework classes.

### Colors

| Token | Value | Used for |
|---|---|---|
| `--codeon-tone-ok`     | `#16a34a` | Success states, healthy pills/dots |
| `--codeon-tone-warn`   | `#eab308` | Grace period, deprecation warnings |
| `--codeon-tone-err`    | `#dc2626` | License invalid, API down, validation errors |
| `--codeon-tone-muted`  | `#9ca3af` | Disabled / unknown / informational |
| `--codeon-bg-card`     | `#ffffff` | Section backgrounds |
| `--codeon-bg-page`     | `#f6f7f7` | Page background, inactive radio cards |
| `--codeon-border`      | `#e5e7eb` | Subtle dividers |
| `--codeon-border-strong` | `#d1d5db` | Form-control borders |
| `--codeon-text`        | `#111827` | Primary text |
| `--codeon-text-soft`   | `#4b5563` | Secondary text |
| `--codeon-text-dim`    | `#6b7280` | Tertiary, captions, descriptions |
| `--codeon-link`        | `#2563eb` | Links, focus rings |

### Spacing scale

`4 / 8 / 12 / 16 / 20 / 24 px`. Tokens `--codeon-space-1` through `--codeon-space-6`. Anything off-scale needs a comment explaining why.

### Radii

`--codeon-radius: 6px` for cards, inputs. `--codeon-radius-pill: 999px` for status pills.

### Typography

System stack via `--codeon-font`. Type ramp:

| Element | Size / weight |
|---|---|
| Page title (`.codeon-header-title`)        | 22 / 600 |
| Section heading (`.codeon-section-h2`)     | 16 / 600 |
| Body                                       | 13 / 400 |
| Description / caption                      | 12 / 400 |
| Health card title (uppercase)              | 11 / 600, letter-spacing 0.05em |

---

## Standard layout

Every codeon-* admin page renders this chrome, in this order:

1. `<header class="codeon-header">` — logo + name + version (left), global status pill (right).
2. `<nav class="codeon-tabs nav-tab-wrapper">` — one anchor per tab, optional `.codeon-tab-dot-{tone}` per tab.
3. `<div class="codeon-content">` — one or more `<section class="codeon-section">` blocks.
4. `<footer class="codeon-footer">` — build watermark + "Powered by CodeOn".

**The plugin only outputs what goes inside `<section>`.** It never owns the wrap, header, or tab nav.

### Width

`.codeon-wrap` is intentionally **full-width** — no `max-width` cap on the page chrome. Wide screens get the full WP admin canvas. Anything that would visually break at 2000+ px (form-table column widths, multi-column grids, prose paragraphs) caps itself at the component level — never at the page wrap. Don't reintroduce a `max-width` on `.codeon-wrap` to "fix" a layout problem; fix the offending component instead.

### Section anatomy

A `<section class="codeon-section">` is the standard content unit. It contains:

- An `<h2 class="codeon-section-h2">` heading.
- An optional `<p class="codeon-section-description">` description.
- A form table OR a free-form body of related controls.

Stack multiple sections vertically inside `.codeon-content`. They render with consistent vertical rhythm.

---

## Do / Don't

**Do**

- Use `Field::heading('id', 'Label')` to delimit logical groups inside one tab — it produces a section divider that respects the type ramp.
- Use `Field::password()->writeOnly()` for any credential. Always.
- Lean on `HealthCard` and `HealthGrid` for dashboard summaries — three cards in a row is the standard pattern, never one giant table.
- Use `.codeon-pill` + `.codeon-tone-{tone}` for any status indicator. Never invent a new pill style.

**Don't**

- Don't add a second `<h1>` inside a section. The `.codeon-header-title` is the only `<h1>` per page.
- Don't reach for raw HTML if a `Field` builder exists. If you find yourself doing it twice, propose a new field type via PR.
- Don't override framework token values from plugin CSS. If you need a different colour, your design is wrong, not the token.
- Don't ship plugin-specific admin CSS that tries to re-implement chrome. The framework owns it.

---

## When to deviate

Two cases:

1. **Dashboards / report views** — diagnostics tabs, event logs, custom tables. These override `Tab::render()` and emit their own HTML inside `<div class="codeon-content">`. They still use `.codeon-section`, `.codeon-pill`, and the tokens.
2. **Plugin-specific widgets that aren't form fields** — a Google Maps picker, a copy-to-clipboard receiver URL panel, an inline log tail. Wrap with `Field::raw(callable)` so the form/save flow still works around them.

Any other deviation needs a `WHY:` comment in the source pointing at the constraint that forced it (a bank's required HTML, a third-party SDK's DOM expectation, etc.).
