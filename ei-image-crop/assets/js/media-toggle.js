/**
 * Adds a "Show generated crops" checkbox to the media modal grid toolbar,
 * mirroring the classic list table's toggle link.
 */
( function ( $ ) {
	'use strict';

	if ( ! window.wp || ! wp.media || ! wp.media.view || ! wp.media.view.AttachmentsBrowser ) {
		return;
	}

	var BaseBrowser = wp.media.view.AttachmentsBrowser;

	wp.media.view.AttachmentsBrowser = BaseBrowser.extend( {
		createToolbar: function () {
			BaseBrowser.prototype.createToolbar.apply( this, arguments );

			var library = this.collection;
			var $toggle = $(
				'<label class="ei-image-crop-toggle">' +
					'<input type="checkbox" />' +
					' ' + ( window.eiImageCropMedia ? eiImageCropMedia.label : 'Show generated crops' ) +
				'</label>'
			);

			$toggle.find( 'input' ).on( 'change', function () {
				var checked = $( this ).is( ':checked' );
				library.props.set( { eiShowCrops: checked ? 1 : '' } );
			} );

			// Appending straight to the toolbar root (rather than into one of
			// its floated filter/search sections) puts the checkbox at the
			// mercy of whatever's left in the row - it can end up wrapping
			// onto its own line or squeezed somewhere unexpected. The
			// secondary section (date filter, media type dropdown) is the
			// one place across both the standalone Library grid and the
			// media-picker modal that's reliably there and makes sense to
			// group this with, so put it there; fall back to the toolbar
			// root itself on the off chance a future core layout drops that
			// section, so the checkbox still ends up somewhere rather than
			// vanishing entirely.
			var $secondary = this.toolbar.$el.find( '.media-toolbar-secondary' );
			( $secondary.length ? $secondary : this.toolbar.$el ).append( $toggle );
		},
	} );
} )( jQuery );
