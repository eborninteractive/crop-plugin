<?php
/**
 * Migration report and metadata backfill for sites moving from "ACF Image
 * Aspect Ratio Crop" (Johannes Siipola's acf-image-aspect-ratio-crop
 * plugin) to this one. Inventories what that plugin left behind - which
 * fields used its field type and how they were configured, and every
 * cropped attachment it ever generated - and, once reviewed, can backfill
 * this plugin's own crop metadata onto those same attachments so this
 * plugin recognizes them as crops of their true original. Loading the page
 * only ever reads data; the one write path (apply_migration()) only runs
 * when its own "Apply migration" form is explicitly submitted.
 *
 * @package Ei_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ei_Image_Crop_Migration_Aiarc {

	const LEGACY_META_KEY   = 'acf_image_aspect_ratio_crop';
	const LEGACY_FIELD_TYPE = 'image_aspect_ratio_crop';
	const NONCE_ACTION      = 'ei_image_crop_migrate_aiarc';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	/**
	 * Only ever added when there's actually legacy data on the site - no
	 * clutter in Tools for anyone not migrating from that plugin.
	 */
	public static function register_page() {
		if ( ! current_user_can( 'manage_options' ) || ! self::has_legacy_data() ) {
			return;
		}

		add_management_page(
			__( 'Migrate from ACF Image Aspect Ratio Crop', 'ei-image-crop' ),
			__( 'Migrate Image Crops', 'ei-image-crop' ),
			'manage_options',
			'ei-image-crop-migrate-aiarc',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @return bool
	 */
	protected static function has_legacy_data() {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::LEGACY_META_KEY
			)
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Migrate from ACF Image Aspect Ratio Crop', 'ei-image-crop' ) . '</h1>';

		if ( isset( $_POST['ei_image_crop_migrate_aiarc_apply'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			self::render_apply_results( self::apply_migration() );
		}

		echo '<p>' . esc_html__( 'The report below only reads data. The "Apply migration" button further down writes this plugin\'s own crop metadata onto the attachments listed - review the report first, ideally on a test copy of the site.', 'ei-image-crop' ) . '</p>';

		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Run "Apply migration" before switching any field over to this plugin, not after.', 'ei-image-crop' ) . '</strong> ' . esc_html__( 'It reads each old field\'s crop type/size directly from its current settings to work out the right crop for every attachment - once a field is switched to this plugin, those old settings are gone and every one of its crops would fall back to "free" instead. Migrate first, then switch field types one at a time and set "Image size" to match the suggestion below.', 'ei-image-crop' ) . '</p></div>';

		$fields = self::collect_legacy_fields();
		self::render_fields_table( $fields );

		$field_names = wp_list_pluck( $fields, 'name' );
		self::render_crops_table( $field_names );

		self::render_apply_form();

		echo '</div>';
	}

	/**
	 * Backfills this plugin's own crop metadata (_ei_crop_parent,
	 * _ei_crop_ratio, _ei_crop_box, _ei_crop_hash, and _ei_crop_field_key
	 * when a referencing field is found) onto every legacy cropped
	 * attachment that doesn't already have it - the exact metadata this
	 * plugin needs to recognize it as a crop of its true original (see
	 * Ei_Image_Crop_Generator::resolve_root()), rather than treating the
	 * already-cropped image as if it were itself an uncropped source.
	 *
	 * Never touches any file, never deletes the old plugin's own
	 * metadata, and skips (rather than overwrites) any attachment that
	 * already has _ei_crop_parent - safe to run more than once.
	 *
	 * @return array{migrated: array, skipped: array, already: array}
	 */
	protected static function apply_migration() {
		global $wpdb;

		$fields         = self::collect_legacy_fields();
		$field_names    = wp_list_pluck( $fields, 'name' );
		$fields_by_name = array();
		foreach ( $fields as $field ) {
			$fields_by_name[ $field['name'] ] = $field;
		}

		$crop_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::LEGACY_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$results = array(
			'migrated' => array(),
			'skipped'  => array(),
			'already'  => array(),
		);

		foreach ( $crop_ids as $crop_id ) {
			if ( get_post_meta( $crop_id, '_ei_crop_parent', true ) ) {
				$results['already'][] = $crop_id;
				continue;
			}

			$original_id = (int) get_post_meta( $crop_id, 'acf_image_aspect_ratio_crop_original_image_id', true );
			$coords      = get_post_meta( $crop_id, 'acf_image_aspect_ratio_crop_coordinates', true );

			if ( ! $original_id || ! get_post( $original_id ) ) {
				$results['skipped'][] = array(
					'id'     => $crop_id,
					'reason' => __( 'original image missing', 'ei-image-crop' ),
				);
				continue;
			}

			if ( ! is_array( $coords ) || ! isset( $coords['x'], $coords['y'], $coords['width'], $coords['height'] ) ) {
				$results['skipped'][] = array(
					'id'     => $crop_id,
					'reason' => __( 'no stored coordinates', 'ei-image-crop' ),
				);
				continue;
			}

			$source = Ei_Image_Crop_Generator::get_original_source( $original_id );

			if ( is_wp_error( $source ) ) {
				$results['skipped'][] = array(
					'id'     => $crop_id,
					'reason' => $source->get_error_message(),
				);
				continue;
			}

			$box = Ei_Image_Crop_Generator::sanitize_box(
				array(
					'x' => $coords['x'] / $source['width'],
					'y' => $coords['y'] / $source['height'],
					'w' => $coords['width'] / $source['width'],
					'h' => $coords['height'] / $source['height'],
				)
			);

			// Derived directly from the old field's own settings, not
			// whatever the new field's "Image size" happens to be set to
			// right now - correct regardless of migration order, as long
			// as the new field ends up pointed at a registered size with
			// these same dimensions (see suggest_image_size()).
			$ratio     = 'free';
			$field_key = '';
			$usage     = self::find_usage( $crop_id, $field_names, $wpdb );

			if ( $usage && isset( $fields_by_name[ $usage[0]['field_name'] ] ) ) {
				$field = $fields_by_name[ $usage[0]['field_name'] ];
				$field_key = $field['key'];

				if ( 'free_crop' !== $field['crop_type'] && $field['width'] && $field['height'] ) {
					$ratio = $field['width'] . ':' . $field['height'];
				}
			}

			$hash = Ei_Image_Crop_Generator::box_hash( $original_id, $ratio, $box );

			update_post_meta( $crop_id, '_ei_crop_parent', $original_id );
			update_post_meta( $crop_id, '_ei_crop_ratio', $ratio );
			update_post_meta( $crop_id, '_ei_crop_box', $box );
			update_post_meta( $crop_id, '_ei_crop_hash', $hash );

			if ( $field_key ) {
				update_post_meta( $crop_id, '_ei_crop_field_key', $field_key );
			}

			$results['migrated'][] = array(
				'id'    => $crop_id,
				'ratio' => $ratio,
			);
		}

		return $results;
	}

	/**
	 * @param array $results
	 */
	protected static function render_apply_results( array $results ) {
		echo '<div class="notice notice-success"><p>';
		printf(
			/* translators: 1: migrated count, 2: already-migrated count, 3: skipped count */
			esc_html__( 'Migrated %1$d attachment(s). %2$d already had this plugin\'s metadata and were left untouched. %3$d were skipped.', 'ei-image-crop' ),
			count( $results['migrated'] ),
			count( $results['already'] ),
			count( $results['skipped'] )
		);
		echo '</p></div>';

		if ( $results['skipped'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Skipped:', 'ei-image-crop' ) . '</p><ul>';
			foreach ( $results['skipped'] as $skip ) {
				echo '<li>#' . esc_html( $skip['id'] ) . ' - ' . esc_html( $skip['reason'] ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	protected static function render_apply_form() {
		echo '<h2>' . esc_html__( 'Apply migration', 'ei-image-crop' ) . '</h2>';
		echo '<p>' . esc_html__( 'Writes this plugin\'s own crop metadata onto every cropped attachment above that doesn\'t already have it, using the resolved original and normalized box shown in the table. Nothing is deleted and no image files are touched - the old plugin\'s own metadata is left in place. Attachments that already have this plugin\'s metadata are left untouched, so this is safe to run more than once.', 'ei-image-crop' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Do this before switching any field over to this plugin - not after.', 'ei-image-crop' ) . '</strong></p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		submit_button( __( 'Apply migration now', 'ei-image-crop' ), 'primary', 'ei_image_crop_migrate_aiarc_apply' );
		echo '</form>';
	}

	/**
	 * Walks every field group - including fields nested inside repeaters,
	 * groups, and flexible content layouts - collecting every field still
	 * using the old plugin's own field type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected static function collect_legacy_fields() {
		$found = array();

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return $found;
		}

		foreach ( acf_get_field_groups() as $group ) {
			$fields = acf_get_fields( $group );
			if ( $fields ) {
				self::walk_fields( $fields, $group['title'], $found );
			}
		}

		return $found;
	}

	/**
	 * @param array    $fields
	 * @param string   $group_title
	 * @param array    $found
	 */
	protected static function walk_fields( $fields, $group_title, array &$found ) {
		foreach ( $fields as $field ) {
			if ( self::LEGACY_FIELD_TYPE === $field['type'] ) {
				$found[] = array(
					'key'           => $field['key'],
					'name'          => $field['name'],
					'label'         => $field['label'],
					'group'         => $group_title,
					'crop_type'     => isset( $field['crop_type'] ) ? $field['crop_type'] : 'aspect_ratio',
					'width'         => (int) ( isset( $field['aspect_ratio_width'] ) ? $field['aspect_ratio_width'] : 0 ),
					'height'        => (int) ( isset( $field['aspect_ratio_height'] ) ? $field['aspect_ratio_height'] : 0 ),
					'library'       => isset( $field['library'] ) ? $field['library'] : 'all',
					'return_format' => isset( $field['return_format'] ) ? $field['return_format'] : 'array',
					'min_width'     => isset( $field['min_width'] ) ? $field['min_width'] : '',
					'min_height'    => isset( $field['min_height'] ) ? $field['min_height'] : '',
					'min_size'      => isset( $field['min_size'] ) ? $field['min_size'] : '',
					'max_width'     => isset( $field['max_width'] ) ? $field['max_width'] : '',
					'max_height'    => isset( $field['max_height'] ) ? $field['max_height'] : '',
					'max_size'      => isset( $field['max_size'] ) ? $field['max_size'] : '',
					'mime_types'    => isset( $field['mime_types'] ) ? $field['mime_types'] : '',
				);
			}

			// Repeater / group sub-fields.
			if ( ! empty( $field['sub_fields'] ) ) {
				self::walk_fields( $field['sub_fields'], $group_title, $found );
			}

			// Flexible content's own per-layout field sets.
			if ( ! empty( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) ) {
						self::walk_fields( $layout['sub_fields'], $group_title, $found );
					}
				}
			}
		}
	}

	/**
	 * @param array $fields
	 */
	protected static function render_fields_table( array $fields ) {
		echo '<h2>' . esc_html__( 'Fields using the old field type', 'ei-image-crop' ) . '</h2>';

		if ( ! $fields ) {
			echo '<p>' . esc_html__( 'None found in any field group.', 'ei-image-crop' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'Field', 'ei-image-crop' ),
				__( 'Field group', 'ei-image-crop' ),
				__( 'Crop type', 'ei-image-crop' ),
				__( 'Configured size', 'ei-image-crop' ),
				__( 'Suggested Ei Image Crop setup', 'ei-image-crop' ),
				__( 'Not carried over', 'ei-image-crop' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $fields as $field ) {
			$dropped = array();
			foreach ( array( 'min_width', 'min_height', 'min_size', 'max_width', 'max_height', 'max_size', 'mime_types' ) as $k ) {
				if ( '' !== $field[ $k ] && 0 !== (int) $field[ $k ] ) {
					$dropped[] = $k . '=' . $field[ $k ];
				}
			}

			echo '<tr>';
			echo '<td>' . esc_html( $field['label'] ) . ' <code>' . esc_html( $field['name'] ) . '</code></td>';
			echo '<td>' . esc_html( $field['group'] ) . '</td>';
			echo '<td>' . esc_html( $field['crop_type'] ) . '</td>';
			echo '<td>' . esc_html( $field['width'] . ' × ' . $field['height'] ) . '</td>';
			echo '<td>' . esc_html( self::suggest_image_size( $field ) ) . '</td>';
			echo '<td>' . esc_html( $dropped ? implode( ', ', $dropped ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array $field
	 * @return string
	 */
	protected static function suggest_image_size( array $field ) {
		if ( 'free_crop' === $field['crop_type'] ) {
			return __( 'Free crop - leave "Image size" unset, or register one with add_image_size(..., false) for a max resolution cap', 'ei-image-crop' );
		}

		if ( ! $field['width'] || ! $field['height'] ) {
			return __( 'Missing width/height - cannot suggest a setup', 'ei-image-crop' );
		}

		if ( 'aspect_ratio' === $field['crop_type'] ) {
			// The old plugin's "aspect_ratio" type locks the box's shape but
			// never resizes the result to an exact pixel target - this
			// plugin has no equivalent third mode, so the closest match is
			// the hard-crop one (always resized to the exact target),
			// worth calling out since it's a real, if usually minor,
			// behavior change.
			$note = __( ' (note: the old field never resized to this exact size, just locked the shape - this plugin always will)', 'ei-image-crop' );
		} else {
			$note = '';
		}

		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			if ( ! empty( $size['crop'] ) && (int) $size['width'] === $field['width'] && (int) $size['height'] === $field['height'] ) {
				return sprintf(
					/* translators: %s: registered image size name */
					__( 'Use existing size "%s"', 'ei-image-crop' ),
					$name
				) . $note;
			}
		}

		return sprintf(
			"add_image_size( '%s', %d, %d, true ) then select it",
			sanitize_title( $field['name'] ),
			$field['width'],
			$field['height']
		) . $note;
	}

	/**
	 * @param string[] $field_names Field names collected above, used to
	 *   find which posts currently reference each crop.
	 */
	protected static function render_crops_table( array $field_names ) {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Existing cropped attachments', 'ei-image-crop' ) . '</h2>';

		$crop_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::LEGACY_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		if ( ! $crop_ids ) {
			echo '<p>' . esc_html__( 'None found.', 'ei-image-crop' ) . '</p>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of cropped attachments found */
					__( '%d cropped attachment(s) found.', 'ei-image-crop' ),
					count( $crop_ids )
				)
			)
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'Crop', 'ei-image-crop' ),
				__( 'Original', 'ei-image-crop' ),
				__( 'Stored pixel box', 'ei-image-crop' ),
				__( 'Normalized box', 'ei-image-crop' ),
				__( 'Currently used by', 'ei-image-crop' ),
				__( 'Issues', 'ei-image-crop' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $crop_ids as $crop_id ) {
			self::render_crop_row( $crop_id, $field_names, $wpdb );
		}

		echo '</tbody></table>';
	}

	/**
	 * @param int      $crop_id
	 * @param string[] $field_names
	 * @param wpdb     $wpdb
	 */
	protected static function render_crop_row( $crop_id, array $field_names, $wpdb ) {
		$issues      = array();
		$original_id = (int) get_post_meta( $crop_id, 'acf_image_aspect_ratio_crop_original_image_id', true );
		$coords      = get_post_meta( $crop_id, 'acf_image_aspect_ratio_crop_coordinates', true );

		if ( ! $original_id || ! get_post( $original_id ) ) {
			$issues[] = __( 'original image missing', 'ei-image-crop' );
		}

		if ( ! is_array( $coords ) || ! isset( $coords['x'], $coords['y'], $coords['width'], $coords['height'] ) ) {
			$issues[] = __( 'no stored coordinates', 'ei-image-crop' );
		}

		$normalized = '—';

		if ( $original_id && empty( $issues ) ) {
			$source = Ei_Image_Crop_Generator::get_original_source( $original_id );

			if ( is_wp_error( $source ) ) {
				$issues[] = $source->get_error_message();
			} else {
				$normalized = sprintf(
					'x=%.4f y=%.4f w=%.4f h=%.4f',
					$coords['x'] / $source['width'],
					$coords['y'] / $source['height'],
					$coords['width'] / $source['width'],
					$coords['height'] / $source['height']
				);
			}
		}

		$usage         = self::find_usage( $crop_id, $field_names, $wpdb );
		$usage_display = array();
		foreach ( $usage as $used_by ) {
			$usage_display[] = '#' . $used_by['post_id'] . ' (' . $used_by['field_name'] . ')';
		}

		echo '<tr>';
		echo '<td>' . esc_html( $crop_id . ' - ' . get_the_title( $crop_id ) ) . '</td>';
		echo '<td>' . esc_html( $original_id ? ( $original_id . ' - ' . get_the_title( $original_id ) ) : '—' ) . '</td>';
		echo '<td>' . esc_html( is_array( $coords ) ? wp_json_encode( $coords ) : '—' ) . '</td>';
		echo '<td>' . esc_html( $normalized ) . '</td>';
		echo '<td>' . esc_html( $usage_display ? implode( ', ', $usage_display ) : __( 'not currently referenced', 'ei-image-crop' ) ) . '</td>';
		echo '<td>' . esc_html( $issues ? implode( '; ', $issues ) : '—' ) . '</td>';
		echo '</tr>';
	}

	/**
	 * @param int      $crop_id
	 * @param string[] $field_names
	 * @param wpdb     $wpdb
	 * @return array<int, array{post_id: int, field_name: string}> One entry
	 *   for every post whose value for one of the collected field names is
	 *   this crop.
	 */
	protected static function find_usage( $crop_id, array $field_names, $wpdb ) {
		if ( ! $field_names ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $field_names ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				array_merge( $field_names, array( $crop_id ) )
			)
		);

		$usage = array();
		foreach ( $rows as $row ) {
			$usage[] = array(
				'post_id'    => (int) $row->post_id,
				'field_name' => $row->meta_key,
			);
		}

		return $usage;
	}
}
