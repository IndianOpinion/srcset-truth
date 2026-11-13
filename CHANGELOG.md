# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Planned

- AVIF as a second `<source>` (deferred from 0.1.0: AVIF encodes are several
  times slower than WebP and encoder support is patchy).

## [0.1.0] - 2026-11-13

### Added

- Sizes correction: detects core's default `sizes`, resolves a template key
  and a slot, and replaces the value from a width map supplied by filters or
  the `grid` preset. Validated grammar, capping at the image width, the
  `auto` rule, one classifier for every path.
- Slot API: `with_slot()`, `push_slot()`, `pop_slot()`, `current_slot()`,
  `sibling_path()`, `sibling_url()`, `compile_sizes()`.
- WebP siblings at upload for the main file and `medium`, `medium_large`,
  `large`, with Imagick or GD: real-type detection, alpha, colour profiles,
  animated GIFs, atomic writes, size and frame limits, keep-only-if-smaller.
- Background backfill: cursor from the newest ID, 45 s budget, single-run
  lock, full-pass tracking.
- Serving: `<picture>` wrapping with a WebP `<source>` (default), so browsers
  without WebP fall back to the untouched `<img>`; type-aware in-place
  rewriting of `srcset` and `src` is an opt-in (`srcset_truth_picture_wrap`
  returning `false`) because it breaks images in those browsers. Images
  printed with `wp_get_attachment_image()` are wrapped through its output
  filter.
- Buffer mode for theme-hard-coded tags, and an opt-in LCP fix with preload.
- WP-CLI: `audit`, `generate`, `status`, `map`, `remove-siblings`.
- Site Health checks, an administrator slot probe, a Settings, Media section.
- `SRCSET_TRUTH_DISABLE` constant.

[Unreleased]: https://github.com/IndianOpinion/srcset-truth/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/srcset-truth/releases/tag/v0.1.0
