=== Ei Image Crop ===
Contributors: eborninteractive
Tags: acf, image, crop, media, aspect ratio
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.10.31
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
* **Tidy Media Library.** Generated crops are hidden from the Media Library grid and list view by default, behind "Original images" / "Crops" tabs.

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
3. Add a field of type "Image Crop" to any field group, and configure its image size, admin preview size, library and return format.

== Frequently Asked Questions ==

= Which "Image size" should I pick in the field settings? =

Any image size already registered on the site - a core size (thumbnail, medium, large) or a custom one added via `add_image_size()`. The dropdown shows each one's pixel dimensions.

A size registered with hard cropping (`add_image_size( $name, $width, $height, true )`, or a specific crop position) locks the field's crop frame to that exact shape, defaults new crops to that size centered on the image, and always outputs a crop at exactly those dimensions (scaling down a larger selection, or up - with a red on-screen warning while dragging - a smaller one).

A size registered without hard cropping (`add_image_size( $name, $width, $height, false )`, WordPress's own "fit inside, don't force this shape" mode) is treated as a free-form crop: the frame can be resized independently on each axis, and the crop keeps whatever size it's actually dragged to.

= What happens to a crop's file if I delete the source image? =

Every crop generated from that source is deleted along with it, so the Media Library doesn't accumulate orphaned files.

== Changelog ==

= 1.10.31 =
* Reverted the grid view's tabs to their compact (non-full-width) size - the full-width attempt was pushing the top margin out - and locked the remaining spacing/border in place with !important.

= 1.10.30 =
* Made the grid view's tabs full-width again (with !important) so the divider line goes all the way across, like it did before the previous fix.

= 1.10.29 =
* Fixed the list view's tab styling incorrectly also applying to the standalone grid view - its toolbar turns out to also carry WordPress's own .wp-filter class, which the list view's CSS rule was (coincidentally) also matching. Now targeted by a marker class added only when the list view actually relocates its tabs, not by that ambiguous structural match.

= 1.10.28 =
* Fixed the 10px bottom margin from 1.10.27 landing on the list view instead of the grid view - moved it to the correct rule.

= 1.10.27 =
* Reduced the list view's tabs bottom margin (20px to 10px).

= 1.10.26 =
* Actually removed the popup view's bottom divider - the previous attempt (1.10.25) only removed the declaration from its own rule, but the standalone grid's border-bottom was still cascading through underneath it.

= 1.10.25 =
* Dropped the tabs' full-width stretch in both the popup and list views (back to their natural content width), and removed the popup's bottom divider.

= 1.10.24 =
* Gave the tabs their own full-width styling specifically in the "Select or Upload Media" popup (and similar field pickers), separate from the standalone Media Library grid's compact box.

= 1.10.23 =
* Adjusted the tabs' padding in both the grid and list views to add left indent and, in the grid view, top padding.

= 1.10.22 =
* Adjusted the list view's tabs spacing (bigger top/bottom margins) and brought back a bottom divider, styled slightly differently from the grid view's.

= 1.10.21 =
* Tweaked the tabs' spacing: the grid view's version is now a compact box with just a bottom divider (was full-width with borders on both sides), and the list view's version dropped its borders, keeping just a full-width row with a smaller bottom margin.

= 1.10.20 =
* Matched the list view's tabs to the grid view's styling: same padding and top/bottom borders.

= 1.10.19 =
* Fixed the list view's tabs for real this time: instead of fighting WordPress's own filter-bar layout with CSS, the tabs are now moved with a small script to sit as a plain row below that bar, where being full-width is just the default.

= 1.10.18 =
* Replaced the previous fixed-pixel/absolute-positioning fix for the list view's tabs with a flex-based one that doesn't depend on hardcoded heights - it grows and pushes the table below it down naturally instead.

= 1.10.17 =
* Actually fixed the classic Media Library list view's tabs staying inline. The previous attempt (1.10.13) targeted `.tablenav`, but this screen doesn't use that wrapper at all - its filter bar is WP core's own `.wp-filter` (an inline-block "actions" box), which was silently ignoring the fix. Now correctly positioned as its own row below that bar.

= 1.10.16 =
* Adjusted the crop canvas margin to 32px top / 20px bottom (was 28px / 15px).

= 1.10.15 =
* Adjusted the crop canvas margin to 28px top / 15px bottom (was 15px both), and fixed its background to the correct #090c0f instead of the lighter grey it was inheriting.

= 1.10.14 =
* Added a 15px top/bottom margin around the crop canvas so a crop box covering the entire image no longer hides its top and bottom resize handles against the modal's edge.

= 1.10.13 =
* Fixed the "Original images"/"Crops" tabs in the classic Media Library list view still appearing inline next to the date filter/"Filtrera" button instead of on their own row below, like the grid/picker views already do.

= 1.10.12 =
* Removed the spaces around "×" in new crop attachments' titles - "1400×1400" instead of "1400 × 1400".

= 1.10.11 =
* New crop attachments' titles now show their ratio as "1400 × 1400" instead of "1400:1400", matching the "×" convention already used elsewhere in the plugin (e.g. the "Image size" setting) - a colon reads as a raw value rather than a width/height pair. Cosmetic only: the underlying ratio value itself is unaffected everywhere else. Crops created before this update keep their existing title as-is.

= 1.10.10 =
* Reduced the "Original images"/"Crops" tabs' corner radius from 4px to 2px, matching WP admin's own default button shape more closely.

= 1.10.9 =
* Fixed 1.10.8's fix only targeting `.attachments-wrapper`, missing that WordPress's actual CSS rule (found live via dev tools) also covers `.uploader-inline` and `.attachments`, and that the toolbar itself is *also* positioned absolutely - meaning the tabs row (the only real normal-flow content left) needed its own top margin to clear the toolbar, not just push the grid down below itself. Both are now computed at runtime from the grid's own original position instead of guessed pixel values.

= 1.10.8 =
* Found the actual root cause of the tabs being invisible despite existing in the DOM (confirmed live via DevTools): the attachments grid and sidebar are positioned absolutely by WordPress's own CSS, each with a hardcoded `top` that assumes only the toolbar sits above them - a normal-flow sibling like our tabs row doesn't push them down, so nothing ever made room for it. Now pushes each of them down by exactly the tabs row's own rendered height right after inserting it, rather than guessing a fixed offset.

= 1.10.7 =
* Found the actual reason the crop field's own "Select image" picker never showed the tabs, thanks to a full log trace: insertion genuinely succeeded every time (confirmed by its own "inserting toggle now" log line) - something in WordPress's own rendering then ran a further step afterward that replaced the container's content, silently wiping the toggle back out again every time. Insertion is now self-healing: it keeps re-asserting the toggle's presence on every further change for as long as the browse view stays open, instead of reacting once and stopping.

= 1.10.6 =
* No functional change - added detailed console logging (prefixed "[Ei Image Crop]") at every decision point in media-toggle.js's toggle-insertion logic, to diagnose in one pass why the tabs still don't appear in the crop field's own "Select image" picker despite 1.10.2-1.10.5's fixes for other pickers.

= 1.10.5 =
* Fixed the "Original images"/"Crops" tabs still never appearing in the "Edit image details" popup even with 1.10.4's unbounded wait - confirmed live via direct DOM inspection, the element was never created at all. Root cause: that popup (scoped to a single attachment, opened via the crop field's pencil icon) apparently never attaches a visible toolbar row at all, since filtering by type/date makes no sense for one fixed image - waiting for the toolbar to attach waited forever for something that was never going to happen. Now waits for the browse view's own container instead (which always attaches, for anything in it to be visible), placing the tabs after the toolbar when one exists in that same tree, or at the top of the view otherwise.

