# CodeOn Plugin Icon System

One consistent visual language for every CodeOn-distributed WordPress
plugin. When a merchant installs multiple CodeOn plugins the icons
should look like they came out of the same box — not a pile of mixed
branding.

**Reference implementation:** `quickshipper-delivery` (v0.3.11+).

---

## The rules (all plugins must follow)

### 1. Canvas + corners

- `icon-256x256.png` and `icon-128x128.png`, both square.
- Rounded corners baked in at `rx=48` on the 256 canvas (≈18.75%
  radius — matches iOS app-icon grid).
- No transparency in the body. The whole rounded square is filled.
  The corners themselves are transparent so WP's admin UI can round
  against any page background.

### 2. Background

- Deep-blue gradient, top-left → bottom-right:
  - stop 0: `#1e3a8a`
  - stop 1: `#2563eb`
- Same gradient every plugin. **Do not change the gradient per
  plugin.** The background is the CodeOn family signature; the
  central glyph is how plugins differ.

### 3. Central glyph

- Drawn within a **192×192 safe zone** centred on the 256 canvas
  (32px margin on every side).
- White as the primary fill (`#ffffff` → `#e0e7ff` vertical
  gradient). Accents in `#2563eb` / `#1e40af` so the glyph reads
  flat + crisp at 40px.
- One idea per icon, not a composition. "Courier box with motion
  arrow." "Invoice with checkmark." "Credit card with flag." If you
  need two shapes to tell the story, simplify until one carries it.
- Soft under-shadow: `<ellipse rx="70" ry="8" fill="#000" opacity=".18">`
  placed at the glyph's baseline. Grounds the object.

### 4. CodeOn corner mark (mandatory)

Bottom-right corner, at `transform="translate(196 196)"` on the 256
canvas. Three elements:

```xml
<g transform="translate(196 196)" opacity="0.9">
  <rect x="-18" y="-18" width="36" height="36" rx="10" ry="10"
        fill="none" stroke="#ffffff" stroke-width="3"/>
  <path d="M -6 -7 L -11 0 L -6 7"
        fill="none" stroke="#ffffff" stroke-width="3"
        stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="6" cy="0" r="3" fill="#ffffff"/>
</g>
```

This is the `CodeOnMark` component from `codeon.ge/src/components/logo.tsx`,
scaled to 36px. **Never change the proportions or colours.** The mark
is what says "this is a CodeOn plugin" across the whole catalogue.

### 5. Motion / depth layer (optional, recommended)

Two accents that sell the icon without adding clutter:

- Speed lines, upper-left: 3 short white strokes @ 16% opacity, 6px
  wide, rounded caps. Suggest "this ships fast" or "this moves data."
- Motion swoosh behind the glyph: a single white path @ 28% opacity,
  curving left → right. Adds dimension without a drop-shadow.

Omit for plugins where it makes no sense (e.g. a settings-panel
plugin). Keep for anything involving flow, delivery, sync, payments.

### 6. No text on the 256 icon

The plugin name is set next to the icon by WordPress. Text baked into
a 40×40 thumbnail is illegible and competes with the glyph. The
**banner** (§7) is where text lives.

---

## Banner specs

WP shows the banner in the "View details" modal. Two sizes:

- `banner-1544x500.png` — retina.
- `banner-772x250.png` — downscaled from the 1544 using `sips -z 250 772`.

Layout:

| Zone | Content |
|---|---|
| Background | Same deep-blue gradient as the icon |
| Left ~55% | Plugin wordmark (700 / 84px), one-line subtitle (500 / 48px), single-line feature strip (500 / 22px all-caps) |
| Right ~35% | Oversized version of the icon's central glyph |
| Bottom-left | `codeon.ge` wordmark + CodeOn mark, 88% opacity |

Keep all text in the **left 50%** so the WP modal's padding doesn't
clip it on smaller screens. Keep the glyph at least 48px from every
edge.

Font stack: `-apple-system, BlinkMacSystemFont, 'Segoe UI',
system-ui, sans-serif`. Don't embed a webfont — the SVG renders
before upload in our headless-Chrome pipeline, so whatever the macOS
renderer picks is what lands in the PNG.

---

## Rendering pipeline (macOS)

We hand-author the SVG and let headless Chrome rasterise to PNG —
produces the cleanest edge-to-edge PNG with no padding, and handles
the gradient + opacity layers correctly. `qlmanage` adds a transparent
gutter so **do not use it for release assets.**

