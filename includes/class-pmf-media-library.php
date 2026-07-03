<?php
/**
 * Media library integration: folder filter in list and grid modes, a
 * "Move to folder" bulk action, and a folder field on attachment screens.
 *
 * @package Physical_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMF_Media_Library {

	/**
	 * Sentinel value meaning "the uploads root" in folder dropdowns, since
	 * an empty value means "all folders".
	 */
	const ROOT = '/';

	/**
	 * @var PMF_Media_Library|null
	 */
	protected static $instance = null;

	/**
	 * @return PMF_Media_Library
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();

			add_action( 'restrict_manage_posts', array( self::$instance, 'render_list_filter' ) );
			add_action( 'pre_get_posts', array( self::$instance, 'apply_list_filter' ) );
			add_filter( 'posts_clauses', array( self::$instance, 'filter_by_folder_clauses' ), 10, 2 );
			add_filter( 'ajax_query_attachments_args', array( self::$instance, 'apply_grid_filter' ) );

			add_filter( 'bulk_actions-upload', array( self::$instance, 'register_bulk_action' ) );
			add_filter( 'handle_bulk_actions-upload', array( self::$instance, 'handle_bulk_action' ), 10, 3 );

			add_filter( 'attachment_fields_to_edit', array( self::$instance, 'add_folder_field' ), 10, 2 );
			add_filter( 'attachment_fields_to_save', array( self::$instance, 'save_folder_field' ), 10, 2 );

			add_filter( 'manage_media_columns', array( self::$instance, 'add_folder_column' ) );
			add_action( 'manage_media_custom_column', array( self::$instance, 'render_folder_column' ), 10, 2 );

			add_action( 'admin_enqueue_scripts', array( self::$instance, 'enqueue_assets' ) );
		}
		return self::$instance;
	}

	/**
	 * Capability required to move files between folders.
	 *
	 * @return string
	 */
	public static function move_capability() {
		return apply_filters( 'pmf_move_capability', 'upload_files' );
	}

	/**
	 * Keep only the attachments this user may move. Moving rewrites URLs
	 * across post content, so it requires edit rights on the attachment
	 * itself, not just upload_files.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return int[]
	 */
	public static function filter_movable( $ids ) {
		return array_values(
			array_filter(
				array_map( 'intval', (array) $ids ),
				function ( $id ) {
					return current_user_can( 'edit_post', $id );
				}
			)
		);
	}

	/**
	 * The physical folder an attachment currently lives in.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Relative folder path, '' for the uploads root.
	 */
	public static function get_attachment_folder( $attachment_id ) {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file || false === strpos( $file, '/' ) ) {
			return '';
		}
		return dirname( wp_normalize_path( $file ) );
	}

	/**
	 * Folder choices for dropdowns.
	 *
	 * @param string $all_label Label for the "all folders" option ('' to omit).
	 * @return array value => label
	 */
	public static function folder_choices( $all_label = '' ) {
		$choices = array();

		if ( '' !== $all_label ) {
			$choices[''] = $all_label;
		}

		$choices[ self::ROOT ] = __( 'Uploads root', 'physical-media-folders' );

		foreach ( PMF_Folders::get_folders() as $folder ) {
			$depth              = substr_count( $folder, '/' );
			$choices[ $folder ] = str_repeat( '— ', $depth ) . wp_basename( $folder );
		}

		return $choices;
	}

	/**
	 * Normalize a dropdown value to a folder path ('' = root) or null when
	 * no folder was chosen.
	 *
	 * @param string $value Raw dropdown value.
	 * @return string|null
	 */
	public static function normalize_choice( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}
		if ( self::ROOT === $value ) {
			return '';
		}
		$clean = PMF_Folders::sanitize_path( $value );
		return is_wp_error( $clean ) ? null : $clean;
	}

	/**
	 * Folder dropdown on the media library list screen.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_list_filter( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$current = isset( $_GET['pmf_folder'] ) ? sanitize_text_field( wp_unslash( $_GET['pmf_folder'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.

		echo '<select name="pmf_folder" id="pmf_folder_filter">';
		foreach ( self::folder_choices( __( 'All folders', 'physical-media-folders' ) ) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Carry the list-screen filter into the main attachment query.
	 *
	 * @param WP_Query $query Query.
	 */
	public function apply_list_filter( $query ) {
		global $pagenow;

		if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		if ( ! isset( $_GET['pmf_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$folder = self::normalize_choice( sanitize_text_field( wp_unslash( $_GET['pmf_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( null !== $folder ) {
			$query->set( 'pmf_folder', ( '' === $folder ) ? self::ROOT : $folder );
		}
	}

	/**
	 * Carry the grid-mode filter (sent by our media JS) into the ajax
	 * attachment query. WordPress strips unknown keys before this filter
	 * runs, so the value is read from the raw request.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public function apply_grid_filter( $args ) {
		if ( isset( $_REQUEST['query']['pmf_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core verifies the ajax nonce.
			$folder = self::normalize_choice( sanitize_text_field( wp_unslash( $_REQUEST['query']['pmf_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core verifies the ajax nonce.
			if ( null !== $folder ) {
				$args['pmf_folder'] = ( '' === $folder ) ? self::ROOT : $folder;
			}
		}
		return $args;
	}

	/**
	 * Restrict a query to attachments physically inside one folder, using an
	 * anchored LIKE (meta_query LIKE cannot anchor to the start of a value).
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public function filter_by_folder_clauses( $clauses, $query ) {
		global $wpdb;

		$folder = $query->get( 'pmf_folder' );
		if ( ! $folder ) {
			return $clauses;
		}

		if ( self::ROOT === $folder ) {
			$clauses['where'] .= $wpdb->prepare(
				" AND EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pmf_meta
					WHERE pmf_meta.post_id = {$wpdb->posts}.ID
					AND pmf_meta.meta_key = '_wp_attached_file'
					AND pmf_meta.meta_value NOT LIKE %s
				)",
				'%/%'
			);
			return $clauses;
		}

		$like = $wpdb->esc_like( $folder ) . '/%';

		$clauses['where'] .= $wpdb->prepare(
			" AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pmf_meta
				WHERE pmf_meta.post_id = {$wpdb->posts}.ID
				AND pmf_meta.meta_key = '_wp_attached_file'
				AND pmf_meta.meta_value LIKE %s
				AND pmf_meta.meta_value NOT LIKE %s
			)",
			$like,
			$like . '/%'
		);

		return $clauses;
	}

	/**
	 * Add the "Move to folder" bulk action.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function register_bulk_action( $actions ) {
		if ( current_user_can( self::move_capability() ) ) {
			$actions['pmf_move'] = __( 'Move to folder…', 'physical-media-folders' );
		}
		return $actions;
	}

	/**
	 * Send the selected attachments to the folder-picker screen.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action name.
	 * @param array  $ids      Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'pmf_move' !== $action || empty( $ids ) ) {
			return $redirect;
		}

		if ( ! current_user_can( self::move_capability() ) ) {
			return $redirect;
		}

		$ids = array_map( 'intval', $ids );

		// Large selections would overflow URL length limits; park them in
		// a transient and pass a sentinel instead.
		if ( count( $ids ) > 100 ) {
			set_transient( 'pmf_bulk_ids_' . get_current_user_id(), $ids, 15 * MINUTE_IN_SECONDS );
			$ids_param = 'stored';
		} else {
			$ids_param = implode( ',', $ids );
		}

		return add_query_arg(
			array(
				'page'     => 'pmf-folders',
				'pmf_view' => 'bulk-move',
				'ids'      => $ids_param,
				'_wpnonce' => wp_create_nonce( 'pmf_bulk_move' ),
			),
			admin_url( 'upload.php' )
		);
	}

	/**
	 * Folder dropdown on the attachment edit screen and media modal.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment post.
	 * @return array
	 */
	public function add_folder_field( $fields, $post ) {
		if ( ! current_user_can( self::move_capability() ) ) {
			return $fields;
		}

		$current = self::get_attachment_folder( $post->ID );
		$value   = ( '' === $current ) ? self::ROOT : $current;

		$html = '<select name="attachments[' . $post->ID . '][pmf_folder]" id="attachments-' . $post->ID . '-pmf_folder">';
		foreach ( self::folder_choices() as $choice => $label ) {
			if ( '' === $choice ) {
				continue;
			}
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $choice ),
				selected( $value, $choice, false ),
				esc_html( $label )
			);
		}
		$html .= '</select>';

		$fields['pmf_folder'] = array(
			'label' => __( 'Folder', 'physical-media-folders' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'Changing the folder moves the file on the server and updates links.', 'physical-media-folders' ),
		);

		return $fields;
	}

	/**
	 * Move the file when the folder field changed on save.
	 *
	 * @param array $post       Attachment data.
	 * @param array $attachment Submitted fields.
	 * @return array
	 */
	public function save_folder_field( $post, $attachment ) {
		if ( ! isset( $attachment['pmf_folder'] ) || ! current_user_can( self::move_capability() ) ) {
			return $post;
		}

		$target = self::normalize_choice( sanitize_text_field( $attachment['pmf_folder'] ) );
		if ( null === $target ) {
			return $post;
		}

		if ( $target === self::get_attachment_folder( $post['ID'] ) ) {
			return $post;
		}

		$result = PMF_Mover::move( $post['ID'], $target );
		if ( is_wp_error( $result ) ) {
			$post['errors']['pmf_folder']['errors'][] = $result->get_error_message();
		}

		return $post;
	}

	/**
	 * Folder column in the list table.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_folder_column( $columns ) {
		$columns['pmf_folder'] = __( 'Folder', 'physical-media-folders' );
		return $columns;
	}

	/**
	 * Render the folder column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Attachment ID.
	 */
	public function render_folder_column( $column, $post_id ) {
		if ( 'pmf_folder' !== $column ) {
			return;
		}

		$folder = self::get_attachment_folder( $post_id );
		$value  = ( '' === $folder ) ? self::ROOT : $folder;
		$label  = ( '' === $folder ) ? __( 'Uploads root', 'physical-media-folders' ) : $folder;

		printf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'pmf_folder', rawurlencode( $value ), admin_url( 'upload.php?mode=list' ) ) ),
			esc_html( $label )
		);
	}

	/**
	 * Enqueue the searchable folder combobox, used wherever a folder
	 * select renders.
	 */
	protected function enqueue_folder_picker() {
		wp_enqueue_script(
			'pmf-folder-picker',
			PMF_PLUGIN_URL . 'assets/folder-picker.js',
			array(),
			PMF_VERSION,
			true
		);

		wp_localize_script(
			'pmf-folder-picker',
			'pmfPicker',
			array(
				'placeholder' => __( 'Search folders…', 'physical-media-folders' ),
				'noMatches'   => __( 'No folders match.', 'physical-media-folders' ),
			)
		);

		wp_enqueue_style(
			'pmf-folder-picker',
			PMF_PLUGIN_URL . 'assets/folder-picker.css',
			array(),
			PMF_VERSION
		);
	}

	/**
	 * Enqueue the grid-mode filter script on media screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// The settings page only needs the page styles and the picker.
		if ( 'media_page_pmf-folders' === $hook ) {
			wp_enqueue_style( 'pmf-admin', PMF_PLUGIN_URL . 'assets/admin.css', array(), PMF_VERSION );
			$this->enqueue_folder_picker();
			return;
		}

		// The Add Media File screen only needs the picker (for its
		// "Upload to folder" dropdown).
		if ( 'media-new.php' === $hook ) {
			$this->enqueue_folder_picker();
			return;
		}

		$media_screens = array( 'upload.php', 'post.php', 'post-new.php' );
		if ( ! in_array( $hook, $media_screens, true ) && ! did_action( 'wp_enqueue_media' ) ) {
			return;
		}

		$this->enqueue_folder_picker();

		wp_enqueue_script(
			'pmf-media',
			PMF_PLUGIN_URL . 'assets/admin.js',
			array( 'media-views' ),
			PMF_VERSION,
			true
		);

		// On upload.php the full tree ships with pmf-tree; the dropdown
		// builds its choices from that instead of a duplicate list.
		wp_localize_script(
			'pmf-media',
			'pmfMedia',
			array(
				'choices'   => ( 'upload.php' === $hook ) ? array() : self::folder_choices( __( 'All folders', 'physical-media-folders' ) ),
				'label'     => __( 'Filter by folder', 'physical-media-folders' ),
				'allLabel'  => __( 'All folders', 'physical-media-folders' ),
				'rootLabel' => __( 'Uploads root', 'physical-media-folders' ),
				'canManage' => current_user_can( PMF_Admin::manage_capability() ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'pmf_ajax' ),
				'i18n'      => array(
					'newFolder'    => __( 'New folder', 'physical-media-folders' ),
					'folderName'   => __( 'Folder name…', 'physical-media-folders' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'physical-media-folders' ),
				),
			)
		);

		wp_enqueue_style(
			'pmf-admin',
			PMF_PLUGIN_URL . 'assets/admin.css',
			array(),
			PMF_VERSION
		);

		// The tree sidebar loads on the library screens only (not inside
		// the post editor's media modal).
		if ( 'upload.php' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'pmf-tree',
			PMF_PLUGIN_URL . 'assets/tree.js',
			array( 'wp-plupload' ),
			PMF_VERSION,
			true
		);

		wp_enqueue_style(
			'pmf-tree',
			PMF_PLUGIN_URL . 'assets/tree.css',
			array(),
			PMF_VERSION
		);

		$current = null;
		if ( isset( $_GET['pmf_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
			$current = self::normalize_choice( sanitize_text_field( wp_unslash( $_GET['pmf_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		wp_localize_script(
			'pmf-tree',
			'pmfTree',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'pmf_ajax' ),
				'tree'          => PMF_Folders::get_tree(),
				'canManage'     => current_user_can( PMF_Admin::manage_capability() ),
				'currentFolder' => $current,
				'i18n'          => array(
					'heading'           => __( 'Folders', 'physical-media-folders' ),
					'allFiles'          => __( 'All files', 'physical-media-folders' ),
					'uploadsRoot'       => __( 'Uploads root', 'physical-media-folders' ),
					'newFolder'         => __( 'New folder', 'physical-media-folders' ),
					'collapseAll'       => __( 'Collapse all folders', 'physical-media-folders' ),
					'searchMedia'       => __( 'Search Media', 'physical-media-folders' ),
					'dropHint'          => __( 'Drop onto a folder to move the file(s) there.', 'physical-media-folders' ),
					'newSubfolder'      => __( 'New subfolder', 'physical-media-folders' ),
					'newFolderName'     => __( 'Folder name…', 'physical-media-folders' ),
					'folderName'        => __( 'Folder name', 'physical-media-folders' ),
					'rename'            => __( 'Rename', 'physical-media-folders' ),
					'deleteFolder'      => __( 'Delete (empty folders only)', 'physical-media-folders' ),
					'confirmShort'      => __( 'Sure?', 'physical-media-folders' ),
					'folderMoved'       => __( 'Folder moved. Links updated and a redirect was added.', 'physical-media-folders' ),
					'folderDeleted'     => __( 'Folder deleted.', 'physical-media-folders' ),
					/* translators: %d is replaced in JS with the number of files moved. */
					'movedFiles'        => __( '%d file(s) moved.', 'physical-media-folders' ),
					'cannotMoveIntoSelf' => __( 'A folder cannot be moved inside itself.', 'physical-media-folders' ),
					'genericError'      => __( 'Something went wrong. Please try again.', 'physical-media-folders' ),
					'searchFolders'     => __( 'Search folders…', 'physical-media-folders' ),
					'noMatches'         => __( 'No folders match.', 'physical-media-folders' ),
					/* translators: %d is replaced in JS with the number of files uploaded. */
					'uploadedFiles'     => __( '%d file(s) uploaded.', 'physical-media-folders' ),
				),
			)
		);
	}
}
