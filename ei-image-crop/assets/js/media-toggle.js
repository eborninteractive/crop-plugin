/**
 * Adds "Original images" / "Crops" tabs to the media modal grid toolbar,
 * mirroring the classic list table's tabs - always exactly one or the
 * other, never both at once, since regular uploads vastly outnumber crops
 * and a combined view would barely differ from "Original images" alone.
 */
( function ( $ ) {
	'use strict';

	if ( typeof $ !== 'function' ) {
		console.error( '[Ei Image Crop] media-toggle.js loaded but jQuery was not available at that point.' );
		return;
	}

	var MAX_ATTEMPTS = 30;
	var RETRY_DELAY = 200;

	function originalsLabel() {
		return window.eiImageCropMedia ? eiImageCropMedia.originalsLabel : 'Original images';
	}

	function cropsLabel() {
		return window.eiImageCropMedia ? eiImageCropMedia.cropsLabel : 'Crops';
	}

	// WordPress's wp_ajax_query_attachments() whitelists which query keys it
	// passes through to the ajax_query_attachments_args filter (s, order,
	// orderby, posts_per_page, paged, post_mime_type, post_parent, author,
	// post__in, post__not_in, year, monthnum, plus taxonomy query vars) via
	// array_intersect_key() - anything else, including a custom prop like
	// eiShowCrops, is silently stripped before that filter ever runs. So the
	// checkbox's state can never reach PHP through library.props at all; a
	// cookie (sent with every request regardless of that whitelist) is used
	// instead. props.set() is still called purely to trigger WordPress's own
	// refetch-on-change listener - its value just isn't what PHP reads.
	//
	// Deliberately write-only from here: addToggle() always resets it to
	// "Original images" on every fresh toolbar build (see below) rather
	// than reading back whatever was left over from a previous one, so a
	// tab switch only ever lasts for the currently open grid/picker, never
	// carries over to the next time the Media Library is opened.
	var COOKIE_NAME = 'ei_show_crops';

	function setShowCropsCookie( checked ) {
		document.cookie = COOKIE_NAME + '=' + ( checked ? '1' : '0' ) + '; path=/; max-age=' + ( 60 * 60 * 24 );
	}

	/**
	 * Injects the two tabs into an already-built AttachmentsBrowser view and
	 * wires them up.
	 *
	 * @param {Object}  browserView   A wp.media.view.AttachmentsBrowser instance.
	 * @param {boolean} forceFallback If true, insert onto the toolbar's own
	 *   $el even when the secondary sub-view isn't ready yet, instead of
	 *   waiting - used once retries are exhausted so the tabs end up
	 *   *somewhere* rather than never appearing at all.
	 * @return {boolean} True once the tabs are in the DOM (in the right
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

		// Plain inline layout only - unlike the checkbox+<label> this used
		// to be, WordPress's screen-reader-text hiding of bare <label>
		// elements in this toolbar section doesn't apply to a <span> of
		// <button>s, so no !important visibility overrides are needed here.
		var LAYOUT_STYLE = 'display:inline-flex;align-items:center;gap:4px;margin:0 0 0 12px;';

		var library = browserView.collection;

		// Every fresh toolbar build - a plain page load of the standalone
		// Media Library, or each new "Add Media" / field picker frame -
		// always starts on "Original images", regardless of whatever tab
		// was left active the last time one of these was open. The cookie
		// itself needs resetting here too, not just the button's own
		// look: it's what the very first collection fetch for this grid is
		// actually scoped by (see filter_grid_query() on the PHP side),
		// and that fetch can happen before any click ever reaches this
		// toggle.
		setShowCropsCookie( false );

		var $toggle = $(
			'<span class="ei-image-crop-toggle" style="' + LAYOUT_STYLE + '">' +
				'<button type="button" class="ei-image-crop-tab is-active" data-crops="0">' + originalsLabel() + '</button>' +
				'<button type="button" class="ei-image-crop-tab" data-crops="1">' + cropsLabel() + '</button>' +
			'</span>'
		);

		$toggle.find( '.ei-image-crop-tab' ).on( 'click', function () {
			var wantsCrops = '1' === $( this ).data( 'crops' ).toString();
			setShowCropsCookie( wantsCrops );
			$toggle.find( '.ei-image-crop-tab' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			// The actual value here is irrelevant server-side (see the
			// comment above COOKIE_NAME) - this call's only real purpose is
			// to trigger WordPress's own listener that refetches the
			// collection whenever its query props change.
			library.props.set( { eiShowCrops: wantsCrops ? 1 : '' } );
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

		if ( done ) {
			console.log( '[Ei Image Crop] toggle inserted on attempt ' + attempt + ( frame.browserView.toolbar.secondary ? '' : ' (fallback spot)' ) );
			return;
		}

		if ( attempt < MAX_ATTEMPTS ) {
			setTimeout( function () {
				patchExistingFrame( attempt + 1 );
			}, RETRY_DELAY );
		} else {
			console.warn( '[Ei Image Crop] gave up after ' + attempt + ' attempts - wp.media.frame present: ' + !! frame );
		}
	}

	$( function () {
		try {
			console.log( '[Ei Image Crop] media-toggle.js running, jQuery available: ' + ( typeof $ === 'function' ) );
			patchClassForFutureViews();
			patchExistingFrame( 0 );
		} catch ( e ) {
			console.error( '[Ei Image Crop] media-toggle.js threw an error:', e );
		}
	} );
} )( window.jQuery );
