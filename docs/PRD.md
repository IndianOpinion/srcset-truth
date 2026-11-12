# srcset-truth: product requirements

Status: draft for build. Audience: the engineer who builds version 0.1.0
without access to any other code. Everything a builder needs is here or in
public WordPress and browser documentation.

## 1. Problem

WordPress gives every attachment image a `srcset` with several widths, which
is good. It then gives the same image a `sizes` attribute that says, in
effect, "this image is as wide as the viewport, up to its own width". For an
image that a theme paints into a fixed slot (a card in a grid, a thumbnail in
a list, a sidebar tile) that statement is false. The browser trusts it.

A worked case. A theme asks for the 1024 x 576 derivative and paints it into a
372 x 266 card. WordPress emits
`sizes="auto, (max-width: 1024px) 100vw, 1024px"`. On a 412 px wide phone at
a device pixel ratio of 2, the browser computes a need of 824 device pixels
and downloads the 1024w candidate. The 300w and 768w files already exist on
disk and one of them was the right answer. The `srcset` was fine. The `sizes`
hint lied.

The second fault is format. Most uploads are JPEG, PNG or GIF. WebP is much
smaller for the same visual quality, and every current browser renders it.
Converting a whole media library is expensive in disk and CPU, and rewriting
URLs on the server behind `Accept` negotiation breaks on CDNs that do not vary
their cache on `Accept`.

A third fault rides along. Themes often hard-code `loading="lazy"` on every
image, including the largest one above the fold. That defeats the browser's
own priority logic and delays Largest Contentful Paint (LCP).

Measured result (Lighthouse, mobile, a news home page): 8,908 KiB total page
weight, of which 6,898 KiB was recoverable by serving the right size in a
modern format.

Without this plugin a site either ships several times the bytes it needs to
phones, or regenerates thousands of derivatives it cannot afford, or turns on
server-side format negotiation that can hand a WebP to a client that cannot
render it.

## 2. Goals and non-goals

Goals:

1. Replace the core default `sizes` value with the truth about the slot the
   image is painted into, using a width map the site supplies per template and
   per slot. No new image files are needed for this part.
2. Detect when the emitted `sizes` value is the core default (and therefore
   probably wrong) and leave values a theme deliberately authored alone.
3. Serve WebP through `<picture>` with a `<source type="image/webp">`, so the
   browser chooses and one URL per format stays correct in every cache.
4. Create WebP siblings next to the originals, only for the sizes actually
   served, at upload time and in a time-boxed background backfill. Never encode
   during a page request.
5. Optionally fix lazy loading and priority for the LCP image when the theme
   gets it wrong, and preload it.
6. Report the before-and-after image weight of a page for a given viewport, so
   the operator can see the saving and find slots that are still wrong.
7. Make every default a filter. A theme's slot widths are configuration, not
   code in this plugin.

Non-goals:

1. Creating new sub-sizes or regenerating existing ones. If the right width
   does not exist in `srcset`, the audit says so; it does not fix it.
2. Changing the `srcset` candidate list WordPress builds, other than adding a
   parallel WebP list in a `<source>`.
3. Server or CDN `Accept` negotiation, rewrite rules or `Vary: Accept`.
4. AVIF in 0.1.0 (see open questions).
5. Image CDNs that resize or transcode on the fly. When candidate URLs do not
   map to local files, the plugin does nothing to them.
6. Converting the original upload or replacing the stored main file format.
   WordPress core can already do that with its output-format filter; this
   plugin works alongside it and skips files that are already WebP.
7. Lazy loading for iframes, videos or CSS background images.
8. Learning slot widths automatically from visitors' browsers.

## 3. Users and setting

- Who installs it: a site operator or theme developer on a self-hosted
  WordPress site, usually after a Lighthouse or PageSpeed report flags
  "Properly size images" and "Serve images in modern formats".
- Who configures it: the theme developer, through filters in the theme or a
  small site plugin, plus a short settings section for the operator.
- Where it runs: on the origin, inside WordPress page rendering (front end
  only), in WP-Cron or a real cron runner for the backfill, and in WP-CLI.
- Usual stack around it: a full-page cache (nginx FastCGI cache, Varnish, a
  caching plugin), a CDN in front, uploads on local disk or synchronised to an
  object store by a separate process.
- Installable as a normal plugin or as a must-use plugin (a loader file in
  `mu-plugins` that requires the main file).

## 4. Behaviour

Terms used below:

- Core default sizes: the exact value WordPress builds when nothing filters
  it: `(max-width: Wpx) 100vw, Wpx` where W is the image width WordPress
  used, optionally preceded by `auto, ` (WordPress 6.7 and later add that for
  lazy-loaded images).
- Template key: a short string that names the kind of page being rendered.
- Slot: a named place in a layout where images have a known painted width
  (for example `card`, `hero`, `thumb`, `content`).
- Sizes spec: what the width map holds for one template and slot. Either a
  ready `sizes` string, or a list of breakpoint pairs (see section 5.3).
- Sibling: the WebP file stored next to an original. Its path is the original
  path with `.webp` appended (`photo-768x432.jpg` gives
  `photo-768x432.jpg.webp`).
- Request types: front-end HTML request; admin; REST; AJAX; feed; sitemap;
  embed; WP-CLI; cron.

### 4.1 Sizes detection and correction

1. When WordPress computes `sizes` for an attachment image on a front-end
   HTML request, the plugin classifies the value. If it is the core default
   (with or without the `auto, ` prefix, any W, tolerant of whitespace
   differences), it is eligible for correction.
