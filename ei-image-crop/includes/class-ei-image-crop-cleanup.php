<?php
/**
 * Keeps generated crops from turning into orphaned files.
 *
 * @package Ei_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ei_Image_Crop_Cleanup {

	public static function init() {
		add_action( 'delete_attachment', array( __CLASS__, 'delete_child_crops' ) );
	}

	/**
	 * When an original image is deleted, delete every crop generated from it
	 * too - a crop with no source is useless, and leaving it behind just
	 * re-clutters the library this plugin is trying to keep tidy.
	 *
	 * @param int $attachment_id
	 */
	public static function delete_child_crops( $attachment_id ) {
		// Crops are attachments themselves; skip if this deletion IS a crop
		// (nothing further to cascade).
		if ( get_post_meta( $attachment_id, '_ei_crop_parent', true ) ) {
			return;
		}

		$children = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_ei_crop_parent',
						'value' => $attachment_id,
					),
				),
			)
		);

		foreach ( $children as $child_id ) {
			wp_delete_attachment( $child_id, true );
		}
	}
}
