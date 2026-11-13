# srcset-truth: architecture

How 0.1.0 is built, and every decision taken on the way. The behaviour is
specified in [PRD.md](PRD.md); case numbers below refer to it.

## Modules

| Path | Role |
|---|---|
| `srcset-truth.php` | Plugin header, the `SRCSET_TRUTH_DISABLE` switch, loader, activation hooks |
| `src/autoload.php`, `src/functions.php` | Class loader (no Composer at runtime) and the public slot API (5.2) |
| `src/Plugin.php` | All hook wiring, buffer start and callback, audit token, per-request reset |
| `src/Settings.php` | The one settings option, defaults, normalisation, Settings API sanitisation |
| `src/Request.php`, `src/Response.php`, `src/Http.php`, `src/Logger.php` | Request type, header sink, HTTP helpers, opt-in debug log (once per key per request) |
| `src/Sizes/` | `CoreDefault` (detect core's value), `Grammar` (case 22), `Compiler` (spec to string, cap), `Evaluator` (audit maths, case 78) |
| `src/Layout/` | `TemplateResolver` (4.2), `SlotStack` and `SlotResolver` (4.3), `WidthMap` (4.4), `Presets` |
| `src/Correction/Corrector.php` | The one classifier every path shares (case 10) |
| `src/Markup/` | `ImageProcessor` (all per-image rules), `FilterMode` (three core filters), `BufferProcessor` (buffer mode and LCP), `WebpPlanner`, `Wrapper` (`<picture>`), `Lcp`, `Srcset`, `Attributes` |
| `src/Files/` | `Uploads`, `PathMapper` (URL to path), `Siblings` (naming, freshness), `ImageType` (signatures, frames, colour), `SkipLedger`, `AttachmentFiles` |
| `src/Encoding/` | `Encoder` interface, `ImagickEncoder`, `GdEncoder`, `EncoderFactory`, `Limits`, `SiblingCreator` |
| `src/Lifecycle.php` | Upload, edit and delete hooks |
| `src/Backfill/` | `Backfill` (cron, cursor, lock, budget), `AttachmentSource` and its `$wpdb` implementation |
| `src/Audit/` | `Auditor` (fetch and analyse), `Picker` (candidate choice) |
| `src/Cli/Command.php` | `wp srcset-truth audit|generate|status|map|remove-siblings` |
| `src/Admin/` | Settings section, slot probe, Site Health checks |
| `uninstall.php` | Removes options, lock, cron event, legacy meta; keeps sibling files |

## Data flow

Page request, filter mode:

```
wp_content_img_tag / post_thumbnail_html / wp_get_attachment_image_attributes
  -> Request::isFrontHtml()            (else unchanged)
  -> ImageProcessor::process()         (tag processor paused on the IMG)
       -> Corrector::correct()         core default? template, slot, map, compile, cap, validate, auto rule
       -> WebpPlanner::plan()          skip rules, URL -> path, Siblings::isFresh() (memoised stat)
       -> rewrite srcset/src in place  (default)  or  mark for <picture> (opt-in)
  -> Wrapper::apply()                  only when wrapping
```

Page request, buffer mode: `template_redirect` starts an output buffer; the
callback hands a complete 200 HTML response to `BufferProcessor`, which walks
it once with the tag processor and calls the same `ImageProcessor`. With the
LCP fix on, a read-only walk runs first to find the LCP index.

Upload: `wp_generate_attachment_metadata` and `wp_update_attachment_metadata`
call `Lifecycle::createFor()`, which runs `SiblingCreator::create()` for the
main file and the configured sizes. Cron: `Backfill::run()` walks IDs from the
newest down with a stored cursor. Delete: `delete_attachment` and
`wp_delete_file` remove siblings.

## Decisions

### Rulings on the PRD's open questions

1. **Audit default DPR is 2.** Ruling. It is a common phone density, it is
   what the PRD's worked example uses, and with `--width=412` it reproduces the
   numbers in section 1 by hand. The table output prints the viewport and DPR
   on its second line, and the JSON carries them under `viewport`.
2. **Sibling records are derived from disk, not stored in post meta.** Ruling.
   A sibling exists when `<original>.webp` exists and is not older than the
   original. Deletion uses the attachment's metadata (main file, every size,
   the pre-scaling original, backup sizes from edits) and removes the
   `.webp` next to each. Drift handling: there is nothing to drift, because
   the file system is the record. A sibling made by something else with the
   same name is treated as ours; the `.jpg.webp` naming makes that unlikely.
   Negative results (not beneficial, failed) cannot be read from disk, so
   they live in one non-autoloaded option keyed by the path under uploads,
   with the source's modification time and size. A changed original no
   longer matches its entry and is tried again. The option is capped at
   10,000 entries (oldest dropped first), so the worst case is a retry, never
   unbounded growth. `no-encoder` is not recorded, because it is a property
   of the server, not the file.
