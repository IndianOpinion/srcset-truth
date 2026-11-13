=== srcset-truth ===
Contributors: indianopinion
Tags: srcset, sizes, webp, responsive images, performance
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Corrects the sizes attribute for images painted into fixed slots, serves WebP siblings, and audits page image weight.

== Description ==

WordPress tells the browser that every image is as wide as the screen, so phones download far larger files than the slot needs. srcset-truth replaces that `sizes` value with the real width of the slot (from a width map your theme supplies, or the card-grid preset), writes WebP copies next to the originals at upload and in a background backfill, and serves them with the same URLs for every visitor.

* One classifier for content images, featured images, attachment images and (in buffer mode) theme-hard-coded tags.
* WebP siblings named `photo.jpg.webp`, created only when smaller, never during a page request.
* Optional LCP fix: the likely hero image is eager with high priority and preloaded.
* `wp srcset-truth audit` reports the before-and-after weight of any page.

Nothing changes until a width map exists: with no map, core's value is kept.

Important: anything that syncs uploads to an object store must include the `.webp` files. Behind a CDN that converts images itself, turn off WebP serving and keep sizes correction.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the `srcset-truth` folder to `/wp-content/plugins/`, or install it with Composer as `indianopinion/srcset-truth`.
2. Activate the plugin.
3. Pick a preset under Settings, Media, or add a width map with the `srcset_truth_width_map` filter. See the README on GitHub.

To run it as a must-use plugin, add `wp-content/mu-plugins/srcset-truth-loader.php` containing `<?php require WPMU_PLUGIN_DIR . '/srcset-truth/srcset-truth.php';`.

== Frequently Asked Questions ==

= Nothing changed after activation =

Supply a width map or pick the grid preset. Without one, the plugin keeps core's value on purpose.

= Does it encode images on page views? =

No. Encoding happens on upload, in the backfill and in WP-CLI only.

= How do I remove the WebP files? =

Purge your caches, then run `wp srcset-truth remove-siblings --yes`. Uninstall keeps the files because cached pages may still use them.

== Changelog ==

= 0.1.0 =
* First release.