= 1.10.4 =
* Fixed the "Original images"/"Crops" tabs still not appearing in the "Edit image details" popup (opened via the crop field's pencil icon) after 1.10.2's fix - confirmed live, that popup attaches its toolbar to the document slower than every other picker this was tested against, so the fixed 3-second retry budget added in 1.10.2 was still too short and gave up before it happened. Replaced the fixed retry budget with a MutationObserver that waits as long as it actually takes, the same technique already used in field.js for an equivalent timing problem.

= 1.10.3 =
* Fixed the "Original images"/"Crops" tabs never appearing at all (not even misplaced - completely absent, confirmed live via console log "wp.media.frame present: false" after 30 attempts) in a media picker opened from a field that lives inside an ACF block. media-toggle.js was only ever loaded into the block editor's outer document, never into its canvas iframe - a field inside a block runs its own wp.media() call within that iframe's separate window, so wp.media.frame there was never the one this script was checking. Now also loaded via enqueue_block_assets, the same fix already applied to the crop field's own assets in 1.9.0.

= 1.10.2 =
* Fixed the "Original images"/"Crops" tabs not appearing at all in the "Select or Upload Media" popup (opened from within a page/block), a regression from 1.10.0's redesign - confirmed live, the toolbar element exists at that point but isn't attached to the document yet in that specific picker, unlike the standalone Media Library page, so appending our row directly after it was a silent no-op. Now retries until the toolbar is actually attached before inserting.

= 1.10.1 =
* Added self-hosted update support (via the bundled Plugin Update Checker library, pointed at this plugin's own GitHub Releases) - every site running this plugin now sees a normal "Update available" notice on the Plugins page, the same as any wordpress.org plugin, instead of needing a manual re-upload. Pushing a version tag (e.g. "v1.10.1") triggers a GitHub Actions workflow that builds and publishes the release zip automatically.

= 1.10.0 =
* Redesigned how the "Original images"/"Crops" tabs are placed in the media grid/picker toolbar, after repeatedly fighting that toolbar's layout across several releases (it isn't a flex container, and its own set of filters varies - one date filter in an ACF field's own picker, but a media type filter too in the standalone "Open Media Library" picker, which broke the previous "align to the last select" approach as soon as a wrongly-targeted select produced a bad offset). Instead of trying to align inline among those filters, the tabs now sit on their own full-width row directly below the whole toolbar - no measuring, no alignment, no dependency on however many filters happen to be there.

= 1.9.5 =
* The "Image size" field setting's dropdown now shows a friendlier label for each registered size - e.g. "Kvadrat fri" instead of the raw slug "kvadrat-fri" (hyphens/underscores replaced with spaces, first letter capitalized). Purely cosmetic - the value actually saved is still the untouched slug.

= 1.9.4 =
* A field inside an ACF block still never got initialized even with 1.9.3's fix, confirmed live - ACF's own "append" action apparently doesn't fire (or fires too early) for however this specific markup actually lands in the DOM. Rather than keep guessing which ACF-internal event corresponds to it, field.js now also watches the DOM directly with a MutationObserver and initializes any `.ei-image-crop-field` the moment it actually appears, with no dependency on ACF's own eventing.

= 1.9.3 =
* Found the actual reason a field inside an ACF block never got its click handlers bound, confirmed live by comparing two matching fields on the same page: `initAll()` only ever searched for `.ei-image-crop-field` *inside* the element ACF's "append" action handed it, but for a field inside an ACF block that element turns out to already BE the field's own wrapper, not a container around it - so the search found nothing and silently skipped it forever, every time. Now also checks whether the handed-in element itself is the field before searching its descendants.

= 1.9.2 =
* Found the actual reason the crop popup never opened inside an ACF block, confirmed via console logs: the click handlers WERE registering correctly (1.9.1's fix wasn't the missing piece after all) - the real bug is that the modal itself, built with `position: fixed; inset: 0` to cover the whole viewport, was appended to `$('body')`, which inside an ACF block means the block editor's own canvas iframe's body, not the real page. The popup was very likely opening the whole time, just invisibly confined to that iframe's own small rectangle. It's now explicitly appended to the real top-level document's body (adopting the node across documents where needed), which is a no-op - and behaves exactly as before - outside an ACF block.

= 1.9.1 =
* Fixed the crop button still doing nothing inside an ACF block even after 1.9.0 got our JS/CSS loading into the block editor's iframe: field.js registers its click handlers for a field's markup either at page load or via ACF's own "append" action (fired whenever ACF injects new field markup later, which is exactly how a block's fields appear), but it only checked once, synchronously, whether window.acf existed yet before registering for that "append" event - and inside the iframe that check could run before ACF's own core script had finished executing, permanently skipping registration. Now retries briefly instead of checking once, and logs the outcome to the console (prefixed "[Ei Image Crop]").

= 1.9.0 =
* Fixed the crop popup never opening for this field type when used inside an ACF block (works fine in a classic meta box) - the block editor's canvas iframe is a separate document that ACF's own acf/input/admin_enqueue_scripts hook never reaches, so Cropper.js and field.js were simply never loaded there. Now also enqueued via WordPress's enqueue_block_assets whenever the block editor is active, the same mechanism ACF's own core field JS already relies on for fields to work inside blocks at all.

= 1.8.14 =
* Fixed the media-picker toolbar row (date filter dropdown, and our "Original images"/"Crops" tabs once aligned to it) sitting close enough to the row above it to read as clipped/too far down - .media-toolbar-secondary turned out to be a CSS grid (align-items: end), not flex or floats as earlier changelog entries assumed; nudging it up 10px (confirmed live via browser dev tools) fixes it without affecting the relative alignment between the select and our tabs.

= 1.8.13 =
* Fixed 1.8.12's retry only checking that the date filter `<select>` existed, not that it (and our own toggle) actually had a rendered size yet - confirmed live, the very first check found the `<select>` but measured an all-zero box for both elements (the modal was still mid fade-in), so "delta" came out to a false-positive 0 and nothing was ever applied. Now retries until both elements report real height, not just until the `<select>` exists.

= 1.8.12 =
* Fixed 1.8.10/1.8.11's alignment silently never applying: the date filter's `<select>` is populated from an async request (it needs to know which months have attachments) and often doesn't exist yet at the exact moment we first check, so the alignment code was finding zero `<select>` elements and giving up immediately. It now retries for up to 2 seconds, and logs its result to the browser console (prefixed "[Ei Image Crop]") so a future regression is easy to spot.

= 1.8.11 =
* Changed the runtime alignment added in 1.8.10 to match bottom edges instead of vertical centers - the "Original images"/"Crops" tabs now line up with the same bottom line as the date filter dropdown and the search box next to it, instead of just centering on the dropdown.

= 1.8.10 =
* Replaced the ineffective align-self attempt (1.8.9 - .media-toolbar-secondary turned out not to be a flex container at all, confirmed via its own .spinner child using plain absolute positioning) with a runtime fix: media-toggle.js now measures the neighboring date filter dropdown's actual rendered position and nudges the "Original images"/"Crops" tabs to its vertical center, instead of guessing a fixed CSS offset that could drift with admin font size or WP version.

= 1.8.9 =
* Fixed the previous alignment attempt (1.8.7) actually landing the "Original images"/"Crops" tabs next to the "Filter by date" label instead of the select below it - now pinned to the bottom of the toolbar row (align-self: flex-end) instead of centered, so they sit level with the dropdown itself.

= 1.8.8 =
* Moved `position: relative` from the reuse thumbnail's `.is-selected` state onto the base `.ei-image-crop-reuse-item` rule itself, so every thumbnail is a positioning context, not just the selected one.

= 1.8.7 =
* Lined up the "Original images"/"Crops" tabs in the media picker's grid toolbar with the neighboring date filter dropdown - they were sitting noticeably higher than it, since they were independently centered around a shorter box height instead of matching the dropdown's own ~30px height.

= 1.8.6 =
* Made a selected "Existing crops" reuse thumbnail a positioning context (position: relative), for any future badge/overlay on top of it.

= 1.8.5 =
* Reduced the "Crop image"/"Use image" button's corner radius from 5px to 2px, matching WP admin's own default button shape more closely.

= 1.8.4 =
* Added a plain "Cancel" button next to "Crop image" in the crop modal, so closing without saving no longer requires reaching for the small × in the corner.

= 1.8.3 =
* Fixed the "Existing crops" reuse thumbnails being clipped/padded inside a fixed 72px-tall box - height is now automatic so the row simply matches the (max-width-constrained) thumbnails themselves. Also dropped now-unused `position: relative` and `background: none` from that item's styles.

= 1.8.2 =
* Added explicit warnings to the migration page (top of the report, and right above the "Apply migration now" button) that migration must be run BEFORE switching any field over to this plugin, not after - it reads each old field's crop type/size from its current settings to work out the right crop for every attachment, and those settings are gone once the field itself is switched over.

= 1.8.1 =
* The migration report (1.8.0) can now actually apply the migration: an "Apply migration now" button backfills this plugin's own crop metadata (_ei_crop_parent, _ei_crop_ratio, _ei_crop_box, _ei_crop_hash) onto every legacy cropped attachment that doesn't already have it. This is what makes "Adjust crop" find the true original afterward, instead of re-cropping the already-cropped image - simply switching the field's type over, without this step, leaves that broken. Nothing is deleted, no image files are touched, the old plugin's own metadata is left in place, and attachments that already have this plugin's metadata are left untouched, so it's safe to run more than once.

= 1.8.0 =
* Added a read-only migration report (Tools > Migrate Image Crops, only shown when legacy data is actually found) for sites moving from "ACF Image Aspect Ratio Crop" (Johannes Siipola's acf-image-aspect-ratio-crop plugin). Lists every field still using that plugin's field type with its configured crop type/size and a suggested Ei Image Crop "Image size" setup, plus every cropped attachment it ever generated with its resolved original image, normalized crop box, which post(s) currently reference it, and any issues found (missing original, missing coordinates, etc.). This page only reads data - nothing is written or changed by viewing it, in preparation for an actual migration step.

= 1.7.21 =
* The "Image size" field setting now shows a free-crop size's own max width/height too when it has one, e.g. "kvadrat-fri (1500 × 1500, free crop)" instead of just "kvadrat-fri (free crop)". A free-crop size registered with both dimensions at 0 (truly unbounded) still shows just "(free crop)".

= 1.7.20 =
* Bumped the admin preview images (field thumbnail, "Existing crops" reuse preview, post-save preview) from 1.7.19's 'medium' to 'large' - they're displayed at a fixed CSS max-height rather than their own native size, so 'medium' was being upscaled and looked visibly soft/blurry. 'large' gives real headroom for high-density screens instead. The "Existing crops" row icons themselves stay at 'medium' - plenty for their much smaller footprint.

= 1.7.19 =
* Admin-side preview images (the field's own thumbnail, the "Existing crops" row icons, and the preview shown right after saving/reusing a crop) now automatically use the smaller 'medium' size instead of the full-resolution crop, now that 1.7.18 guarantees it exists (and shares the crop's true aspect ratio) whenever the crop is actually bigger than it. No setting to configure - it's always correct by construction, so there's nothing to choose.

= 1.7.18 =
* Restored responsive images (srcset) for crops, without going back to generating every registered size for each one. A crop's own metadata used to skip all registered sizes entirely (since 1.0.13, to avoid needless mass file generation) - but WordPress's responsive-images feature builds srcset directly from that same list, so crops silently lost it too. Now only the sizes that scale proportionally (crop => false) are generated - a hard-cropped custom size never shares the crop's own aspect ratio anyway, so WordPress's own srcset logic would filter it straight back out even if it existed, making it pure waste to generate in the first place.

= 1.7.17 =
* The field type's name in ACF's own field-type selector now reads "Bild med beskäring (Ei Image Crop)" (Swedish sites) instead of "Bildbeskärning".

= 1.7.16 =
* Removed the "Admin preview size" field setting entirely - a crop never gets WordPress's usual thumbnail/medium/large copies generated for it at all (see 1.0.13's use of the intermediate_image_sizes_advanced filter to suppress that, avoiding needless mass file generation), so any size chosen there always silently fell back to the crop's own one full-size file anyway. The field's preview now just requests 'full' directly everywhere - same result, one less setting to think about.

= 1.7.15 =
* "Admin preview size" now only offers image sizes that scale proportionally (e.g. Medium, Large) - a hard-cropped size like the default Thumbnail (square by default) would show the field's own preview at a different aspect ratio than what was actually cropped, making an already-correct crop look wrong at a glance. Lower resolution is fine; a different shape isn't.

= 1.7.14 =
* Fixed the pencil icon's popup (1.7.13) sometimes opening on the empty "Upload files" tab instead of the Media Library view showing the attachment's own details - the frame's own guess at which tab to show first depends on whether its (still loading) collection already has items at that exact moment, which isn't reliable for a query scoped down to one attachment. The Media Library tab is now forced open every time instead.

= 1.7.13 =
* Fixed the pencil icon's popup (1.7.12) showing the correct attachment details in its sidebar but "No items found" in the grid itself, whenever the image being edited was itself a crop - the Media Library's own crop-hiding default was filtering out the exact attachment the popup explicitly asked for by id. That filtering is now skipped entirely whenever the query already names specific attachment ids (post__in), since a caller that asked for those exact attachments should get them regardless of crop status.

= 1.7.12 =
* Fixed the pencil icon's popup (1.7.11) opening blank - acf.newMediaPopup() didn't actually populate the attachment's own data (no thumbnail, no alt text, no dimensions, just the bare title/excerpt/description/URL fields every attachment has). Rebuilt on plain wp.media() instead - the same core API the field's own "Select image" picker already uses, just scoped to one attachment via library.post__in and explicitly added to the frame's selection so its details sidebar shows.

= 1.7.11 =
* The pencil icon's "edit image details" popup is no longer a custom iframe loading upload.php - it now opens WP's native attachment-edit popup via ACF's own acf.newMediaPopup() helper (the same one ACF's Image/Gallery/File fields use), matching the look and behavior of any other ACF image field's edit-in-place popup, with none of the double admin-bar/menu or iframe sizing quirks the previous approach had.

