/**
 * Admin UI for the "Image Crop" ACF field type: picks a source image from
 * the Media Library, then opens a Cropper.js modal (fixed, scalable frame
 * for locked ratios; a free-form frame otherwise) with a live preview and a
 * row of already-existing crops of the same source/ratio for reuse.
 */
( function ( $ ) {
	'use strict';

	var $modal, cropper, currentField, currentSourceId, currentExistingId;

	function t( key ) {
		return ( window.ebImageCrop && ebImageCrop.i18n && ebImageCrop.i18n[ key ] ) || key;
	}

	function parseAspectRatio( ratio ) {
		if ( ! ratio || 'free' === ratio ) {
			return NaN;
		}

		var parts = ratio.split( ':' );
		if ( 2 !== parts.length ) {
			return NaN;
		}

		var w = parseFloat( parts[ 0 ] );
		var h = parseFloat( parts[ 1 ] );

		return w > 0 && h > 0 ? w / h : NaN;
	}

	function getState( $field ) {
		var raw = $field.find( '.eb-image-crop-value' ).val();

		try {
			var parsed = JSON.parse( raw );
			return { id: parsed.id || '', source: parsed.source || '' };
		} catch ( e ) {
			return { id: raw || '', source: '' };
		}
	}

	function setState( $field, state ) {
		$field.find( '.eb-image-crop-value' ).val(
			JSON.stringify( { id: state.id || '', source: state.source || '' } )
		);
	}

	function setPreview( $field, url ) {
		var $preview = $field.find( '.eb-image-crop-preview' );

		if ( url ) {
			$preview.html( $( '<img />' ).attr( 'src', url ) );
			$field.find( '.eb-image-crop-edit, .eb-image-crop-remove' ).prop( 'hidden', false );
		} else {
			$preview.html( '<div class="eb-image-crop-placeholder"></div>' );
			$field.find( '.eb-image-crop-edit, .eb-image-crop-remove' ).prop( 'hidden', true );
		}
	}

	function buildModal() {
		if ( $modal ) {
			return $modal;
		}

		$modal = $(
			'<div class="eb-image-crop-modal" hidden>' +
				'<div class="eb-image-crop-modal-inner">' +
					'<div class="eb-image-crop-modal-main">' +
						'<div class="eb-image-crop-canvas"><img class="eb-image-crop-img" alt="" /></div>' +
					'</div>' +
					'<div class="eb-image-crop-modal-side">' +
						'<div class="eb-image-crop-live-preview"></div>' +
						'<div class="eb-image-crop-reuse" hidden>' +
							'<p class="eb-image-crop-reuse-title"></p>' +
							'<div class="eb-image-crop-reuse-list"></div>' +
						'</div>' +
						'<p class="eb-image-crop-error" hidden></p>' +
						'<div class="eb-image-crop-modal-actions">' +
							'<button type="button" class="button button-primary eb-image-crop-save"></button>' +
							'<button type="button" class="button eb-image-crop-cancel"></button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $modal );

		$modal.find( '.eb-image-crop-save' ).text( t( 'save' ) ).on( 'click', onSave );
		$modal.find( '.eb-image-crop-cancel' ).text( t( 'cancel' ) ).on( 'click', closeModal );
		$modal.find( '.eb-image-crop-reuse-title' ).text( t( 'reuseTitle' ) );

		return $modal;
	}

	function showError( message ) {
		$modal.find( '.eb-image-crop-error' ).text( message ).prop( 'hidden', false );
	}

	function clearError() {
		$modal.find( '.eb-image-crop-error' ).prop( 'hidden', true ).text( '' );
	}

	function closeModal() {
		if ( cropper ) {
			cropper.destroy();
			cropper = null;
		}

		if ( $modal ) {
			$modal.prop( 'hidden', true );
		}

		currentField = null;
		currentSourceId = null;
		currentExistingId = null;
	}

	function openCropper( $field, sourceId, existingId ) {
		var modal = buildModal();

		currentField = $field;
		currentSourceId = sourceId;
		currentExistingId = existingId || '';

		clearError();
		modal.find( '.eb-image-crop-reuse' ).prop( 'hidden', true );
		modal.find( '.eb-image-crop-reuse-list' ).empty();
		modal.prop( 'hidden', false );

		var $img = modal.find( '.eb-image-crop-img' );
		$img.attr( 'src', '' );

		$.post( ebImageCrop.ajaxUrl, {
			action: 'eb_image_crop_get_source',
			nonce: ebImageCrop.nonce,
			source_id: sourceId,
			current_id: existingId || '',
			ratio: $field.data( 'ratio' ),
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( ( response && response.data && response.data.message ) || t( 'error' ) );
					return;
				}

				var data = response.data;

				renderReuseList( $field, data.existing || [] );

				$img.one( 'load', function () {
					initCropper( $field, this, data.box );
				} );

				$img.attr( 'src', data.edit.url );
			} )
			.fail( function () {
				showError( t( 'error' ) );
			} );
	}

	function initCropper( $field, imgEl, box ) {
		if ( cropper ) {
			cropper.destroy();
		}

		var aspectRatio = parseAspectRatio( $field.data( 'ratio' ) );

		cropper = new Cropper( imgEl, {
			aspectRatio: aspectRatio,
			viewMode: 1,
			dragMode: 'move',
			autoCropArea: 1,
			responsive: true,
			background: false,
			preview: '.eb-image-crop-live-preview',
			ready: function () {
				var natural = { w: imgEl.naturalWidth, h: imgEl.naturalHeight };

				cropper.setData( {
					x: box.x * natural.w,
					y: box.y * natural.h,
					width: box.w * natural.w,
					height: box.h * natural.h,
				} );
			},
		} );
	}

	function renderReuseList( $field, crops ) {
		if ( ! crops.length ) {
			return;
		}

		var $list = $modal.find( '.eb-image-crop-reuse-list' ).empty();

		crops.forEach( function ( crop ) {
			var $thumb = $( '<button type="button" class="eb-image-crop-reuse-item"></button>' )
				.attr( 'title', crop.title )
				.append( $( '<img />' ).attr( 'src', crop.url ) )
				.on( 'click', function () {
					setState( $field, { id: crop.id, source: currentSourceId } );
					setPreview( $field, crop.url );
					closeModal();
				} );

			$list.append( $thumb );
		} );

		$modal.find( '.eb-image-crop-reuse' ).prop( 'hidden', false );
	}

	function onSave() {
		if ( ! cropper || ! currentField ) {
			return;
		}

		var data = cropper.getData();
		var natural = cropper.getImageData();
		var box = {
			x: data.x / natural.naturalWidth,
			y: data.y / natural.naturalHeight,
			w: data.width / natural.naturalWidth,
			h: data.height / natural.naturalHeight,
		};

		var $field = currentField;
		var fieldKey = $field.data( 'field-key' );
		var ratio = $field.data( 'ratio' );
		var sourceId = currentSourceId;
		var existingId = currentExistingId;

		clearError();
		$modal.find( '.eb-image-crop-save' ).prop( 'disabled', true );

		$.post( ebImageCrop.ajaxUrl, {
			action: 'eb_image_crop_save',
			nonce: ebImageCrop.nonce,
			source_id: sourceId,
			existing_id: existingId,
			field_key: fieldKey,
			ratio: ratio,
			box: box,
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( ( response && response.data && response.data.message ) || t( 'error' ) );
					return;
				}

				setState( $field, { id: response.data.id, source: sourceId } );
				setPreview( $field, response.data.url );
				closeModal();
			} )
			.fail( function () {
				showError( t( 'error' ) );
			} )
			.always( function () {
				$modal.find( '.eb-image-crop-save' ).prop( 'disabled', false );
			} );
	}

	function openMediaFrame( $field ) {
		var frame = wp.media( {
			title: t( 'selectImage' ),
			library: { type: 'image' },
			multiple: false,
			button: { text: t( 'useImage' ) },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			openCropper( $field, attachment.id, '' );
		} );

		frame.open();
	}

	function initField( el ) {
		var $field = $( el );

		if ( $field.data( 'ebImageCropInitialized' ) ) {
			return;
		}
		$field.data( 'ebImageCropInitialized', true );

		$field.on( 'click', '.eb-image-crop-select', function ( e ) {
			e.preventDefault();
			openMediaFrame( $field );
		} );

		$field.on( 'click', '.eb-image-crop-edit', function ( e ) {
			e.preventDefault();
			var state = getState( $field );
			if ( state.source ) {
				openCropper( $field, state.source, state.id );
			}
		} );

		$field.on( 'click', '.eb-image-crop-remove', function ( e ) {
			e.preventDefault();
			setState( $field, { id: '', source: '' } );
			setPreview( $field, '' );
		} );
	}

	function initAll( $context ) {
		$( '.eb-image-crop-field', $context ).each( function () {
			initField( this );
		} );
	}

	$( function () {
		initAll( document );
	} );

	if ( window.acf && acf.addAction ) {
		acf.addAction( 'append', function ( $el ) {
			initAll( $el );
		} );
	}
} )( jQuery );