3. **`<picture>` wrapping is opt-in behind `srcset_truth_picture_wrap`,
   default off.** Ruling. The default path is type-aware srcset rewriting:
   each candidate of the `<img>` (and `src`) whose original is a JPEG, PNG or
   GIF with a fresh sibling gets the sibling URL, in place, with its
   descriptor; every other candidate keeps its URL. The theme's markup and
   CSS are untouched, which answers the CSS question for most sites. Cost:
   browsers without WebP (Safari before 14) get images they cannot render;
   sites that care turn wrapping on. When wrapping is on, the plugin prints
   `picture.srcset-truth{display:contents}` (filter
   `srcset_truth_picture_css`) so flex and grid children keep their layout.
   Child selectors like `.card > img` still stop matching; that is
   documented, not hidden.
4. **AVIF is out of 0.1.0** and noted under Unreleased in the changelog.
   Ruling. For 0.2.0: AVIF encodes are several times slower than WebP,
   Imagick needs libheif with AVIF write support and GD only has it from PHP
   8.1 with libavif, so it would be a second `<source>` (needs wrapping) and
   a backfill twice as long.
5. **Buffer mode is off by default; filter-based rewriting is the
   default.** Ruling. Buffer start: `template_redirect` priority 1. Caching
   plugins start their buffers earlier (in `advanced-cache.php` or on
   `init`), so ours nests inside theirs and their cache stores our output.
   Conflict detection: the callback rewrites only when it receives the whole
   response in one call (`PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL`).
   If anything flushes early, the rest of that response passes through
   untouched and a debug line says so. Only GET, status 200 and an HTML
   content type are processed. A buffer that ends inside a tag (or that
   WordPress 6.5+ reports as incomplete) is sent unchanged.
6. **The grid preset reads the theme content width when set, otherwise
   1200.** Ruling. The `content` slot is `(max-width: Wpx) 100vw, Wpx` with W
   from `$content_width`, under the `single` and `page` keys, and the
   preset's first rule sends images from post content to it. On those keys
   `card` uses the same value, so a featured image painted wide on a single
   post is never declared at 380 px (lesson 8). The capping rule (case 7)
   still applies.

### Builder decisions (no ruling given)

7. **Template order.** `attachment` and `page` are checked before
   `single-{post_type}`, because `is_single()` is true for attachments and
   the PRD wants an `attachment` key. `author` and `date` fall back straight
   to `default`, as case 12 says.
8. **Capping non-px lengths.** Case 7 caps the final length. A px length
   above the image width becomes the width; any other final length `L`
   becomes `min(L, Wpx)`. The evaluator supports `min()`, `max()` and
   `clamp()` so the audit can read these.
9. **Recognising our own output (case 8).** Values the plugin produced in a
   request are remembered (without `auto`). A tag carrying one is not
   corrected again; only the `auto` rule can still apply.
