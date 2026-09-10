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
		if ( ! browserView || ! browserView.el || ! browserView.collection ) {
			console.log( '[Ei Image Crop] addToggle: bailing, missing browserView/el/collection', { hasBrowserView: !! browserView, hasEl: !! ( browserView && browserView.el ), hasCollection: !! ( browserView && browserView.collection ) } );
			return false;
		}

		var $browser = $( browserView.el );

		// Already inserted into this browser view - never insert a second
		// one. Deliberately silent: with the self-healing watch in
		// insertToggleWhenAttached(), this is the common case on every
		// mutation once the toggle has settled in, not worth logging.
		if ( $browser.find( '> .ei-image-crop-toggle' ).length ) {
			return true;
		}

		// Confirmed live: in the "Select or Upload Media" picker (as opposed
		// to the standalone Media Library page), the toolbar view can exist
		// - createToolbar() has run - without being attached to the
		// document yet; WordPress inserts it into the visible frame
		// slightly later. And in the "Edit image details" popup (a single
		// attachment, opened via the crop field's own pencil icon), the
		// toolbar apparently never gets a visible row attached at all -
		// filtering by type/date makes no sense for one fixed attachment.
		// Rather than depend on the toolbar sub-view specifically (which
		// may never attach), wait for the browse view's own top-level
		// element to be attached instead - it has to be, for anything in
		// it to be visible at all - and place the toggle right after the
		// toolbar when one actually rendered into this same tree, or at
		// the top of the browse view otherwise.
		if ( ! $browser[ 0 ].isConnected ) {
			console.log( '[Ei Image Crop] addToggle: browserView.el exists but is not connected yet', browserView.el );
			return false;
		}

		console.log( '[Ei Image Crop] addToggle: inserting toggle now', browserView.el );

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

		var $toolbar = browserView.toolbar && browserView.toolbar.$el;

		if ( $toolbar && $toolbar.length && $toolbar.parent().is( $browser ) ) {
			$toolbar.after( $toggle );
		} else {
			$browser.prepend( $toggle );
		}

		reserveSpaceForToggle( $browser, $toggle );

		return true;
	}

	/**
	 * Confirmed live (with the actual WordPress core CSS rule involved,
	 * found via dev tools): the toggle was inserting correctly all along
	 * (and staying there) - it just never became visible. Two things,
	 * both stemming from the same cause - WordPress positions the
	 * toolbar, the attachments grid/list AND the uploader dropzone all
	 * absolutely, each with a hardcoded `top` that assumes only the
	 * toolbar sits above the grid:
	 *
	 * 1. Those siblings' own `top` doesn't leave room for anything else -
	 *    a normal-flow sibling like ours doesn't push an absolutely
	 *    positioned element down, so they need to be pushed down manually
	 *    by exactly this toggle's own rendered height.
	 * 2. The toolbar itself is ALSO positioned absolutely, so it takes no
	 *    space in normal flow either - our toggle, being the only real
	 *    normal-flow content left in .attachments-browser, would
	 *    otherwise render starting at the very top of it, overlapped by
	 *    the toolbar drawn on top. It needs a top margin of its own to
	 *    clear the toolbar, before the grid gets pushed down to clear it.
	 *
	 * The grid/uploader's own original `top` (exactly how much room the
	 * toolbar needs) doubles as that margin, rather than measuring the
	 * toolbar separately - one less thing that can be a different element
	 * than expected. Computed at runtime instead of hardcoding pixel
	 * values, so this holds regardless of the toolbar's actual height in
	 * any given WP version/admin color scheme/theme.
	 *
	 * @param {jQuery} $browser The .attachments-browser element.
	 * @param {jQuery} $toggle  The just-inserted .ei-image-crop-toggle.
	 */
	function reserveSpaceForToggle( $browser, $toggle ) {
		var $positioned = $browser.find( '.uploader-inline, .attachments-wrapper, .attachments, .media-sidebar' ).filter( function () {
			return 'absolute' === $( this ).css( 'position' );
		} );

		if ( ! $positioned.length ) {
			return;
		}

		$toggle.css( 'margin-top', $positioned.first().css( 'top' ) );

		var toggleHeight = $toggle.outerHeight( false );

		$positioned.each( function () {
			var $el = $( this );
			var currentTop = parseFloat( $el.css( 'top' ) ) || 0;
			$el.css( 'top', ( currentTop + toggleHeight ) + 'px' );
		} );
	}

	// A fixed retry budget (tried first: 30 attempts x 100ms = 3s) turned
	// out to still be too short for some pickers. Rather than guess at
	// ever-larger fixed budgets, watch the document directly for the
	// browse view's own element actually becoming attached
	// (Node.isConnected) and act the moment it does, with no arbitrary
	// time limit - the same approach field.js already relies on for an
	// equivalent problem (a field's own init not reliably happening in
	// time via ACF's events).
	var ATTACH_SAFETY_TIMEOUT = 30000;

	/**
	 * addToggle() can come back false because the browse view's own
	 * element isn't attached to the document yet (see the comment inside
	 * addToggle). Insert as soon as that stops being true, however long
	 * it takes.
	 *
	 * @param {Object} browserView
	 */
	function insertToggleWhenAttached( browserView ) {
		if ( ! browserView || ! browserView.el ) {
			console.log( '[Ei Image Crop] insertToggleWhenAttached: bailing, no browserView/el at all' );
			return;
		}

		var browserEl = browserView.el;

		// Confirmed live: a plain "wait for it to connect, insert once"
		// watch DID see the browse view connect and DID successfully
		// insert the toggle (logged "addToggle: inserting toggle now") -
		// yet the toggle was gone again by the time the popup was actually
		// checked. Something in WordPress's own rendering runs AFTER that
		// first connection and replaces the container's content, wiping
		// our insert out along with it. Rather than guess how many
		// rendering passes to wait through, keep re-asserting the
		// toggle's presence on every subsequent mutation too, for as long
		// as the browse view itself stays connected - addToggle() is
		// already a no-op once the toggle is there and staying there, so
		// this costs nothing once things settle down.
		var stopWatching = setTimeout( function () {
			observer.disconnect();
			if ( ! $( browserEl ).find( '> .ei-image-crop-toggle' ).length ) {
				console.warn( '[Ei Image Crop] gave up - toggle never stuck within ' + ( ATTACH_SAFETY_TIMEOUT / 1000 ) + 's', browserEl );
			}
		}, ATTACH_SAFETY_TIMEOUT );

		var observer = new MutationObserver( function () {
			if ( ! browserEl.isConnected ) {
				// View closed/torn down - nothing left to maintain.
				observer.disconnect();
				clearTimeout( stopWatching );
				return;
			}
			addToggle( browserView );
		} );

		observer.observe( document.documentElement, { childList: true, subtree: true } );

		// Try immediately too - covers the (already-connected) case where
		// no further mutations happen to ever trigger the observer.
		addToggle( browserView );
	}

	/**
	 * Patches the AttachmentsBrowser class so any browser view built AFTER
	 * this point (e.g. a media-picker modal opened later by a user click)
	 * gets the toggle automatically via its own createToolbar().
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
				console.log( '[Ei Image Crop] patched createToolbar() ran', this.el );
				insertToggleWhenAttached( this );
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
			console.log( '[Ei Image Crop] (page-load check) toggle inserted on attempt ' + attempt );
			return;
		}

		if ( attempt < MAX_ATTEMPTS ) {
			setTimeout( function () {
				patchExistingFrame( attempt + 1 );
			}, RETRY_DELAY );
		} else {
			// Only covers a frame that already exists at page-load time (e.g.
			// the standalone Media Library's own bootstrap) - irrelevant
			// noise for a picker opened later via a click, which
			// patchClassForFutureViews()'s createToolbar() override handles
			// instead. Labelled to avoid it being mistaken for a real
			// failure signal when a later click is what's being debugged.
			console.warn( '[Ei Image Crop] (page-load check, not relevant to a later click) gave up after ' + attempt + ' attempts - wp.media.frame present: ' + !! frame );
		}
	}

	/**
	 * The classic list table's own toggle (see render_list_toggle() PHP-side)
	 * has to be echoed from inside the restrict_manage_posts hook, which
	 * fires inside WP core's own .wp-filter > .filter-items > .actions box -
	 * sandwiched between the "Alla datum" dropdown and the "Filtrera"
	 * button, both of which it needs to sit below, not next to. That box is
	 * server-rendered as part of the page itself (unlike the Backbone
	 * pickers the rest of this file deals with), so it's already in the DOM
	 * by the time this runs - no waiting/observing needed, just move it.
	 */
	function relocateListTableToggle() {
		var $toggle = $( '.wp-filter .ei-image-crop-toggle' );
		var $wpFilter = $toggle.closest( '.wp-filter' );

		if ( $toggle.length && $wpFilter.length ) {
			$toggle.insertAfter( $wpFilter );
		}
	}

	$( function () {
		try {
			console.log( '[Ei Image Crop] media-toggle.js running, jQuery available: ' + ( typeof $ === 'function' ) );
			relocateListTableToggle();
			patchClassForFutureViews();
			patchExistingFrame( 0 );
		} catch ( e ) {
			console.error( '[Ei Image Crop] media-toggle.js threw an error:', e );
		}
	} );
} )( window.jQuery );
