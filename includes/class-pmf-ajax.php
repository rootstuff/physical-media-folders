<?php
/**
 * AJAX endpoints for the folder tree sidebar: moving attachments by drag
 * and drop, and folder create/rename/move/delete.
 *
 * @package Physical_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMF_Ajax {

	/**
	 * @var PMF_Ajax|null
	 */
	protected static $instance = null;

	/**
	 * @return PMF_Ajax
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_action( 'wp_ajax_pmf_tree', array( self::$instance, 'tree' ) );
			add_action( 'wp_ajax_pmf_move_attachments', array( self::$instance, 'move_attachments' ) );
			add_action( 'wp_ajax_pmf_folder_op', array( self::$instance, 'folder_op' ) );
		}
		return self::$instance;
	}

	/**
	 * Verify the nonce and a capability, or send a JSON error.
	 *
	 * @param string $capability Required capability.
	 */
	protected function verify( $capability ) {
		check_ajax_referer( 'pmf_ajax', 'nonce' );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to do that.', 'physical-media-folders' ) ),
				403
			);
		}
	}

	/**
	 * Current tree payload sent back after every operation so the sidebar
	 * can re-render with fresh counts.
	 *
	 * @return array
	 */
	protected function tree_payload() {
		return array( 'tree' => PMF_Folders::get_tree() );
	}

	/**
	 * Return the folder tree.
	 */
	public function tree() {
		$this->verify( PMF_Media_Library::move_capability() );
		wp_send_json_success( $this->tree_payload() );
	}

	/**
	 * Move one or more attachments into a folder (drag and drop).
	 */
	public function move_attachments() {
		$this->verify( PMF_Media_Library::move_capability() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer() runs in $this->verify() above.
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		$target = isset( $_POST['target'] ) ? PMF_Media_Library::normalize_choice( sanitize_text_field( wp_unslash( $_POST['target'] ) ) ) : null;
		// phpcs:enable

		if ( ! $ids || null === $target ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to move.', 'physical-media-folders' ) ), 400 );
		}

		$movable = PMF_Media_Library::filter_movable( $ids );
		$denied  = count( $ids ) - count( $movable );

		$result = PMF_Mover::move_many( $movable, $target );

		if ( $denied ) {
			$result['errors'][] = sprintf(
				/* translators: %d: number of files */
				_n( '%d file skipped: no permission to edit it.', '%d files skipped: no permission to edit them.', $denied, 'physical-media-folders' ),
				$denied
			);
		}

		wp_send_json_success(
			array_merge(
				$this->tree_payload(),
				array(
					'moved'  => $result['moved'],
					'errors' => array_values( $result['errors'] ),
				)
			)
		);
	}

	/**
	 * Create, rename/move, or delete a folder.
	 */
	public function folder_op() {
		$this->verify( PMF_Admin::manage_capability() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer() runs in $this->verify() above.
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		// phpcs:enable

		switch ( $op ) {
			case 'create':
				$result = PMF_Folders::create( $path );
				break;
			case 'rename':
				$result = PMF_Folders::rename( $path, $to );
				break;
			case 'delete':
				$result = PMF_Folders::delete( $path );
				break;
			default:
				$result = new WP_Error( 'pmf_bad_op', __( 'Unknown folder operation.', 'physical-media-folders' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $this->tree_payload() );
	}
}
