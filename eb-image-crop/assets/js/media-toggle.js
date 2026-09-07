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
				'<label class="eb-image-crop-toggle">' +
					'<input type="checkbox" />' +
					' ' + ( window.ebImageCropMedia ? ebImageCropMedia.label : 'Show generated crops' ) +
				'</label>'
			);

			$toggle.find( 'input' ).on( 'change', function () {
				var checked = $( this ).is( ':checked' );
				library.props.set( { ebShowCrops: checked ? 1 : '' } );
			} );

			this.toolbar.$el.append( $toggle );
		},
	} );
} )( jQuery );
