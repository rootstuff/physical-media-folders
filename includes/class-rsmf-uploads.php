<?php
/**
 * Routes new uploads into a chosen physical folder instead of the
 * default year/month structure.
 *
 * @package Rootstuff_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSMF_Uploads {

	/**
	 * @var RSMF_Uploads|null
	 */
	protected static $instance = null;

	/**
	 * @return RSMF_Uploads
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_filter( 'upload_dir', array( self::$instance, 'filter_upload_dir' ) );
			add_filter( 'plupload_init', array( self::$instance, 'filter_plupload_init' ) );
			add_action( 'pre-plupload-upload-ui', array( self::$instance, 'render_folder_picker' ) );
		}
		return self::$instance;
	}

	/**
	 * Folder requested via the URL (carried from the media library's
	 * folder filter), or null when none.
	 *
	 * @return string|null Relative path ('' for the uploads root) or null.
	 */
	protected function requested_folder() {
		if ( ! isset( $_GET['rsmf_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- destination preselection only.
			return null;
		}
		return RSMF_Media_Library::normalize_choice( sanitize_text_field( wp_unslash( $_GET['rsmf_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Seed the classic uploader (media-new.php) with the folder carried in
	 * the URL so its uploads route like drag and drop ones.
	 *
	 * @param array $init Plupload settings.
	 * @return array
	 */
	public function filter_plupload_init( $init ) {
		$folder = $this->requested_folder();
		if ( null !== $folder ) {
			if ( ! isset( $init['multipart_params'] ) || ! is_array( $init['multipart_params'] ) ) {
				$init['multipart_params'] = array();
			}
			$init['multipart_params']['rsmf_folder'] = ( '' === $folder ) ? RSMF_Media_Library::ROOT : $folder;
		}
		return $init;
	}

	/**
	 * Destination folder picker on the Add Media File screen
	 * (media-new.php), preselected from the URL.
	 */
	public function render_folder_picker() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'media' !== $screen->id ) {
			return;
		}

		$requested = $this->requested_folder();
		$current   = ( null === $requested ) ? '' : ( ( '' === $requested ) ? RSMF_Media_Library::ROOT : $requested );
		?>
		<p class="rsmf-upload-destination">
			<label for="rsmf-upload-folder"><strong><?php esc_html_e( 'Upload to folder:', 'rootstuff-media-folders' ); ?></strong></label>
			<select name="rsmf_folder" id="rsmf-upload-folder">
				<option value=""><?php esc_html_e( 'WordPress default (year/month)', 'rootstuff-media-folders' ); ?></option>
				<?php
				foreach ( RSMF_Media_Library::folder_choices() as $choice => $label ) {
					if ( '' === $choice ) {
						continue;
					}
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $choice ),
						selected( $current, $choice, false ),
						esc_html( $label )
					);
				}
				?>
			</select>
		</p>
		<?php
		// The change listener that syncs this select into the plupload
		// instance lives in assets/folder-picker.js.
	}

	/**
	 * Point new media uploads at the configured default folder.
	 *
	 * @param array $dirs Upload directory data.
	 * @return array
	 */
	public function filter_upload_dir( $dirs ) {
		$folder = (string) rsmf_get_setting( 'default_upload_folder' );
		$folder = ( '' === $folder ) ? null : $folder;

		// A folder selected in the tree sidebar rides along with the upload
		// request and wins over the default setting. Capability and nonce
		// for the upload itself are enforced by core before files land.
		if ( isset( $_POST['rsmf_folder'] ) && '' !== $_POST['rsmf_folder'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$requested = RSMF_Media_Library::normalize_choice( sanitize_text_field( wp_unslash( $_POST['rsmf_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( null !== $requested ) {
				$folder = $requested;
			}
		}

		/**
		 * Filter the physical folder new uploads are placed in.
		 *
		 * Return a path relative to the uploads directory, '' for the
		 * uploads root, or null to keep the WordPress default (year/month
		 * or the saved setting when one is configured).
		 *
		 * @param string|null $folder Relative folder path or null.
		 */
		$folder = apply_filters( 'rsmf_upload_folder', $folder );

		if ( null === $folder || ! $this->is_media_upload() ) {
			return $dirs;
		}

		$clean = RSMF_Folders::sanitize_path( $folder );
		if ( is_wp_error( $clean ) ) {
			return $dirs;
		}

		$subdir = ( '' === $clean ) ? '' : '/' . $clean;

		$dirs['subdir'] = $subdir;
		$dirs['path']   = $dirs['basedir'] . $subdir;
		$dirs['url']    = $dirs['baseurl'] . $subdir;

		return $dirs;
	}

	/**
	 * Whether the current request is a media library upload, as opposed to
	 * some other consumer of wp_upload_dir() (plugin/theme packages,
	 * exporters, form plugins, and so on).
	 *
	 * @return bool
	 */
	protected function is_media_upload() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		global $pagenow;

		// async-upload.php receives every media library upload — both the
		// modern ajax flow (action=upload-attachment) and the legacy short
		// form used by media-new.php, which sends no action parameter.
		if ( isset( $pagenow ) && 'async-upload.php' === $pagenow ) {
			return true;
		}

		// The block editor uploads through the REST API.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			return (bool) preg_match( '#/wp/v2/media(?:/|$)#', $route );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- routing decision only; core verifies nonces before handling the upload.
		// html-upload: the no-JS fallback form on media-new.php.
		if ( isset( $_POST['html-upload'] ) ) {
			return true;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		// phpcs:enable

		// upload-attachment: the async uploader (grid view and media modal).
		// media-form: the classic uploader on media-new.php.
		return in_array( $action, array( 'upload-attachment', 'media-form' ), true );
	}
}
