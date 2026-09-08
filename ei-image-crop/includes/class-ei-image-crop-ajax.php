<?php
/**
 * AJAX endpoints backing the field's cropper UI.
 *
 * @package Ei_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ei_Image_Crop_Ajax {

	public static function init() {
		add_action( 'wp_ajax_ei_image_crop_get_source', array( __CLASS__, 'get_source' ) );
		add_action( 'wp_ajax_ei_image_crop_save', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_ei_image_crop_delete', array( __CLASS__, 'delete_crop' ) );
	}

	/**
	 * Shared auth/nonce guard for both endpoints.
	 */
	protected static function check_access() {
		check_ajax_referer( 'ei_image_crop', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ei-image-crop' ) ), 403 );
		}
	}

	/**
	 * Validates a requested preview size against what's actually registered,
	 * so the field preview shown right after saving/reusing a crop is built
	 * from the exact same size render_field() will use after a page reload
	 * (instead of a hardcoded guess that may not match, and looks like a
	 * "smaller until you reload" bug when it doesn't).
	 *
	 * @param mixed $raw
	 * @return string
	 */
	protected static function sanitize_preview_size( $raw ) {
		$raw          = is_string( $raw ) ? sanitize_key( $raw ) : '';
		$valid_sizes  = get_intermediate_image_sizes();
		$valid_sizes[] = 'full';

		return in_array( $raw, $valid_sizes, true ) ? $raw : 'medium';
	}

	/**
	 * Returns the data needed to open the cropper for a given source image:
	 * the best-resolution edit URL, a default (or existing) crop box, and a
	 * list of already-existing crops of this source at the same ratio for reuse.
	 */
	public static function get_source() {
		self::check_access();

		$source_id    = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
		$ratio_label  = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : 'free';
		$current_id   = isset( $_POST['current_id'] ) ? (int) $_POST['current_id'] : 0;
		$preview_size = self::sanitize_preview_size( $_POST['preview_size'] ?? '' );

		if ( ! wp_attachment_is_image( $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid source image.', 'ei-image-crop' ) ) );
		}

		// A fresh pick (no current_id yet) of an image that is itself
		// already a crop - e.g. reused from the Media Library - should let
		// you adjust it against its own true original with its existing box
		// marked, not crop the crop. Treat the picked crop as the one being
		// edited in place, so saving updates that same shared attachment
		// rather than generating a derivative-of-a-derivative.
		if ( ! $current_id && (int) get_post_meta( $source_id, '_ei_crop_parent', true ) ) {
			$current_id = $source_id;
		}

		// Resolve all the way up the crop chain to the true root, not just
		// one level - a crop can itself have been made from another crop
		// (e.g. re-adjusting a reused crop), and stopping one level short
		// would load an already-cropped image as if it were the original.
		$source_id = Ei_Image_Crop_Generator::resolve_root( $source_id );

		$edit_source = Ei_Image_Crop_Generator::get_edit_source( $source_id );
		if ( ! $edit_source ) {
			wp_send_json_error( array( 'message' => __( 'Could not read the source image.', 'ei-image-crop' ) ) );
		}

		$ratio = Ei_Image_Crop_Generator::parse_ratio( $ratio_label );

		// The true original's own filename/dimensions, for the modal header -
		// deliberately not $edit_source, which may be a smaller "large" size
		// used only to keep the editor snappy.
		$full_meta = wp_get_attachment_metadata( $source_id );

		if ( $current_id ) {
			$stored_box = get_post_meta( $current_id, '_ei_crop_box', true );
			$box        = is_array( $stored_box ) ? Ei_Image_Crop_Generator::sanitize_box( $stored_box ) : false;
		} else {
			$box = false;
		}

		if ( ! $box ) {
			$box = Ei_Image_Crop_Generator::center_box( $edit_source['width'], $edit_source['height'], $ratio );
		}

		wp_send_json_success(
			array(
				'edit'        => $edit_source,
				'box'         => $box,
				'existing'    => self::get_existing_crops( $source_id, $ratio_label, $preview_size ),
				// Echo these back since a fresh pick of an already-cropped
				// image gets resolved above to its true original + that
				// crop's own id - the client started the request not
				// knowing that yet, so it needs the resolved values to
				// carry on with (which attachment "save" should overwrite,
				// which source the reuse row and later saves are against).
				'source_id'   => $source_id,
				'existing_id' => $current_id ? $current_id : '',
				'filename'    => wp_basename( get_attached_file( $source_id ) ),
				'full_width'  => isset( $full_meta['width'] ) ? (int) $full_meta['width'] : $edit_source['width'],
				'full_height' => isset( $full_meta['height'] ) ? (int) $full_meta['height'] : $edit_source['height'],
			)
		);
	}

	/**
	 * Generate (or overwrite/reuse) a crop and return its attachment data.
	 */
	public static function save() {
		self::check_access();

		$source_id    = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
		$ratio_label  = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : 'free';
		$field_key    = isset( $_POST['field_key'] ) ? sanitize_text_field( wp_unslash( $_POST['field_key'] ) ) : '';
		$existing_id  = isset( $_POST['existing_id'] ) ? (int) $_POST['existing_id'] : 0;
		$box          = isset( $_POST['box'] ) && is_array( $_POST['box'] ) ? wp_unslash( $_POST['box'] ) : array();
		$preview_size = self::sanitize_preview_size( $_POST['preview_size'] ?? '' );

		if ( ! wp_attachment_is_image( $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid source image.', 'ei-image-crop' ) ) );
		}

		// Only allow overwriting an attachment that is actually a crop of this source.
		if ( $existing_id && (int) get_post_meta( $existing_id, '_ei_crop_parent', true ) !== $source_id ) {
			$existing_id = 0;
		}

		$box = Ei_Image_Crop_Generator::sanitize_box(
			array(
				'x' => $box['x'] ?? 0,
				'y' => $box['y'] ?? 0,
				'w' => $box['w'] ?? 1,
				'h' => $box['h'] ?? 1,
			)
		);

		$result = Ei_Image_Crop_Generator::generate( $source_id, $box, $ratio_label, $field_key, $existing_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'id'  => $result,
				'url' => wp_get_attachment_image_url( $result, $preview_size ),
			)
		);
	}

	/**
	 * Permanently deletes a crop attachment, e.g. from the "existing crops"
	 * row's per-item delete button. Refuses anything that isn't actually a
	 * crop this plugin generated, so this endpoint can't be used to delete
	 * arbitrary attachments.
	 */
	public static function delete_crop() {
		self::check_access();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id || ! get_post_meta( $id, '_ei_crop_parent', true ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a crop this plugin can delete.', 'ei-image-crop' ) ) );
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ei-image-crop' ) ), 403 );
		}

		$deleted = wp_delete_attachment( $id, true );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete that crop.', 'ei-image-crop' ) ) );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * @param int    $parent_id
	 * @param string $ratio_label
	 * @param string $preview_size Size to report back as `preview`, used when
	 *                             a row item is picked as the field's active
	 *                             image - kept separate from the small `url`
	 *                             icon so the row itself never has to load
	 *                             large images just to render a row of icons.
	 * @return array<int, array{id:int, url:string, preview:string, title:string, box:array}>
	 */
	protected static function get_existing_crops( $parent_id, $ratio_label, $preview_size = 'medium' ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 24,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_ei_crop_parent',
						'value' => $parent_id,
					),
					array(
						'key'   => '_ei_crop_ratio',
						'value' => $ratio_label ? $ratio_label : 'free',
					),
				),
			)
		);

		$crops = array();

		foreach ( $ids as $id ) {
			// 'thumbnail' is a hard, forced-square crop by default, which
			// would misrepresent the crop's real aspect ratio. An array
			// size doesn't reliably avoid that either: WordPress matches it
			// against registered sizes by *closest aspect ratio*, and a
			// square target (e.g. 120x120) matches the square 'thumbnail'
			// size and returns that same hard crop. 'medium' is a real
			// registered, proportionally-resized (crop=false) size, so it
			// actually reflects the crop's true shape.
			$thumb = wp_get_attachment_image_url( $id, 'medium' );
			if ( ! $thumb ) {
				continue;
			}

			$stored_box = get_post_meta( $id, '_ei_crop_box', true );

			$crops[] = array(
				'id'      => $id,
				'url'     => $thumb,
				'preview' => wp_get_attachment_image_url( $id, $preview_size ) ?: $thumb,
				'title'   => get_the_title( $id ),
				// Lets the picker mark this crop's own region on the source
				// image when it's selected for a look before committing to it,
				// instead of only ever showing a flat thumbnail.
				'box'     => is_array( $stored_box ) ? Ei_Image_Crop_Generator::sanitize_box( $stored_box ) : null,
			);
		}

		return $crops;
	}
}
