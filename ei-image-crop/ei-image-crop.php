<?php
/**
 * Plugin Name:       Ei Image Crop
 * Plugin URI:        https://github.com/eborninteractive/crop-plugin
 * Description:       ACF field type for cropping images on demand (not at upload), with reusable, editable crops that live as regular Media Library attachments.
 * Version:           1.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Eborn Interactive
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ei-image-crop
 * Domain Path:       /languages
 *
 * @package Ei_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EI_IMAGE_CROP_VERSION', '1.6.0' );
define( 'EI_IMAGE_CROP_FILE', __FILE__ );
define( 'EI_IMAGE_CROP_PATH', plugin_dir_path( __FILE__ ) );
define( 'EI_IMAGE_CROP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstraps the plugin once all other plugins have loaded, so the ACF
 * dependency check below sees an accurate picture.
 */
function ei_image_crop_bootstrap() {
	if ( ! class_exists( 'ACF' ) ) {
		add_action( 'admin_notices', 'ei_image_crop_missing_acf_notice' );
		return;
	}

	require_once EI_IMAGE_CROP_PATH . 'includes/class-ei-image-crop-generator.php';
	require_once EI_IMAGE_CROP_PATH . 'includes/class-ei-image-crop-ajax.php';
	require_once EI_IMAGE_CROP_PATH . 'includes/class-ei-image-crop-media-library.php';
	require_once EI_IMAGE_CROP_PATH . 'includes/class-ei-image-crop-cleanup.php';

	add_action( 'acf/include_field_types', 'ei_image_crop_register_field_type' );

	Ei_Image_Crop_Ajax::init();
	Ei_Image_Crop_Media_Library::init();
	Ei_Image_Crop_Cleanup::init();

	load_plugin_textdomain( 'ei-image-crop', false, dirname( plugin_basename( EI_IMAGE_CROP_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ei_image_crop_bootstrap' );

/**
 * Registers the ACF field type. Hooked on acf/include_field_types, which ACF
 * fires once it (and its own field base class) is fully loaded.
 */
function ei_image_crop_register_field_type() {
	require_once EI_IMAGE_CROP_PATH . 'includes/class-ei-image-crop-field.php';

	if ( class_exists( 'Ei_Image_Crop_Field' ) ) {
		new Ei_Image_Crop_Field();
	}
}

/**
 * Admin notice shown when Advanced Custom Fields isn't active.
 */
function ei_image_crop_missing_acf_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Ei Image Crop requires Advanced Custom Fields (or ACF PRO) to be installed and active.', 'ei-image-crop' )
	);
}
