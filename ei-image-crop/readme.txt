=== Ei Image Crop ===
Contributors: eborninteractive
Tags: acf, image, crop, media, aspect ratio
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An ACF field type for cropping images on demand, with reusable, editable crops that live as regular Media Library attachments.

== Description ==

Ei Image Crop replaces the "select image + crop at upload" workflow of plugins like Advanced Custom Fields: Image Aspect Ratio Crop with a lazier, more reusable one:

* **No cost at upload.** Nothing is cropped until a field using this crop is actually saved. If you never open the cropper, a center-center crop is generated automatically from the configured aspect ratio.
* **Crops are real attachments.** Every generated crop lives in the Media Library like any other image, with its own registered image sizes, so it can be reused across posts and fields.
* **Reuse instead of duplicate.** Cropping the same source image to the same ratio and box reuses the existing crop attachment instead of creating a new one. The field also shows existing crops of the current source image for one-click reuse.
* **Adjustable afterwards.** Re-opening a crop and adjusting it overwrites the same attachment ID, so every place that crop is referenced updates automatically.
* **Familiar cropping UI.** Built on Cropper.js: the original image is shown with a crop frame that scales while keeping a locked aspect ratio, or resizes freely when the field has no fixed ratio, plus a live preview of the result.
* **Tidy Media Library.** Generated crops are hidden from the Media Library grid and list view by default, behind a "Show generated crops" toggle.

= Requirements =

* Advanced Custom Fields (or ACF PRO), any recent version with the field type API (5.x / 6.x).

= Data model =

Each crop is stored as its own attachment with this meta:

* `_ei_crop_parent` – attachment ID of the source image.
* `_ei_crop_ratio` – ratio label, e.g. `16:9` or `free`.
* `_ei_crop_box` – normalized `x`/`y`/`w`/`h` (0–1) crop box, relative to the source image's own proportions. Using fractions instead of pixels means the crop survives `-scaled` copies of large uploads and image regeneration.
* `_ei_crop_hash` – hash of parent + ratio + box, used to detect and reuse identical crops.

The ACF field itself stores only the resulting crop's attachment ID, and `format_value()` returns the same array shape as ACF's native Image field, so existing templates built against `get_field()` output don't need to change.

== Installation ==

1. Upload the `ei-image-crop` folder to `/wp-content/plugins/`.
2. Activate the plugin. Advanced Custom Fields must already be active.
3. Add a field of type "Image Crop" to any field group, and configure its aspect ratio, admin preview size, library and return format.

== Frequently Asked Questions ==

= Can a field have a free-form (unlocked) aspect ratio? =

Yes — set "Aspect ratio" to "Free" in the field settings. The crop frame can then be resized independently on each axis instead of only scaled.

= What happens to a crop's file if I delete the source image? =

Every crop generated from that source is deleted along with it, so the Media Library doesn't accumulate orphaned files.

== Changelog ==

= 1.0.10 =
* Picking an already-cropped image now applies it as-is immediately, instead of forcing the crop editor open. "Adjust crop" remains the explicit way to open the editor for it (against its true original, with the existing box marked) afterward.

= 1.0.9 =
* Disabled Cropper.js's scroll-wheel zoom, which made the crop area flicker/jitter - the image now stays fixed, only the crop frame moves.
* Picking an already-cropped image from the Media Library now resolves to its true original with the existing crop box marked, instead of treating the crop as a brand new source to crop again. Adjusting it updates that same shared attachment (with "Save as new crop" still available as before).

= 1.0.8 =
* Changed the "show crops" toggle to show ONLY crops when checked, instead of everything - a plain "show all" mostly just repeated what was already visible, since regular uploads vastly outnumber crops.

= 1.0.7 =
* Fixed the real, confirmed root cause of the grid toggle never revealing crops: WordPress's wp_ajax_query_attachments() whitelists which query keys survive into the ajax_query_attachments_args filter, silently stripping our custom eiShowCrops prop before our code ever saw it - meaning the exclusion was unconditionally applied regardless of the checkbox. Switched to a cookie, which isn't subject to that whitelist.

= 1.0.6 =
* Found and fixed the real bug: WordPress visually hides every bare <label> in the media grid toolbar's filter section by default (its screen-reader-text technique), which was silently swallowing the toggle's checkbox and text. Overrides are now applied both in field.css and, unconditionally, as inline styles.
* field.css is now explicitly enqueued on the standalone Media Library screen too, instead of relying on ACF's own enqueue hook to have loaded it there.

= 1.0.5 =
* Added diagnostic console logging (prefixed "[Ei Image Crop]") and error handling to media-toggle.js, to pin down why the grid toggle still wasn't appearing despite the underlying logic testing correctly in isolation.

= 1.0.4 =
* Fixed the grid toggle silently giving up forever: the retry loop bailed out with no retry scheduled if wp.media.frame itself wasn't assigned yet at the very first check, instead of treating that the same as any other "not ready yet" state.

= 1.0.3 =
* Fixed the grid toggle landing in the wrong (invisible) spot: it now waits for the toolbar's secondary filter section to actually finish rendering before inserting, instead of falling back permanently on its very first, too-early check.

= 1.0.2 =
* Fixed the grid toggle for real this time: it now hooks wp.media.frame.browserView directly (confirmed against a live session) instead of guessing at internal WordPress media view structure.

= 1.0.1 =
* Fixed the "show generated crops" toggle not appearing in the standalone Media Library grid.
* Fixed field preview looking smaller right after saving a crop than after a page reload.
* Skip the crop modal when a freshly picked image already matches the field's fixed aspect ratio.
* Various fixes carried over from initial testing (crop modal not closing, crop value not persisting, reuse thumbnails showing as square).

= 1.0.0 =
* Initial release: field type, lazy on-demand cropping, reuse, in-place re-cropping, and Media Library filtering.