```bash
# Icon — 256×256 retina
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless --disable-gpu --no-sandbox \
  --window-size=256,256 \
  --default-background-color=00000000 \
  --screenshot=assets/icon/icon-256x256.png \
  --hide-scrollbars \
  "file://$PWD/assets/icon/icon.svg"

# 128×128 downscale
sips -Z 128 assets/icon/icon-256x256.png --out assets/icon/icon-128x128.png

# Banner — 1544×500 retina
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless --disable-gpu --no-sandbox \
  --window-size=1544,500 \
  --default-background-color=00000000 \
  --screenshot=assets/icon/banner-1544x500.png \
  --hide-scrollbars \
  "file://$PWD/assets/icon/banner.svg"

# 772×250 downscale
sips -z 250 772 assets/icon/banner-1544x500.png \
  --out assets/icon/banner-772x250.png
```

Verify the final size before committing:

```bash
sips -g pixelWidth -g pixelHeight assets/icon/*.png
```

---

## Where the files live

```
<plugin>/assets/icon/
  icon.svg              # source, hand-edited
  icon-128x128.png      # WP Updates screen thumbnail
  icon-256x256.png      # WP "View details" modal + retina displays
  banner.svg            # source, hand-edited
  banner-772x250.png    # WP "View details" modal banner
  banner-1544x500.png   # retina
```

Commit all six. The SVGs are the source of truth — when tweaking a
plugin icon, edit the SVG, re-render the PNGs, commit all. Do not
edit the PNG directly.

---

## Wiring icons into the update manifest (PHP, mandatory)

Every plugin ships a `Updates\UpdateChecker` (or equivalent). Both
its `plugins_api` response AND its `site_transient_update_plugins`
injection must include `icons` + `banners` keys, otherwise WP shows
the empty-picture placeholder on the Updates screen and blank header
in the details modal.

Add this static helper once per plugin:

```php
public static function assetPack(): array
{
    $base = plugins_url('assets/icon/', <PLUGIN>_FILE);
    return [
        'icons' => [
            '1x' => $base . 'icon-128x128.png',
            '2x' => $base . 'icon-256x256.png',
            'svg' => $base . 'icon.svg',
            'default' => $base . 'icon-256x256.png',
        ],
        'banners' => [
            'low' => $base . 'banner-772x250.png',
            'high' => $base . 'banner-1544x500.png',
        ],
    ];
}
```

Then merge into both responses:

```php
// injectUpdate() — goes on the transient response object
$assets = self::assetPack();
$transient->response[$basename] = (object)[
    /* …existing fields… */
    'icons' => $assets['icons'],
    'banners' => $assets['banners'],
];

// pluginsApi() — goes on the "View details" response object
$assets = self::assetPack();
return (object)[
    /* …existing fields… */
    'icons' => $assets['icons'],
    'banners' => $assets['banners'],
    'sections' => [ /* … */ ],
];
```

**Why `plugins_url` not codeon.ge URL**: the PNGs live inside the
plugin ZIP, so the merchant's own WP install serves them. Renders
even when codeon.ge is slow or unreachable during a WP-Admin page
paint.

---

## Per-plugin design notes (checklist before cutting a new icon)

Before drawing, write a one-sentence answer to each:

1. **What one noun does this plugin act on?** (Package, invoice, card,
   ticket, file…) — that's the glyph's subject.
2. **What one verb does this plugin perform on that noun?** (Ship,
   sync, settle, sign, stream…) — that's the motion cue.
3. **Does the icon need a brand-colour accent from the plugin's
   domain?** Shipping = blue (universal). Payments = green. Documents
   = indigo. Be conservative — the gradient is doing most of the
   colour work.

If you can't answer 1 + 2 in one sentence each, the plugin's scope is
blurry and the icon will look like a compromise. Sharpen the plugin
name before drawing.

---

## Don'ts

- **Don't use `qlmanage` for release PNGs.** It pads.
- **Don't swap the background gradient per plugin.** Central glyph
  differs; background is invariant.
- **Don't bake the plugin name into the 256 icon.** Banner only.
- **Don't skip the CodeOn corner mark.** It's the bundle signature.
- **Don't serve icons from codeon.ge.** Ship them in the plugin ZIP.
- **Don't let the glyph touch the canvas edge.** 32px minimum margin.
- **Don't change `rx=48`.** All CodeOn icons share the same corner.
- **Don't use `emoji` in the banner wordmark.** macOS renders them
  as colour bitmaps that differ from Chromium on the merchant's
  page; the two can disagree on alignment.
