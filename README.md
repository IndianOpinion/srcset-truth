# srcset-truth

WordPress tells the browser that every image is as wide as the screen, so a phone downloads the 1024 px file for a 372 px card. srcset-truth replaces that `sizes` value with the real width of the slot the image sits in, serves WebP copies next to the originals, and reports the before-and-after image weight of any page.

Measured result (Lighthouse, mobile, a news home page): 8,908 KiB total page weight, of which 6,898 KiB was recoverable by serving the right size in a modern format.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [WP-CLI](#wp-cli)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later. WebP creation needs Imagick built with WebP, or GD with WebP (static images only). Without either, sizes correction still works.

### Without Composer

1. Download the release zip, or `git clone https://github.com/IndianOpinion/srcset-truth.git` into `wp-content/plugins/srcset-truth`.
2. Activate **srcset-truth** under Plugins.

No Composer is needed at runtime: the plugin ships its own class loader.

### With Composer

```sh
composer require indianopinion/srcset-truth
```

With `composer/installers` the package lands in `wp-content/plugins/srcset-truth` (type `wordpress-plugin`).

### As a must-use plugin

Put the plugin folder anywhere WordPress can read (for example `wp-content/mu-plugins/srcset-truth/`), then add a one-line loader `wp-content/mu-plugins/srcset-truth-loader.php`:

```php
<?php require WPMU_PLUGIN_DIR . '/srcset-truth/srcset-truth.php';
```

Must-use plugins never run activation hooks; the backfill schedules itself on the next `init` instead.

## Quick start

Nothing changes until you tell the plugin how wide your slots are. With no width map, core's value is kept, because a guess that is too small makes images blurry.

1. Find the real widths. Log in as an administrator, open a page, and click **Probe image slots** in the admin bar. The browser console lists every image's rendered width, device pixel ratio, downloaded width and the `sizes` it carried, and marks images that downloaded more than 1.5 times what they needed.
2. Describe your slots in the theme (or a small site plugin):

```php
add_filter('srcset_truth_width_map', function (array $map): array {
    $map['default']['card'] = [[767, '92vw'], [1024, '46vw'], [null, '380px']];
    $map['default']['hero'] = '(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px';
    $map['single-post']['content'] = [[800, '100vw'], [null, '760px']];
    return $map;
});

add_filter('srcset_truth_slot_rules', function (): array {
    return [
        ['slot' => 'hero', 'min_width' => 1200],
        ['slot' => 'card', 'size' => ['medium', 'medium_large', 'large']],
    ];
});
```

3. Or name the slot where you render it:

```php
use function IndianOpinion\SrcsetTruth\with_slot;

echo with_slot('card', fn () => get_the_post_thumbnail(null, 'large'));
```

4. Check the saving: `wp srcset-truth audit / --width=412`.

For a typical card-grid theme, the **Card grid** preset on Settings, Media does steps 2 and 3 for you (see [Presets](#presets)).

More in [`examples/`](examples/).

## Configuration

### Settings (Settings, Media)

| Setting | Default | What it does |
|---|---|---|
| Serve WebP siblings | On | Create siblings at upload and in the backfill, and serve them |
| WebP photo quality | 82 | 1 to 100. Static GIFs use 80 and animated GIFs 72 (filterable) |
| Mode | Filter | **Filter**: core image filters. **Buffer**: the whole HTML page, which also reaches theme-hard-coded tags and is needed for the LCP fix |
| LCP fix | Off | Buffer mode only. Makes the likely LCP image eager with high priority, lazy-loads the rest, preloads the hero |
| Width map preset | None | `none` or `grid` |
| Override authored sizes | Off | Also replace `sizes` values the theme wrote itself |
| Background backfill | On | Every 5 minutes, 45 s budget, newest images first |
| Debug log | Off | Writes `[srcset-truth]` lines through `error_log`, only when `WP_DEBUG_LOG` is set |

The constant `SRCSET_TRUTH_DISABLE` set to `true` in `wp-config.php` turns the whole plugin off. Nothing is hooked.

### Filters

| Filter | Value | Extra arguments | Default |
|---|---|---|---|
| `srcset_truth_width_map` | array | template key | preset map or `[]` |
| `srcset_truth_slot_rules` | list | | preset rules or `[]` |
| `srcset_truth_template` | string | | computed template key |
| `srcset_truth_slot` | ?string | context array | null |
| `srcset_truth_should_correct` | bool | sizes value, context | true for the core default only |
| `srcset_truth_sizes` | string | context | the compiled value (validated again) |
| `srcset_truth_webp_enabled` | bool | | the setting |
| `srcset_truth_webp_quality` | int 1 to 100 | kind (`photo`, `graphic`, `animated`), MIME | 82, 80, 72 |
| `srcset_truth_webp_sizes` | list of size names | attachment ID | `medium`, `medium_large`, `large` |
| `srcset_truth_picture_wrap` | bool | | true: wrap in `<picture>`; false rewrites candidates in place (drops browsers without WebP) |
| `srcset_truth_picture_css` | bool | | true: print `picture.srcset-truth{display:contents}` when wrapping |
| `srcset_truth_should_wrap` | bool | tag HTML, context | true (gates WebP for one tag in either strategy) |
| `srcset_truth_skip_classes` | list | | `[]` |
| `srcset_truth_url_to_path` | ?string | URL | uploads mapping |
| `srcset_truth_has_sibling` | ?bool | original path | null (check disk) |
| `srcset_truth_buffer_enabled` | bool | | true in buffer mode |
| `srcset_truth_lcp_enabled` | bool | | the setting |
| `srcset_truth_lcp_rules` | array | | min width 600, ratio 1.2 to 2.2, markers `logo`, `avatar`, `gravatar`, `icon` |
| `srcset_truth_lcp_candidate` | ?bool | tag attributes, index | null (use the rules) |
| `srcset_truth_backfill_enabled` | bool | | the setting |
| `srcset_truth_backfill_budget` | int seconds | | 45 (never above 80% of `max_execution_time`) |
| `srcset_truth_backfill_interval` | int seconds | | 300 |
| `srcset_truth_limits` | array | | 40 megapixels, 30 MB, 300 frames |
| `srcset_truth_grid_card_width` | int px | | 380 |
| `srcset_truth_is_front_html` | bool | | computed |

The context array has `template`, `slot`, `attachment_id`, `size`, `image_width`, `src`, `class` and `source` (`content`, `thumbnail`, `attachment`, `buffer`).

### Actions

| Action | Arguments |
|---|---|
| `srcset_truth_sizes_corrected` | old value, new value, context |
| `srcset_truth_sibling_created` | original path, sibling path, original bytes, sibling bytes |
| `srcset_truth_sibling_failed` | original path, reason code |
| `srcset_truth_backfill_done` | files done, files failed, seconds, cursor |

Reason codes: `no-encoder`, `unsupported-type`, `too-large`, `too-many-frames`, `not-beneficial`, `encode-error`, `write-error`, `colour-profile`.

### Slot API

All in the `IndianOpinion\SrcsetTruth` namespace:

| Function | Purpose |
|---|---|
| `with_slot(string $slot, callable $render): mixed` | Push, render, pop (also when `$render` throws) |
| `push_slot(string $slot)`, `pop_slot()` | For templates that cannot wrap a callable |
| `current_slot(): ?string` | The innermost slot |
| `sibling_path(string $original): string` | `photo.jpg` gives `photo.jpg.webp` |
| `sibling_url(string $url): ?string` | The sibling URL if a fresh sibling exists |
| `compile_sizes(array\|string $spec, ?int $width = null): string` | Compile and validate; throws `InvalidSizesSpec` |

Slot names are 1 to 40 lower case letters, digits and hyphens; others throw `InvalidSlotName`.

### Template keys

`front`, `home`, `attachment`, `page`, `single-{post_type}`, `taxonomy-{taxonomy}`, `author`, `date`, `archive-{post_type}`, `search`, `404`, otherwise `default`. Lookup falls back: `single-post`, `single`, `default`; `taxonomy-category`, `taxonomy`, `archive`, `default`; `archive-{type}`, `archive`, `default`; anything else, `default`. `*` is an alias for `default`.

### Presets

`grid` is for card-grid themes: one column on phones, two on tablets, three on desktop.

- `hero` (images 1200 px or wider): `(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px`
- `card` (everything else): `(max-width: 767px) 92vw, (max-width: 1024px) 46vw, 380px`
- `content` (images in post content on single posts and pages): `(max-width: Wpx) 100vw, Wpx`, where W is the theme's `$content_width`, or 1200 when the theme does not set it. On singular views cards are declared at W too, so a featured image painted wide is never under-declared.

## How it works

1. **Sizes.** On a front-end HTML request, every image passes one classifier, whichever way it arrives (content filter, post thumbnail, attachment attributes, or the page buffer). The classifier recognises core's default `(max-width: Wpx) 100vw, Wpx` (with or without `auto, `). It resolves a template key and a slot, looks the pair up in the width map, compiles and validates the value, and caps the last length at the image's own width so a map written for big images never inflates small ones. Anything it is unsure of keeps core's value.
2. **WebP siblings.** On upload, the plugin writes `photo-768x432.jpg.webp` next to `photo-768x432.jpg` for the main file and the `medium`, `medium_large` and `large` sizes. Type comes from the file signature, not the name. Writes are atomic (temporary file, then rename). A sibling that is not smaller than its original is deleted and not tried again until the original changes. The backfill walks the whole library from the newest ID down, in a 45 s budget, one run at a time.
3. **Serving.** The plugin wraps the image in `<picture>` with a `<source type="image/webp">` and leaves the `<img>` exactly as it was, so a browser without WebP falls back to the original. The `<source>` lists the same candidates in the same order with the same descriptors: each candidate that has a fresh sibling gets the sibling URL, and one without keeps the original URL, so the browser's width choice does not change. Only JPEG, PNG and GIF originals are mapped; WebP, AVIF and SVG candidates are left alone. Returning `false` from `srcset_truth_picture_wrap` rewrites the `<img>`'s own `srcset` and `src` in place instead, which adds no element but hands WebP to every browser: use it only if you accept that browsers without WebP (Safari before 14, older Android WebViews) show broken images. Pages never encode: they only check whether a sibling exists, once per file per request.
4. **Caches.** The HTML is the same for every visitor: no `Vary: Accept`, no user-agent sniffing. Each format has its own URL, so a CDN caches `.jpg` and `.jpg.webp` separately. The plugin never purges; hook `srcset_truth_sibling_created` if you want to.
5. **LCP fix** (buffer mode, off by default). The first image that looks like a content photo (600 px or wider, aspect ratio 1.2 to 2.2, no logo, avatar or icon marker) gets `fetchpriority="high"` and no `loading`; every other image gets `loading="lazy"`; duplicate attributes are collapsed, and a matching `<link rel="preload">` goes before `</head>`. If no image qualifies, nothing changes.

**Anything that syncs uploads to an object store or media host must include the `.webp` files, or pages will link to files the media host does not have.**

If your CDN converts images itself (an edge image optimiser), turn off WebP serving and keep sizes correction.

Web servers must send `.webp` as `image/webp`. Current nginx and Apache do; Site Health checks it for you and reports a wrong type as critical.

## WP-CLI

```sh
wp srcset-truth audit <path-or-url> [--width=412] [--dpr=2] [--format=table|json]
wp srcset-truth generate [--all] [--attachment=<id>] [--since=<date>] [--limit=<n>] [--dry-run]
wp srcset-truth status
wp srcset-truth map [--template=<key>]
wp srcset-truth remove-siblings --yes [--attachment=<id>]
```

`audit` fetches one page of this site (never another host, never a redirect to another host) and reports, per image, the candidate a browser would pick under core's `sizes` in the original format (before) and under the emitted `sizes` in WebP where offered (after), with bytes and flags. The pick rule is the specification's: the smallest candidate whose density reaches the device pixel ratio. Flags: `unmapped`, `authored`, `invalid-spec`, `no-candidate-near-slot` (with the missing width), `no-sibling`, `not-beneficial`, `lazy-lcp`, `unsized`. It exits 1 when the page cannot be fetched or is not HTML.

The audit sends a short-lived signed header (HMAC-SHA256 keyed from the site's salts, valid for at most ten minutes, compared in constant time), so only WP-CLI on the same site can ask for annotations; a visitor cannot. When the page is rendered fresh, images carry a `data-srcset-truth` note with template, slot and status, and the response is marked `private, no-store` and defines `DONOTCACHEPAGE`, so neither a page-cache plugin nor a header-respecting proxy keeps it. If your proxy cache ignores `Cache-Control` and does not key on request headers, exclude requests carrying `X-Srcset-Truth-Audit` from it. A page served from a full-page cache has no notes; the audit then infers what it can.

JSON shape:

```json
{
  "url": "https://example.com/",
  "viewport": {"width": 412, "dpr": 2},
  "images": [
    {"index": 0, "src": "...", "template": "front", "slot": "card", "sizes": "...",
     "before": {"url": "...", "width": 1024, "bytes": 81234, "format": "jpeg"},
     "after": {"url": "...", "width": 768, "bytes": 23110, "format": "webp"},
     "flags": [], "missing_width": null, "unknown": false}
  ],
  "totals": {"images": 1, "counted": 1, "before_bytes": 81234, "after_bytes": 23110, "saving_kib": 56.7, "saving_percent": 71.6}
}
```

## Limitations

- It does not create or regenerate sub-sizes. If the right width is missing from `srcset`, the audit says so (`no-candidate-near-slot`).
- No AVIF in 0.1.0.
- WebP is served through `<picture>`, which changes the element tree around the image (see the CSS question below). The in-place alternative avoids that but breaks images in browsers without WebP.
- In filter mode, images a theme prints with its own `<img>` markup are not reached; buffer mode reaches them. Images printed with `wp_get_attachment_image()` are wrapped through its output filter.
- Uploads offloaded to an object store fail the file check, so originals are served unless you answer `srcset_truth_has_sibling` from your own index.
- Image CDNs that resize on the fly are left alone.

## FAQ

**Nothing changed after activating it.** That is by design. Supply a width map (or pick the grid preset); without one, core's value is the safest answer.

**Will it break my theme's CSS?** Usually not. The plugin prints `picture.srcset-truth{display:contents}` (filter `srcset_truth_picture_css`), so the `<picture>` generates no box and flex and grid layouts treat the `<img>` as a direct child. Child selectors such as `.card > img` and `:first-child` rules no longer match the `<img>`; update them to `.card img`, or return `false` from `srcset_truth_picture_wrap` if you accept losing browsers without WebP.

**Does it slow pages down?** No encoding ever happens on a page request. Pages do at most one file check per candidate per request.

**How do I remove everything?** Purge page and CDN caches, run `wp srcset-truth remove-siblings --yes`, then delete the plugin. Uninstall removes settings and state but leaves sibling files, because cached pages may still point at them.

**Another plugin already serves WebP.** Turn off WebP serving here and keep sizes correction. Tags already inside `<picture>` are never touched.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, tests and pull request rules.

## Licence

MIT. See [LICENSE](LICENSE).
