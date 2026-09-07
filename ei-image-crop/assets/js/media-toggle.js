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

		// toolbar.secondary is the actual rendered sub-view holding the date
		// filter / media type dropdown (confirmed via wp.media.frame.browserView
		// in a live session - it has its own real $el), which groups the
		// checkbox with the other filters instead of leaving its position to
		// chance. Fall back to the toolbar's own $el if a future core version
		// doesn't have that sub-view, so the checkbox still shows up somewhere.
		var $target = ( browserView.toolbar.secondary && browserView.toolbar.secondary.$el && browserView.toolbar.secondary.$el.length )
			? browserView.toolbar.secondary.$el
			: browserView.toolbar.$el;

		$target.append( $toggle );
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
	 * original class. wp.media.frame.browserView is WordPress's own direct
	 * reference to that already-built view, so reach into it directly.
	 */
	function patchExistingFrame() {
		if ( ! window.wp || ! wp.media || ! wp.media.frame ) {
			return;
		}

		addToggle( wp.media.frame.browserView );
	}

	$( function () {
		patchClassForFutureViews();
		patchExistingFrame();

		// wp.media.frame.browserView can be (re)assigned slightly after
		// DOMContentLoaded in some WordPress versions. A couple of delayed
		// re-checks catch that without depending on this script's exact
		// position relative to core's own bootstrap script.
		setTimeout( patchExistingFrame, 300 );
		setTimeout( patchExistingFrame, 1000 );
	} );
} )( jQuery );