= 1.7.10 =
* The media grid's "Original images" / "Crops" tabs (standalone Media Library, "Add Media", and the field's own "Select image" picker) now always start on "Original images" when freshly opened, instead of remembering whichever tab was last active. Switching tabs still works as before, it just no longer carries over to the next time the grid is opened.

= 1.7.9 =
* The crop frame now turns red for a free-crop field capped by a registered size's own width/height (see 1.7.8) when the drawn box falls below that cap in BOTH dimensions - a heads-up that the result won't reach the size the cap allows for, not an upscale warning (a free crop is never upscaled). A single-axis cap (one side left at 0) never triggers this.

= 1.7.8 =
* A free-crop image size registered with a non-zero width and/or height (e.g. `add_image_size( 'kvadrat-fri', 1500, 1500, false )`) now treats those numbers as a resolution cap, same as WordPress's own proportional thumbnails: the result is scaled down to fit within them (never upscaled) if the drawn box is bigger, left at its own size otherwise. Previously a free crop's registered width/height were ignored entirely and the result kept whatever native resolution the drawn box happened to be. The pixel counts shown in the crop popup (live preview caption and the floating box label) now reflect the same cap.

= 1.7.7 =
* The "Crop image" button now shows a loading state while its request is in flight: it switches to a white background with a blue border, a small spinner appears after the label, and the cursor turns into a wait/busy cursor - previously it just quietly disabled itself with no other feedback.

