<?php
/**
 * Crop math and on-demand image generation.
 *
 * @package Ei_Image_Crop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ei_Image_Crop_Generator {

	/**
	 * Parse a ratio string like "16:9" into array( 16, 9 ). Returns null for
	 * "free", "free:W:H" (see parse_max_size()), or empty - none of those
	 * lock the crop box to a fixed shape.
	 *
	 * @param string $ratio
	 * @return array|null
	 */
	public static function parse_ratio( $ratio ) {
		if ( empty( $ratio ) || 'free' === $ratio || 0 === strpos( (string) $ratio, 'free:' ) ) {
			return null;
		}

		$parts = explode( ':', $ratio );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$w = (float) $parts[0];
		$h = (float) $parts[1];

		if ( $w <= 0 || $h <= 0 ) {
			return null;
		}

		return array( $w, $h );
	}

	/**
	 * The optional max width/height encoded in a "free:W:H" ratio label
	 * (see Ei_Image_Crop_Field::resolve_ratio()) - a free-form crop whose
	 * registered image size still has a non-zero width and/or height,
	 * meant as a cap on the result's resolution the same way WordPress's
	 * own (non-cropped) thumbnail generation treats those numbers, not a
	 * fixed ratio to lock the crop box's shape to. A 0 on either side
	 * means that axis isn't capped, matching how add_image_size() itself
	 * treats a 0 width or height. Returns null for anything else (a hard
	 * ratio, or "free" with no size behind it at all).
	 *
	 * @param string $ratio
	 * @return array{0:int,1:int}|null
	 */
	public static function parse_max_size( $ratio ) {
		if ( 0 !== strpos( (string) $ratio, 'free:' ) ) {
			return null;
		}

		$parts = explode( ':', substr( $ratio, 5 ) );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$w = (int) $parts[0];
		$h = (int) $parts[1];

		if ( $w <= 0 && $h <= 0 ) {
			return null;
		}

		return array( $w, $h );
	}

	/**
	 * Scales $w x $h down (never up) to fit within $max_w x $max_h,
	 * preserving its own aspect ratio - a 0 max on either axis means no
	 * cap there. Returns null when it already fits, so the caller can
	 * leave the crop at its own native pixel size untouched.
	 *
	 * @param int $w
	 * @param int $h
	 * @param int $max_w
	 * @param int $max_h
	 * @return array{0:int,1:int}|null
	 */
	public static function fit_within( $w, $h, $max_w, $max_h ) {
		$scale = 1.0;

		if ( $max_w > 0 && $w > $max_w ) {
			$scale = min( $scale, $max_w / $w );
		}

		if ( $max_h > 0 && $h > $max_h ) {
			$scale = min( $scale, $max_h / $h );
		}

		if ( $scale >= 1.0 ) {
			return null;
		}

		return array( (int) round( $w * $scale ), (int) round( $h * $scale ) );
	}

	/**
	 * Calculate the default centered box inside the source dimensions,
	 * normalized to 0-1. Always the largest box of the target ratio that
	 * fits the source - i.e. flush with the source's edges on whichever
	 * axis is the tighter fit, centered on the other - regardless of how
	 * the source's own absolute pixel size compares to the ratio's literal
	 * target dimensions, so the initial selection always starts out
	 * maximized rather than at the exact target size.
	 *
	 * @param int        $orig_w
	 * @param int        $orig_h
	 * @param array|null $ratio Result of parse_ratio(), or null for a free/full crop.
	 * @return array{x: float, y: float, w: float, h: float}
	 */
	public static function center_box( $orig_w, $orig_h, $ratio ) {
		if ( ! $ratio || $orig_w <= 0 || $orig_h <= 0 ) {
			return array(
				'x' => 0.0,
				'y' => 0.0,
				'w' => 1.0,
				'h' => 1.0,
			);
		}

		list( $ratio_w, $ratio_h ) = $ratio;

		$target_ratio = $ratio_w / $ratio_h;
		$orig_ratio   = $orig_w / $orig_h;

		if ( $orig_ratio > $target_ratio ) {
			$box_h = $orig_h;
			$box_w = $orig_h * $target_ratio;
		} else {
			$box_w = $orig_w;
			$box_h = $orig_w / $target_ratio;
		}

		$x = ( $orig_w - $box_w ) / 2;
		$y = ( $orig_h - $box_h ) / 2;

		return array(
			'x' => $x / $orig_w,
			'y' => $y / $orig_h,
			'w' => $box_w / $orig_w,
			'h' => $box_h / $orig_h,
		);
	}

	/**
	 * Walks `_ei_crop_parent` up from an attachment to the true root source -
	 * the original upload that was never itself generated as a crop. Needed
	 * because a crop can be made from another crop (e.g. reusing one from the
	 * Media Library, then adjusting it again later): naively trusting a
	 * single `_ei_crop_parent` lookup would treat that intermediate crop as
	 * "the" source, so edits keep chaining onto an already-cropped image
	 * instead of the real original, and _ei_crop_parent on new crops keeps
	 * pointing one level too shallow.
	 *
	 * @param int $attachment_id
	 * @return int The root attachment id (the input id itself if it isn't a crop of anything).
	 */
	public static function resolve_root( $attachment_id ) {
		$current = (int) $attachment_id;
		$seen    = array();

		while ( $current && ! isset( $seen[ $current ] ) ) {
			$seen[ $current ] = true;

			$parent = (int) get_post_meta( $current, '_ei_crop_parent', true );

			if ( ! $parent || ! wp_attachment_is_image( $parent ) ) {
				break;
			}

			$current = $parent;
		}

		return $current;
	}

	/**
	 * Best-resolution image to load in the browser cropper. Prefers the
	 * "large" registered size over the full original so huge uploads stay
	 * snappy to edit - normalized coordinates only remain valid doing that
	 * if "large" is actually proportional to the original, which WordPress's
	 * own default definition of it is, but a theme or plugin can re-register
	 * "large" (or any named size) as a hard crop to a fixed, unrelated shape.
	 * Using such a size here would silently load a reshaped image into the
	 * editor instead of the real original, and the resulting crop box would
	 * no longer line up with the actual file - so it's only used when its
	 * own aspect ratio actually matches the original's.
	 *
	 * @param int $attachment_id
	 * @return array{url: string, width: int, height: int}|false
	 */
	public static function get_edit_source( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return false;
		}

		$original_ratio = $meta['width'] / $meta['height'];
		$large          = image_get_intermediate_size( $attachment_id, 'large' );

		if ( $large && ! empty( $large['width'] ) && ! empty( $large['height'] ) ) {
			$large_ratio = $large['width'] / $large['height'];

			if ( abs( $large_ratio - $original_ratio ) / $original_ratio < 0.01 ) {
				return array(
					'url'    => $large['url'],
					'width'  => (int) $large['width'],
					'height' => (int) $large['height'],
				);
			}
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'full' );

		if ( ! $url ) {
			return false;
		}

		return array(
			'url'    => $url,
			'width'  => (int) $meta['width'],
			'height' => (int) $meta['height'],
		);
	}

	/**
	 * True pixel dimensions of the original, un-scaled source file. WordPress
	 * may store a `-scaled` copy as the "full" size for big uploads; crop math
	 * must run against the real original so normalized coordinates line up.
	 * Public (not just used internally by generate()) because the same
	 * "-scaled" trap applies wherever the true original's size matters -
	 * e.g. Ei_Image_Crop_Ajax::get_source() reporting it for the modal
	 * header and the undersized/upscale-warning check, both of which need
	 * the real dimensions, not a possibly-downscaled "full" size.
	 *
	 * @param int $attachment_id
	 * @return array{path: string, width: int, height: int}|WP_Error
	 */
	public static function get_original_source( $attachment_id ) {
		$path = wp_get_original_image_path( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'ei_image_crop_no_source', __( 'Could not find the original image file.', 'ei-image-crop' ) );
		}

		$size = @getimagesize( $path );

		if ( ! $size ) {
			return new WP_Error( 'ei_image_crop_bad_source', __( 'Could not read the original image dimensions.', 'ei-image-crop' ) );
		}

		return array(
			'path'   => $path,
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		);
	}

	/**
	 * Stable hash for a parent + ratio + box combination, used to reuse an
	 * identical crop instead of creating a duplicate attachment.
	 *
	 * @param int    $parent_id
	 * @param string $ratio
	 * @param array  $box Normalized x/y/w/h.
	 * @return string
	 */
	public static function box_hash( $parent_id, $ratio, $box ) {
		$normalized = sprintf(
			'%d|%s|%.4f|%.4f|%.4f|%.4f',
			$parent_id,
			$ratio,
			$box['x'],
			$box['y'],
			$box['w'],
			$box['h']
		);

		return md5( $normalized );
	}

	/**
	 * Find an existing, still-valid crop attachment for this hash.
	 *
	 * @param string $hash
	 * @return int|false Attachment ID, or false if none found.
	 */
	public static function find_existing( $hash ) {
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_ei_crop_hash',
						'value' => $hash,
					),
				),
			)
		);

		if ( empty( $existing ) ) {
			return false;
		}

		$id   = (int) $existing[0];
		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		return $id;
	}

	/**
	 * Generate (or reuse) a cropped attachment.
	 *
	 * @param int      $parent_id  Source attachment ID.
	 * @param array    $box        Normalized x/y/w/h (0-1), relative to the original image.
	 * @param string   $ratio      Ratio label, e.g. "16:9" or "free".
	 * @param string   $field_key  ACF field key this crop was made for (optional, for grouping).
	 * @param int|false $existing_id Attachment ID to overwrite in place (re-crop), or false to create new.
	 * @return int|WP_Error New/updated attachment ID.
	 */
	public static function generate( $parent_id, $box, $ratio, $field_key = '', $existing_id = false ) {
		$parent_id = (int) $parent_id;

		if ( ! wp_attachment_is_image( $parent_id ) ) {
			return new WP_Error( 'ei_image_crop_bad_parent', __( 'Source is not a valid image attachment.', 'ei-image-crop' ) );
		}

		// Always resolve to the true root before doing anything else, so a
		// crop made from another crop still gets parented directly to the
		// real original (not the intermediate crop it happened to be made
		// from) - otherwise every subsequent adjustment keeps chaining one
		// level deeper onto an already-cropped image.
		$parent_id = self::resolve_root( $parent_id );

		$box = self::sanitize_box( $box );

		$hash = self::box_hash( $parent_id, $ratio, $box );

		if ( ! $existing_id ) {
			$reuse = self::find_existing( $hash );
			if ( $reuse ) {
				return $reuse;
			}
		}

		$source = self::get_original_source( $parent_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$px_x = (int) round( $box['x'] * $source['width'] );
		$px_y = (int) round( $box['y'] * $source['height'] );
		$px_w = (int) round( $box['w'] * $source['width'] );
		$px_h = (int) round( $box['h'] * $source['height'] );

		// Clamp to source bounds.
		$px_x = max( 0, min( $px_x, $source['width'] - 1 ) );
		$px_y = max( 0, min( $px_y, $source['height'] - 1 ) );
		$px_w = max( 1, min( $px_w, $source['width'] - $px_x ) );
		$px_h = max( 1, min( $px_h, $source['height'] - $px_y ) );

		$editor = wp_get_image_editor( $source['path'] );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		// A ratio derived from a registered WP image size is always its own
		// literal target width:height (see resolve_ratio()), not just a
		// proportion - resizing the crop to exactly that during the same
		// crop() call, rather than leaving it at whatever size the box was
		// actually dragged to, is what makes a smaller-than-target
		// selection upscale and a larger one downscale, always landing on
		// the registered size's exact pixel dimensions.
		$target = self::parse_ratio( $ratio );
		$dst_w  = null;
		$dst_h  = null;

		if ( $target ) {
			$dst_w = (int) round( $target[0] );
			$dst_h = (int) round( $target[1] );
		} else {
			// A free-form field whose registered size still carries a
			// width/height (see parse_max_size()) treats those as a cap on
			// the result, same as WordPress's own proportional (non-cropped)
			// thumbnails - scaled down to fit when the drawn box is bigger,
			// left at its own native pixel size otherwise (fit_within()
			// returns null when it already fits - never upscaled).
			$max = self::parse_max_size( $ratio );
			if ( $max ) {
				$fit = self::fit_within( $px_w, $px_h, $max[0], $max[1] );
				if ( $fit ) {
					list( $dst_w, $dst_h ) = $fit;
				}
			}
		}

		$cropped = $editor->crop( $px_x, $px_y, $px_w, $px_h, $dst_w, $dst_h, false );
		if ( is_wp_error( $cropped ) ) {
			return $cropped;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'ei_image_crop_upload_dir', $upload_dir['error'] );
		}

		$parent_filename = pathinfo( $source['path'], PATHINFO_FILENAME );
		$ext             = pathinfo( $source['path'], PATHINFO_EXTENSION );
		$ratio_slug      = sanitize_title( $ratio ? $ratio : 'free' );
		$suffix          = $existing_id ? '-' . substr( md5( microtime() ), 0, 6 ) : '';
		$new_filename    = wp_unique_filename(
			$upload_dir['path'],
			"{$parent_filename}-crop-{$ratio_slug}{$suffix}.{$ext}"
		);
		$new_path        = trailingslashit( $upload_dir['path'] ) . $new_filename;

		$saved = $editor->save( $new_path );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( $existing_id ) {
			self::delete_physical_files( $existing_id );

			update_attached_file( $existing_id, $saved['path'] );

			$attachment_id = $existing_id;
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $saved['mime-type'],
				)
			);
		} else {
			$parent_post = get_post( $parent_id );
			$title       = $parent_post ? $parent_post->post_title : $parent_filename;

			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => $saved['mime-type'],
					'post_title'     => sprintf( '%s – %s', $title, $ratio ? $ratio : __( 'free crop', 'ei-image-crop' ) ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				),
				$saved['path']
			);

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$alt = get_post_meta( $parent_id, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		// A crop only ever needs to exist at the one size it was actually
		// cropped to - generating the site's whole registered set of
		// thumbnail/medium/large/etc. copies on top of that is wasted disk
		// space and processing for every single crop. Suppressing it here
		// still leaves wp_generate_attachment_metadata() computing the
		// crop's own width/height/file as normal; anything that later asks
		// for a specific named size on this attachment just falls back to
		// the full/only file WordPress already does that natively.
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		$metadata = wp_generate_attachment_metadata( $attachment_id, $saved['path'] );
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );

		wp_update_attachment_metadata( $attachment_id, $metadata );

		update_post_meta( $attachment_id, '_ei_crop_parent', $parent_id );
		update_post_meta( $attachment_id, '_ei_crop_ratio', $ratio ? $ratio : 'free' );
		update_post_meta( $attachment_id, '_ei_crop_box', $box );
		update_post_meta( $attachment_id, '_ei_crop_hash', $hash );

		if ( $field_key ) {
			update_post_meta( $attachment_id, '_ei_crop_field_key', $field_key );
		}

		return $attachment_id;
	}

	/**
	 * Remove the physical files (all sizes) for an attachment without
	 * deleting the attachment post itself, ahead of writing a fresh crop
	 * into the same attachment ID.
	 *
	 * @param int $attachment_id
	 */
	protected static function delete_physical_files( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = dirname( $file );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$size_path = trailingslashit( $dir ) . $size['file'];
					if ( file_exists( $size_path ) ) {
						@unlink( $size_path );
					}
				}
			}
		}

		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	/**
	 * Sanitize and clamp a normalized crop box to the 0-1 range.
	 *
	 * @param array $box
	 * @return array{x: float, y: float, w: float, h: float}
	 */
	public static function sanitize_box( $box ) {
		$x = isset( $box['x'] ) ? (float) $box['x'] : 0.0;
		$y = isset( $box['y'] ) ? (float) $box['y'] : 0.0;
		$w = isset( $box['w'] ) ? (float) $box['w'] : 1.0;
		$h = isset( $box['h'] ) ? (float) $box['h'] : 1.0;

		$x = max( 0.0, min( $x, 1.0 ) );
		$y = max( 0.0, min( $y, 1.0 ) );
		$w = max( 0.01, min( $w, 1.0 - $x ) );
		$h = max( 0.01, min( $h, 1.0 - $y ) );

		return array(
			'x' => $x,
			'y' => $y,
			'w' => $w,
			'h' => $h,
		);
	}
}
