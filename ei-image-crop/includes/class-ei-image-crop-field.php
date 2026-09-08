<?php
/**
 * ACF field type: Image Crop.
 *
 * @package Ei_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'acf_field' ) ) {
	return;
}

class Ei_Image_Crop_Field extends acf_field {

	public function __construct() {
		$this->name     = 'ei_image_crop';
		$this->label    = __( 'Image Crop', 'ei-image-crop' );
		$this->category = 'content';
		$this->defaults = array(
			'image_size'    => '',
			'preview_size'  => 'medium',
			'library'       => 'all',
			'return_format' => 'array',
		);

		parent::__construct();
	}

	/**
	 * Choices for the "Image size" field setting: every image size
	 * registered on the site (core sizes plus anything added via
	 * add_image_size()), labelled with its pixel dimensions. A size
	 * registered without hard cropping (`add_image_size( $name, $w, $h,
	 * false )`, WordPress's own "fit inside, don't force this shape"
	 * mode) is labelled as a free crop and treated as one - there's no
	 * fixed target shape to crop to, so nothing is locked or checked
	 * against.
	 *
	 * @return array<string,string>
	 */
	protected function get_image_size_setting_choices() {
		$choices = array();

		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$choices[ $name ] = empty( $size['crop'] )
				? sprintf( '%s (%s)', $name, __( 'free crop', 'ei-image-crop' ) )
				: sprintf( '%s (%d × %d)', $name, $size['width'], $size['height'] );
		}

		return $choices;
	}

	/**
	 * Field settings shown when configuring the field in ACF.
	 *
	 * @param array $field
	 */
	public function render_field_settings( $field ) {
		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Image size', 'ei-image-crop' ),
				'instructions' => __( 'One of the image sizes registered on this site (Settings > Media, or added by a theme/plugin via add_image_size()). A size registered without hard cropping is treated as a free-form crop.', 'ei-image-crop' ),
				'type'         => 'select',
				'name'         => 'image_size',
				'choices'      => $this->get_image_size_setting_choices(),
			)
		);

		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Admin preview size', 'ei-image-crop' ),
				'instructions' => __( 'Image size used to preview the crop in the field editor.', 'ei-image-crop' ),
				'type'         => 'select',
				'name'         => 'preview_size',
				'choices'      => $this->get_image_size_choices(),
			)
		);

		acf_render_field_setting(
			$field,
			array(
				'label'   => __( 'Library', 'ei-image-crop' ),
				'type'    => 'radio',
				'name'    => 'library',
				'layout'  => 'horizontal',
				'choices' => array(
					'all'        => __( 'All', 'ei-image-crop' ),
					'uploadedTo' => __( 'Uploaded to post', 'ei-image-crop' ),
				),
			)
		);

		acf_render_field_setting(
			$field,
			array(
				'label'   => __( 'Return format', 'ei-image-crop' ),
				'type'    => 'radio',
				'name'    => 'return_format',
				'layout'  => 'horizontal',
				'choices' => array(
					'array' => __( 'Image array', 'ei-image-crop' ),
					'url'   => __( 'Image URL', 'ei-image-crop' ),
					'id'    => __( 'Image ID', 'ei-image-crop' ),
				),
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	protected function get_image_size_choices() {
		$sizes   = get_intermediate_image_sizes();
		$choices = array();

		foreach ( $sizes as $size ) {
			$choices[ $size ] = $size;
		}

		$choices['full'] = __( 'full', 'ei-image-crop' );

		return $choices;
	}

	/**
	 * Resolve a field's configured image size setting down to a "W:H" or
	 * "free" string - literally the registered size's own pixel
	 * dimensions when it hard-crops, not just their proportion, since
	 * that's also what the cropper UI's default box and undersized/upscale
	 * warning need. Falls back to "free" if the field has no size chosen
	 * yet, or the chosen one no longer exists (renamed/removed since).
	 *
	 * @param array $field
	 * @return string
	 */
	public static function resolve_ratio( $field ) {
		$size_name = isset( $field['image_size'] ) ? $field['image_size'] : '';
		$sizes     = wp_get_registered_image_subsizes();

		if ( ! $size_name || empty( $sizes[ $size_name ] ) || empty( $sizes[ $size_name ]['crop'] ) ) {
			return 'free';
		}

		$size = $sizes[ $size_name ];

		return $size['width'] . ':' . $size['height'];
	}

	/**
	 * Render the field input in the post editor.
	 *
	 * @param array $field
	 */
	public function render_field( $field ) {
		$value       = (int) $field['value'];
		$ratio       = self::resolve_ratio( $field );
		$parent_id   = $value ? Ei_Image_Crop_Generator::resolve_root( $value ) : 0;
		$preview_id  = $value ? $value : 0;
		$preview_url = $preview_id ? wp_get_attachment_image_url( $preview_id, $field['preview_size'] ) : '';
		$has_image   = (bool) $preview_url;

		// The field only ever submits ONE input to ACF (required for the field
		// to work correctly when nested inside a repeater/flexible content/group,
		// where ACF controls the surrounding name="acf[...]" bracket nesting).
		// Everything the field needs on save - the resolved crop id plus the
		// chosen source image id - travels together as a JSON blob.
		$state = wp_json_encode(
			array(
				'id'     => $value ? $value : '',
				'source' => $parent_id ? $parent_id : '',
			)
		);

		$wrapper_atts = array(
			'class'              => 'ei-image-crop-field',
			'data-field-key'     => $field['key'],
			'data-ratio'         => $ratio,
			'data-preview-size'  => $field['preview_size'],
			'data-library'       => $field['library'],
			'data-mime-types'    => 'image',
		);

		echo '<div ' . acf_esc_attrs( $wrapper_atts ) . '>';

		acf_hidden_input(
			array(
				'class' => 'ei-image-crop-value',
				'name'  => $field['name'],
				'value' => $state,
			)
		);

		// No image yet: nothing to show but the one button that lets you pick
		// one - no dashed drop-zone placeholder, since dropping files onto it
		// isn't actually supported and it only invites people to try.
		echo '<div class="ei-image-crop-preview"' . ( $has_image ? '' : ' hidden' ) . '>';
		if ( $has_image ) {
			// The image itself doubles as the "pick a different image"
			// control (same convention as ACF's own native Image field) -
			// the pencil icon is for something else entirely (see below).
			echo '<img src="' . esc_url( $preview_url ) . '" alt="" class="ei-image-crop-select" />';
			echo '<div class="ei-image-crop-overlay">';
			echo '<a href="' . esc_url( admin_url( 'post.php?action=edit&post=' . $value ) ) . '" target="_blank" rel="noopener" class="ei-image-crop-icon-btn ei-image-crop-open-attachment" title="' . esc_attr__( 'Edit image details (caption, alt text, etc.)', 'ei-image-crop' ) . '">' . self::pencil_icon() . '</a>';
			echo '<button type="button" class="ei-image-crop-icon-btn ei-image-crop-edit" title="' . esc_attr__( 'Adjust crop', 'ei-image-crop' ) . '">' . self::crop_icon() . '</button>';
			echo '<button type="button" class="ei-image-crop-icon-btn ei-image-crop-remove" title="' . esc_attr__( 'Remove image', 'ei-image-crop' ) . '">&times;</button>';
			echo '</div>';
		}
		echo '</div>';

		echo '<div class="ei-image-crop-existing" hidden></div>';

		echo '<div class="ei-image-crop-actions"' . ( $has_image ? ' hidden' : '' ) . '>';
		echo '<button type="button" class="button ei-image-crop-select">' . esc_html__( 'Select image', 'ei-image-crop' ) . '</button>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Inline SVG crop icon - dashicons has no crop glyph, so the "Adjust
	 * crop" button uses this instead.
	 *
	 * @return string
	 */
	public static function crop_icon() {
		return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M17 15h2V7c0-1.1-.9-2-2-2H9v2h8v8zM7 17V1H5v4H1v2h4v10c0 1.1.9 2 2 2h10v4h2v-4h4v-2H7z"/></svg>';
	}

	/**
	 * Inline SVG pencil icon - dashicons-edit renders with a baseline stroke
	 * under the pencil that read as a stray underline at this size, so this
	 * plain pencil (no line) is used for "edit image details" instead.
	 *
	 * @return string
	 */
	public static function pencil_icon() {
		return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>';
	}

	/**
	 * Enqueue admin assets. Called by ACF once per page load when the field type is present.
	 */
	public function input_admin_enqueue_scripts() {
		$url = EI_IMAGE_CROP_URL;
		$ver = EI_IMAGE_CROP_VERSION;

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'ei-image-crop-cropperjs', $url . 'assets/vendor/cropperjs/cropper.min.css', array(), '1.6.2' );
		wp_enqueue_script( 'ei-image-crop-cropperjs', $url . 'assets/vendor/cropperjs/cropper.min.js', array(), '1.6.2', true );

		wp_enqueue_style( 'ei-image-crop-field', $url . 'assets/css/field.css', array(), $ver );
		wp_enqueue_script(
			'ei-image-crop-field',
			$url . 'assets/js/field.js',
			array( 'jquery', 'ei-image-crop-cropperjs', 'media-editor', 'acf-input' ),
			$ver,
			true
		);

		wp_localize_script(
			'ei-image-crop-field',
			'eiImageCrop',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'ei_image_crop' ),
				'editUrlBase' => admin_url( 'post.php?action=edit&post=' ),
				'i18n'    => array(
					'selectImage'          => __( 'Select an image', 'ei-image-crop' ),
					'useImage'             => __( 'Use this image', 'ei-image-crop' ),
					'modalTitle'           => __( 'Crop image', 'ei-image-crop' ),
					'editDetails'          => __( 'Edit image details (caption, alt text, etc.)', 'ei-image-crop' ),
					'adjustCrop'           => __( 'Adjust crop', 'ei-image-crop' ),
					'removeImage'          => __( 'Remove image', 'ei-image-crop' ),
					'save'                 => __( 'Crop image', 'ei-image-crop' ),
					'useCrop'              => __( 'Use image', 'ei-image-crop' ),
					'close'                => __( 'Close', 'ei-image-crop' ),
					'deleteCrop'           => __( 'Delete image', 'ei-image-crop' ),
					'confirmDeleteTitle'   => __( 'Delete image?', 'ei-image-crop' ),
					'confirmDeleteMessage' => __( 'This cannot be undone.', 'ei-image-crop' ),
					'delete'               => __( 'Delete', 'ei-image-crop' ),
					'cancel'               => __( 'Cancel', 'ei-image-crop' ),
					'previewTitle'         => __( 'Preview', 'ei-image-crop' ),
					'reuseTitle'           => __( 'Existing crops', 'ei-image-crop' ),
					/* translators: %d: number of existing crops of this image. */
					'savedCount'           => __( '%d saved', 'ei-image-crop' ),
					'error'                => __( 'Something went wrong while cropping the image.', 'ei-image-crop' ),
				),
			)
		);
	}

	/**
	 * Decode the JSON blob submitted by the field's single hidden input into
	 * {id, source} ints. Falls back to treating a plain scalar as the id, so
	 * a value written by another integration (e.g. direct update_field()) still works.
	 *
	 * @param mixed $value
	 * @return array{id: int, source: int}
	 */
	protected static function decode_state( $value ) {
		if ( is_array( $value ) && isset( $value['id'] ) ) {
			return array(
				'id'     => (int) $value['id'],
				'source' => (int) ( $value['source'] ?? 0 ),
			);
		}

		// WordPress slash-escapes every value in $_POST (see wp_magic_quotes()),
		// and that raw, still-slashed value is what reaches a field's
		// update_value()/validate_value(). Our JSON blob is full of quotes, so
		// without unslashing here json_decode() silently fails on it and the
		// field would save as empty on every real post save.
		if ( is_string( $value ) ) {
			$value = wp_unslash( $value );
		}

		if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return array(
					'id'     => (int) ( $decoded['id'] ?? 0 ),
					'source' => (int) ( $decoded['source'] ?? 0 ),
				);
			}
		}

		return array(
			'id'     => (int) $value,
			'source' => 0,
		);
	}

	/**
	 * Persist the field value. If no explicit crop was made (id empty but a
	 * source image was chosen), generate the default center-center crop now.
	 *
	 * @param mixed $value
	 * @param int   $post_id
	 * @param array $field
	 * @return int|string
	 */
	public function update_value( $value, $post_id, $field ) {
		$state = self::decode_state( $value );

		if ( $state['id'] ) {
			return $state['id'];
		}

		if ( ! $state['source'] ) {
			return '';
		}

		$ratio_label = self::resolve_ratio( $field );
		$ratio       = Ei_Image_Crop_Generator::parse_ratio( $ratio_label );
		$edit_source = Ei_Image_Crop_Generator::get_edit_source( $state['source'] );

		if ( ! $edit_source ) {
			return '';
		}

		$box = Ei_Image_Crop_Generator::center_box( $edit_source['width'], $edit_source['height'], $ratio );

		$generated = Ei_Image_Crop_Generator::generate( $state['source'], $box, $ratio_label, $field['key'] );

		if ( is_wp_error( $generated ) ) {
			return '';
		}

		return $generated;
	}

	/**
	 * Format the field's value for use in templates, mirroring ACF's native
	 * image field output shapes so existing templates don't need to change.
	 *
	 * @param mixed $value
	 * @param int   $post_id
	 * @param array $field
	 * @return mixed
	 */
	public function format_value( $value, $post_id, $field ) {
		if ( empty( $value ) ) {
			return false;
		}

		$value = (int) $value;

		if ( 'id' === $field['return_format'] ) {
			return $value;
		}

		if ( 'url' === $field['return_format'] ) {
			return wp_get_attachment_url( $value );
		}

		if ( function_exists( 'acf_get_attachment' ) ) {
			$attachment = acf_get_attachment( $value );
			return $attachment ? $attachment : false;
		}

		return $value;
	}

	/**
	 * Validate the field before save.
	 *
	 * @param bool   $valid
	 * @param mixed  $value
	 * @param array  $field
	 * @param string $input
	 * @return bool|string
	 */
	public function validate_value( $valid, $value, $field, $input ) {
		if ( ! $field['required'] ) {
			return $valid;
		}

		$state = self::decode_state( $value );

		if ( empty( $state['id'] ) && empty( $state['source'] ) ) {
			return __( 'Please select an image.', 'ei-image-crop' );
		}

		return $valid;
	}
}