= 1.7.6 =
* The delete-crop confirmation now spells out what's actually at stake - the image is gone for good, and if that exact crop is used elsewhere on the site, it'll need replacing - instead of just "This cannot be undone."

= 1.7.5 =
* CSS tweaks to the "Existing crops" row: tighter vertical gap between wrapped rows, delete button flush to the thumbnail's corner instead of overhanging it.

= 1.7.4 =
* Fixed "Adjust crop" on an already-cropped image not actually ending up in the "Use image" state: Cropper.js dispatches its 'crop' event asynchronously, so the flag meant to tell the initial box placement apart from a real manual drag could already be reset by the time the event fired, immediately clearing the preselected "Existing crops" match right back out. Now uses Cropper's own 'cropstart' event instead, which only ever fires from an actual pointer press on the crop box - never from a setData() call - so there's no longer a race to lose.

= 1.7.3 =
* Fixed existing-crop thumbnails in "Existing crops" looking stretched/distorted - height:100% paired with max-width:100% clamped a wide crop's width down without shrinking its height to match, squashing it. Now scales down proportionally instead.

= 1.7.2 =
* The initial crop selection now always starts out maximized - flush with the source image's edges on whichever axis is the tighter fit for the target ratio, centered on the other - instead of at the ratio's exact literal pixel size when the source was big enough to fit that.
* Opening "Adjust crop" on an image that already matches one of its own existing crops now starts with that crop marked selected in "Existing crops" and the Save button already reading "Use image", instead of looking like an unrelated fresh crop.

