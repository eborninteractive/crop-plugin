/**
 * Adds a "Show generated crops" checkbox to the media modal grid toolbar,
 * mirroring the classic list table's toggle link.
 */
( function ( $ ) {
	'use strict';

	var MAX_ATTEMPTS = 30;
	var RETRY_DELAY = 200;

	function label() {
		return window.eiImageCropMedia ? eiImageCropMedia.label : 'Show generated crops';
	}

	/**
	 * Injects the checkbox into an already-built AttachmentsBrowser view and
	 * wires it up.
	 *
	 * @param {Object}  browserView   A wp.media.view.AttachmentsBrowser instance.
	 * @param {boolean} forceFallback If true, insert onto the toolbar's own
	 *   $el even when the secondary sub-view isn't ready yet, instead of
	 *   waiting - used once retries are exhausted so the checkbox ends up
	 *   *somewhere* rather than never appearing at all.
	 * @return {boolean} True once the checkbox is in the DOM (in the right
	 *   spot, or the fallback spot when forced); false if not ready yet.
	 */
	function addToggle( browserView, forceFallback ) {
		if ( ! browserView || ! browserView.toolbar || ! browserView.collection ) {
			return false;
		}

		// Already inserted somewhere under the toolbar - never insert a
		// second one, even if it landed in the fallback spot on an earlier
		// forced attempt and a proper secondary section is available now.
		if ( browserView.toolbar.$el.find( '.ei-image-crop-toggle' ).length ) {
			return true;
		}

		var secondary = browserView.toolbar.secondary;
		var $secondaryEl = ( secondary && secondary.$el && secondary.$el.length ) ? secondary.$el : null;

		// The secondary sub-view (date filter, media type dropdown) can still
		// be mid-render even once `toolbar` itself exists - WordPress builds
		// it as a nested region, not necessarily in the same tick. Wait for
		// it rather than silently falling back to the toolbar's outer
		// element, which is a flex row with no space reserved for an extra
		// item and effectively hides whatever lands there.
		if ( ! $secondaryEl && ! forceFallback ) {
			return false;
		}

		var library = browserView.collection;
		var $toggle = $(
			'<label class="ei-image-crop-toggle">' +
				'<input type="checkbox" />' +
				' ' + label() +
			'</label>'
		);

		$toggle.find( 'input' ).on( 'change', function () {
			var checked = $( this ).is( ':checked' );
			library.props.set( { eiShowCrops: checked ? 1 : '' } );
		} );

		( $secondaryEl || browserView.toolbar.$el ).append( $toggle );

		return true;
	}

	/**
	 * Patches the AttachmentsBrowser class so any browser view built AFTER
	 * this point (e.g. a media-picker modal opened later by a user click)
	 * gets the toggle automatically via its own createToolbar(). By the time
	 * that method returns, the toolbar's sub-views are already built
	 * synchronously, so a single addToggle() call here (no retry needed)
	 * reliably lands in the secondary section.
	 */
	function patchClassForFutureViews() {
		if ( ! window.wp || ! wp.media || ! wp.media.view || ! wp.media.view.AttachmentsBrowser ) {
			return;
		}

		var BaseBrowser = wp.media.view.AttachmentsBrowser;

		if ( BaseBrowser.prototype.__eiImageCropPatched ) {
			return;
		}

		wp.media.view.AttachmentsBrowser = BaseBrowser.extend( {
			__eiImageCropPatched: true,
			createToolbar: function () {
				BaseBrowser.prototype.createToolbar.apply( this, arguments );
				addToggle( this, true );
			},
		} );
	}

	/**
	 * Covers pages - notably the standalone Media Library grid (Media >
	 * Library) - where WordPress's own bootstrap script already constructs
	 * and renders the attachments browser as part of the page load itself,
	 * often before this script gets a chance to run. Patching the class at
	 * that point is too late: reassigning wp.media.view.AttachmentsBrowser
	 * doesn't retroactively change a view instance already built from the
	 * original class. wp.media.frame.browserView is WordPress's own direct
	 * reference to that already-built view, so reach into it directly -
	 * retrying for a bit since its toolbar.secondary sub-view can still be
	 * rendering at the exact moment this first runs.
	 *
	 * @param {number} attempt
	 */
	function patchExistingFrame( attempt ) {
		attempt = attempt || 0;

		// wp.media.frame itself not existing yet is just another "not ready
		// yet" state, same as toolbar.secondary not being there - it must
		// keep retrying here too, not bail out for good. An early return
		// with no retry scheduled is exactly how this silently gave up
		// forever on the very first check if the frame happened to not be
		// assigned yet at that exact moment.
		var frame = window.wp && wp.media && wp.media.frame;
		var forceFallback = attempt >= MAX_ATTEMPTS;
		var done = frame && addToggle( frame.browserView, forceFallback );

		if ( ! done && attempt < MAX_ATTEMPTS ) {
			setTimeout( function () {
				patchExistingFrame( attempt + 1 );
			}, RETRY_DELAY );
		}
	}

	$( function () {
		patchClassForFutureViews();
		patchExistingFrame( 0 );
	} );
} )( jQuery );