2. A value that is not the core default is theme-authored. It is left alone
   unless the setting "override authored sizes" is on, or the
   `srcset_truth_should_correct` filter returns true for it.
3. An eligible image is resolved to a template key (4.2) and a slot (4.3).
   The width map (4.4) is looked up for that pair. If a sizes spec is found it
   is compiled to a `sizes` string, validated (4.5) and returned in place of
   the core value.
4. If no slot matches, or the map has no entry for the pair, the core value
   is returned unchanged. The image is counted as unmapped for the audit.
5. An image without a `srcset` is not touched by sizes correction (the
   attribute is meaningless without one).
6. The `auto` keyword. If the final tag will be lazy-loaded and the original
   value began with `auto, `, the corrected value keeps `auto, ` at the front,
   so browsers that support it still use the real layout width. If the final
   tag is eager (including an image the LCP fix in 4.9 makes eager), `auto` is
   removed, because it is only valid with `loading="lazy"`.
7. Correction never makes a declared width larger than the core default would
   have declared for the same viewport. If a compiled spec would ask for more
   than the image's own width at its widest breakpoint, the last length is
   capped at the image width in px. This keeps a map written for large
   images from inflating small ones.
8. Correction runs at most once per tag. A tag that already carries a value
   this plugin produced (recognised because the value equals the compiled
   output for its slot) is not processed again, so buffer mode and filter
   mode together do not double-handle a tag.
9. Theme-hard-coded `<img>` tags that never pass through WordPress image
   functions are corrected only in buffer mode (4.8), by the same rules,
   reading W from the tag's `width` attribute.
10. Images in content (block and classic) are handled through the core
    content-image filter; featured images through the post thumbnail HTML
    filter and the attachment image attribute filter. All three paths share
    one classifier so they cannot disagree.

### 4.2 Template keys

11. The template key is computed once per request after the main query, from
    WordPress conditionals, most specific first:
    `front` (static or posts front page), `home` (posts page when not the
    front), `single-{post_type}`, `page`, `attachment`,
    `taxonomy-{taxonomy}` (categories give `taxonomy-category`, tags
    `taxonomy-post_tag`), `author`, `date`, `archive-{post_type}`, `search`,
    `404`, otherwise `default`.
12. Lookup falls back from specific to generic: `single-post` then `single`
    then `default`; `taxonomy-category` then `taxonomy` then `archive` then
    `default`; `archive-{type}` then `archive` then `default`; any other key
    then `default`.
13. The `srcset_truth_template` filter may replace the key (for example to
    split a landing page template out of `page`). A non-string or empty result
    falls back to the computed key.
14. Before the main query has run (very early output), the key is `default`.

### 4.3 Slot resolution

15. Slots resolve in this order, first match wins:
    a. Explicit context set by the theme with the slot API (section 5.2). A
       stack, so nested regions work; the innermost slot wins.
    b. Slot rules from the `srcset_truth_slot_rules` filter, in the order
       given. A rule may match on any of: requested size name(s), a CSS class
       present on the `<img>`, a CSS class on the attachment image call's
       attributes, a minimum and maximum image width in px. All conditions in
       one rule must hold.
    c. The `srcset_truth_slot` filter, which receives the context (section
       5.4) and may return a slot name or null.
    d. No slot: the image is unmapped (case 4).
16. An unknown slot name (no entry in the map for any template) is treated as
    unmapped and recorded once per request for the debug log.
17. Slot context set with the API is cleared at the end of the request; an
    unbalanced push without a pop is closed automatically and logged in debug
    mode.

### 4.4 Width maps

18. The width map is an array from template key to an array from slot name to
    sizes spec, supplied by the `srcset_truth_width_map` filter and by an
    optional preset (section 7).
19. Lookup is: the template key chain from case 12, at each step the slot
    name; first hit wins. A `*` template key is accepted as an alias for
    `default`.
20. A breakpoint list is compiled into `(max-width: Apx) L1, (max-width: Bpx)
    L2, ..., Ln` in ascending breakpoint order, the final pair having a null
    breakpoint. Lists out of order are sorted. Duplicate breakpoints keep the
    last entry.
21. The compiled string is cached per request per (template, slot, image
    width) so a page with hundreds of images compiles each spec once.

### 4.5 Sizes validation

22. A sizes string (supplied directly or compiled) is accepted only if it
    parses as a comma-separated list where every entry but the last is a media
    condition in parentheses followed by a length, the last entry is a length
    alone, and an `auto` keyword appears only first. Lengths are a number with
    `px`, `vw`, `em` or `rem`, or a `calc()` / `min()` / `max()` / `clamp()`
    expression with balanced parentheses. Lengths in `%` are rejected (they
    are invalid in `sizes`).
23. An invalid spec fails open: the core value is kept, the image is counted
    as invalid, and a debug log line names the template, slot and spec once
    per request.
24. The output is attribute-escaped. Characters outside the grammar (quotes,
    angle brackets) make the spec invalid rather than being escaped through.

### 4.6 WebP siblings: creation

25. On upload, after WordPress generates attachment metadata, the plugin
    creates siblings for the configured sizes (default: `medium`,
    `medium_large`, `large`) and for the main file named in the metadata
    (which is the `-scaled` file when core has scaled a big upload). Sizes not
    in the list are not converted.
