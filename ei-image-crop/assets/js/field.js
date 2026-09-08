/**
 * Admin UI for the "Image Crop" ACF field type: picks a source image from
 * the Media Library, then opens a Cropper.js modal (fixed, scalable frame
 * for locked ratios; a free-form frame otherwise) with a live preview and a
 * row of already-existing crops of the same source/ratio for reuse.
 */
( function ( $ ) {
	'use strict';

	var $modal, $confirmModal, $attachmentModal, cropper, currentField, currentSourceId, currentExistingId;
	// How many true-original pixels each on-screen crop-box pixel actually
	// represents - see initCropper()'s trueScale param. 1 when the editor
	// image is already the true original; only ever bigger, since the edit
	// image is at most that size (a smaller "-scaled"/"large" copy, never
	// larger). Read by updatePreviewCaption()/updateBoxLabel() so the
	// pixel counts shown always reflect the real crop that would be
	// produced, not the editor's own downscaled preview.
	var currentTrueScale = 1;
	// The current field's target size (see parseTargetSize()), or null for
	// a free-form field - set in initCropper(). Read by isUndersized(),
	// updatePreviewCaption() and updateBoxLabel() so they don't each need
	// their own reference to it.
	var currentTargetSize = null;
	// Set while a reuse thumbnail's own box is being shown for a look before
	// committing to it; cleared as soon as the user adjusts the crop box
	// themselves, or the modal closes. Drives the save button's "Use image"
	// vs. "Crop image" state.
	var selectedReuseCrop = null;
	// Guards the crop box's own 'crop' event handler against the setData()
	// call selecting a reuse thumbnail makes on the user's behalf - without
	// this, showing that thumbnail's box would immediately look like a
	// manual adjustment and deselect itself.
	var suppressCropEvents = false;

	function t( key ) {
		return ( window.eiImageCrop && eiImageCrop.i18n && eiImageCrop.i18n[ key ] ) || key;
	}

	function buildConfirmDialog() {
		if ( $confirmModal ) {
			return $confirmModal;
		}

		$confirmModal = $(
			'<div class="ei-image-crop-confirm" hidden>' +
				'<div class="ei-image-crop-confirm-inner">' +
					'<p class="ei-image-crop-confirm-title"></p>' +
					'<p class="ei-image-crop-confirm-message"></p>' +
					'<div class="ei-image-crop-confirm-actions">' +
						'<button type="button" class="button ei-image-crop-confirm-cancel"></button>' +
						'<button type="button" class="button button-primary ei-image-crop-confirm-delete"></button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $confirmModal );

		return $confirmModal;
	}

	/**
	 * In-app replacement for window.confirm() - the browser's native dialog
	 * wraps the message in its own "From <host>:" chrome, which looks broken
	 * and unpolished for something as final as a permanent delete.
	 *
	 * @param {string} title
	 * @param {string} message
	 * @return {Promise<boolean>} Resolves true if the user confirmed.
	 */
	function confirmDialog( title, message ) {
		var $dialog = buildConfirmDialog();

		$dialog.find( '.ei-image-crop-confirm-title' ).text( title );
		$dialog.find( '.ei-image-crop-confirm-message' ).text( message );
		$dialog.find( '.ei-image-crop-confirm-cancel' ).text( t( 'cancel' ) );
		$dialog.find( '.ei-image-crop-confirm-delete' ).text( t( 'delete' ) );
		$dialog.prop( 'hidden', false );

		return new Promise( function ( resolve ) {
			function settle( result ) {
				$dialog.prop( 'hidden', true );
				$dialog.off( '.eiImageCropConfirm' );
				resolve( result );
			}

			$dialog.on( 'click.eiImageCropConfirm', '.ei-image-crop-confirm-cancel', function () {
				settle( false );
			} );
			$dialog.on( 'click.eiImageCropConfirm', '.ei-image-crop-confirm-delete', function () {
				settle( true );
			} );
			// Clicking the dimmed backdrop itself (not the dialog card) cancels.
			$dialog.on( 'click.eiImageCropConfirm', function ( e ) {
				if ( e.target === $dialog[ 0 ] ) {
					settle( false );
				}
			} );
		} );
	}

	function buildAttachmentModal() {
		if ( $attachmentModal ) {
			return $attachmentModal;
		}

		$attachmentModal = $(
			'<div class="ei-image-crop-attachment-modal" hidden>' +
				'<div class="ei-image-crop-attachment-modal-inner">' +
					'<button type="button" class="ei-image-crop-close ei-image-crop-attachment-close" aria-label="' + t( 'close' ) + '"></button>' +
					'<iframe class="ei-image-crop-attachment-frame" title="' + t( 'editDetails' ) + '"></iframe>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $attachmentModal );

		$attachmentModal.find( '.ei-image-crop-attachment-close' ).on( 'click', closeAttachmentModal );
		// Clicking the dimmed backdrop itself (not the popup card) closes it too.
		$attachmentModal.on( 'click', function ( e ) {
			if ( e.target === $attachmentModal[ 0 ] ) {
				closeAttachmentModal();
			}
		} );

		return $attachmentModal;
	}

	/**
	 * Shows the WP attachment details popup (Media Library's own
	 * upload.php?item={id} view) in an iframe, on top of the current page,
	 * instead of following the link - that link still works as a normal
	 * <a> (middle-click/ctrl-click to open a real new tab still does), but
	 * a plain left-click would otherwise navigate this whole tab away to
	 * the Media Library, leaving you there once the WP popup is closed
	 * instead of back on whatever you were editing.
	 *
	 * @param {string} href
	 */
	function openAttachmentModal( href ) {
		var $modal = buildAttachmentModal();
		$modal.find( '.ei-image-crop-attachment-frame' ).attr( 'src', href );
		$modal.prop( 'hidden', false );
	}

	function closeAttachmentModal() {
		if ( ! $attachmentModal ) {
			return;
		}
		$attachmentModal.prop( 'hidden', true );
		// Clears the iframe's content (not just hiding it) so any state in
		// there (unsaved edits, playing media) doesn't linger for next time.
		$attachmentModal.find( '.ei-image-crop-attachment-frame' ).attr( 'src', 'about:blank' );
	}

	// Dashicons has no crop glyph, so "Adjust crop" uses this inline SVG
	// instead - kept identical to Ei_Image_Crop_Field::crop_icon() so a
	// freshly-saved preview (built here) looks the same as the server-rendered one.
	var CROP_ICON_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M17 15h2V7c0-1.1-.9-2-2-2H9v2h8v8zM7 17V1H5v4H1v2h4v10c0 1.1.9 2 2 2h10v4h2v-4h4v-2H7z"/></svg>';
	// Plain pencil, no baseline stroke - kept identical to
	// Ei_Image_Crop_Field::pencil_icon() (dashicons-edit's baseline read as
	// a stray underline at this size).
	var PENCIL_ICON_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>';

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

	/**
	 * A field's ratio (see Ei_Image_Crop_Field::resolve_ratio()) is always
	 * a registered WP image size's own literal pixel dimensions when it
	 * locks the ratio at all, not just a proportion - this pulls those
	 * back out for the undersized/upscale-warning check, which needs the
	 * actual target size, not just its shape.
	 *
	 * @param {string} ratio
	 * @return {{width: number, height: number}|null}
	 */
	function parseTargetSize( ratio ) {
		if ( ! ratio || 'free' === ratio ) {
			return null;
		}

		var parts = ratio.split( ':' );
		if ( 2 !== parts.length ) {
			return null;
		}

		var w = parseFloat( parts[ 0 ] );
		var h = parseFloat( parts[ 1 ] );

		return ( w > 0 && h > 0 ) ? { width: w, height: h } : null;
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

	/**
	 * @param {jQuery} $field
	 * @param {string} url Preview image URL, or '' to clear the preview.
	 * @param {number|string} [id] Current attachment id, only needed to link
	 *   the "edit details" icon to its own WP attachment edit screen.
	 */
	function setPreview( $field, url, id ) {
		var $preview = $field.find( '.ei-image-crop-preview' );
		var $actions = $field.find( '.ei-image-crop-actions' );

		if ( url ) {
			// The image itself doubles as the "pick a different image"
			// control (same convention as ACF's own native Image field) -
			// the pencil icon is for something else entirely (see below).
			$preview.empty()
				.append( $( '<img class="ei-image-crop-select" />' ).attr( 'src', url ) )
				.append(
					$( '<div class="ei-image-crop-overlay" />' ).append(
						$( '<a target="_blank" rel="noopener" class="ei-image-crop-icon-btn ei-image-crop-open-attachment">' + PENCIL_ICON_SVG + '</a>' )
							.attr( 'href', eiImageCrop.editUrlBase + id )
							.attr( 'title', t( 'editDetails' ) ),
						$( '<button type="button" class="ei-image-crop-icon-btn ei-image-crop-edit">' + CROP_ICON_SVG + '</button>' )
							.attr( 'title', t( 'adjustCrop' ) ),
						$( '<button type="button" class="ei-image-crop-icon-btn ei-image-crop-remove"></button>' )
							.attr( 'title', t( 'removeImage' ) )
					)
				)
				.prop( 'hidden', false );
			$actions.prop( 'hidden', true );
		} else {
			$preview.empty().prop( 'hidden', true );
			$actions.prop( 'hidden', false );
		}
	}

	function buildModal() {
		if ( $modal ) {
			return $modal;
		}

		$modal = $(
			'<div class="ei-image-crop-modal" hidden>' +
				'<div class="ei-image-crop-modal-inner">' +
					'<div class="ei-image-crop-modal-header">' +
						'<div class="ei-image-crop-modal-heading">' +
							'<strong class="ei-image-crop-modal-title"></strong>' +
							'<span class="ei-image-crop-modal-subtitle"></span>' +
						'</div>' +
						// The × itself is a ::after in field.css, not markup here -
						// same approach as .ei-image-crop-reuse-delete.
						'<button type="button" class="ei-image-crop-close" aria-label="' + t( 'close' ) + '"></button>' +
					'</div>' +
					'<div class="ei-image-crop-modal-body">' +
						'<div class="ei-image-crop-modal-main">' +
							'<div class="ei-image-crop-canvas"><img class="ei-image-crop-img" alt="" /></div>' +
						'</div>' +
						'<div class="ei-image-crop-modal-side">' +
							'<p class="ei-image-crop-preview-title"></p>' +
							'<div class="ei-image-crop-live-preview"></div>' +
							'<p class="ei-image-crop-live-preview-caption"></p>' +
							'<div class="ei-image-crop-reuse" hidden>' +
								'<div class="ei-image-crop-reuse-heading">' +
									'<p class="ei-image-crop-reuse-title"></p>' +
									'<span class="ei-image-crop-reuse-count"></span>' +
								'</div>' +
								'<div class="ei-image-crop-reuse-list"></div>' +
							'</div>' +
							'<p class="ei-image-crop-error" hidden></p>' +
							'<div class="ei-image-crop-modal-actions">' +
								'<button type="button" class="button ei-image-crop-save"></button>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $modal );

		$modal.find( '.ei-image-crop-modal-title' ).text( t( 'modalTitle' ) );
		$modal.find( '.ei-image-crop-preview-title' ).text( t( 'previewTitle' ) );
		$modal.find( '.ei-image-crop-save' ).text( t( 'save' ) ).on( 'click', onSaveButtonClick );
		$modal.find( '.ei-image-crop-close' ).on( 'click', closeModal );
		$modal.find( '.ei-image-crop-reuse-title' ).text( t( 'reuseTitle' ) );

		return $modal;
	}

	/**
	 * The single save button does one of two things depending on whether a
	 * reuse thumbnail is currently selected (and hasn't been deselected by
	 * an adjustment since): apply that exact crop as-is ("Use image"), or
	 * crop the image fresh from the current box ("Crop image").
	 */
	function onSaveButtonClick() {
		if ( selectedReuseCrop ) {
			useSelectedCrop();
		} else {
			performSave();
		}
	}

	/**
	 * Switches the save button between its two states/labels/colors.
	 */
	function setSaveButtonState( isUseCrop ) {
		$modal.find( '.ei-image-crop-save' )
			.text( isUseCrop ? t( 'useCrop' ) : t( 'save' ) )
			.toggleClass( 'ei-image-crop-save-use', isUseCrop );
	}

	/**
	 * Marks a reuse thumbnail selected: shows its own crop box (and, via
	 * Cropper's own preview feature, the live preview) without committing to
	 * it yet, and switches the save button to "Use image". Any further manual
	 * adjustment of the crop box (see the 'crop' event handler in
	 * initCropper()) clears this back out again.
	 */
	function selectReuseThumbnail( $field, crop, $thumb ) {
		if ( ! cropper || ! crop.box ) {
			return;
		}

		var natural = cropper.getImageData();

		suppressCropEvents = true;
		cropper.setData( {
			x: crop.box.x * natural.naturalWidth,
			y: crop.box.y * natural.naturalHeight,
			width: crop.box.w * natural.naturalWidth,
			height: crop.box.h * natural.naturalHeight,
		} );
		suppressCropEvents = false;

		selectedReuseCrop = crop;
		setSaveButtonState( true );

		$modal.find( '.ei-image-crop-reuse-item' ).removeClass( 'is-selected' );
		$thumb.addClass( 'is-selected' );
	}

	/**
	 * Applies the selected reuse thumbnail as-is (no new crop generated) and
	 * closes the modal - what clicking a thumbnail used to do immediately;
	 * now it's a deliberate second step via the "Use image" button, so
	 * clicking a thumbnail can show a look at it first without committing.
	 */
	function useSelectedCrop() {
		if ( ! selectedReuseCrop || ! currentField ) {
			return;
		}

		setState( currentField, { id: selectedReuseCrop.id, source: currentSourceId } );
		setPreview( currentField, selectedReuseCrop.preview, selectedReuseCrop.id );
		closeModal();
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
		selectedReuseCrop = null;
		currentTrueScale = 1;
		currentTargetSize = null;
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
		selectedReuseCrop = null;
		setSaveButtonState( false );

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
				// rest of this session (reuse row, save).
				sourceId   = data.source_id;
				existingId = data.existing_id || '';
				currentSourceId    = sourceId;
				currentExistingId  = existingId;

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
					setPreview( $field, previewUrl, existingId );
					currentField = null;
					currentSourceId = null;
					currentExistingId = null;
					return;
				}

				function showInteractiveCropper() {
					modal.find( '.ei-image-crop-modal-subtitle' ).text(
						data.filename + ' · ' + data.full_width + ' × ' + data.full_height + ' px'
					);
					renderReuseList( $field, data.existing || [] );
					modal.prop( 'hidden', false );

					$img.one( 'load', function () {
						// The edit image can be a smaller "-scaled"/"large"
						// copy of the true original (see get_edit_source()),
						// so the crop box's on-screen pixels understate the
						// actual crop's eventual size by this same factor -
						// the undersized/upscale-warning check needs to
						// compare against the real thing, not what's shown.
						var trueScale = data.full_width / data.edit.width;
						initCropper( $field, this, data.box, trueScale );
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
				setPreview( $field, response.data.url, response.data.id );
				currentField = null;
				currentSourceId = null;
				currentExistingId = null;
			} )
			.fail( onFallback );
	}

	/**
	 * The crop box's real final pixel size (scaled up from the editor
	 * image's own, possibly smaller, on-screen pixels - see currentTrueScale)
	 * is smaller than the field's target size in either dimension - i.e.
	 * whether saving now would need to upscale the result.
	 * Always false for a free-form field (currentTargetSize is null).
	 *
	 * @return {boolean}
	 */
	function isUndersized() {
		if ( ! cropper || ! currentTargetSize ) {
			return false;
		}

		var data = cropper.getData();
		var actualWidth = data.width * currentTrueScale;
		var actualHeight = data.height * currentTrueScale;

		return actualWidth < currentTargetSize.width - 0.5 || actualHeight < currentTargetSize.height - 0.5;
	}

	/**
	 * The live preview's caption is deliberately quiet for a target-size
	 * field as long as the selection is big enough - saving always
	 * produces exactly the target size either way (see generate()'s
	 * resize), so there's nothing to report until the selection can't
	 * reach it without upscaling, at which point the real (smaller, and
	 * shrinking further as the box does) pixel size is worth calling out
	 * as a warning. A free-form field has no target to compare against,
	 * so it always just shows the box's actual size.
	 */
	function updatePreviewCaption() {
		if ( ! cropper ) {
			return;
		}

		var $caption = $modal.find( '.ei-image-crop-live-preview-caption' );

		if ( currentTargetSize && ! isUndersized() ) {
			$caption.empty().removeClass( 'is-undersized' );
			return;
		}

		var data = cropper.getData();
		var w = Math.round( data.width * currentTrueScale );
		var h = Math.round( data.height * currentTrueScale );

		if ( currentTargetSize ) {
			$caption.addClass( 'is-undersized' ).text( '⚠ ' + w + ' × ' + h + ' px' );
		} else {
			$caption.removeClass( 'is-undersized' ).text( w + ' × ' + h + ' px' );
		}
	}

	/**
	 * Small tag floating just above the crop box itself, mirroring the same
	 * info as the live preview's caption but where you're actually looking
	 * while dragging - except while the selection is big enough for a
	 * target-size field, when it shows that fixed target size instead of
	 * the fluctuating actual selection: saving always produces exactly the
	 * target size in that case (see generate()'s resize), so the target
	 * itself is the more useful, stable number to show. Only once the
	 * selection can't reach the target without upscaling does the real,
	 * shrinking size become the more honest thing to show. A free-form
	 * field just always shows the box's actual size.
	 *
	 * Positioned with cropper.getCropBoxData(), which already reports
	 * on-screen pixels relative to Cropper's own .cropper-container - the
	 * element this tag lives inside - so no extra coordinate conversion is
	 * needed.
	 */
	function updateBoxLabel( $label ) {
		if ( ! cropper ) {
			return;
		}

		var box = cropper.getCropBoxData();
		var text;

		if ( currentTargetSize && ! isUndersized() ) {
			text = currentTargetSize.width + ' × ' + currentTargetSize.height + ' px';
		} else {
			var data = cropper.getData();
			text = Math.round( data.width * currentTrueScale ) + ' × ' + Math.round( data.height * currentTrueScale ) + ' px';
		}

		// Centered above the box horizontally (the negative translate in
		// field.css does both that and sitting above rather than on top of
		// the box's own top edge), not left-aligned to it.
		$label.text( text ).css( {
			left: ( box.left + box.width / 2 ) + 'px',
			top: box.top + 'px',
		} );
	}

	/**
	 * @param {jQuery} $field
	 * @param {HTMLImageElement} imgEl
	 * @param {Object} box Normalized 0-1 x/y/w/h to preselect.
	 * @param {number} [trueScale] How many true-original pixels each pixel
	 *   of imgEl (the edit image, possibly a smaller "-scaled"/"large"
	 *   copy) actually represents - 1 when they're the same size. Used
	 *   only for the undersized/upscale-warning check, which needs the
	 *   crop's real eventual size, not what's shown on screen.
	 */
	function initCropper( $field, imgEl, box, trueScale ) {
		if ( cropper ) {
			cropper.destroy();
		}

		$modal.find( '.ei-image-crop-box-label' ).remove();
		var $boxLabel = $( '<div class="ei-image-crop-box-label"></div>' );

		var aspectRatio = parseAspectRatio( $field.data( 'ratio' ) );
		var $canvas     = $( imgEl ).closest( '.ei-image-crop-canvas' );

		currentTrueScale = trueScale > 0 ? trueScale : 1;
		currentTargetSize = parseTargetSize( $field.data( 'ratio' ) );

		/**
		 * Turns the crop box red (see .is-undersized in field.css) once the
		 * selection can't reach the target size without upscaling - the
		 * blur warning this is meant to give ahead of time, not after.
		 */
		function updateUndersizedState() {
			$canvas.toggleClass( 'is-undersized', isUndersized() );
		}

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

				// .cropper-container only exists once Cropper has finished its
				// own setup, which is exactly what "ready" signals - appending
				// here (rather than right after `new Cropper()`) guarantees
				// it's actually there to append into.
				$( imgEl ).closest( '.ei-image-crop-canvas' ).find( '.cropper-container' ).append( $boxLabel );

				suppressCropEvents = true;
				cropper.setData( {
					x: box.x * natural.w,
					y: box.y * natural.h,
					width: box.w * natural.w,
					height: box.h * natural.h,
				} );
				suppressCropEvents = false;
				updatePreviewCaption();
				updateBoxLabel( $boxLabel );
				updateUndersizedState();
			},
		} );

		// Cropper.js fires 'crop' on the image element for every crop box
		// change, including the setData() calls above and in
		// selectReuseThumbnail() - suppressCropEvents tells those apart from
		// an actual manual adjustment, which should drop the "viewing an
		// existing crop" selection back to a fresh "Crop image" state.
		imgEl.addEventListener( 'crop', function () {
			updatePreviewCaption();
			updateBoxLabel( $boxLabel );
			updateUndersizedState();

			if ( suppressCropEvents || ! selectedReuseCrop ) {
				return;
			}

			selectedReuseCrop = null;
			setSaveButtonState( false );
			$modal.find( '.ei-image-crop-reuse-item' ).removeClass( 'is-selected' );
		} );
	}

	function renderReuseList( $field, crops ) {
		if ( ! crops.length ) {
			return;
		}

		var $list = $modal.find( '.ei-image-crop-reuse-list' ).empty();

		crops.forEach( function ( crop ) {
			var $thumb = $( '<div class="ei-image-crop-reuse-item"></div>' )
				.attr( 'title', crop.title )
				.append( $( '<img />' ).attr( 'src', crop.url ) )
				.on( 'click', function () {
					selectReuseThumbnail( $field, crop, $thumb );
				} );

			// The × itself is a ::before in field.css now, not markup here -
			// gives full control over its own sizing/position independent of
			// the button's box model.
			var $delete = $(
				'<button type="button" class="ei-image-crop-reuse-delete" aria-label="' + t( 'deleteCrop' ) + '"></button>'
			).on( 'click', function ( e ) {
				e.stopPropagation();
				deleteReuseCrop( $field, crop, $thumb );
			} );

			$thumb.append( $delete );
			$list.append( $thumb );
		} );

		$modal.find( '.ei-image-crop-reuse' ).prop( 'hidden', false );
		updateReuseCount();
	}

	function updateReuseCount() {
		var n = $modal.find( '.ei-image-crop-reuse-item' ).length;
		$modal.find( '.ei-image-crop-reuse-count' ).text( t( 'savedCount' ).replace( '%d', n ) );
	}

	/**
	 * Permanently deletes a crop from the reuse row (and the Media Library
	 * entirely), after confirming - this can't be undone. If it turns out to
	 * be the field's own already-saved value, that value is cleared too,
	 * since it would otherwise be left pointing at an attachment that no
	 * longer exists.
	 */
	function deleteReuseCrop( $field, crop, $thumb ) {
		confirmDialog( t( 'confirmDeleteTitle' ), t( 'confirmDeleteMessage' ) ).then( function ( confirmed ) {
			if ( ! confirmed ) {
				return;
			}

			$.post( eiImageCrop.ajaxUrl, {
				action: 'ei_image_crop_delete',
				nonce: eiImageCrop.nonce,
				id: crop.id,
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						showError( ( response && response.data && response.data.message ) || t( 'error' ) );
						return;
					}

					if ( selectedReuseCrop && selectedReuseCrop.id === crop.id ) {
						selectedReuseCrop = null;
						setSaveButtonState( false );
					}

					var state = getState( $field );
					if ( String( state.id ) === String( crop.id ) ) {
						setState( $field, { id: '', source: currentSourceId } );
						setPreview( $field, '' );
					}

					$thumb.remove();
					updateReuseCount();

					if ( ! $modal.find( '.ei-image-crop-reuse-item' ).length ) {
						$modal.find( '.ei-image-crop-reuse' ).prop( 'hidden', true );
					}
				} )
				.fail( function () {
					showError( t( 'error' ) );
				} );
		} );
	}

	/**
	 * Crops the image fresh from the current box. Always produces a new
	 * attachment (or reuses an existing one via hash-based dedup if it's
	 * identical to a crop that already exists) - it never overwrites
	 * whatever crop the field currently holds, even when adjusting one via
	 * "Adjust crop"; that in-place-overwrite behavior isn't reachable from
	 * this single button on purpose, since silently mutating a crop that
	 * may be shared elsewhere is surprising. "Use image" (see
	 * useSelectedCrop()) is the only way this modal applies an existing
	 * attachment as-is.
	 */
	function performSave() {
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

		clearError();
		$modal.find( '.ei-image-crop-save' ).prop( 'disabled', true );

		$.post( eiImageCrop.ajaxUrl, {
			action: 'ei_image_crop_save',
			nonce: eiImageCrop.nonce,
			source_id: sourceId,
			existing_id: '',
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
				setPreview( $field, response.data.url, response.data.id );
				closeModal();
			} )
			.fail( function () {
				showError( t( 'error' ) );
			} )
			.always( function () {
				$modal.find( '.ei-image-crop-save' ).prop( 'disabled', false );
			} );
	}

	/**
	 * Scopes the media grid's "Crops" tab listing to crops
	 * matching this field's own ratio, while its picker is open - so
	 * everything shown there is actually usable as-is, without still
	 * needing a new crop to fit. A "free" field has no fixed shape to match
	 * against, so any existing crop already counts as usable; no scoping
	 * is applied there. Mirrors the eiShowCrops cookie's approach (see
	 * class-ei-image-crop-media-library.php) since a custom prop on the
	 * picker's query model can't reach that filter either.
	 */
	function setCropRatioCookie( ratio ) {
		if ( ratio && 'free' !== ratio ) {
			// Short-lived on purpose: this only needs to survive for as
			// long as the picker itself is realistically open. It's cleared
			// on frame close anyway, but a short expiry limits the damage
			// if that somehow doesn't fire (e.g. the tab closes mid-pick) -
			// a stale cookie would otherwise keep scoping the standalone
			// Media Library by whatever ratio was last picked against.
			document.cookie = 'ei_crop_ratio=' + encodeURIComponent( ratio ) + '; path=/; max-age=300';
		} else {
			clearCropRatioCookie();
		}
	}

	function clearCropRatioCookie() {
		document.cookie = 'ei_crop_ratio=; path=/; max-age=0';
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

		frame.on( 'close', clearCropRatioCookie );

		setCropRatioCookie( $field.data( 'ratio' ) );
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

		$field.on( 'click', '.ei-image-crop-open-attachment', function ( e ) {
			e.preventDefault();
			openAttachmentModal( $( this ).attr( 'href' ) );
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
