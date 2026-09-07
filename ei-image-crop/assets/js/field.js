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
		return ( window.eiImageCrop && eiImageCrop.i18n && eiImageCrop.i18n[ key ] ) || key;
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
		var raw = $field.find( '.ei-image-crop-value' ).val();

		try {
			var parsed = JSON.parse( raw );
			return { id: parsed.id || '', source: parsed.source || '' };
		} catch ( e ) {
			return { id: raw || '', source: '' };
		}
	}

	function setState( $field, state ) {
		$field.find( '.ei-image-crop-value' ).val(
			JSON.stringify( { id: state.id || '', source: state.source || '' } )
		);
	}

	function setPreview( $field, url ) {
		var $preview = $field.find( '.ei-image-crop-preview' );

		if ( url ) {
			$preview.html( $( '<img />' ).attr( 'src', url ) );
			$field.find( '.ei-image-crop-edit, .ei-image-crop-remove' ).prop( 'hidden', false );
		} else {
			$preview.html( '<div class="ei-image-crop-placeholder"></div>' );
			$field.find( '.ei-image-crop-edit, .ei-image-crop-remove' ).prop( 'hidden', true );
		}
	}

	function buildModal() {
		if ( $modal ) {
			return $modal;
		}

		$modal = $(
			'<div class="ei-image-crop-modal" hidden>' +
				'<div class="ei-image-crop-modal-inner">' +
					'<div class="ei-image-crop-modal-main">' +
						'<div class="ei-image-crop-canvas"><img class="ei-image-crop-img" alt="" /></div>' +
					'</div>' +
					'<div class="ei-image-crop-modal-side">' +
						'<div class="ei-image-crop-live-preview"></div>' +
						'<div class="ei-image-crop-reuse" hidden>' +
							'<p class="ei-image-crop-reuse-title"></p>' +
							'<div class="ei-image-crop-reuse-list"></div>' +
						'</div>' +
						'<p class="ei-image-crop-error" hidden></p>' +
						'<div class="ei-image-crop-modal-actions">' +
							'<button type="button" class="button button-primary ei-image-crop-save"></button>' +
							'<button type="button" class="button ei-image-crop-save-new" hidden></button>' +
							'<button type="button" class="button ei-image-crop-cancel"></button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $modal );

		$modal.find( '.ei-image-crop-save' ).text( t( 'save' ) ).on( 'click', function () {
			performSave( false );
		} );
		$modal.find( '.ei-image-crop-save-new' ).text( t( 'saveNew' ) ).on( 'click', function () {
			performSave( true );
		} );
		$modal.find( '.ei-image-crop-cancel' ).text( t( 'cancel' ) ).on( 'click', closeModal );
		$modal.find( '.ei-image-crop-reuse-title' ).text( t( 'reuseTitle' ) );

		return $modal;
	}

	function showError( message ) {
		$modal.find( '.ei-image-crop-error' ).text( message ).prop( 'hidden', false );
	}

	function clearError() {
		$modal.find( '.ei-image-crop-error' ).prop( 'hidden', true ).text( '' );
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

	/**
	 * @param {number} actual Source image's own width/height ratio.
	 * @param {number} target Field's configured aspect ratio.
	 * @return {boolean} True when close enough that cropping would be a no-op.
	 */
	function ratioAlreadyMatches( actual, target ) {
		return Math.abs( actual - target ) / target < 0.01;
	}

	/**
	 * @param {Object} [pickedAttachment] Raw wp.media attachment JSON for a
	 *   fresh pick (undefined for "Adjust crop" on an already-set value) -
	 *   used to preview an already-cropped pick immediately without a
	 *   round trip, if it turns out to need no cropper at all.
	 */
	function openCropper( $field, sourceId, existingId, pickedAttachment ) {
		var modal = buildModal();

		currentField = $field;
		currentSourceId = sourceId;
		currentExistingId = existingId || '';

		clearError();
		modal.find( '.ei-image-crop-reuse' ).prop( 'hidden', true );
		modal.find( '.ei-image-crop-reuse-list' ).empty();
		// Only offer "save as new" when there's an existing crop that a plain
		// Save would otherwise overwrite in place; a brand new selection has
		// nothing to preserve, so the two buttons would do the same thing.
		modal.find( '.ei-image-crop-save-new' ).prop( 'hidden', ! existingId );

		var $img = modal.find( '.ei-image-crop-img' );
		$img.attr( 'src', '' );

		$.post( eiImageCrop.ajaxUrl, {
			action: 'ei_image_crop_get_source',
			nonce: eiImageCrop.nonce,
			source_id: sourceId,
			current_id: existingId || '',
			ratio: $field.data( 'ratio' ),
			preview_size: $field.data( 'preview-size' ),
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( ( response && response.data && response.data.message ) || t( 'error' ) );
					modal.prop( 'hidden', false );
					return;
				}

				var data = response.data;
				var wasFreshPick = ! existingId;

				// A fresh pick (existingId started out empty) of an image
				// that's itself already a crop gets resolved server-side to
				// its true original + that crop's own id, so adjusting it
				// later marks its actual existing box on the real original
				// instead of treating the crop as a brand new source to
				// crop again. Carry the resolved values forward for the
				// rest of this session (reuse row, save, save-as-new).
				sourceId   = data.source_id;
				existingId = data.existing_id || '';
				currentSourceId    = sourceId;
				currentExistingId  = existingId;
				modal.find( '.ei-image-crop-save-new' ).prop( 'hidden', ! existingId );

				// Picking an already-cropped image on purpose means using
				// that exact crop, not being forced to make a new one right
				// away - apply it as-is and leave the cropper unopened.
				// "Adjust crop" (which starts with a non-empty existingId,
				// so wasFreshPick is false) remains the explicit way to open
				// the editor for it afterward.
				if ( wasFreshPick && existingId ) {
					var previewSize = $field.data( 'preview-size' );
					var previewUrl = ( pickedAttachment && pickedAttachment.sizes && pickedAttachment.sizes[ previewSize ] )
						? pickedAttachment.sizes[ previewSize ].url
						: ( pickedAttachment ? pickedAttachment.url : data.edit.url );

					setState( $field, { id: existingId, source: sourceId } );
					setPreview( $field, previewUrl );
					currentField = null;
					currentSourceId = null;
					currentExistingId = null;
					return;
				}

				function showInteractiveCropper() {
					renderReuseList( $field, data.existing || [] );
					modal.prop( 'hidden', false );

					$img.one( 'load', function () {
						initCropper( $field, this, data.box );
					} );

					$img.attr( 'src', data.edit.url );
				}

				var ratioLabel = $field.data( 'ratio' );
				var targetRatio = parseAspectRatio( ratioLabel );
				var actualRatio = data.edit.width / data.edit.height;

				// A brand new selection (not an explicit "Adjust crop", and
				// not resolved above to an existing crop either) whose own
				// proportions already closely match a fixed target ratio has
				// nothing meaningful to crop - use it right away instead of
				// forcing the modal open. "Adjust crop" is still there
				// afterward for anyone who wants to fine-tune it anyway.
				if ( ! existingId && ! isNaN( targetRatio ) && ratioAlreadyMatches( actualRatio, targetRatio ) ) {
					autoSave( $field, sourceId, ratioLabel, data.box, showInteractiveCropper );
					return;
				}

				showInteractiveCropper();
			} )
			.fail( function () {
				showError( t( 'error' ) );
				modal.prop( 'hidden', false );
			} );
	}

	/**
	 * Silently saves the server-computed default box without opening the
	 * cropper UI, for the "already the right shape" fast path. Falls back to
	 * the interactive cropper if the save unexpectedly fails, so the field
	 * never gets stuck with a picked image and no way to finish setting it.
	 */
	function autoSave( $field, sourceId, ratio, box, onFallback ) {
		$.post( eiImageCrop.ajaxUrl, {
			action: 'ei_image_crop_save',
			nonce: eiImageCrop.nonce,
			source_id: sourceId,
			existing_id: '',
			field_key: $field.data( 'field-key' ),
			ratio: ratio,
			box: box,
			preview_size: $field.data( 'preview-size' ),
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					onFallback();
					return;
				}

				setState( $field, { id: response.data.id, source: sourceId } );
				setPreview( $field, response.data.url );
				currentField = null;
				currentSourceId = null;
				currentExistingId = null;
			} )
			.fail( onFallback );
	}

	function initCropper( $field, imgEl, box ) {
		if ( cropper ) {
			cropper.destroy();
		}

		var aspectRatio = parseAspectRatio( $field.data( 'ratio' ) );

		cropper = new Cropper( imgEl, {
			aspectRatio: aspectRatio,
			viewMode: 1,
			// 'move' lets dragging the canvas pan the underlying image
			// around; 'none' keeps the image completely fixed and leaves
			// only the crop box itself draggable/resizable via its own
			// handles, which is what "the image should stay put" means.
			dragMode: 'none',
			autoCropArea: 1,
			responsive: true,
			background: false,
			zoomOnWheel: false,
			preview: '.ei-image-crop-live-preview',
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

		var $list = $modal.find( '.ei-image-crop-reuse-list' ).empty();

		crops.forEach( function ( crop ) {
			var $thumb = $( '<button type="button" class="ei-image-crop-reuse-item"></button>' )
				.attr( 'title', crop.title )
				.append( $( '<img />' ).attr( 'src', crop.url ) )
				.on( 'click', function () {
					setState( $field, { id: crop.id, source: currentSourceId } );
					setPreview( $field, crop.preview );
					closeModal();
				} );

			$list.append( $thumb );
		} );

		$modal.find( '.ei-image-crop-reuse' ).prop( 'hidden', false );
	}

	/**
	 * @param {boolean} forceNew When true, always create a new crop
	 *   attachment instead of overwriting the one currently being edited -
	 *   for keeping an alternate composition around instead of replacing it.
	 */
	function performSave( forceNew ) {
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
		var existingId = forceNew ? '' : currentExistingId;

		clearError();
		$modal.find( '.ei-image-crop-save, .ei-image-crop-save-new' ).prop( 'disabled', true );

		$.post( eiImageCrop.ajaxUrl, {
			action: 'ei_image_crop_save',
			nonce: eiImageCrop.nonce,
			source_id: sourceId,
			existing_id: existingId,
			field_key: fieldKey,
			ratio: ratio,
			box: box,
			preview_size: $field.data( 'preview-size' ),
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
				$modal.find( '.ei-image-crop-save, .ei-image-crop-save-new' ).prop( 'disabled', false );
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
			openCropper( $field, attachment.id, '', attachment );
		} );

		frame.open();
	}

	function initField( el ) {
		var $field = $( el );

		if ( $field.data( 'eiImageCropInitialized' ) ) {
			return;
		}
		$field.data( 'eiImageCropInitialized', true );

		$field.on( 'click', '.ei-image-crop-select', function ( e ) {
			e.preventDefault();
			openMediaFrame( $field );
		} );

		$field.on( 'click', '.ei-image-crop-edit', function ( e ) {
			e.preventDefault();
			var state = getState( $field );
			if ( state.source ) {
				openCropper( $field, state.source, state.id );
			}
		} );

		$field.on( 'click', '.ei-image-crop-remove', function ( e ) {
			e.preventDefault();
			setState( $field, { id: '', source: '' } );
			setPreview( $field, '' );
		} );
	}

	function initAll( $context ) {
		$( '.ei-image-crop-field', $context ).each( function () {
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
