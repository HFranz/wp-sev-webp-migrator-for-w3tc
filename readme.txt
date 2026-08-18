=== SEV WebP Migrator for W3TC ===
Contributors: hfranz
Tags: webp, images, w3-total-cache, performance, optimization
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replaces image URLs with their WebP versions once W3 Total Cache ImageService converts them, with optional deletion of the originals.

== Description ==

SEV WebP Migrator for W3TC is a companion plugin for [W3 Total Cache](https://wordpress.org/plugins/w3-total-cache/) that finishes the job once W3TC ImageService has converted an image to WebP.

No changes to `.htaccess`, `mod_rewrite`, or web server configuration are required. Instead, it writes the replacement back into the database **once**, unlike a runtime content filter that swaps URLs on every request: as soon as W3TC marks an attachment as converted, every occurrence of that image's URL in `post_content` across the whole site is permanently replaced, from its original extension (jpg/jpeg/png/gif) to `.webp`. The attachment's own record (attached file, metadata, mime type) is updated to match, so the Media Library and REST API stay consistent too.

Optionally, once an image has been fully replaced, the plugin can delete the now-unused original source files from disk to reclaim storage space.

This is an independent, unofficial add-on and is not affiliated with, endorsed by, or sponsored by BoldGrid / W3 EDGE, the makers of W3 Total Cache. "W3 Total Cache" is a trademark of its respective owner and is used here only to describe compatibility.

**Features**

* Listens for W3TC ImageService conversions and reacts automatically.
* Replaces every reference to the converted image (full size and all intermediate sizes) in `post_content`, across all posts.
* Updates the attachment's own file reference, metadata, and mime type to match.
* Optional: deletes the original source files after a successful replacement.
* A source file is only ever deleted once its `.webp` counterpart has been confirmed to exist on disk.
* Manual "process now" tool in **Settings → WebP Migrator for W3TC** for images that were converted before this plugin was active.

**How it works**

1. W3 Total Cache ImageService converts an image and marks the attachment's `w3tc_imageservice` post meta as `converted`.
2. This plugin detects that meta change and looks up every file W3TC generated for the attachment (full size and each registered thumbnail size).
3. It scans `post_content` across all posts for the old URLs and replaces them with the `.webp` versions directly in the database.
4. The attachment's own `_wp_attached_file`, `_wp_attachment_metadata`, and `post_mime_type` are updated to point at the `.webp` files.
5. If enabled in the settings, the original files (jpg/jpeg/png/gif) are deleted from disk.

**Requirements**

* W3 Total Cache must be installed and active.
* Images must be converted using **Media → W3TC Image Service**; this plugin does not perform any conversion itself.

**Limitations**

This plugin **does not convert images** to WebP — conversion is handled entirely by W3 Total Cache ImageService. It only reacts once W3TC reports an image as converted.

This plugin only handles **WebP**. AVIF is not supported: W3 Total Cache only offers AVIF conversion in its paid Pro version, and this plugin's free-tier ImageService integration only ever sees WebP conversions.

Deleting source images is permanent and cannot be undone by this plugin. Keep backups before enabling automatic deletion.

== Installation ==

1. Upload the `sev-webp-migrator-for-w3tc` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure W3 Total Cache is installed and active.
4. Optionally enable "Delete source images" under **Settings → WebP Migrator for W3TC**.
5. For images W3TC already converted before activation, use the "process now" tool on the same settings page.

== Frequently Asked Questions ==

= Does this plugin convert images to WebP? =

No. Image conversion is handled entirely by W3 Total Cache ImageService. This plugin only replaces already-converted images' URLs, permanently, in post content and in the attachment's own record.

= How is this different from "SEV Rewrite-Free WebP for W3TC"? =

The rewrite-free plugin swaps URLs on the fly for browsers that support WebP, leaving the original content and files untouched. This plugin instead permanently replaces the stored content and, optionally, removes the original files — useful once you no longer need to serve the original format to any visitor.

= Is deleting source images safe? =

A source file is only deleted once its `.webp` counterpart has been confirmed to exist on disk, after post content and the attachment record have already been updated. Deletion is still permanent and disabled by default — enable it deliberately, and keep backups.

= Does it work with WordPress Multisite installations? =

Yes. Each site processes its own W3 Total Cache ImageService conversions independently.

== Screenshots ==
screenshot-1.png
screenshot-2.png
screenshot-3.png

== Changelog ==

= 2.0.9 =
* Fixed attachments getting permanently stuck when their attached file was already renamed to .webp (e.g. from an interrupted earlier migration) but `post_mime_type` was never updated to match, so the plugin kept treating them as unconverted while being unable to build a rewrite pair for an already-.webp URL. Such attachments are now recognized and their mime type/metadata is corrected.

= 2.0.8 =
* Improved the diagnostic log message for attachments skipped because no usable URL/extension was found, to distinguish `wp_get_attachment_url()` returning nothing at all from it returning a URL whose extension isn't recognised as convertible - these point at different underlying problems and the previous single generic message didn't say which one applied.

= 2.0.7 =
* Fixed original image files being deleted while still referenced from a widget (e.g. a Custom HTML/Text widget) or a theme mod (e.g. a Customizer background image), since only `wp_posts.post_content` was ever searched for references. Both are now also checked and rewritten (safely, via get_option()/update_option() rather than a raw string replace, since their values are serialized).

= 2.0.6 =
* Fixed original image files being deleted while still referenced from content that used a root-relative or protocol-relative URL (e.g. `url(/wp-content/uploads/...)` in the Customizer's "Additional CSS"), because only the full absolute URL was searched for and replaced. Root-relative and protocol-relative variants are now checked as well.

= 2.0.5 =
* Fixed attachments getting stuck reporting "0 images processed" despite their post content being successfully rewritten to working WebP URLs. Attachment_Migrator was independently re-predicting the WebP path with the older, simpler logic instead of reusing what Processor had already resolved (including the 2.0.4 child-attachment fallback), so it could fail to find the file and leave the attachment marked unmigrated forever.

= 2.0.4 =
* Fixed images getting permanently stuck when the full-size WebP file was missing from its predicted path despite W3 Total Cache marking the image "converted" (e.g. after a later re-conversion job deleted and replaced it). The plugin now also checks W3TC's own "child attachment" record for the converted file, which is authoritative regardless of the file's actual path on disk.

= 2.0.3 =
* Fixed the "Process Previously Converted Images" batch silently finding 0 images to process (with the remaining count stuck at the same number and nothing logged) when attachments with a non-"converted" w3tc_imageservice status happened to sort ahead of converted ones by ID. The status is now filtered at the database query level instead of after the batch size limit was already applied.

= 2.0.2 =
* Fixed images getting permanently stuck as "not yet processed" when W3 Total Cache's ImageService silently skips generating one intermediate size (e.g. Site Icon sizes registered only in certain admin contexts). The missing size is now regenerated from the already-converted full-size WebP instead of waiting forever for an event that never comes.
* Added diagnostic logging (behind WP_DEBUG_LOG) explaining why an image was skipped during processing, to help diagnose batches that make no progress ("N images remaining" stuck at the same count).

= 2.0.1 =
* Tested up to WordPress 7.1 and W3 Total Cache 2.10.2.

= 2.0.0 =
* Improved compatibility with W3 Total Cache ImageService image conversions.
* Added an option to automatically delete original images after successful conversion.
* Various code improvements.

= 1.0.0 =
* Initial release.
