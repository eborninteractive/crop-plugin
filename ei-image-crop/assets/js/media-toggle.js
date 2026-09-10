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
	 * wires them up. Placed on its own full-width row directly below the
	 * whole toolbar (not inline inside .media-toolbar-secondary among the
	 * existing filters) after repeatedly fighting that toolbar's layout:
	 * it isn't a flex container, its own filter set isn't fixed (one
	 * <select> in an ACF field's picker, two - media type AND date - in
	 * the standalone "Open Media Library" picker), and the filters
	 * themselves render asynchronously. A dedicated row below everything
	 * else needs none of that - no measuring, no aligning, no dependency
	 * on however many filters happen to precede it.
	 *
	 * @param {Object} browserView A wp.media.view.AttachmentsBrowser instance.
	 * @return {boolean} True once the tabs are in the DOM; false if not ready yet.
	 */
	function addToggle( browserView ) {
		if ( ! browserView || ! browserView.toolbar || ! browserView.toolbar.$el || ! browserView.toolbar.$el.length || ! browserView.collection ) {
			return false;
		}

		var $toolbar = browserView.toolbar.$el;

		// Already inserted after this toolbar - never insert a second one.
		if ( $toolbar.next( '.ei-image-crop-toggle' ).length ) {
			return true;
		}

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
			'<div class="ei-image-crop-toggle">' +
				'<button type="button" class="ei-image-crop-tab is-active" data-crops="0">' + originalsLabel() + '</button>' +
				'<button type="button" class="ei-image-crop-tab" data-crops="1">' + cropsLabel() + '</button>' +
			'</div>'
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

		$toolbar.after( $toggle );

		return true;
	}

	/**
	 * Patches the AttachmentsBrowser class so any browser view built AFTER
	 * this point (e.g. a media-picker modal opened later by a user click)
	 * gets the toggle automatically via its own createToolbar(). By the time
	 * that method returns, the toolbar itself is already built synchronously,
	 * so a single addToggle() call here (no retry needed) reliably works.
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
				addToggle( this );
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
	 * retrying for a bit since it can still not exist yet at the exact
	 * moment this first runs.
	 *
	 * @param {number} attempt
	 */
	function patchExistingFrame( attempt ) {
		attempt = attempt || 0;

		// wp.media.frame itself not existing yet is just another "not ready
		// yet" state - it must keep retrying here too, not bail out for
		// good. An early return with no retry scheduled is exactly how this
		// silently gave up forever on the very first check if the frame
		// happened to not be assigned yet at that exact moment.
		var frame = window.wp && wp.media && wp.media.frame;
		var done = frame && addToggle( frame.browserView );

		if ( done ) {
			console.log( '[Ei Image Crop] toggle inserted on attempt ' + attempt );
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
