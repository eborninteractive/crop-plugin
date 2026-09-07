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
	 * Returns the data needed to open the cropper for a given source image:
	 * the best-resolution edit URL, a default (or existing) crop box, and a
	 * list of already-existing crops of this source at the same ratio for reuse.
	 */
	public static function get_source() {
		self::check_access();

		$source_id   = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
		$ratio_label = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : 'free';
		$current_id  = isset( $_POST['current_id'] ) ? (int) $_POST['current_id'] : 0;

		if ( ! wp_attachment_is_image( $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid source image.', 'ei-image-crop' ) ) );
		}

		$edit_source = Ei_Image_Crop_Generator::get_edit_source( $source_id );
		if ( ! $edit_source ) {
			wp_send_json_error( array( 'message' => __( 'Could not read the source image.', 'ei-image-crop' ) ) );
		}

		$ratio = Ei_Image_Crop_Generator::parse_ratio( $ratio_label );

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
				'edit'     => $edit_source,
				'box'      => $box,
				'existing' => self::get_existing_crops( $source_id, $ratio_label ),
			)
		);
	}

	/**
	 * Generate (or overwrite/reuse) a crop and return its attachment data.
	 */
	public static function save() {
		self::check_access();

		$source_id   = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
		$ratio_label = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : 'free';
		$field_key   = isset( $_POST['field_key'] ) ? sanitize_text_field( wp_unslash( $_POST['field_key'] ) ) : '';
		$existing_id = isset( $_POST['existing_id'] ) ? (int) $_POST['existing_id'] : 0;
		$box         = isset( $_POST['box'] ) && is_array( $_POST['box'] ) ? wp_unslash( $_POST['box'] ) : array();

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
				'url' => wp_get_attachment_image_url( $result, 'medium' ),
			)
		);
	}

	/**
	 * @param int    $parent_id
	 * @param string $ratio_label
	 * @return array<int, array{id:int, url:string, title:string}>
	 */
	protected static function get_existing_crops( $parent_id, $ratio_label ) {
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
			$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( ! $thumb ) {
				continue;
			}

			$crops[] = array(
				'id'    => $id,
				'url'   => $thumb,
				'title' => get_the_title( $id ),
			);
		}

		return $crops;
	}
}