26. Sources are JPEG, PNG and GIF, recognised by real MIME type from the
    metadata or file signature, not only by extension. WebP, AVIF, SVG and
    anything else are skipped.
27. Encoder choice: Imagick when it reports WebP write support, otherwise GD
    when `imagewebp` exists and the source is not animated, otherwise none. With
    no encoder, sibling creation is off and Site Health says why (section 11).
28. Quality: photos 82, static GIF 80, animated GIF 72, each filterable by
    kind and source MIME. PNG with transparency keeps alpha.
29. Colour: if the source has an embedded colour profile, convert to sRGB
    before stripping metadata, or keep the profile. Never strip a non-sRGB
    profile without converting (the image shifts colour). CMYK JPEG is
    converted to sRGB or skipped.
30. Animated GIF: all frames are coalesced and written as animated WebP with
    the original timing and loop count. A GIF over the frame limit (default
    300 frames) is skipped.
31. Keep only if it helps. If the sibling is not smaller than the original,
    it is deleted and the original is recorded as "not beneficial" for that
    file, so neither upload nor backfill tries it again until the original
    changes.
32. Writes are atomic: encode to a temporary file in the same directory,
    then rename over the final path. A partial sibling must never be visible
    to a web server or CDN.
33. File mode follows the WordPress `FS_CHMOD_FILE` constant when defined,
    otherwise 0644.
34. Sources above a pixel limit (default 40 megapixels) or a byte limit
    (default 30 MB) are skipped and recorded, to protect memory.
35. Creation is idempotent: an existing sibling whose modification time is
    not older than the original's is left alone and counts as success.
36. A sibling older than its original is stale. It is treated as missing for
    serving and is re-created by the next backfill or explicit generate.
37. Every encode failure is caught. The original is never modified or
    removed. The failure is recorded with a reason and raised as an action.
38. A page request never encodes. Not on a cache miss, not for an
    administrator, not with a query flag. Encoding happens only on upload, in
    the backfill, and in WP-CLI.

### 4.7 WebP siblings: backfill and lifecycle

39. A background backfill runs on its own WP-Cron interval (default every 5
    minutes). It walks image attachments from the newest ID down using a
    stored cursor, creating missing and stale siblings for the configured
    sizes. It wraps to the top when it reaches the oldest and records a full
    pass time.
40. Each run stops starting new encodes once its time budget (default 45
    seconds, filterable, never more than 80% of `max_execution_time` when
    that is set) is spent.
41. Only one backfill runs at a time. A lock with an expiry slightly longer
    than the budget is taken; a run that finds the lock held exits at once.
    A stale lock is broken after expiry.
42. The backfill can be switched off (setting or filter). Upload-time creation
    still runs.
43. When an attachment is deleted, its siblings are deleted with it. Core
    only deletes files it knows about, so without this the siblings are
    orphaned.
44. When an image is edited in the media editor (crop, rotate, scale), the
    new files get siblings on the next backfill or, when the edit regenerates
    metadata, at once. Siblings of files core removed on edit are removed too.
45. When a site regenerates thumbnails with a third-party tool, the upload
    hook runs again and refreshes siblings through the staleness rule.

### 4.8 Serving WebP through `<picture>`

46. On a front-end HTML request, each eligible `<img>` is wrapped:
    `<picture><source type="image/webp" srcset="..." sizes="..."><img ...></picture>`.
    The `<img>` keeps every attribute it had.
47. The `<source>` srcset lists the same candidates in the same order with the
    same width or density descriptors. For each candidate with a fresh
    sibling, the sibling URL is used. For a candidate without one, the
    original URL is kept in that position. This keeps the browser's width
    choice identical to the `<img>`; dropping candidates would push the
    browser to a larger file.
48. If no candidate has a sibling, the tag is not wrapped.
49. An `<img>` with no `srcset` is wrapped with a single-URL `<source>` when
    its `src` has a sibling.
50. The `<source>` carries the final `sizes` value of the `<img>` (after
    correction), or none when the `<img>` has none.
51. Skipped, left exactly as found:
    a. a tag already inside a `<picture>`;
    b. a `src` that is a `data:` URI, an SVG, or already WebP or AVIF;
    c. a candidate URL that does not map to a local file under the uploads
       directory (another host, an image CDN, a query string that changes the
       image such as resize parameters; a plain cache-busting `ver` parameter
       is ignored for mapping);
    d. tags inside `<noscript>`, `<template>`, `<svg>`, `<textarea>` or
       comments;
    e. tags carrying a script lazy loader's placeholder pattern (a
       `data-src` or `data-srcset` attribute and no real candidate list);
    f. tags whose class matches the "do not wrap" class list (default empty,
       filterable), and tags the `srcset_truth_should_wrap` filter rejects;
    g. feeds, REST, AJAX, admin, embeds, sitemaps, AMP endpoints and any
       non-HTML response.
52. URL to path mapping accepts the uploads base URL over `http`, `https` and
    protocol-relative forms, and the site URL when it differs only by scheme.
    The `srcset_truth_url_to_path` filter may map CDN-rewritten URLs back to
    disk paths.
53. Existence checks are memoised for the request. Each candidate path is
    stat-checked at most once per request.
54. When uploads are not on local disk (offloaded to an object store), file
    checks fail and the plugin serves originals: fail open. The
    `srcset_truth_has_sibling` filter lets an integrator answer from its own
    index instead.
