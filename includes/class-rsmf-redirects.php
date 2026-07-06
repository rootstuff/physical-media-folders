<?php
/**
 * Stores and serves 301 redirects for files that have been moved.
 *
 * On standard WordPress rewrite setups (Apache RewriteCond !-f, nginx
 * try_files), a request for a missing static file falls through to
 * WordPress, where this class matches it against the redirect table.
 *
 * @package Rootstuff_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this class IS the data layer
// for the plugin's own redirects table; there is no higher-level API for it and
// per-request lookups are not meaningfully cacheable.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$table} is
// built from $wpdb->prefix and a literal; table names cannot be placeholders.

class RSMF_Redirects {

	/**
	 * @var RSMF_Redirects|null
	 */
	protected static $instance = null;

	/**
	 * @return RSMF_Redirects
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			// Uploads paths are handled early on init: if a request for a
			// file under the uploads URL reaches PHP at all, the file does
			// not exist on disk, so is_404() need not be trusted (some
			// server configs resolve unmatched paths to the front page).
			add_action( 'init', array( self::$instance, 'maybe_redirect_upload_path' ), 1 );
			add_action( 'template_redirect', array( self::$instance, 'maybe_redirect' ), 1 );
		}
		return self::$instance;
	}

	/**
	 * Redirect requests for moved files under the uploads URL, before the
	 * main query runs.
	 */
	public function maybe_redirect_upload_path() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$request_path = $this->request_path();
		if ( ! $request_path ) {
			return;
		}

		$uploads_path = (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		if ( '' === $uploads_path || 0 !== strpos( $request_path, trailingslashit( $uploads_path ) ) ) {
			return;
		}

		$this->redirect_if_match( $request_path );
	}

	/**
	 * Redirects table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rsmf_redirects';
	}

	/**
	 * Create the redirects table on activation.
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			old_path VARCHAR(255) NOT NULL,
			new_path VARCHAR(255) NOT NULL,
			match_type VARCHAR(10) NOT NULL DEFAULT 'exact',
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY old_path (old_path(191)),
			KEY match_type (match_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record a redirect. Replaces any previous rule for the same old path,
	 * and re-points older rules whose destination was the path now moving
	 * again (A→B then B→C becomes A→C, avoiding redirect chains).
	 *
	 * @param string $old_path   URL path of the old location.
	 * @param string $new_path   URL path of the new location.
	 * @param string $match_type 'exact' or 'prefix'.
	 */
	public static function add( $old_path, $new_path, $match_type = 'exact' ) {
		global $wpdb;

		$old_path = '/' . ltrim( (string) $old_path, '/' );
		$new_path = '/' . ltrim( (string) $new_path, '/' );

		if ( $old_path === $new_path ) {
			return;
		}

		$table = self::table();

		// Collapse chains: anything that pointed at the old path now points at the new one.
		$wpdb->update( $table, array( 'new_path' => $new_path ), array( 'new_path' => $old_path ) );

		// One rule per source path.
		$wpdb->delete( $table, array( 'old_path' => $old_path ) );

		// Moving a file back to a previous home makes any rule *from* the new
		// location circular — remove it.
		$wpdb->delete(
			$table,
			array(
				'old_path' => $new_path,
				'new_path' => $old_path,
			)
		);

		$wpdb->insert(
			$table,
			array(
				'old_path'   => $old_path,
				'new_path'   => $new_path,
				'match_type' => ( 'prefix' === $match_type ) ? 'prefix' : 'exact',
				'created'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Fallback: on a 404, look the requested path up in the redirect table
	 * (covers setups where the uploads URL lives on another path).
	 */
	public function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}

		$request_path = $this->request_path();
		if ( $request_path ) {
			$this->redirect_if_match( $request_path );
		}
	}

	/**
	 * The decoded path portion of the current request.
	 *
	 * @return string
	 */
	protected function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = (string) wp_parse_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		// Stored paths are unencoded; normalize the request to match.
		return rawurldecode( $path );
	}

	/**
	 * Issue a 301 when the path matches a redirect rule.
	 *
	 * @param string $request_path Decoded request path.
	 */
	protected function redirect_if_match( $request_path ) {
		$target = self::resolve( $request_path );
		if ( ! $target ) {
			return;
		}

		wp_safe_redirect( home_url( $target ), 301, 'Rootstuff Media Folders' );
		exit;
	}

	/**
	 * Resolve a request path to its redirect target, or null.
	 *
	 * @param string $request_path URL path being requested.
	 * @return string|null
	 */
	public static function resolve( $request_path ) {
		global $wpdb;

		$table = self::table();

		// Exact rules win.
		$exact = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT new_path FROM {$table} WHERE match_type = 'exact' AND old_path = %s LIMIT 1",
				$request_path
			)
		);
		if ( $exact ) {
			return $exact;
		}

		// Longest matching prefix rule (folder renames). LOCATE, not LIKE:
		// stored paths often contain underscores, which are LIKE wildcards
		// and would over-match unrelated paths.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT old_path, new_path FROM {$table}
				 WHERE match_type = 'prefix' AND LOCATE( old_path, %s ) = 1
				 ORDER BY LENGTH( old_path ) DESC LIMIT 1",
				$request_path
			)
		);
		if ( $row ) {
			return $row->new_path . substr( $request_path, strlen( $row->old_path ) );
		}

		return null;
	}

	/**
	 * Recent redirect rules, for the admin screen.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT old_path, new_path, match_type, created FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Total number of redirect rules.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
