<?php
/**
 * Admin screen under Media: folder management, the bulk-move destination
 * picker, settings, and the redirect log.
 *
 * @package Rootstuff_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSMF_Admin {

	/**
	 * @var RSMF_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Notices queued for the current request.
	 *
	 * @var array
	 */
	protected $notices = array();

	/**
	 * @return RSMF_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_action( 'admin_menu', array( self::$instance, 'register_menu' ) );
			add_action( 'admin_init', array( self::$instance, 'handle_actions' ) );
		}
		return self::$instance;
	}

	/**
	 * Capability required to manage folders and settings.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		return apply_filters( 'rsmf_manage_capability', 'manage_options' );
	}

	/**
	 * Register the Media > Folders screen.
	 */
	public function register_menu() {
		add_media_page(
			__( 'Folder Settings', 'rootstuff-media-folders' ),
			__( 'Folder Settings', 'rootstuff-media-folders' ),
			RSMF_Media_Library::move_capability(),
			'rsmf-folders',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Process form submissions before output starts.
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['rsmf_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['rsmf_action'] ) );

		check_admin_referer( 'rsmf_' . $action );

		$required = ( 'settings' === $action )
			? self::manage_capability()
			: RSMF_Media_Library::move_capability();

		if ( ! current_user_can( $required ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'rootstuff-media-folders' ) );
		}

		switch ( $action ) {
			case 'bulk_move':
				$raw    = isset( $_POST['rsmf_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['rsmf_ids'] ) ) : '';
				$ids    = $this->resolve_bulk_ids( $raw );
				$target = isset( $_POST['rsmf_target'] ) ? RSMF_Media_Library::normalize_choice( sanitize_text_field( wp_unslash( $_POST['rsmf_target'] ) ) ) : null;

				if ( null === $target || ! $ids ) {
					$this->redirect_with_notice( new WP_Error( 'rsmf_no_target', __( 'Choose a destination folder.', 'rootstuff-media-folders' ) ), '' );
					break;
				}

				$movable = RSMF_Media_Library::filter_movable( $ids );
				$denied  = count( $ids ) - count( $movable );

				$result = RSMF_Mover::move_many( $movable, $target );
				delete_transient( 'rsmf_bulk_ids_' . get_current_user_id() );

				$message = sprintf(
					/* translators: %d: number of files moved */
					_n( '%d file moved.', '%d files moved.', $result['moved'], 'rootstuff-media-folders' ),
					$result['moved']
				);
				if ( $denied ) {
					$result['errors'][] = sprintf(
						/* translators: %d: number of files */
						_n( '%d file skipped: no permission to edit it.', '%d files skipped: no permission to edit them.', $denied, 'rootstuff-media-folders' ),
						$denied
					);
				}
				if ( $result['errors'] ) {
					$message .= ' ' . sprintf(
						/* translators: %s: error details */
						__( 'Some files were skipped: %s', 'rootstuff-media-folders' ),
						implode( ' ', $result['errors'] )
					);
				}
				$this->redirect_with_notice( true, $message );
				break;

			case 'settings':
				$settings = array(
					'rewrite_content'       => empty( $_POST['rsmf_rewrite_content'] ) ? 0 : 1,
					'create_redirects'      => empty( $_POST['rsmf_create_redirects'] ) ? 0 : 1,
					'update_guid'           => empty( $_POST['rsmf_update_guid'] ) ? 0 : 1,
					'default_upload_folder' => '',
				);

				if ( isset( $_POST['rsmf_default_upload_folder'] ) ) {
					$choice = RSMF_Media_Library::normalize_choice( sanitize_text_field( wp_unslash( $_POST['rsmf_default_upload_folder'] ) ) );
					if ( null !== $choice && '' !== $choice ) {
						$settings['default_upload_folder'] = $choice;
					} elseif ( '' === $choice ) {
						// Uploads root selected: store the sentinel so it is
						// distinguishable from "WordPress default".
						$settings['default_upload_folder'] = RSMF_Media_Library::ROOT;
					}
				}

				update_option( 'rsmf_settings', $settings );
				$this->redirect_with_notice( true, __( 'Settings saved.', 'rootstuff-media-folders' ) );
				break;
		}
	}

	/**
	 * Selected IDs for the bulk-move flow. Large selections are parked in
	 * a transient so the id list never overflows URL length limits; the
	 * sentinel 'stored' stands in for them in the request.
	 *
	 * @param string $raw Raw ids value ('stored' or comma-separated ids).
	 * @return int[]
	 */
	protected function resolve_bulk_ids( $raw ) {
		if ( 'stored' === $raw ) {
			$ids = get_transient( 'rsmf_bulk_ids_' . get_current_user_id() );
			return is_array( $ids ) ? array_filter( array_map( 'intval', $ids ) ) : array();
		}
		return array_filter( array_map( 'intval', explode( ',', $raw ) ) );
	}

	/**
	 * Redirect back to the screen with a notice in the URL.
	 *
	 * @param mixed  $result  Operation result (WP_Error on failure).
	 * @param string $success Success message.
	 */
	protected function redirect_with_notice( $result, $success ) {
		$args = array( 'page' => 'rsmf-folders' );

		if ( is_wp_error( $result ) ) {
			$args['rsmf_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['rsmf_notice'] = rawurlencode( $success );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'upload.php' ) ) );
		exit;
	}

	/**
	 * Render the admin screen.
	 */
	public function render_page() {
		if ( isset( $_GET['rsmf_view'] ) && 'bulk-move' === $_GET['rsmf_view'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in render_bulk_move().
			$this->render_bulk_move();
			return;
		}

		$this->render_notices();

		$can_manage = current_user_can( self::manage_capability() );
		?>
		<div class="wrap rsmf-wrap">
			<h1><?php esc_html_e( 'Folder Settings', 'rootstuff-media-folders' ); ?></h1>
			<p>
				<?php esc_html_e( 'Folders are real directories inside your uploads folder. Create, rename, and organize them from the folder sidebar in the', 'rootstuff-media-folders' ); ?>
				<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( 'Media Library', 'rootstuff-media-folders' ); ?></a>.
			</p>

			<?php if ( $can_manage ) : ?>
				<h2><?php esc_html_e( 'Settings', 'rootstuff-media-folders' ); ?></h2>
				<form method="post" class="rsmf-form">
					<?php wp_nonce_field( 'rsmf_settings' ); ?>
					<input type="hidden" name="rsmf_action" value="settings" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'When moving files', 'rootstuff-media-folders' ); ?></th>
							<td>
								<label><input type="checkbox" name="rsmf_rewrite_content" value="1" <?php checked( rsmf_get_setting( 'rewrite_content' ) ); ?> /> <?php esc_html_e( 'Rewrite file URLs in post content', 'rootstuff-media-folders' ); ?></label><br />
								<label><input type="checkbox" name="rsmf_create_redirects" value="1" <?php checked( rsmf_get_setting( 'create_redirects' ) ); ?> /> <?php esc_html_e( 'Create a 301 redirect from the old location', 'rootstuff-media-folders' ); ?></label><br />
								<label><input type="checkbox" name="rsmf_update_guid" value="1" <?php checked( rsmf_get_setting( 'update_guid' ) ); ?> /> <?php esc_html_e( 'Update the attachment GUID to the new URL', 'rootstuff-media-folders' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rsmf_default_upload_folder"><?php esc_html_e( 'Default folder for new uploads', 'rootstuff-media-folders' ); ?></label></th>
							<td>
								<select name="rsmf_default_upload_folder" id="rsmf_default_upload_folder">
									<option value=""><?php esc_html_e( 'WordPress default (year/month)', 'rootstuff-media-folders' ); ?></option>
									<?php
									$current_default = (string) rsmf_get_setting( 'default_upload_folder' );
									foreach ( RSMF_Media_Library::folder_choices() as $choice => $label ) {
										if ( '' === $choice ) {
											continue;
										}
										printf(
											'<option value="%s"%s>%s</option>',
											esc_attr( $choice ),
											selected( $current_default, $choice, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Settings', 'rootstuff-media-folders' ) ); ?>
				</form>

				<h2><?php esc_html_e( 'Recent redirects', 'rootstuff-media-folders' ); ?></h2>
				<?php $redirects = RSMF_Redirects::get_recent(); ?>
				<?php if ( $redirects ) : ?>
					<p>
						<?php
						printf(
							/* translators: %d: number of redirect rules */
							esc_html( _n( '%d redirect rule is active.', '%d redirect rules are active.', RSMF_Redirects::count(), 'rootstuff-media-folders' ) ),
							(int) RSMF_Redirects::count()
						);
						?>
					</p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'From', 'rootstuff-media-folders' ); ?></th>
								<th><?php esc_html_e( 'To', 'rootstuff-media-folders' ); ?></th>
								<th><?php esc_html_e( 'Type', 'rootstuff-media-folders' ); ?></th>
								<th><?php esc_html_e( 'Created', 'rootstuff-media-folders' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $redirects as $redirect ) : ?>
								<tr>
									<td><code><?php echo esc_html( $redirect['old_path'] ); ?></code></td>
									<td><code><?php echo esc_html( $redirect['new_path'] ); ?></code></td>
									<td><?php echo esc_html( $redirect['match_type'] ); ?></td>
									<td><?php echo esc_html( $redirect['created'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'No redirects yet. They are created automatically when files move.', 'rootstuff-media-folders' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Destination picker for the "Move to folder" bulk action.
	 */
	protected function render_bulk_move() {
		check_admin_referer( 'rsmf_bulk_move' );

		if ( ! current_user_can( RSMF_Media_Library::move_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'rootstuff-media-folders' ) );
		}

		$ids = isset( $_GET['ids'] ) ? $this->resolve_bulk_ids( sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) : array();
		?>
		<div class="wrap rsmf-wrap">
			<h1><?php esc_html_e( 'Move files to folder', 'rootstuff-media-folders' ); ?></h1>
			<?php if ( ! $ids ) : ?>
				<p><?php esc_html_e( 'No files were selected.', 'rootstuff-media-folders' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %d: number of selected files */
					esc_html( _n( 'You selected %d file:', 'You selected %d files:', count( $ids ), 'rootstuff-media-folders' ) ),
					count( $ids )
				);
				?>
			</p>
			<ul class="rsmf-file-list">
				<?php foreach ( array_slice( $ids, 0, 25 ) as $id ) : ?>
					<li>
						<code><?php echo esc_html( get_post_meta( $id, '_wp_attached_file', true ) ); ?></code>
					</li>
				<?php endforeach; ?>
				<?php if ( count( $ids ) > 25 ) : ?>
					<li>
						<?php
						printf(
							/* translators: %d: number of additional files */
							esc_html__( '…and %d more.', 'rootstuff-media-folders' ),
							count( $ids ) - 25
						);
						?>
					</li>
				<?php endif; ?>
			</ul>

			<form method="post">
				<?php wp_nonce_field( 'rsmf_bulk_move' ); ?>
				<input type="hidden" name="rsmf_action" value="bulk_move" />
				<input type="hidden" name="rsmf_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
				<label for="rsmf_target"><?php esc_html_e( 'Destination folder:', 'rootstuff-media-folders' ); ?></label>
				<select name="rsmf_target" id="rsmf_target">
					<?php
					foreach ( RSMF_Media_Library::folder_choices() as $choice => $label ) {
						if ( '' === $choice ) {
							continue;
						}
						printf( '<option value="%s">%s</option>', esc_attr( $choice ), esc_html( $label ) );
					}
					?>
				</select>
				<?php submit_button( __( 'Move Files', 'rootstuff-media-folders' ) ); ?>
				<p class="description"><?php esc_html_e( 'Files are moved on the server, database paths are updated, content links are rewritten, and redirects are created.', 'rootstuff-media-folders' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Print notices passed back via the redirect.
	 */
	protected function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['rsmf_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['rsmf_error'] ) ) ) )
			);
		}
		if ( ! empty( $_GET['rsmf_notice'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['rsmf_notice'] ) ) ) )
			);
		}
		// phpcs:enable
	}
}