55. Two modes:
    a. Filter mode (default): content images, featured images and
       attachment images are handled through core filters.
    b. Buffer mode (opt-in): the whole front-end HTML response is processed
       once before it is sent, which also reaches theme-hard-coded tags. Only
       for front-end GET requests with a 200 status and an HTML content type.
       If the buffer is not well formed enough for the HTML tag processor to
       walk, it is sent unchanged.
56. Markup is generated through the WordPress HTML API (tag processor), not
    regular expressions, so single-quoted, unquoted and upper-case attributes
    are handled.

### 4.9 Lazy loading and LCP (buffer mode only, off by default)

57. When the LCP fix is on, the plugin walks images in document order (not
    counting `<noscript>` content) and picks the first one that looks like a
    content photograph: width at least 600 px, width to height ratio between
    1.2 and 2.2, and no branding marker (`logo`, `avatar`, `gravatar`, `icon`
    in class, id, alt or src). All thresholds and the marker list are
    filterable, and `srcset_truth_lcp_candidate` can veto or force a tag.
58. The chosen image gets `fetchpriority="high"` and `decoding="async"`, and
    no `loading` attribute (eager). Every other image gets `loading="lazy"`
    and `decoding="async"`.
59. Existing `loading`, `fetchpriority` and `decoding` attributes are removed
    first, including duplicates (some themes emit `loading="lazy"` twice on
    one tag). Each tag ends with at most one of each.
60. Exactly one image on the page carries `fetchpriority="high"`. If
    WordPress core or another plugin already set it on a different image, that
    one is downgraded.
61. A preload link is injected before `</head>`:
    `<link rel="preload" as="image" fetchpriority="high" ...>` with
    `imagesrcset` and `imagesizes` mirroring the chosen image (the WebP list
    and `type="image/webp"` when the image was wrapped, otherwise the
    original list). If the page already has an image preload, none is added.
    If there is no `</head>`, nothing is added.
62. If no image qualifies, nothing is made eager and no preload is added.
    The page is no worse than before.
63. An image without `width` and `height` gets them from the local file's
    real dimensions when it maps to one, so the browser can reserve space.
    Remote images are left alone.
64. With the LCP fix off, the plugin never changes `loading`, `fetchpriority`
    or `decoding`, except removing `auto` from sizes as case 6 requires when
    core itself made the image eager.

### 4.10 Interaction with WordPress srcset generation

65. The plugin does not add, remove or reorder `srcset` candidates. It relies
    on core's list (same aspect ratio sizes up to the core maximum srcset
    width).
66. If the corrected `sizes` gives a need smaller than the smallest candidate,
    the browser still picks the smallest candidate. The audit flags this as
    "no candidate near slot" and names the missing width.
67. If the theme filters `srcset` to remove small candidates, correction
    cannot help and the audit says so.
68. When the main file is already WebP (core output format mapping), no
    siblings are made and no `<picture>` is added; sizes correction still runs.

### 4.11 Caches and CDNs

69. The HTML is the same for every client. No `Vary: Accept`, no
    user-agent sniffing, no cookies. The full-page cache stores one copy and
    it is right for every browser.
70. Each format has its own URL, so a CDN caches `.jpg` and `.jpg.webp`
    separately and never serves one in place of the other.
71. The plugin does not purge caches. A sibling created after a page was
    cached appears on that page when the cache entry expires. The
    `srcset_truth_sibling_created` action exists so an integrator can purge if
    it wants to.
72. Site Health checks that a known sibling is served with
    `Content-Type: image/webp` by requesting it over HTTP (same site only,
    short timeout). A wrong type is reported as critical, because some
    browsers refuse to render a WebP sent as `application/octet-stream`.
73. Sibling files are ordinary files in the uploads tree. Any process that
    syncs uploads to an object store must include the `.webp` extension. The
    README says so in bold.
74. If the CDN converts images itself (an edge image optimiser), the operator
    turns off WebP serving and keeps sizes correction.

### 4.12 Audit (before and after)

