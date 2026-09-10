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
		// Covers the media modal opened from a post edit screen (which calls
		// wp_enqueue_media() itself, firing this action).
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_grid_toggle' ) );
		// The standalone Media Library grid (Media > Library) doesn't reliably
		// fire wp_enqueue_media's action for this, so enqueue directly there too;
		// wp_enqueue_script() no-ops harmlessly if it's already been added.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_on_library_screen' ) );
		// Neither of the two hooks above reaches the block editor's canvas
		// iframe - a media picker opened from a field that lives inside an
		// ACF block (confirmed live: its own wp.media() call, and the
		// resulting wp.media.frame, exist only in that iframe's own
		// separate window, invisible to this script if it only runs in the
		// outer document) needs this the same way the crop field itself
		// does (see Ei_Image_Crop_Field::enqueue_block_editor_assets()).
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_block_editor_grid_toggle' ) );
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
	 * @return array
	 */
	protected static function inclusion_clause() {
		return array(
			'key'     => '_ei_crop_parent',
			'compare' => 'EXISTS',
		);
	}

	/**
	 * Filters the classic Media Library list table: by default, hides crops
	 * so the library isn't drowned in derivative images; with the "Only
	 * show generated crops" link clicked (adds ?ei_show_crops=1), flips to
	 * showing ONLY crops - a plain "show everything" would mostly just
	 * repeat what's already visible without the toggle, since regular
	 * uploads vastly outnumber crops.
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

		$showing_only_crops = ! empty( $_GET['ei_show_crops'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = $showing_only_crops ? self::inclusion_clause() : self::exclusion_clause();
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Filters the media modal grid the same way filter_list_table() filters
	 * the classic list table: hide crops by default, or show ONLY crops
	 * once the toggle is checked. When that grid is our own field's picker
	 * (as opposed to the standalone Media Library), it further scopes the
	 * "only crops" view to crops matching that field's own ratio, via the
	 * ei_crop_ratio cookie field.js sets while its picker is open - so
	 * everything listed there is actually usable as-is, without a "reused"
	 * crop turning out to need a new one anyway once picked.
	 *
	 * The toggle's state can't be read from $query here: WordPress's
	 * wp_ajax_query_attachments() whitelists which keys survive from the
	 * request into this filter (s, order, orderby, posts_per_page, paged,
	 * post_mime_type, post_parent, author, post__in, post__not_in, year,
	 * monthnum, plus taxonomy query vars) via array_intersect_key() -
	 * anything else, including a custom prop the toggle sets on the
	 * client-side query model, is silently stripped before this filter ever
	 * runs. A cookie doesn't go through that whitelist, so it's used instead.
	 *
	 * Skips the crop hide/show filtering entirely when the query already
	 * names specific attachment ids via post__in - e.g. the pencil icon's
	 * "edit attachment details" popup, which scopes the grid to exactly
	 * the one attachment being edited whether or not it's itself a crop.
	 * Applying the crop-hiding default on top of that would silently
	 * exclude the very attachment the caller explicitly asked for,
	 * leaving the grid empty instead.
	 *
	 * @param array $query
	 * @return array
	 */
	public static function filter_grid_query( $query ) {
		if ( ! empty( $query['post__in'] ) ) {
			return $query;
		}

		$showing_only_crops = ! empty( $_COOKIE['ei_show_crops'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$meta_query   = isset( $query['meta_query'] ) ? (array) $query['meta_query'] : array();
		$meta_query[] = $showing_only_crops ? self::inclusion_clause() : self::exclusion_clause();

		if ( $showing_only_crops && ! empty( $_COOKIE['ei_crop_ratio'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$meta_query[] = array(
				'key'   => '_ei_crop_ratio',
				'value' => sanitize_text_field( wp_unslash( $_COOKIE['ei_crop_ratio'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}

		$query['meta_query'] = $meta_query;

		return $query;
	}

	/**
	 * Two tabs ("Original images" / "Crops") shown above the classic Media
	 * Library list table - always exactly one or the other, never both at
	 * once, since regular uploads vastly outnumber crops and a combined
	 * view would barely differ from "Original images" alone.
	 *
	 * @param string $post_type
	 */
	public static function render_list_toggle( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$showing_crops = ! empty( $_GET['ei_show_crops'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base_url      = remove_query_arg( 'ei_show_crops' );

		printf(
			'<span class="ei-image-crop-toggle">' .
				'<a href="%1$s" class="ei-image-crop-tab%2$s">%3$s</a>' .
				'<a href="%4$s" class="ei-image-crop-tab%5$s">%6$s</a>' .
			'</span>',
			esc_url( $base_url ),
			$showing_crops ? '' : ' is-active',
			esc_html__( 'Original images', 'ei-image-crop' ),
			esc_url( add_query_arg( 'ei_show_crops', '1', $base_url ) ),
			$showing_crops ? ' is-active' : '',
			esc_html__( 'Crops', 'ei-image-crop' )
		);
	}

	/**
	 * @param string $hook_suffix
	 */
	public static function enqueue_on_library_screen( $hook_suffix ) {
		if ( 'upload.php' !== $hook_suffix ) {
			return;
		}

		// Make sure the underlying media views script this toggle patches is
		// actually present on this screen before enqueuing it.
		wp_enqueue_media();

		self::enqueue_grid_toggle();
	}

	/**
	 * enqueue_block_assets also fires on the public-facing frontend (it's
	 * WordPress's one hook that reaches both the site and the block
	 * editor's canvas iframe) - only the block editor itself needs this,
	 * so bail everywhere else, same guard as
	 * Ei_Image_Crop_Field::enqueue_block_editor_assets().
	 */
	public static function enqueue_block_editor_grid_toggle() {
		$screen = is_admin() ? get_current_screen() : null;

		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}

		wp_enqueue_media();
		self::enqueue_grid_toggle();
	}

	/**
	 * Small script adding an equivalent toggle checkbox to the media modal grid.
	 */
	public static function enqueue_grid_toggle() {
		// Needed for the toggle to actually be visible: WordPress visually
		// hides bare <label> elements in this toolbar section by default
		// (see field.css), and this stylesheet is normally only loaded via
		// ACF's own field-input enqueue action - which doesn't necessarily
		// fire on the standalone Media Library page. wp_enqueue_style() is
		// a harmless no-op if it's already been enqueued another way.
		wp_enqueue_style(
			'ei-image-crop-field',
			EI_IMAGE_CROP_URL . 'assets/css/field.css',
			array(),
			EI_IMAGE_CROP_VERSION
		);

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
				'originalsLabel' => __( 'Original images', 'ei-image-crop' ),
				'cropsLabel'     => __( 'Crops', 'ei-image-crop' ),
			)
		);
	}
}
