<?php
/**
 * Folder operations: listing, creating, renaming, and deleting real
 * directories under the uploads folder.
 *
 * The filesystem is the source of truth. An attachment belongs to a folder
 * when dirname( _wp_attached_file ) equals that folder's relative path.
 *
 * @package Rootstuff_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- folder membership is defined
// by _wp_attached_file path prefixes; the meta APIs cannot express anchored
// prefix scans or grouped counts, and results change with every file move.

class RSMF_Folders {

	const MAX_DEPTH = 8;

	/**
	 * Sanitize a folder path relative to the uploads directory.
	 *
	 * Returns a clean 'a/b/c' path, '' for the uploads root, or WP_Error
	 * when the path is invalid (traversal, absolute, too deep, bad chars).
	 *
	 * @param string $path Raw folder path.
	 * @return string|WP_Error
	 */
	public static function sanitize_path( $path ) {
		$path = trim( wp_unslash( (string) $path ) );
		$path = str_replace( '\\', '/', $path );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = explode( '/', $path );

		if ( count( $segments ) > self::MAX_DEPTH ) {
			return new WP_Error( 'rsmf_too_deep', __( 'Folder path is too deep.', 'rootstuff-media-folders' ) );
		}

		$clean = array();
		foreach ( $segments as $segment ) {
			// Validate the raw segment first: sanitization strips leading
			// dots, which would silently accept hidden paths.
			if ( '' === $segment || '.' === $segment[0] ) {
				return new WP_Error( 'rsmf_bad_segment', __( 'Folder path contains an invalid segment.', 'rootstuff-media-folders' ) );
			}
			$segment = self::sanitize_segment( $segment );
			if ( '' === $segment ) {
				return new WP_Error( 'rsmf_bad_segment', __( 'Folder path contains an invalid segment.', 'rootstuff-media-folders' ) );
			}
			$clean[] = $segment;
		}

		return implode( '/', $clean );
	}

	/**
	 * Sanitize one folder name.
	 *
	 * Not sanitize_file_name(): that function has filename semantics — a
	 * segment that looks like a bare extension ("mp3") is rewritten to
	 * "unnamed-file.mp3", corrupting real directory names. This strips the
	 * same unsafe characters without the extension handling.
	 *
	 * @param string $segment Raw folder name (one path segment).
	 * @return string Cleaned segment, '' if nothing safe remains.
	 */
	public static function sanitize_segment( $segment ) {
		// Same character blacklist core uses for filenames.
		$special = array(
			'?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"',
			'&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%',
			'+', '’', '«', '»', '”', '“', chr( 0 ),
		);

		// Percent-encoded octets could smuggle stripped characters, so
		// remove them while the '%' is still present.
		$segment = preg_replace( '/%[0-9a-fA-F]{2}/', '', $segment );
		$segment = str_replace( $special, '', $segment );
		// Collapse whitespace runs to single hyphens.
		$segment = preg_replace( '/[\r\n\t ]+/', '-', $segment );

		return trim( $segment, '.-_' );
	}

	/**
	 * Absolute filesystem path for a relative folder path, with a
	 * containment check against the uploads base directory.
	 *
	 * @param string $relative Sanitized relative path ('' for root).
	 * @return string|WP_Error
	 */
	public static function absolute_path( $relative ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		$full    = wp_normalize_path( $base . ( '' === $relative ? '' : '/' . $relative ) );

		if ( 0 !== strpos( trailingslashit( $full ), trailingslashit( $base ) ) ) {
			return new WP_Error( 'rsmf_outside_uploads', __( 'Path is outside the uploads directory.', 'rootstuff-media-folders' ) );
		}

		return $full;
	}

	/**
	 * List all real folders under uploads as a flat, sorted array of
	 * relative paths (root '' excluded).
	 *
	 * @return string[]
	 */
	public static function get_folders() {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		$folders = array();

		if ( ! is_dir( $base ) ) {
			return $folders;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
					function ( $current ) {
						// Skip hidden directories and files at every level.
						return 0 !== strpos( $current->getFilename(), '.' );
					}
				),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setMaxDepth( self::MAX_DEPTH - 1 );
		} catch ( Exception $e ) {
			return $folders;
		}

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				$folders[] = ltrim( str_replace( $base, '', wp_normalize_path( $item->getPathname() ) ), '/' );
			}
		}

		sort( $folders, SORT_NATURAL | SORT_FLAG_CASE );

		return $folders;
	}

	/**
	 * Count registered attachments directly inside a folder.
	 *
	 * @param string $relative Sanitized relative folder path ('' for root).
	 * @return int
	 */
	public static function count_attachments( $relative ) {
		global $wpdb;

		if ( '' === $relative ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value NOT LIKE '%/%'"
			);
		}

		$like = $wpdb->esc_like( $relative ) . '/%';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file'
				 AND meta_value LIKE %s AND meta_value NOT LIKE %s",
				$like,
				$like . '/%'
			)
		);
	}

	/**
	 * The folder hierarchy as a nested tree for the JS sidebar.
	 *
	 * @return array { root_count: int, folders: array[] } where each node is
	 *               { name, path, count, children }.
	 */
	public static function get_tree() {
		$counts = self::count_all();
		$root   = array();

		foreach ( self::get_folders() as $folder ) {
			$level = &$root;
			$path  = '';

			foreach ( explode( '/', $folder ) as $segment ) {
				$path = ( '' === $path ) ? $segment : $path . '/' . $segment;

				if ( ! isset( $level[ $segment ] ) ) {
					$level[ $segment ] = array(
						'name'     => $segment,
						'path'     => $path,
						'count'    => isset( $counts[ $path ] ) ? $counts[ $path ] : 0,
						'children' => array(),
					);
				}

				$level = &$level[ $segment ]['children'];
			}

			unset( $level );
		}

		return array(
			'root_count' => isset( $counts[''] ) ? $counts[''] : 0,
			'folders'    => self::tree_values( $root ),
		);
	}

	/**
	 * Convert the name-keyed tree levels into plain arrays, recursively.
	 *
	 * @param array $nodes Name-keyed nodes.
	 * @return array[]
	 */
	protected static function tree_values( $nodes ) {
		$out = array();
		foreach ( $nodes as $node ) {
			$node['children'] = self::tree_values( $node['children'] );
			$out[]            = $node;
		}
		return $out;
	}

	/**
	 * Attachment counts for every folder in one query.
	 *
	 * @return array Map of relative folder path ('' for root) to count.
	 */
	public static function count_all() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT
				CASE WHEN meta_value LIKE '%/%'
					THEN LEFT( meta_value, LENGTH( meta_value ) - LENGTH( SUBSTRING_INDEX( meta_value, '/', -1 ) ) - 1 )
					ELSE ''
				END AS folder,
				COUNT(*) AS total
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_wp_attached_file'
			 GROUP BY folder",
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row['folder'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Attachment IDs whose files live directly inside a folder.
	 *
	 * @param string $relative Sanitized relative folder path.
	 * @return int[]
	 */
	public static function get_attachment_ids( $relative ) {
		global $wpdb;

		if ( '' === $relative ) {
			return array_map(
				'intval',
				$wpdb->get_col(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attached_file' AND meta_value NOT LIKE '%/%'"
				)
			);
		}

		$like = $wpdb->esc_like( $relative ) . '/%';

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attached_file'
					 AND meta_value LIKE %s AND meta_value NOT LIKE %s",
					$like,
					$like . '/%'
				)
			)
		);
	}

	/**
	 * Create a folder.
	 *
	 * @param string $path Raw relative folder path.
	 * @return string|WP_Error The sanitized path on success.
	 */
	public static function create( $path ) {
		$relative = self::sanitize_path( $path );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}
		if ( '' === $relative ) {
			return new WP_Error( 'rsmf_empty_path', __( 'Folder name is required.', 'rootstuff-media-folders' ) );
		}

		$full = self::absolute_path( $relative );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		if ( is_dir( $full ) ) {
			return new WP_Error( 'rsmf_exists', __( 'That folder already exists.', 'rootstuff-media-folders' ) );
		}

		if ( ! wp_mkdir_p( $full ) ) {
			return new WP_Error( 'rsmf_mkdir_failed', __( 'Could not create the folder on the server. Check file permissions.', 'rootstuff-media-folders' ) );
		}

		do_action( 'rsmf_folder_created', $relative );

		return $relative;
	}

	/**
	 * Rename or move a folder, updating every attachment inside it (at any
	 * depth), rewriting content URLs, and creating a prefix redirect.
	 *
	 * @param string $from Raw current relative path.
	 * @param string $to   Raw new relative path.
	 * @return array|WP_Error { moved: int } on success.
	 */
	public static function rename( $from, $to ) {
		$from = self::sanitize_path( $from );
		$to   = self::sanitize_path( $to );

		if ( is_wp_error( $from ) ) {
			return $from;
		}
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( '' === $from || '' === $to ) {
			return new WP_Error( 'rsmf_empty_path', __( 'Both the current and new folder paths are required.', 'rootstuff-media-folders' ) );
		}
		if ( $from === $to ) {
			return new WP_Error( 'rsmf_same_path', __( 'The new folder path matches the current one.', 'rootstuff-media-folders' ) );
		}
		if ( 0 === strpos( $to . '/', $from . '/' ) ) {
			return new WP_Error( 'rsmf_into_self', __( 'A folder cannot be moved inside itself.', 'rootstuff-media-folders' ) );
		}

		$from_full = self::absolute_path( $from );
		$to_full   = self::absolute_path( $to );
		if ( is_wp_error( $from_full ) ) {
			return $from_full;
		}
		if ( is_wp_error( $to_full ) ) {
			return $to_full;
		}

		if ( ! is_dir( $from_full ) ) {
			return new WP_Error( 'rsmf_missing', __( 'The folder to rename does not exist.', 'rootstuff-media-folders' ) );
		}
		if ( file_exists( $to_full ) ) {
			return new WP_Error( 'rsmf_exists', __( 'A folder already exists at the new path.', 'rootstuff-media-folders' ) );
		}

		$parent = dirname( $to_full );
		if ( ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'rsmf_mkdir_failed', __( 'Could not create the destination parent folder.', 'rootstuff-media-folders' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving within uploads.
		if ( ! rename( $from_full, $to_full ) ) {
			return new WP_Error( 'rsmf_rename_failed', __( 'Could not rename the folder on the server.', 'rootstuff-media-folders' ) );
		}

		$moved = self::update_database_after_rename( $from, $to );

		do_action( 'rsmf_folder_renamed', $from, $to, $moved );

		return array( 'moved' => $moved );
	}

	/**
	 * After a directory rename, fix _wp_attached_file, attachment metadata,
	 * GUIDs, post content URLs, and add a prefix redirect.
	 *
	 * @param string $from Old sanitized relative path.
	 * @param string $to   New sanitized relative path.
	 * @return int Number of attachments updated.
	 */
	protected static function update_database_after_rename( $from, $to ) {
		global $wpdb;

		$uploads  = wp_get_upload_dir();
		$old_url  = $uploads['baseurl'] . '/' . $from . '/';
		$new_url  = $uploads['baseurl'] . '/' . $to . '/';
		$like     = $wpdb->esc_like( $from ) . '/%';

		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
					$like
				)
			)
		);

		// The redirect goes in BEFORE the per-attachment updates: if the
		// loop is interrupted, every file stays reachable at its old URL
		// (the directory itself has already moved on disk).
		if ( rsmf_get_setting( 'create_redirects' ) && $ids ) {
			RSMF_Redirects::add(
				wp_parse_url( $old_url, PHP_URL_PATH ),
				wp_parse_url( $new_url, PHP_URL_PATH ),
				'prefix'
			);
		}

		foreach ( $ids as $id ) {
			$file = get_post_meta( $id, '_wp_attached_file', true );
			$new  = $to . substr( $file, strlen( $from ) );
			update_post_meta( $id, '_wp_attached_file', $new );

			// _wp_attachment_metadata is serialized, so it must be updated in PHP.
			$meta = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && isset( $meta['file'] ) && 0 === strpos( $meta['file'], $from . '/' ) ) {
				$meta['file'] = $to . substr( $meta['file'], strlen( $from ) );
				wp_update_attachment_metadata( $id, $meta );
			}

			if ( rsmf_get_setting( 'update_guid' ) ) {
				$wpdb->update(
					$wpdb->posts,
					array( 'guid' => $uploads['baseurl'] . '/' . $new ),
					array( 'ID' => $id )
				);
			}

			clean_attachment_cache( $id );
		}

		// Post content is not serialized, so a SQL prefix replace is safe here.
		if ( rsmf_get_setting( 'rewrite_content' ) && $ids ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s )
					 WHERE post_content LIKE %s",
					$old_url,
					$new_url,
					'%' . $wpdb->esc_like( $old_url ) . '%'
				)
			);
		}

		return count( $ids );
	}

	/**
	 * Delete an empty folder. Folders containing files (registered or not)
	 * or subfolders are refused.
	 *
	 * @param string $path Raw relative folder path.
	 * @return true|WP_Error
	 */
	public static function delete( $path ) {
		$relative = self::sanitize_path( $path );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}
		if ( '' === $relative ) {
			return new WP_Error( 'rsmf_empty_path', __( 'The uploads root cannot be deleted.', 'rootstuff-media-folders' ) );
		}

		$full = self::absolute_path( $relative );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		if ( ! is_dir( $full ) ) {
			return new WP_Error( 'rsmf_missing', __( 'The folder does not exist.', 'rootstuff-media-folders' ) );
		}

		$entries = array_diff( scandir( $full ), array( '.', '..' ) );
		// Tolerate stray index placeholders only.
		$entries = array_diff( $entries, array( 'index.php', 'index.html', '.DS_Store' ) );

		if ( ! empty( $entries ) ) {
			return new WP_Error( 'rsmf_not_empty', __( 'Only empty folders can be deleted. Move or delete its files first.', 'rootstuff-media-folders' ) );
		}

		foreach ( array( 'index.php', 'index.html', '.DS_Store' ) as $placeholder ) {
			if ( file_exists( $full . '/' . $placeholder ) ) {
				wp_delete_file( $full . '/' . $placeholder );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		if ( ! rmdir( $full ) ) {
			return new WP_Error( 'rsmf_rmdir_failed', __( 'Could not delete the folder on the server.', 'rootstuff-media-folders' ) );
		}

		do_action( 'rsmf_folder_deleted', $relative );

		return true;
	}
}