75. `wp srcset-truth audit <path>` fetches one page of this site (path or
    full URL on the site's own host only; any other host is refused) with an
    anonymous request, and reports per image: slot and template if known,
    the declared sizes, the candidate a browser would pick at the given
    viewport, its bytes, the format served, and flags.
76. Viewport and density come from `--width` (default 412) and `--dpr`
    (default 2; open question 1 asks whether to change it).
77. Before: the candidate the browser would pick under the core default sizes
    for that image's width, in the original format. After: the candidate under
    the emitted sizes, in WebP where the `<source>` offers it. Bytes come from
    the local file size, else from a HEAD request's `Content-Length` on the
    same host, else are reported as unknown and excluded from totals.
78. Media conditions supported by the evaluator: `max-width` and `min-width`
    in px, em (16 px) and the `and` combinator; lengths px, vw, em, rem;
    `calc()` with those units and `+ - * /`. Anything else makes that image
    "unknown" rather than guessed.
79. Flags: `unmapped`, `authored` (left alone), `invalid-spec`,
    `no-candidate-near-slot`, `no-sibling`, `not-beneficial`, `lazy-lcp`
    (the LCP candidate is lazy), `unsized` (no width or height).
80. Output: a table by default, `--format=json` for machines. The last lines
    are totals: before bytes, after bytes, saving in KiB and percent.
81. Exit code 0 on success, 1 if the page could not be fetched or was not
    HTML.

### 4.13 Slot probe (administrators only)

82. When an administrator (capability `manage_options`) loads a front-end page
    with `?srcset-truth-probe=1` and a valid nonce from the admin bar link, a
    small inline script logs to the browser console, for each image: rendered
    CSS width, device pixel ratio, the natural width of `currentSrc`, the
    declared sizes, and a flag when the downloaded file is more than 1.5 times
    the needed width. This is how a developer finds the true slot widths for
    the map.
83. Probe responses send `Cache-Control: private, no-store` so a page cache
    never stores them. Without the capability or nonce the parameter is
    ignored and the page is unchanged.

### 4.14 Failure modes

| Failure | Behaviour | Why |
|---|---|---|
| Invalid sizes spec | Core value kept, logged | A wrong `sizes` can make images blurry; core's value is merely wasteful |
| No slot match | Core value kept | Under-declaring is worse than over-declaring |
| No WebP encoder | Sizes correction only, Site Health warning | Feature degrades, page works |
| Encode throws or runs out of memory budget | Original untouched, reason recorded, action fired | Never risk the original |
| Sibling missing, stale or offloaded | That candidate keeps its original URL | Page never references a file that may not exist |
| HTML tag processor cannot parse a buffer | Buffer sent unchanged | Never break a page to save bytes |
| Backfill lock held | Run exits | Two encoders at once can saturate a small server |
| Backfill over budget | Stops starting encodes, saves cursor | Cron must not become the load |
| Audit cannot fetch the page | Exit 1 with the HTTP status | Clear for scripts |
| `SRCSET_TRUTH_DISABLE` defined true | Plugin registers nothing | One switch for incidents |

All failures fail open: the visitor gets the page WordPress would have sent.

## 5. Public interface

Namespace for PHP: `IndianOpinion\SrcsetTruth`. Hook prefix: `srcset_truth_`.

### 5.1 Plugin

- Main file `srcset-truth.php` with a standard plugin header (Requires at
  least 6.4, Requires PHP 8.1). No Composer at runtime.
- A must-use loader is documented: a one-line file in `mu-plugins` that
  requires the main file from wherever the plugin lives.
- Constant `SRCSET_TRUTH_DISABLE` (bool): when true, nothing is hooked.

### 5.2 Slot API (functions in the namespace)

- `with_slot(string $slot, callable $render): mixed`: pushes the slot,
  calls `$render`, pops the slot even if `$render` throws, returns its
  result.
- `push_slot(string $slot): void` and `pop_slot(): void` for templates that
  cannot wrap a callable. `pop_slot` on an empty stack is a no-op that logs in
  debug mode.
- `current_slot(): ?string`.
- `sibling_path(string $original): string`: the sibling path for a file.
- `sibling_url(string $original_url): ?string`: the sibling URL if a fresh
  sibling exists, else null.
- `compile_sizes(array|string $spec, ?int $image_width = null): string`:
  compile and validate; throws `InvalidSizesSpec` on bad input.
- Slot names: lower case letters, digits and hyphens, 1 to 40 characters.
  Others throw `InvalidSlotName`.

### 5.3 Data shapes

Width map:

```
[
  'default' => [
    'card' => [[767, '92vw'], [1024, '46vw'], [null, '380px']],
    'hero' => '(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px',
  ],
  'single-post' => [
    'content' => [[800, '100vw'], [null, '760px']],
  ],
]
```

A breakpoint pair is `[int|null max_width_px, string length]`. Exactly one
pair has a null breakpoint, and it is last after sorting.

Slot rules (ordered list):

```
[
  ['slot' => 'hero', 'min_width' => 1200],
  ['slot' => 'card', 'size' => ['medium', 'medium_large', 'large']],
  ['slot' => 'thumb', 'class' => 'is-thumb'],
]
```

Keys: `slot` (required), `size` (string or list), `class` (string or list,
any match), `min_width`, `max_width` (px, inclusive).

### 5.4 Filters

| Filter | Value | Extra arguments | Default |
|---|---|---|---|
| `srcset_truth_width_map` | array | template key | preset map or `[]` |
| `srcset_truth_slot_rules` | list | none | preset rules or `[]` |
| `srcset_truth_template` | string | none | computed key |
| `srcset_truth_slot` | ?string | context array | null |
| `srcset_truth_should_correct` | bool | sizes value, context | true for core default only |
| `srcset_truth_sizes` | string | context | the compiled value (last word) |
| `srcset_truth_webp_enabled` | bool | none | setting |
| `srcset_truth_webp_quality` | int 1 to 100 | kind (`photo`, `graphic`, `animated`), MIME | 82, 80, 72 |
| `srcset_truth_webp_sizes` | list of size names | attachment ID | `medium`, `medium_large`, `large` |
| `srcset_truth_should_wrap` | bool | tag HTML, context | true |
| `srcset_truth_skip_classes` | list | none | `[]` |
| `srcset_truth_url_to_path` | ?string | URL | uploads mapping |
| `srcset_truth_has_sibling` | ?bool | original path | null (check disk) |
| `srcset_truth_lcp_enabled` | bool | none | setting |
| `srcset_truth_lcp_rules` | array | none | min width 600, ratio 1.2 to 2.2, brand markers |
| `srcset_truth_lcp_candidate` | ?bool | tag attributes, index | null (use rules) |
| `srcset_truth_backfill_budget` | int seconds | none | 45 |
| `srcset_truth_limits` | array | none | 40 MP, 30 MB, 300 frames |

The context array has: `template`, `slot`, `attachment_id` (int or 0),
`size` (name or `[w, h]`), `image_width`, `src`, `class`, `source`
(`content`, `thumbnail`, `attachment`, `buffer`).

### 5.5 Actions

- `srcset_truth_sizes_corrected` (old value, new value, context)
- `srcset_truth_sibling_created` (original path, sibling path, original
  bytes, sibling bytes)
- `srcset_truth_sibling_failed` (original path, reason code)
- `srcset_truth_backfill_done` (files done, files failed, seconds, cursor)

Reason codes: `no-encoder`, `unsupported-type`, `too-large`,
`too-many-frames`, `not-beneficial`, `encode-error`, `write-error`,
`colour-profile`.

### 5.6 WP-CLI

- `wp srcset-truth audit <path-or-url> [--width=<px>] [--dpr=<n>] [--format=table|json]`
- `wp srcset-truth generate [--all] [--attachment=<id>] [--since=<date>] [--limit=<n>] [--dry-run]`:
  create missing and stale siblings, no time budget.
- `wp srcset-truth status`: encoder, coverage counts (originals in
  scope, fresh siblings, stale, not beneficial, failed), cursor, last
  backfill, lock state.
- `wp srcset-truth map [--template=<key>]`: print the resolved width map and
  slot rules after filters, with the compiled sizes strings and any
  validation errors.
- `wp srcset-truth remove-siblings --yes [--attachment=<id>]`: remove the
  siblings this plugin recorded creating. Never touches originals.

### 5.7 Settings

One section on the Settings, Media screen, through the Settings API
(capability `manage_options`, nonce handled by core): WebP serving on or off;
photo quality; mode (filter or buffer); LCP fix on or off; preset; override
authored sizes; backfill on or off; debug log on or off.

## 6. Data

- One option holding settings (small, autoloaded).
- One option for backfill state (cursor, last run, last full pass), not
  autoloaded.
- One short-lived lock (option or transient with expiry).
- Per attachment, one post meta entry listing sibling basenames created and
  files recorded as not beneficial or failed with reason and source
  modification time. This is what `remove-siblings` and attachment deletion
  use.
- Sibling files on disk next to originals.
- Nothing about visitors. No tables.
- Retention: state lives as long as the attachment. Failed and
  not-beneficial records are re-evaluated when the source modification time
  changes.
- Uninstall: removes both options, the lock, the cron event and the post
  meta. It does not delete sibling files, because cached pages and CDNs may
  still reference them. The README tells operators to run `remove-siblings`
  first, after a cache purge, if they want them gone.
- Deactivation: unschedules the cron event; keeps data.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Sizes correction | On, but no preset | With no map, nothing changes; a wrong guess is worse than core |
| Preset | `none` | `none` or `grid` |
| `grid` preset | `hero` for requested width 1200 px or more: `(max-width: 767px) 100vw, (max-width: 1200px) 90vw, 1200px`; `card` otherwise: `(max-width: 767px) 92vw, (max-width: 1024px) 46vw, 380px` | For card-grid themes: one column on phones, two on tablets, three on desktop. The card max width is filterable |
| Override authored sizes | Off | |
| WebP serving | On when an encoder exists | |
| Photo quality | 82 | Static GIF 80, animated 72 |
| Sizes converted at upload | `medium`, `medium_large`, `large`, main file | The sizes cards and heroes actually use |
| Mode | Filter | Buffer is needed for theme-hard-coded tags and for the LCP fix |
| LCP fix | Off | |
| Backfill | On, every 5 minutes, 45 s budget | |
| Limits | 40 MP, 30 MB, 300 frames | |
| Debug log | Off | Writes through `error_log` only when on and `WP_DEBUG_LOG` is set |

## 8. Security and privacy

- No visitor data is read or stored. No cookies, no tracking, no external
  calls on page views.
- The audit command fetches only the site's own host, refuses redirects to
  other hosts, and uses a short timeout. It runs only in WP-CLI.
- Site Health's content-type probe requests one same-site URL.
- The probe script is printed only for `manage_options` users with a valid
  nonce, and its output goes to the console only.
- Settings use the Settings API with sanitisation callbacks; every value is
  validated against its type and range, and the sizes grammar in case 22.
- All markup is generated through the HTML API; URLs and sizes are escaped.
- File operations stay inside the uploads base directory. Paths are
  normalised and anything resolving outside it is refused. No shell, no
  `eval`, no error suppression operator.
- Image decoding of untrusted uploads goes through Imagick or GD with the
  pixel, byte and frame limits in case 34 and 30.

## 9. Performance budget

- Page request, filter mode: under 0.2 ms per image excluding file stats; at
  most one stat per candidate per request; spec compilation cached per
  request.
- Page request, buffer mode: one pass of the tag processor over the HTML; on
  a 500 KB page with 150 images, under 30 ms on a single modern core.
- Zero encodes during page requests (case 38).
- Upload: about one encode per configured size plus the main file, typically
  under half a second in total for a photo.
- Backfill: one run at a time, bounded by its budget.
- Memory: never load more than one source image at a time.

Lesson that set this budget: encoding on demand during page renders took
several seconds on an image-heavy home page, and with a cache warmer running
it piled up PHP workers until the server swapped.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later (the HTML tag processor and the
  content image filter are required).
- WordPress 6.7 or later adds `auto` to sizes; handled by case 6. Earlier
  versions simply never emit it.
- Classic and block themes. Block images go through the content image filter.
- Encoders: Imagick built with WebP; GD with WebP for static images only.
- Web servers: any that serve `.webp` as `image/webp` (nginx and Apache do in
  current default MIME tables; older ones may need a line added).
- Page caches: any. Output is the same for every anonymous visitor.
- CDNs: any. No `Vary` header is needed.
- Other plugins: script lazy loaders (skipped by case 51e), other WebP
  plugins that already wrap in `<picture>` (skipped by case 51a), image CDN
  plugins that rewrite to remote URLs (skipped by case 51c). Turn off this
  plugin's WebP serving if another plugin already does it.
- Multisite: works per site; settings and state are per site.

## 11. Observability

- `wp srcset-truth status` for coverage and backfill state.
- `wp srcset-truth audit` for the before-and-after weight of any page.
- `wp srcset-truth map` to see what the filters resolved to.
- Site Health: encoder present; content type of `.webp` correct; backfill
  ran in the last hour; share of in-scope originals with a fresh sibling.
- Debug log (opt-in): one line per invalid spec, unknown slot, encode
  failure and backfill run, each prefixed `[srcset-truth]`.
- The actions in 5.5 for anyone wiring metrics.
- The admin probe (4.13) for front-end verification.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer; encoder tests use small fixture images and are skipped with a clear
message when the encoder is absent.

1. Core default with and without `auto, ` and with extra spaces is detected
   as eligible (1).
2. A theme-authored value is left alone; with the override setting or filter
   it is replaced (2).
3. Template `front`, slot `card`, map entry present: the compiled value
   replaces the core value (3).
4. No slot match returns the core value and counts unmapped (4).
5. An image without `srcset` keeps its attributes (5).
6. Lazy image keeps `auto, ` at the front; an eager image loses it (6, 64).
7. A spec wider than the image's own width is capped at that width (7).
8. Running filter mode then buffer mode on the same tag changes it once (8).
9. Buffer mode corrects a hard-coded tag using its `width` attribute (9).
10. Content, thumbnail and attachment paths give the same output for the
    same image and context (10).
11. Each conditional combination maps to the right template key (11, 14).
12. Fallback chain `single-post`, `single`, `default` and the taxonomy chain
    resolve in order (12, 19).
13. The template filter overrides the key; an empty return falls back (13).
14. Nested `with_slot` gives the inner slot and restores the outer, also when
    the callable throws (15a, 5.2).
15. Slot rules match on size name, class and width bounds, first rule wins,
    all conditions must hold (15b).
16. The slot filter is consulted after rules (15c).
17. Unknown slot name is unmapped and logged once (16).
18. Unbalanced push is closed at shutdown and logged in debug (17).
19. Breakpoint list compiles in ascending order, sorts, de-duplicates and
    accepts `*` as `default` (18, 20).
20. Compilation runs once per (template, slot, width) per request (21).
21. Grammar: valid strings pass; `%` lengths, missing default length,
    misplaced `auto`, unbalanced `calc(`, quotes and angle brackets fail (22, 24).
22. An invalid spec keeps the core value and logs (23).
23. Upload creates siblings for the configured sizes and the main file only
    (25).
24. A WebP, SVG or mislabelled file is skipped by real type (26).
25. Encoder selection: Imagick, GD fallback, none; Site Health reports none (27).
26. Quality filter receives kind and MIME; PNG alpha survives (28).
27. A wide-gamut JPEG fixture keeps correct colour (profile kept or converted) (29).
28. Animated GIF becomes animated WebP with the same frame count; over the
    frame limit is skipped (30).
29. A sibling larger than its source is removed and recorded; the next run
    does not retry it (31).
30. No partial file is visible at the final path during a simulated write
    failure (32).
31. File mode follows `FS_CHMOD_FILE` (33).
32. Over-limit source is skipped with `too-large` (34).
33. Second call with a fresh sibling does nothing (35).
34. A sibling older than its source is not served and is re-created by
    generate (36).
35. An encoder exception leaves the original byte-identical and fires the
    failure action (37).
36. A front-end request with no sibling never calls the encoder (38).
37. Backfill walks from the newest ID with a cursor and wraps (39).
38. Backfill stops at its budget and respects the execution time cap (40).
39. A held lock makes a second run exit; an expired lock is broken (41).
40. Backfill off: no cron run, upload still converts (42).
41. Deleting an attachment deletes its siblings (43).
42. Editing an image in the media editor leads to siblings for the new files
    and removal for deleted ones (44, 45).
43. Wrapped markup has `<picture>`, `<source type="image/webp">` and the
    untouched `<img>` (46).
44. Mixed coverage: missing candidates keep the original URL in position,
    descriptors unchanged (47).
45. No coverage: no wrap (48). Single `src` with a sibling: single-URL source
    (49).
46. `<source>` carries the corrected sizes (50).
47. Each skip rule in 51a to 51g leaves the tag byte-identical.
48. URL mapping handles http, https, protocol-relative, `ver` parameters
    and the mapping filter; resize parameters are not mapped (52, 51c).
49. A page with the same candidate twice stats it once (53).
50. With uploads absent from disk the page is unchanged; the sibling filter
    can answer instead (54).
51. Filter mode leaves hard-coded tags alone; buffer mode reaches them;
    a non-HTML or non-200 response is untouched; an unparseable buffer is
    sent unchanged (55).
52. Single-quoted, unquoted and upper-case attributes are handled (56).
53. LCP pick skips a 700 x 135 logo and a small thumbnail and picks the first
    16:9 photo; filter can force or veto (57).
54. Chosen image eager with high priority; others lazy; duplicate attributes
    collapsed (58, 59).
55. A second `fetchpriority="high"` is downgraded (60).
56. Preload has `imagesrcset`, `imagesizes` and WebP type when wrapped; none
    when a preload exists or `</head>` is missing (61).
57. No qualifying image: no changes (62).
58. Missing dimensions are filled from a local file; remote left alone (63).
59. LCP fix off: loading attributes untouched (64).
60. `srcset` candidates are identical before and after (65).
61. Audit flags `no-candidate-near-slot` when the smallest candidate exceeds
    the need, and when small candidates were filtered out (66, 67).
62. A WebP main file gets no wrap but does get sizes correction (68).
63. Two anonymous renders produce identical HTML and no `Vary` header is
    added (69, 70).
64. No cache purge is attempted; the created action fires (71).
65. Site Health reports a wrong content type as critical (72).
66. Audit refuses another host and redirects to another host (75, 8).
67. Audit before and after picks match hand-computed choices at 412 px and
    DPR 2 for the worked example in section 1 (76, 77).
68. Evaluator handles `max-width`, `min-width`, `and`, em, `calc()`; an
    unsupported condition gives `unknown` (78).
69. Each flag appears for a fixture page built to trigger it (79).
70. JSON output validates against a documented shape; totals are correct;
    exit code 1 on a 404 (80, 81).
71. Probe script appears only for `manage_options` with a valid nonce, and
    sends `private, no-store` (82, 83).
72. `SRCSET_TRUTH_DISABLE` registers no hooks (4.14).
73. Uninstall removes options, lock, cron event and meta, and leaves files;
    `remove-siblings` removes only recorded siblings (6).
74. Settings sanitisation rejects out-of-range quality and invalid modes (8).

## 13. Open questions

Answer each in `docs/ARCHITECTURE.md`.

1. Default `--dpr` for the audit: 2, 2.625 or 3? Lighthouse's mobile
   emulation uses a fixed device; pick one, say why, and show it in the output.
2. Should the per-attachment record live in post meta (as specified) or be
   derived from the file system on demand? Meta makes deletion exact; the
   file system avoids drift. Decide and document the drift handling.
3. Does wrapping in `<picture>` break common theme CSS (`.card > img`, flex
   children)? Decide whether to ship an opt-in stylesheet (`picture {
   display: contents }`) and what it costs.
4. AVIF as a second `<source>`: worth it in 0.2.0? Note the encode cost and
   encoder support.
5. Buffer mode and other output buffers (caching plugins also buffer): which
   priority and nesting is safe, and how to detect a conflict?
6. Should the `grid` preset read the theme's content width global for the
   `content` slot, or stay out of single-post layouts entirely?

## 14. Lessons from production

1. Fix `sizes` before touching formats. It needs no new files and on a card
   grid it is the bigger saving. Regenerating derivatives for a large library
   is often not affordable in disk or CPU.
2. Never encode during a page request. On demand encoding on an image-heavy
   page took seconds and, with a cache warmer, exhausted the server. Pages
   only check; upload and the backfill do the work.
3. Do not rely on the backfill for new images. A site that publishes all day
   puts new images on the home page before the sweep reaches them, so page
   weight varies between loads. Encode the served sizes at upload; the
   backfill is only for the old library.
4. The backfill must walk the whole library with a cursor. A sweep that only
   looks at the current month's folder never covers older images that are
   still on the page.
5. Do not drop a WebP candidate because one sibling is missing, and do not
   abandon the whole `<picture>` either. Requiring every candidate let one
   new image switch WebP off for its tag and page weight silently went back up.
   Keep the original URL in that slot of the list.
6. Use `<picture>`, not `Accept` negotiation. Common CDNs do not vary their
   image cache on `Accept`, so a negotiated URL can hand WebP to a client that
   cannot render it.
7. Theme-rendered originals hurt most. One animated GIF original was 61% of a
   home page's payload because the theme printed the full file. Animated WebP
   cut such files by roughly 90% with the animation intact. Convert GIFs, keep
   the animation, and keep only results that are smaller.
8. Under-declaring `sizes` is worse than over-declaring. With no map entry,
   leave core's value; a guess that is too small makes images blurry on real
   devices.
9. The first image in the document is rarely the LCP image. Logos and ticker
   thumbnails come first. Width alone is not enough (a wide logo passes a
   600 px test); require a photo-like aspect ratio and exclude branding.
10. Spending the priority hint on the wrong image left the real hero lazy,
    and load delay was nearly half of a five-second LCP. `fetchpriority` alone
    only reorders a request the browser has already found; pair it with a
    preload in the head.
11. Themes duplicate attributes. Strip every `loading`, `fetchpriority` and
    `decoding` before writing one of each.
12. `sizes="auto"` is only valid with lazy loading. If you make an image
    eager, remove it.
13. Every image needs width and height or the layout shifts. When a theme
    omits them, read them from the file, not from a guess.
14. Two originals with the same stem (`photo.jpg`, `photo.png`) collide if the
    sibling replaces the extension. Append `.webp` instead.
15. Write siblings atomically. A CDN that fetches a half-written file caches it
    for the full TTL.
16. Core deletes only the files it knows about. Delete siblings with their
    attachment or they pile up forever.
17. Anything that syncs uploads elsewhere must include `.webp`, or the page
    links to files the media host does not have.