10. **The `auto` rule.** A lazy tag whose original value had `auto, ` keeps it
    in front of the corrected value. Any eager tag loses `auto`, whether or
    not it was corrected (cases 6, 64). On WordPress 6.7+ core may add
    `auto` after the attribute filter; that is correct for lazy images.
11. **Slot rule key `source`.** Rules may also match on the context's
    `source` (`content`, `thumbnail`, `attachment`, `buffer`). The grid
    preset needs it; it is otherwise optional.
12. **Wrapping without regular expressions.** The tag processor cannot
    insert elements, so `ImageProcessor` adds a numbered marker attribute.
    The processor always writes new attributes straight after the tag name,
    so the marker sits at a known offset from `<img`; the tag end is found
    with a quote-aware scan. On any mismatch the marker is removed and the
    tag stays unwrapped.
13. **Attachment attribute path.** The attribute array is turned into a
    single tag, run through the same `ImageProcessor`, and read back, so it
    cannot disagree with the other paths. It never wraps (an array cannot
    hold a `<picture>`); with wrapping on it corrects sizes only.
14. **LCP pass.** When the LCP fix is on, one extra read-only walk finds the
    candidate first, so a page with no candidate is left exactly as it was
    (case 62). Without the fix there is one walk.
15. **Missing dimensions (case 63)** are filled only with the LCP fix on (the
    case sits in 4.9), from the local file. If one of the two is present, the
    other is computed from the file's aspect ratio.
16. **Audit annotation.** The audit sends `X-Srcset-Truth-Audit`, an HMAC over
    a five-minute window keyed by the site's nonce salt. A request carrying a
    valid token gets `data-srcset-truth="template|slot|status"` on each image
    and `Cache-Control: private, no-store`. Without it (for example a
    full-page cache hit) the audit infers `unmapped` from a core default and
    `authored` from a value the current map would not produce;
    `invalid-spec` needs the annotation.
17. **Audit pick rule.** The smallest candidate whose density reaches the DPR,
    else the largest (the specification's rule). `no-candidate-near-slot` is
    raised when the chosen candidate is more than 1.5 times the needed device
    width, and names that width.
18. **Colour.** Imagick keeps an embedded ICC profile in the WebP (strip
    metadata, put the profile back) and converts CMYK to sRGB. GD cannot keep
    a profile, so it refuses non-sRGB profiles and CMYK with `colour-profile`;
    an sRGB profile is dropped safely.
19. **Animated GIFs** are counted by graphic control extensions before
    decoding, so the frame limit costs no memory. GD never writes animation;
    with GD only, animated GIFs fail with `no-encoder`.
20. **Lock.** `add_option` fails when the row exists, which makes it an
    atomic test-and-set without a new table. A lock past its expiry (budget
    plus 15 s) is deleted and taken again.
21. **Upload-time work runs on `wp_update_attachment_metadata` too**, so media
    editor saves and thumbnail regeneration refresh siblings at once; fresh
    siblings cost a stat. `wp_delete_file` removes the sibling of any file
    core deletes, which covers files the editor replaces.
22. **Uninstall** also deletes a `_srcset_truth` post meta key. 0.1.0 never
    writes it; the line keeps the PRD's promise for any build that did.
23. **Static analysis.** PHPStan level 8 over `src/` with
    `treatPhpDocTypesAsCertain: false`, because hook arguments come from
    other code and the runtime checks must stay.
24. **Tests** run against the real WordPress 6.4 HTML tag processor (the
    oldest supported version) loaded from the `johnpbloch/wordpress-core`
    dev dependency, with a small stub layer for everything else. Encoder
    tests use real Imagick and GD and skip with a message when absent.

## Performance notes

- Spec compilation is cached per template, slot and image width per request.
- Each candidate file is stat-checked once per request (`Siblings::$stats`
  counts real checks).
- No encode can happen during a page request: only `SiblingCreator` encodes,
  and it is called from upload hooks, the backfill and WP-CLI.
