/**
 * Adds a "Show generated crops" checkbox to the media modal grid toolbar,
 * mirroring the classic list table's toggle link.
 */
( function ( $ ) {
	'use strict';

	function label() {
		return window.eiImageCropMedia ? eiImageCropMedia.label : 'Show generated crops';
	}

	/**
	 * Injects the checkbox into an already-built AttachmentsBrowser view and
	 * wires it up. Safe to call more than once on the same view - it no-ops
	 * if the checkbox is already there.
	 *
	 * @param {Object} browserView A wp.media.view.AttachmentsBrowser instance.
	 */
	function addToggle( browserView ) {
		if ( ! browserView || ! browserView.toolbar || ! browserView.collection ) {
			return;
		}

		if ( browserView.toolbar.$el.find( '.ei-image-crop-toggle' ).length ) {
			return;
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

		// Appending straight to the toolbar root (rather than into one of its
		// floated filter/search sections) puts the checkbox at the mercy of
		// whatever's left in the row - it can end up wrapping onto its own
		// line or squeezed somewhere unexpected. The secondary section (date
		// filter, media type dropdown) is the one place across both the
		// standalone Library grid and the media-picker modal that's reliably
		// there and makes sense to group this with; fall back to the toolbar
		// root itself so the checkbox still ends up somewhere rather than
		// vanishing entirely if a future core layout drops that section.
		var $secondary = browserView.toolbar.$el.find( '.media-toolbar-secondary' );
		( $secondary.length ? $secondary : browserView.toolbar.$el ).append( $toggle );
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
	 * original class. wp.media.frame is WordPress's own reference to that
	 * already-built frame, so reach into its current content view directly.
	 */
	function patchExistingFrame() {
		if ( ! window.wp || ! wp.media || ! wp.media.frame || ! wp.media.frame.content ) {
			return;
		}

		var content = wp.media.frame.content.get();

		if ( content ) {
			addToggle( content );
		}
	}

	$( function () {
		patchClassForFutureViews();
		patchExistingFrame();

		// wp.media.frame can be assigned, or its content view (re)rendered,
		// slightly after DOMContentLoaded in some WordPress versions. A
		// couple of delayed re-checks catch that without depending on this
		// script's exact position relative to core's own bootstrap script.
		setTimeout( patchExistingFrame, 300 );
		setTimeout( patchExistingFrame, 1000 );
	} );
} )( jQuery );
