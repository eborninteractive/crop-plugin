=== Ei Image Crop ===
Contributors: eborninteractive
Tags: acf, image, crop, media, aspect ratio
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.3
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
