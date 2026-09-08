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

	/**
	 * Ratios offered in the field settings dropdown. Filterable.
	 *
	 * @var array
	 */
	public $ratio_choices;

	public function __construct() {
		$this->name     = 'ei_image_crop';
		$this->label    = __( 'Image Crop', 'ei-image-crop' );
		$this->category = 'content';
		$this->defaults = array(
			'aspect_ratio'  => '16:9',
			'custom_ratio'  => '',
			'preview_size'  => 'medium',
			'library'       => 'all',
			'return_format' => 'array',
		);

		$this->ratio_choices = apply_filters(
			'ei_image_crop/ratio_choices',
			array(
				'free'  => __( 'Free (no fixed ratio)', 'ei-image-crop' ),
				'1:1'   => '1:1',
				'4:3'   => '4:3',
				'3:2'   => '3:2',
				'16:9'  => '16:9',
				'21:9'  => '21:9',
				'custom' => __( 'Custom…', 'ei-image-crop' ),
			)
		);

		parent::__construct();
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
				'label'        => __( 'Aspect ratio', 'ei-image-crop' ),
				'instructions' => __( 'A fixed ratio locks the crop frame while scaling it; Free lets the frame itself be resized.', 'ei-image-crop' ),
				'type'         => 'select',
				'name'         => 'aspect_ratio',
				'choices'      => $this->ratio_choices,
			)
		);

		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Custom ratio', 'ei-image-crop' ),
				'instructions' => __( 'Only used when Aspect ratio above is set to "Custom". Format: width:height, e.g. 5:2.', 'ei-image-crop' ),
				'type'         => 'text',
				'name'         => 'custom_ratio',
				'placeholder'  => '5:2',
				'conditions'   => array(
					'field'    => 'aspect_ratio',
					'operator' => '==',
					'value'    => 'custom',
				),
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
	 * Resolve a field's configured ratio setting down to a "W:H" or "free" string.
	 *
	 * @param array $field
	 * @return string
	 */
	public static function resolve_ratio( $field ) {
		$ratio = isset( $field['aspect_ratio'] ) ? $field['aspect_ratio'] : 'free';

		if ( 'custom' === $ratio ) {
			$custom = isset( $field['custom_ratio'] ) ? trim( $field['custom_ratio'] ) : '';
			return Ei_Image_Crop_Generator::parse_ratio( $custom ) ? $custom : 'free';
		}

		return $ratio;
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

		echo '<div class="ei-image-crop-preview">';
		if ( $preview_url ) {
			echo '<img src="' . esc_url( $preview_url ) . '" alt="" />';
		} else {
			echo '<div class="ei-image-crop-placeholder">' . esc_html__( 'No image selected', 'ei-image-crop' ) . '</div>';
		}
		echo '</div>';

		echo '<div class="ei-image-crop-existing" hidden></div>';

		echo '<div class="ei-image-crop-actions">';
		echo '<button type="button" class="button ei-image-crop-select">' . esc_html__( 'Select image', 'ei-image-crop' ) . '</button> ';
		echo '<button type="button" class="button ei-image-crop-edit"' . ( $parent_id ? '' : ' hidden' ) . '>' . esc_html__( 'Adjust crop', 'ei-image-crop' ) . '</button> ';
		echo '<button type="button" class="button-link-delete ei-image-crop-remove"' . ( $parent_id ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'ei-image-crop' ) . '</button>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Enqueue admin assets. Called by ACF once per page load when the field type is present.
	 */
	public function input_admin_enqueue_scripts() {
		$url = EI_IMAGE_CROP_URL;
		$ver = EI_IMAGE_CROP_VERSION;

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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ei_image_crop' ),
				'i18n'    => array(
					'selectImage'   => __( 'Select an image', 'ei-image-crop' ),
					'useImage'      => __( 'Use this image', 'ei-image-crop' ),
					'save'          => __( 'Crop image', 'ei-image-crop' ),
					'useCrop'       => __( 'Use image', 'ei-image-crop' ),
					'close'         => __( 'Close', 'ei-image-crop' ),
					'deleteCrop'    => __( 'Delete this crop', 'ei-image-crop' ),
					'confirmDelete' => __( 'Delete this crop? This cannot be undone.', 'ei-image-crop' ),
					'reuseTitle'    => __( 'Existing crops of this image', 'ei-image-crop' ),
					'error'         => __( 'Something went wrong while cropping the image.', 'ei-image-crop' ),
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
