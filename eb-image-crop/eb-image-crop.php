<?php
/**
 * Plugin Name:       EB Image Crop
 * Plugin URI:        https://github.com/eborninteractive/crop-plugin
 * Description:       ACF field type for cropping images on demand (not at upload), with reusable, editable crops that live as regular Media Library attachments.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Eborn Interactive
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eb-image-crop
 * Domain Path:       /languages
 *
 * @package EB_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EB_IMAGE_CROP_VERSION', '1.0.0' );
define( 'EB_IMAGE_CROP_FILE', __FILE__ );
define( 'EB_IMAGE_CROP_PATH', plugin_dir_path( __FILE__ ) );
define( 'EB_IMAGE_CROP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstraps the plugin once all other plugins have loaded, so the ACF
 * dependency check below sees an accurate picture.
 */
function eb_image_crop_bootstrap() {
	if ( ! class_exists( 'ACF' ) ) {
		add_action( 'admin_notices', 'eb_image_crop_missing_acf_notice' );
		return;
	}

	require_once EB_IMAGE_CROP_PATH . 'includes/class-eb-image-crop-generator.php';
	require_once EB_IMAGE_CROP_PATH . 'includes/class-eb-image-crop-ajax.php';
	require_once EB_IMAGE_CROP_PATH . 'includes/class-eb-image-crop-media-library.php';
	require_once EB_IMAGE_CROP_PATH . 'includes/class-eb-image-crop-cleanup.php';

	add_action( 'acf/include_field_types', 'eb_image_crop_register_field_type' );

	EB_Image_Crop_Ajax::init();
	EB_Image_Crop_Media_Library::init();
	EB_Image_Crop_Cleanup::init();

	load_plugin_textdomain( 'eb-image-crop', false, dirname( plugin_basename( EB_IMAGE_CROP_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'eb_image_crop_bootstrap' );

/**
 * Registers the ACF field type. Hooked on acf/include_field_types, which ACF
 * fires once it (and its own field base class) is fully loaded.
 */
function eb_image_crop_register_field_type() {
	require_once EB_IMAGE_CROP_PATH . 'includes/class-eb-image-crop-field.php';

	if ( class_exists( 'EB_Image_Crop_Field' ) ) {
		new EB_Image_Crop_Field();
	}
}

/**
 * Admin notice shown when Advanced Custom Fields isn't active.
 */
function eb_image_crop_missing_acf_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'EB Image Crop requires Advanced Custom Fields (or ACF PRO) to be installed and active.', 'eb-image-crop' )
	);
}
