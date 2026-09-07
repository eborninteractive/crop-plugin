<?php
/**
 * Hides generated crop attachments from the Media Library by default, behind
 * a "Show crops" toggle, so the library doesn't drown in derivative images.
 *
 * @package Ei_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ei_Image_Crop_Media_Library {

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list_table' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_grid_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_list_toggle' ) );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_grid_toggle' ) );
	}

	/**
	 * @return array
	 */
	protected static function exclusion_clause() {
		return array(
			'key'     => '_ei_crop_parent',
			'compare' => 'NOT EXISTS',
		);
	}

	/**
	 * Hide crops from the classic Media Library list table, unless the
	 * "Show crops" link has been clicked (adds ?ei_show_crops=1).
	 *
	 * @param WP_Query $query
	 */
	public static function filter_list_table( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( ! empty( $_GET['ei_show_crops'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = self::exclusion_clause();
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Hide crops from the media modal grid, unless the in-modal toggle sent
	 * eiShowCrops through as part of the query props.
	 *
	 * @param array $query
	 * @return array
	 */
	public static function filter_grid_query( $query ) {
		if ( ! empty( $query['eiShowCrops'] ) ) {
			return $query;
		}

		$meta_query          = isset( $query['meta_query'] ) ? (array) $query['meta_query'] : array();
		$meta_query[]        = self::exclusion_clause();
		$query['meta_query'] = $meta_query;

		return $query;
	}

	/**
	 * Toggle link shown above the classic Media Library list table.
	 *
	 * @param string $post_type
	 */
	public static function render_list_toggle( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$showing = ! empty( $_GET['ei_show_crops'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$url = $showing
			? remove_query_arg( 'ei_show_crops' )
			: add_query_arg( 'ei_show_crops', '1' );

		$label = $showing
			? __( 'Hide generated crops', 'ei-image-crop' )
			: __( 'Show generated crops', 'ei-image-crop' );

		printf(
			'<a href="%1$s" class="button ei-image-crop-toggle-link">%2$s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Small script adding an equivalent toggle checkbox to the media modal grid.
	 */
	public static function enqueue_grid_toggle() {
		wp_enqueue_script(
			'ei-image-crop-media-toggle',
			EI_IMAGE_CROP_URL . 'assets/js/media-toggle.js',
			array( 'media-views' ),
			EI_IMAGE_CROP_VERSION,
			true
		);

		wp_localize_script(
			'ei-image-crop-media-toggle',
			'eiImageCropMedia',
			array(
				'label' => __( 'Show generated crops', 'ei-image-crop' ),
			)
		);
	}
}