= 1.7.1 =
* Replaced the "Only show generated crops" checkbox (list view) / toggle link (grid view) with two tabs, "Original images" and "Crops", in both the classic Media Library list table and the JS-injected grid toolbar - including inside the field's own "Select image" picker, which shares the same toolbar code.

= 1.7.0 =
* The pencil icon now opens the attachment details popup in an iframe over the current page, instead of following its link - clicking it no longer navigates the tab away to the Media Library (leaving you there once the popup closes), you stay exactly where you were. Middle-click/ctrl-click still opens a real new tab, same as any other link.

= 1.6.7 =
* Fixed the pencil icon turning blue on hover/focus - it's an <a> tag, so WP admin's own link-hover color was winning over the icon buttons' white.
* The field's own "remove image" icon button now renders its × via a ::before rule too, matching the close/reuse-delete buttons, instead of markup text.

= 1.6.6 =
* Added !important to .cropper-view-box's outline override - something on at least one live site (likely a CSS concatenation/optimization plugin reordering enqueued stylesheets) was letting cropper.min.css's own 1px outline win despite field.css being enqueued after it.

= 1.6.5 =
* Fixed the live preview disappearing entirely - Cropper.js's own preview feature needs the container to have an explicit width/height to compute its scale/position from; 1.6.4 removed those, which collapsed it to a 0x0 box. Restored them (kept the rounded corners from 1.6.4).
* Switched the popup's close button to a ::before pseudo-element for its ×, matching the same approach and fine-tuning as the reuse-delete button.

