<?php
/**
 * AJAX endpoints for the folder tree sidebar: moving attachments by drag
 * and drop, and folder create/rename/move/delete.
 *
 * @package Rootstuff_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSMF_Ajax {

	/**
	 * @var RSMF_Ajax|null
	 */
	protected static $instance = null;

	/**
	 * @return RSMF_Ajax
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_action( 'wp_ajax_rsmf_tree', array( self::$instance, 'tree' ) );
			add_action( 'wp_ajax_rsmf_move_attachments', array( self::$instance, 'move_attachments' ) );
			add_action( 'wp_ajax_rsmf_folder_op', array( self::$instance, 'folder_op' ) );
		}
		return self::$instance;
	}

	/**
	 * Verify the nonce and a capability, or send a JSON error.
	 *
	 * @param string $capability Required capability.
	 */
	protected function verify( $capability ) {
		check_ajax_referer( 'rsmf_ajax', 'nonce' );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to do that.', 'rootstuff-media-folders' ) ),
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
		return array( 'tree' => RSMF_Folders::get_tree() );
	}

	/**
	 * Return the folder tree.
	 */
	public function tree() {
		$this->verify( RSMF_Media_Library::move_capability() );
		wp_send_json_success( $this->tree_payload() );
	}

	/**
	 * Move one or more attachments into a folder (drag and drop).
	 */
	public function move_attachments() {
		$this->verify( RSMF_Media_Library::move_capability() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer() runs in $this->verify() above.
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		$target = isset( $_POST['target'] ) ? RSMF_Media_Library::normalize_choice( sanitize_text_field( wp_unslash( $_POST['target'] ) ) ) : null;
		// phpcs:enable

		if ( ! $ids || null === $target ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to move.', 'rootstuff-media-folders' ) ), 400 );
		}

		$movable = RSMF_Media_Library::filter_movable( $ids );
		$denied  = count( $ids ) - count( $movable );

		$result = RSMF_Mover::move_many( $movable, $target );

		if ( $denied ) {
			$result['errors'][] = sprintf(
				/* translators: %d: number of files */
				_n( '%d file skipped: no permission to edit it.', '%d files skipped: no permission to edit them.', $denied, 'rootstuff-media-folders' ),
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
		$this->verify( RSMF_Admin::manage_capability() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer() runs in $this->verify() above.
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		// phpcs:enable

		switch ( $op ) {
			case 'create':
				$result = RSMF_Folders::create( $path );
				break;
			case 'rename':
				$result = RSMF_Folders::rename( $path, $to );
				break;
			case 'delete':
				$result = RSMF_Folders::delete( $path );
				break;
			default:
				$result = new WP_Error( 'rsmf_bad_op', __( 'Unknown folder operation.', 'rootstuff-media-folders' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $this->tree_payload() );
	}
}
