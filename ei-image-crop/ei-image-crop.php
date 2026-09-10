<?php
/**
 * Plugin Name:       Ei Image Crop
 * Plugin URI:        https://github.com/eborninteractive/crop-plugin
 * Description:       ACF field type for cropping images on demand (not at upload), with reusable, editable crops that live as regular Media Library attachments.
 * Version:           1.10.37
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

define( 'EI_IMAGE_CROP_VERSION', '1.10.37' );
define( 'EI_IMAGE_CROP_FILE', __FILE__ );
define( 'EI_IMAGE_CROP_PATH', plugin_dir_path( __FILE__ ) );
define( 'EI_IMAGE_CROP_URL', plugin_dir_url( __FILE__ ) );

require_once EI_IMAGE_CROP_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Lets every site running this plugin see "Update available" in the
 * Plugins list straight from GitHub Releases, the same as a plugin
 * installed from wordpress.org - this isn't on wordpress.org, so without
 * this, updating it means manually re-uploading it to every single site.
 * Not gated behind the ACF-active check below: a site with ACF
 * temporarily deactivated should still be offered the update.
 */
function ei_image_crop_init_update_checker() {
	$update_checker = PucFactory::buildUpdateChecker(
		'https://github.com/eborninteractive/crop-plugin/',
		EI_IMAGE_CROP_FILE,
		'ei-image-crop'
	);

	// Update source is a GitHub Release's attached zip (built and published
	// automatically by .github/workflows/release.yml whenever a version
	// tag is pushed) rather than a raw snapshot of the repo - a plain
	// branch-archive download would extract as "crop-plugin-<sha>/ei-image-crop/..."
	// instead of "ei-image-crop/...", which WordPress won't recognize as
	// the same plugin when installing the update.
	$update_checker->getVcsApi()->enableReleaseAssets();
}
add_action( 'plugins_loaded', 'ei_image_crop_init_update_checker' );

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
	require_once EI_IMAGE_CROP_PATH . 'includes/class-ei-image-crop-migration-aiarc.php';

	add_action( 'acf/include_field_types', 'ei_image_crop_register_field_type' );

	Ei_Image_Crop_Ajax::init();
	Ei_Image_Crop_Media_Library::init();
	Ei_Image_Crop_Cleanup::init();
	Ei_Image_Crop_Migration_Aiarc::init();

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