= 1.6.4 =
* Applied user-supplied field.css tweaks: the live preview now sizes to its content instead of a fixed 100%/200px box, both it and its reuse-thumbnail images gained rounded corners.

= 1.6.3 =
* Applied user-supplied field.css tweaks: smaller/repositioned reuse thumbnail delete button with a finer-tuned × glyph, and rounded-square (instead of circular) popup close button.

= 1.6.2 =
* Applied a user-supplied field.css tweak: lighter blue background on a selected reuse thumbnail.

= 1.6.1 =
* Added "px" to the floating dimension tag and the live preview's warning caption (e.g. "2000 × 1000 px").
* Reserved space for the live preview's warning caption so the "Existing crops" section below it doesn't jump down the moment the warning text appears.

= 1.6.0 =
* Reworked the target-size resolution displays to be more pedagogical, for a field with a fixed target size:
  * The floating dimension tag on the crop box shows the fixed target size (e.g. "2000 × 1000") as long as the selection is big enough to reach it - saving always produces exactly that size either way - and only switches to showing the real, shrinking selection size once it can't.
  * The live preview's caption is blank until the selection can't reach the target size without upscaling, at which point it shows the real pixel size in red with a small warning triangle.
* A free-form field (no target size) is unaffected - both keep always showing the box's actual size.

