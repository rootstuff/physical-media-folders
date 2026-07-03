<?php
/**
 * Moves a single attachment (original file plus every generated size) to a
 * different physical folder, then updates the database to match.
 *
 * @package Physical_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMF_Mover {

	/**
	 * Move an attachment into a folder.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $folder        Raw destination folder relative to uploads ('' for root).
	 * @return true|WP_Error
	 */
	public static function move( $attachment_id, $folder ) {
		$attachment_id = (int) $attachment_id;

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'pmf_not_attachment', __( 'That item is not an attachment.', 'physical-media-folders' ) );
		}

		$folder = PMF_Folders::sanitize_path( $folder );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$old_relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $old_relative ) {
			return new WP_Error( 'pmf_no_file', __( 'The attachment has no file path recorded.', 'physical-media-folders' ) );
		}

		$old_relative = ltrim( wp_normalize_path( $old_relative ), '/' );
		$filename     = wp_basename( $old_relative );
		$old_folder   = ( false === strpos( $old_relative, '/' ) ) ? '' : dirname( $old_relative );
		$new_relative = ( '' === $folder ) ? $filename : $folder . '/' . $filename;

		if ( $old_folder === $folder ) {
			return true; // Already there.
		}

		$uploads  = wp_get_upload_dir();
		$base_dir = wp_normalize_path( $uploads['basedir'] );
		$base_url = $uploads['baseurl'];

		$old_dir = PMF_Folders::absolute_path( $old_folder );
		$new_dir = PMF_Folders::absolute_path( $folder );
		if ( is_wp_error( $old_dir ) ) {
			return $old_dir;
		}
		if ( is_wp_error( $new_dir ) ) {
			return $new_dir;
		}

		if ( ! file_exists( $base_dir . '/' . $old_relative ) ) {
			return new WP_Error(
				'pmf_source_missing',
				/* translators: %s: file path */
				sprintf( __( 'The file %s is missing on the server.', 'physical-media-folders' ), $old_relative )
			);
		}

		if ( ! is_dir( $new_dir ) && ! wp_mkdir_p( $new_dir ) ) {
			return new WP_Error( 'pmf_mkdir_failed', __( 'Could not create the destination folder.', 'physical-media-folders' ) );
		}

		// Collect every file that belongs to this attachment: the original,
		// each generated size, and the pre-scaled original if present. All of
		// them live in the same directory as the main file.
		$meta      = wp_get_attachment_metadata( $attachment_id );
		$filenames = array( $filename );

		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$filenames[] = wp_basename( $size['file'] );
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$filenames[] = wp_basename( $meta['original_image'] );
			}
		}
		$filenames = array_unique( $filenames );

		// Refuse to overwrite: check every destination before touching anything.
		foreach ( $filenames as $name ) {
			if ( file_exists( $new_dir . '/' . $name ) ) {
				return new WP_Error(
					'pmf_collision',
					/* translators: 1: file name, 2: folder path */
					sprintf( __( 'A file named %1$s already exists in %2$s.', 'physical-media-folders' ), $name, ( '' === $folder ? __( 'the uploads root', 'physical-media-folders' ) : $folder ) )
				);
			}
		}

		// Move the files, rolling back on partial failure.
		$moved = array();
		foreach ( $filenames as $name ) {
			$src = $old_dir . '/' . $name;
			$dst = $new_dir . '/' . $name;

			if ( ! file_exists( $src ) ) {
				continue; // A size listed in metadata but missing on disk is not fatal.
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving within uploads.
			if ( ! rename( $src, $dst ) ) {
				foreach ( $moved as $undo ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					rename( $new_dir . '/' . $undo, $old_dir . '/' . $undo );
				}
				return new WP_Error(
					'pmf_move_failed',
					/* translators: %s: file name */
					sprintf( __( 'Could not move %s. No changes were made.', 'physical-media-folders' ), $name )
				);
			}

			$moved[] = $name;
		}

		// Update the database.
		update_attached_file( $attachment_id, $base_dir . '/' . $new_relative );

		if ( is_array( $meta ) ) {
			if ( isset( $meta['file'] ) ) {
				$meta['file'] = $new_relative;
			}
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		if ( pmf_get_setting( 'update_guid' ) ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->posts,
				array( 'guid' => $base_url . '/' . $new_relative ),
				array( 'ID' => $attachment_id )
			);
		}

		// Rewrite URLs in post content and record redirects, per file.
		$old_prefix = $base_url . '/' . ( '' === $old_folder ? '' : $old_folder . '/' );
		$new_prefix = $base_url . '/' . ( '' === $folder ? '' : $folder . '/' );

		foreach ( $moved as $name ) {
			$old_url = $old_prefix . $name;
			$new_url = $new_prefix . $name;

			if ( pmf_get_setting( 'rewrite_content' ) ) {
				self::rewrite_content_urls( $old_url, $new_url );
			}

			if ( pmf_get_setting( 'create_redirects' ) ) {
				PMF_Redirects::add(
					wp_parse_url( $old_url, PHP_URL_PATH ),
					wp_parse_url( $new_url, PHP_URL_PATH ),
					'exact'
				);
			}
		}

		clean_attachment_cache( $attachment_id );

		/**
		 * Fires after an attachment has been physically moved.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $old_relative  Old path relative to uploads.
		 * @param string $new_relative  New path relative to uploads.
		 */
		do_action( 'pmf_attachment_moved', $attachment_id, $old_relative, $new_relative );

		return true;
	}

	/**
	 * Move several attachments; collects per-item errors.
	 *
	 * @param int[]  $attachment_ids Attachment IDs.
	 * @param string $folder         Destination folder.
	 * @return array { moved: int, errors: array<int,string> }
	 */
	public static function move_many( $attachment_ids, $folder ) {
		$result = array(
			'moved'  => 0,
			'errors' => array(),
		);

		foreach ( array_map( 'intval', (array) $attachment_ids ) as $id ) {
			// Large batches move many files each (original + thumbnails);
			// reset the execution clock per item where the host allows it.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$outcome = self::move( $id, $folder );
			if ( is_wp_error( $outcome ) ) {
				$result['errors'][ $id ] = $outcome->get_error_message();
			} else {
				$result['moved']++;
			}
		}

		return $result;
	}

	/**
	 * Replace an exact URL with another across post content.
	 *
	 * Post content is not PHP-serialized, so a direct SQL replace is safe.
	 * Postmeta is intentionally not touched (it may hold serialized data);
	 * use the pmf_attachment_moved action to handle custom storage.
	 *
	 * @param string $old_url Old absolute URL.
	 * @param string $new_url New absolute URL.
	 */
	protected static function rewrite_content_urls( $old_url, $new_url ) {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s",
				'%' . $wpdb->esc_like( $old_url ) . '%'
			)
		);

		if ( ! $post_ids ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s )
				 WHERE post_content LIKE %s",
				$old_url,
				$new_url,
				'%' . $wpdb->esc_like( $old_url ) . '%'
			)
		);

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( (int) $post_id );
		}
	}
}