= 1.5.3 =
* The floating dimension tag and the live preview's caption now show the crop's true final pixel size (scaled up to the true original, same fix as 1.5.1) instead of the editor's own possibly-downscaled on-screen pixels - 1.5.1 only corrected the undersized-warning threshold itself, the numbers shown were still off.

= 1.5.2 =
* The pencil icon now opens the Media Library's attachment details popup (upload.php?item={id}) instead of the full attachment edit screen.

= 1.5.1 =
* Fixed the undersized/upscale-warning (and the modal header's dimensions) comparing the crop box against the possibly-downscaled "-scaled"/"large" image shown in the editor, instead of the true original - WordPress auto-scales very large uploads and keeps the true original as a separate, bigger file, so a selection that looked "too small" against the shown copy could still be plenty large enough against the real one. Both now correctly use the true original's own pixel dimensions.

= 1.5.0 =
* Replaced the field's "Aspect ratio"/"Custom ratio" settings with a single "Image size" setting: pick any image size already registered on the site (core sizes, or anything added via add_image_size()) instead of typing a ratio.
  * A size registered with hard cropping locks the crop frame to its exact shape, defaults new crops to its literal pixel dimensions centered on the image (not just the largest box that happens to share the ratio), and always saves the crop at exactly those dimensions - scaling a larger selection down, or a smaller one up.
  * A size registered without hard cropping (`add_image_size( $name, $w, $h, false )`) is treated as a free-form crop, same as the old "Free" ratio option.
  * The crop frame turns red while dragging it smaller than the target size, warning that saving will need to upscale the result (blurrier than a same-size-or-larger selection).
* Existing fields configured with the old Aspect ratio/Custom ratio settings will need to be reconfigured with the new Image size dropdown - the two settings aren't automatically migrated.

= 1.4.0 =
* Applied user-supplied field.css tweaks: the reuse thumbnail delete button's × as a ::before rule, and the "Crop image" button now uses --wp-admin-theme-color (the site's own admin color scheme) instead of a fixed color.
* The popup's close button's × is now a ::after rule too, off its markup, matching the same approach already used for the reuse-delete button.

= 1.3.9 =
* Applied user-supplied field.css tweaks: thicker (3px) crop box outline, and the four corner brackets moved further out (-9px) with white instead of black borders.

= 1.3.8 =
* Applied a user-supplied field.css tweak: adjusted padding on the modal side panel.
* Restyled the popup's close button to a smaller, lighter outline style (white background, thin gray border, muted gray ×) instead of the solid dark circle, per a reference screenshot.

= 1.3.7 =
* Applied user-supplied field.css tweaks: top padding on the modal header's heading block, and left margin on the filename/dimensions subtitle.

= 1.3.6 =
* Applied user-supplied field.css tweaks: rounded corners on the "Crop image" button, and left-aligned the modal's action row instead of right-aligned.
* Dropped the aspect-ratio label ("2000:1000") from both the live preview caption and the floating dimension tag on the crop box - they now just show the actual pixel size, e.g. "860 × 430".

= 1.3.5 =
* Removed the reuse thumbnail delete button's × from its markup - it's meant to be added as a ::before rule in field.css instead, for full control over its own sizing/position. field.css itself wasn't touched in this release since it's currently being edited by hand directly.

= 1.3.4 =
* Restyled the crop box's own handles to match the mockup: bold black corner brackets instead of Cropper.js's default small blue dots, and removed the four edge-midpoint handles entirely.
* Centered the floating dimension tag above the crop box instead of left-aligning it to the box's edge.

= 1.3.3 =
* Fixed the "Crop image"/"Use image" button silently falling back to WP's plain white/blue-outline button style - removing the .button-primary class in 1.3.0 also removed the !important overrides its color rules needed, and WP admin's own button CSS ties (or beats) a bare single-class selector like ours.
* Made the "Crop image" popup's title bigger and explicitly black.
* More breathing room between a reuse thumbnail's selection frame and the thumbnail image itself.
* Added a divider line under the "Existing crops" heading.
* Shortened "Existing crops of this image" to just "Existing crops".

= 1.3.2 =
* Gave the crop popup rounded corners, matching the mockup.

= 1.3.1 =
* Closed the remaining gaps against the design mockup:
  * Added a floating "1600 × 900 · 16:9" tag that tracks the crop box itself while dragging, not just the static caption under the live preview.
  * Added a "Preview" heading above the live preview, and a "<n> saved" count next to "Existing crops of this image", both matching the mockup's section headings.
  * Right-aligned the "Crop image" button.
* Added the two new strings above to the Swedish translation.

= 1.3.0 =
* Reworked the crop popup's layout based on a design mockup:
  * Added a header bar with a title, the source image's filename and true pixel dimensions, and the close button.
  * The live preview now shows a caption with the current ratio and resulting pixel size.
  * Existing-crop thumbnails no longer show their title as visible text (just the thumbnail); the selected one now gets a light blue border/background instead of green.
  * The "Crop image" button is black by default (no longer WP's default blue), turning green only once a reuse thumbnail is selected ("Use image").
* Added a Swedish (sv_SE) translation - every string in the plugin now displays in Swedish on a Swedish-locale WordPress install.

= 1.2.3 =
* Replaced the "edit image details" pencil icon (dashicons-edit) with a plain custom pencil - dashicons-edit's baseline stroke read as a stray underline at this size.

= 1.2.2 =
* Inverted the round icon buttons (field preview overlay, crop popup close, reuse thumbnail delete) to dark plates with white icons.
* The field's own "remove image" icon now uses the same × as the crop popup's close button and its reuse thumbnails' delete button, instead of a different dashicon.
* Replaced the browser's native confirm() dialog for deleting a crop (which showed its own "From <host>:" system chrome) with an in-app confirmation dialog, and reworded it to "Delete image?" instead of "Delete this crop?".

= 1.2.1 =
* The pencil icon now opens the image's own WordPress edit screen (in a new tab), for editing its caption/alt text/description - it was mistakenly wired up to reopen the image picker instead. Clicking the preview image itself is now how you pick a different image, same as ACF's own native Image field.

= 1.2.0 =
* Removed the dashed "drop a file here" placeholder shown before an image is selected - dragging and dropping was never actually supported, so it only invited people to try.
* "Adjust crop" (and Remove) no longer appear before an image has been selected.
* Once an image is selected, its preview shows small round icon buttons (change image, adjust crop, remove) overlaid on the image itself, replacing the row of text buttons underneath it.

= 1.1.3 =
* Fixed "Adjust crop" (and the field's stored source) resolving only one level up the crop chain: cropping an already-cropped image kept parenting the new crop to that intermediate crop instead of walking all the way up to the true root original, so editing it again kept loading an already-cropped, wrong-shaped image instead of the real original. Added `Ei_Image_Crop_Generator::resolve_root()` and used it everywhere a crop's source is resolved (field rendering, the "get source" AJAX endpoint, and crop generation itself), so this also self-heals any crop chains that were already affected.

= 1.1.2 =
* Fixed "Adjust crop" loading a reshaped image instead of the true original: it preferred the registered "large" size for speed, assuming it's always proportional to the original, but a theme or plugin can re-register "large" as a hard crop to a fixed, unrelated shape. get_edit_source() now only uses "large" when its own aspect ratio actually matches the true original's, falling back to the full-size image otherwise.

= 1.1.1 =
* Renamed "Use Crop" to "Use image".
* Fixed the "Use image" button not actually turning green - WP core's own .button-primary styling was winning the tie against our color override.
* Pushed the live preview down so it no longer overlaps the modal's close (×) button.

= 1.1.0 =
* Reworked the crop modal's controls:
  * One save button ("Crop image") instead of two - it now always behaves like the old "Save as new crop" (never silently overwrites a shared crop in place).
  * Clicking an existing-crop thumbnail no longer closes the modal immediately - it marks that crop's own box in the crop area and switches the save button to a green "Use Crop", which applies it as-is. Adjusting the crop box by hand afterward deselects it and reverts the button.
  * Added a delete button on each existing-crop thumbnail to remove it (with confirmation) without leaving the modal.
  * Replaced the Cancel button with a close (×) button in the modal's top-right corner.

= 1.0.13 =
* Crops no longer generate the site's full registered set of intermediate sizes (thumbnail/medium/large/etc.) - only the cropped file itself is saved. Anything that requests a specific named size on a crop now falls back to that one file, same as WordPress does natively when a size doesn't exist.

= 1.0.12 =
* When picking an image with "Only show generated crops" checked in a field's own picker, the listing now scopes to crops matching that field's ratio, so everything shown is actually usable as-is without still needing a new crop. Free-ratio fields see all crops, since none of them need a specific shape.

= 1.0.11 =
* Changed the cropper's dragMode from 'move' to 'none' - dragging on the canvas no longer pans the underlying image, only the crop box itself moves/resizes via its own handles.

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
